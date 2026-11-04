<?php
declare(strict_types=1);

$phead = "BOOKS HISTORY REPORT";

require "nav-head.php";
require_once __DIR__ . '/../../includes/books_ledger.php';

function h($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function money($v): string
{
    return 'UGX ' . number_format((float)$v);
}

const BOOKS_HISTORY_PAGE_SIZES = [25, 50, 100];
const BOOKS_HISTORY_DEFAULT_PAGE_SIZE = 25;

/**
 * Builds a link from the validated filter state rather than from $_GET, so a
 * rejected value is not carried forward into every link on the page.
 */
function books_history_query(array $state, array $overrides = []): string
{
    return 'books_history.php?' . http_build_query(array_merge($state, $overrides));
}

$q = trim($_GET['q'] ?? '');
$selected_month = books_normalize_month($_GET['month'] ?? null);
[$monthStart, $monthEnd] = books_month_range($selected_month);
$month_display = books_month_label($selected_month);

$pageSize = (int)($_GET['page_size'] ?? BOOKS_HISTORY_DEFAULT_PAGE_SIZE);
if (!in_array($pageSize, BOOKS_HISTORY_PAGE_SIZES, true)) {
    $pageSize = BOOKS_HISTORY_DEFAULT_PAGE_SIZE;
}

$page = max(1, (int)($_GET['page'] ?? 1));

/*
 * Half-open range on entry_date rather than DATE_FORMAT(entry_date, '%Y-%m'),
 * so the query can use an index on the column. The search terms are only
 * appended when a term was actually given, for the same reason.
 */
$where = "be.status = 'confirmed' AND be.entry_date >= ? AND be.entry_date < ?";
$params = [$monthStart->format('Y-m-d H:i:s'), $monthEnd->format('Y-m-d H:i:s')];
$paramTypes = 'ss';

if ($q !== '') {
    $where .= " AND (u.fname LIKE ? OR u.lname LIKE ? OR COALESCE(c.email, u.email) LIKE ?)";
    $searchLike = '%' . $q . '%';
    array_push($params, $searchLike, $searchLike, $searchLike);
    $paramTypes .= 'sss';
}

$from = "
    FROM customers c
    INNER JOIN user u ON c.user_id = u.id
    INNER JOIN book_entries be ON be.user_id = u.id
    WHERE {$where}
";

$records = [];
$totalRows = 0;
$totalPages = 1;
$offset = 0;
$queryFailed = false;

$countStmt = $conn->prepare("SELECT COUNT(DISTINCT u.id) AS total {$from}");

if (!$countStmt) {
    error_log('books_history: count query prepare failed: ' . $conn->error);
    $queryFailed = true;
} else {
    $countStmt->bind_param($paramTypes, ...$params);
    $countStmt->execute();
    $totalRows = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $totalPages = max(1, (int)ceil($totalRows / $pageSize));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $pageSize;

    $sql = "
        SELECT
            u.id AS user_id,
            CONCAT(COALESCE(u.fname, ''), ' ', COALESCE(u.lname, '')) AS client_name,
            COALESCE(c.email, u.email) AS client_email,
            " . books_money_select_sql('be') . "
        {$from}
        GROUP BY u.id, u.fname, u.lname, c.email, u.email
        ORDER BY client_name
        LIMIT ? OFFSET ?
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log('books_history: list query prepare failed: ' . $conn->error);
        $queryFailed = true;
    } else {
        $listParams = array_merge($params, [$pageSize, $offset]);
        $stmt->bind_param($paramTypes . 'ii', ...$listParams);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $row['net_worth'] = (float)($row['money_in'] ?? 0) - (float)($row['money_out'] ?? 0);
            $records[] = $row;
        }

        $stmt->close();
    }
}

$linkState = [
    'q' => $q,
    'month' => $selected_month,
    'page' => $page,
    'page_size' => $pageSize,
];

// Carry the month being viewed into the client tab so the drill-down lands on
// the same month instead of defaulting to the current one.
$clientTabQuery = [
    'tab' => 'books',
    'month' => $selected_month,
];
?>

