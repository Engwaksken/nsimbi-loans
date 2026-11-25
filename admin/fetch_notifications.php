<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require "db.php";
require_once dirname(__DIR__) . '/includes/officer_assignment.php';

// Staff only (same roles as the admin area).
if (empty($_SESSION['logged_in']) || !in_array((int)($_SESSION['role'] ?? 0), [1, 2], true)) {
    http_response_code(403);
    exit;
}

/** Loan officers: only messages about their own loans or clients. '' for others. */
function officer_message_sql(): string
{
    $officerId = officer_scope_id();
    if ($officerId <= 0) {
        return '';
    }
    return "(
        loan_id IN (SELECT id FROM loan WHERE assigned_officer_id = {$officerId})
        OR (COALESCE(loan_id, 0) = 0 AND (
            user_id IN (SELECT user_id FROM customers WHERE assigned_officer_id = {$officerId})
            OR user_id IN (SELECT user_id FROM members WHERE assigned_officer_id = {$officerId})
        ))
    )";
}

function h($v){
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$page   = max(1, (int)($_GET['page'] ?? 1));
$search = $_GET['search'] ?? '';

$limit  = 10;
$offset = ($page - 1) * $limit;

// ===== WHERE =====
$conditions = [];
$params = [];
$types = "";

if ($search !== '') {
    $conditions[] = "(sender_name LIKE ? OR message_type LIKE ? OR message LIKE ?)";
    $like = "%$search%";
    $params = [$like, $like, $like];
    $types = "sss";
}

if (officer_message_sql() !== '') {
    $conditions[] = officer_message_sql();
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

// ===== COUNT =====
$countSql = "SELECT COUNT(*) total FROM loan_messages $where";
$stmt = $conn->prepare($countSql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$totalRows = $stmt->get_result()->fetch_assoc()['total'];

$totalPages = max(1, ceil($totalRows / $limit));

// ===== DATA =====
$sql = "SELECT * FROM loan_messages $where ORDER BY created_at DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $types .= "ii";
    $params[] = $limit;
    $params[] = $offset;
    $stmt->bind_param($types, ...$params);
} else {
    $stmt->bind_param("ii", $limit, $offset);
}

$stmt->execute();
$result = $stmt->get_result();

// ===== ROWS =====
$rows = '';
$i = $offset + 1;

while ($row = $result->fetch_assoc()) {

    $isRead = $row['is_read'];

$rows .= "
<tr class='notification-row ".($isRead ? '' : 'unread-row')."' 
    data-id='{$row['id']}'
    style='cursor:pointer'>

    <td>{$i}</td>
    <td>".h($row['sender_name'])."</td>
    <td>".h(ucwords(str_replace('_',' ',$row['message_type'])))."</td>

    <td>
        <span class='badge ".($isRead ? 'badge-read' : 'badge-unread')."'>
            ".($isRead ? 'Read' : 'Unread')."
        </span>
    </td>

    <td>".date('M d, Y', strtotime($row['created_at']))."</td>

    <td class='text-center'>
        ".(!$isRead ? "
            <button class='btn btn-sm btn-outline-primary mark-read-btn'>
                <i class='fas fa-check'></i> Read
            </button>
        " : "
            <span class='text-muted small'>Done</span>
        ")."
    </td>

</tr>";
    $i++;
}

// ===== PAGINATION =====
$pagination = '';

if ($totalPages > 1) {

    $pagination .= '<ul class="pagination justify-content-center">';

    for ($p = 1; $p <= $totalPages; $p++) {
        $active = ($p == $page) ? 'active' : '';
        $pagination .= "<li class='page-item $active'>
            <a href='#' class='page-link' onclick='loadNotifications($p)'>$p</a>
        </li>";
    }

    $pagination .= '</ul>';
}

// ===== RETURN JSON =====
echo json_encode([
    'rows' => $rows ?: "<tr><td colspan='6' class='text-center'>No data</td></tr>",
    'pagination' => $pagination
]);