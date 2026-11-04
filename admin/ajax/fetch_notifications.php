<?php
require "db.php";

function h($v){
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$page   = max(1, (int)($_GET['page'] ?? 1));
$search = $_GET['search'] ?? '';

$limit  = 10;
$offset = ($page - 1) * $limit;

// ===== WHERE =====
$where = "";
$params = [];
$types = "";

if ($search !== '') {
    $where = "WHERE sender_name LIKE ? OR message_type LIKE ? OR message LIKE ?";
    $like = "%$search%";
    $params = [$like, $like, $like];
    $types = "sss";
}

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
    <tr class='".($isRead ? '' : 'unread-row')."'>
        <td>{$i}</td>
        <td>".h($row['sender_name'])."</td>
        <td>".h(ucwords(str_replace('_',' ',$row['message_type'])))."</td>
        <td>".h(mb_strimwidth($row['message'],0,80,'...'))."</td>
        <td>".($isRead ? 'Read' : 'Unread')."</td>
        <td>".date('M d, Y', strtotime($row['created_at']))."</td>
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