<div class="report-page">

    <div class="page-header mb-4">
        <div>
            <div class="title">Books History Report</div>
            <div class="sub">
                <?= number_format($totalRows) ?> customer record<?= $totalRows === 1 ? '' : 's' ?>
                for <?= h($month_display) ?>
            </div>
        </div>
    </div>

    <?php if ($queryFailed): ?>
        <div class="alert alert-danger">
            The books history report is temporarily unavailable. Please try again shortly.
        </div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-header">
            <span class="card-title">
                <i class="fas fa-filter"></i> Filter &amp; Search
            </span>
        </div>

        <div class="card-body">
            <form method="GET">
                <input type="hidden" name="page_size" value="<?= (int)$pageSize ?>">

                <div class="grid-3">
                    <div>
                        <label class="form-label" for="booksHistorySearch">Search</label>
                        <input
                            type="text"
                            id="booksHistorySearch"
                            name="q"
                            value="<?= h($q) ?>"
                            class="form-control"
                            placeholder="First name, last name, email..."
                        >
                    </div>

                    <div>
                        <label class="form-label" for="booksHistoryMonth">Select Month</label>
                        <input
                            type="month"
                            id="booksHistoryMonth"
                            name="month"
                            class="form-control"
                            value="<?= h($selected_month) ?>"
                        >
                    </div>

                    <div class="books-filter-actions books-filter-actions-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filter
                        </button>

                        <a href="books_history.php" class="btn btn-ghost">
                            <i class="fas fa-rotate-left"></i> Reset
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <span class="card-title">
                <i class="fas fa-table"></i> Customer Records
            </span>
            <span class="badge badge-muted"><?= number_format($totalRows) ?> records</span>
        </div>

        <div class="card-body no-pad">
            <div class="table-responsive">
                <table class="table" id="tbl-books-history">
                    <thead>
                    <tr>
                        <th class="col-num">#</th>
                        <th>Client</th>
                        <th>Money In</th>
                        <th>Money Out</th>
                        <th>Net Worth</th>
                        <th class="text-end">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($records)): ?>
                        <?php foreach ($records as $index => $row): ?>
                            <?php
                            $clientName = trim((string)($row['client_name'] ?? ''));
                            if ($clientName === '') {
                                $clientName = 'N/A';
                            }
                            $clientHref = 'view_client.php?' . http_build_query(
                                ['user_id' => (int)$row['user_id']] + $clientTabQuery
                            );
                            ?>
                            <tr>
                                <td class="col-num"><?= number_format($offset + $index + 1) ?></td>
                                <td>
                                    <div class="fw-700"><?= h($clientName) ?></div>
                                    <small class="text-muted"><?= h($row['client_email'] ?? '') ?></small>
                                </td>
                                <td class="text-success fw-700"><?= money($row['money_in'] ?? 0) ?></td>
                                <td class="text-danger fw-700"><?= money($row['money_out'] ?? 0) ?></td>
                                <td class="fw-700"><?= money($row['net_worth'] ?? 0) ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <a
                                            href="<?= h($clientHref) ?>"
                                            class="btn btn-info btn-icon-sm"
                                            title="View Books History"
                                        >
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <i class="fas fa-book"></i>
                                    <h3>No customer records found</h3>
                                    <p>Try a different month or search term.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($totalRows > 0): ?>
        <?php
        $pageWindow = 2;
        $firstLinkedPage = max(1, $page - $pageWindow);
        $lastLinkedPage = min($totalPages, $page + $pageWindow);
        $showingFrom = $offset + 1;
        $showingTo = min($offset + $pageSize, $totalRows);
        ?>
        <div class="pag-wrap">
            <div class="pag-info books-pag-info">
                <span>
                    Showing <?= number_format($showingFrom) ?> -
                    <?= number_format($showingTo) ?>
                    of <?= number_format($totalRows) ?> records
                </span>

                <label class="books-page-size" for="booksHistoryPageSize">
                    <span>Rows per page</span>
                    <select id="booksHistoryPageSize" class="form-control">
                        <?php foreach (BOOKS_HISTORY_PAGE_SIZES as $size): ?>
                            <option
                                value="<?= (int)$size ?>"
                                <?= $pageSize === $size ? 'selected' : '' ?>
                                data-href="<?= h(books_history_query($linkState, ['page_size' => $size, 'page' => 1])) ?>"
                            >
                                <?= (int)$size ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <div class="pagination">
                <?php if ($page <= 1): ?>
                    <span class="pg-btn dis" aria-disabled="true"><i class="fas fa-chevron-left"></i></span>
                <?php else: ?>
                    <a class="pg-btn" href="<?= h(books_history_query($linkState, ['page' => $page - 1])) ?>" rel="prev">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                <?php endif; ?>

                <?php for ($p = $firstLinkedPage; $p <= $lastLinkedPage; $p++): ?>
                    <?php if ($p === $page): ?>
                        <span class="pg-btn act" aria-current="page"><?= $p ?></span>
                    <?php else: ?>
                        <a class="pg-btn" href="<?= h(books_history_query($linkState, ['page' => $p])) ?>"><?= $p ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page >= $totalPages): ?>
                    <span class="pg-btn dis" aria-disabled="true"><i class="fas fa-chevron-right"></i></span>
                <?php else: ?>
                    <a class="pg-btn" href="<?= h(books_history_query($linkState, ['page' => $page + 1])) ?>" rel="next">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

</div>

<script src="<?= h(asset_url('assets/js/books-history.js')) ?>"></script>

<?php require "footer.php"; ?>
