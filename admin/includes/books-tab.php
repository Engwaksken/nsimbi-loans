<?php
declare(strict_types=1);

/**
 * Data and link helpers for the Books History tab on view_client.php.
 * Rendering lives in partials/client-books-tab.php.
 */

require_once __DIR__ . '/../../includes/books_ledger.php';

const BOOKS_TAB_STATUSES = [
    'confirmed' => 'Confirmed',
    'all' => 'All statuses',
];

const BOOKS_TAB_SORT_COLUMNS = ['type', 'entry_date', 'item_name', 'quantity', 'unit', 'amount', 'status'];

const BOOKS_TAB_PAGE_SIZES = [25, 50, 100];

const BOOKS_TAB_DEFAULT_PAGE_SIZE = 25;

function books_type_badge(string $type): string
{
    if (books_is_money_in($type)) {
        return 'badge-success';
    }

    return books_is_money_out($type) ? 'badge-warning' : 'badge-muted';
}

/**
 * Builds a link from the validated filter state rather than from $_GET, so a
 * rejected value is not carried forward into every link on the page.
 */
function books_query(array $books, array $overrides = []): string
{
    $params = [
        'user_id' => (int)$books['user_id'],
        'tab' => 'books',
        'cadence' => $books['cadence'],
        'offset' => $books['cadence_offset'],
        'book_status' => $books['status'],
        'book_type' => $books['type'],
        'sort' => $books['sort'],
        'dir' => $books['dir'],
        'page' => $books['page'],
        'page_size' => $books['page_size'],
    ];

    $merged = array_merge($params, $overrides);

    // export=pdf is only for the button / download URL, never a sticky filter.
    if (($merged['export'] ?? '') !== 'pdf') {
        unset($merged['export']);
    }

    return 'view_client.php?' . http_build_query($merged);
}

function books_sort_href(array $books, string $column): string
{
    $nextDir = ($books['sort'] === $column && $books['dir'] === 'asc') ? 'desc' : 'asc';

    return books_query($books, ['sort' => $column, 'dir' => $nextDir, 'page' => 1]);
}

function books_sort_icon(array $books, string $column): string
{
    if ($books['sort'] !== $column) {
        return 'fa-sort text-muted';
    }

    return $books['dir'] === 'asc' ? 'fa-sort-up' : 'fa-sort-down';
}

/**
 * Reads the books filters off the query string, runs the summary and entry
 * queries for one client, and returns everything the partial needs.
 *
 * When $forExport is true (or $_GET['export'] === 'pdf'), every matching row
 * is returned with no LIMIT — same convention as all_loans_report.php.
 */
function books_tab_view_model(mysqli $conn, int $clientUserId, bool $forExport = false): array
{
    $forExport = $forExport || (($_GET['export'] ?? '') === 'pdf');

    $cadence = trim((string)($_GET['cadence'] ?? 'monthly'));
    if (!in_array($cadence, BOOKS_CADENCES, true)) {
        $cadence = 'monthly';
    }

    $cadenceOffset = (int)($_GET['offset'] ?? 0);
    if ($cadenceOffset > 0) {
        $cadenceOffset = 0;
    }

    $status = trim((string)($_GET['book_status'] ?? 'confirmed'));
    if (!isset(BOOKS_TAB_STATUSES[$status])) {
        $status = 'confirmed';
    }

    $type = trim((string)($_GET['book_type'] ?? 'all'));
    if ($type !== 'all' && !in_array($type, BOOKS_ENTRY_TYPES, true)) {
        $type = 'all';
    }

    $sort = trim((string)($_GET['sort'] ?? 'entry_date'));
    if (!in_array($sort, BOOKS_TAB_SORT_COLUMNS, true)) {
        $sort = 'entry_date';
    }

    $dir = strtolower(trim((string)($_GET['dir'] ?? 'desc')));
    if (!in_array($dir, ['asc', 'desc'], true)) {
        $dir = 'desc';
    }

    $pageSize = (int)($_GET['page_size'] ?? BOOKS_TAB_DEFAULT_PAGE_SIZE);
    if (!in_array($pageSize, BOOKS_TAB_PAGE_SIZES, true)) {
        $pageSize = BOOKS_TAB_DEFAULT_PAGE_SIZE;
    }

    $page = max(1, (int)($_GET['page'] ?? 1));

    [$rangeStart, $rangeEnd] = books_cadence_range($cadence, $cadenceOffset);
    $periodLabel = books_cadence_label($cadence, $rangeStart, $rangeEnd);
    $nav = books_cadence_nav($cadence, $cadenceOffset);

    $where = 'user_id = ? AND entry_date >= ? AND entry_date < ?';
    $params = [$clientUserId, $rangeStart->format('Y-m-d H:i:s'), $rangeEnd->format('Y-m-d H:i:s')];
    $paramTypes = 'iss';

    if ($status === 'confirmed') {
        $where .= " AND status = 'confirmed'";
    }

    if ($type !== 'all') {
        $where .= ' AND type = ?';
        $params[] = $type;
        $paramTypes .= 's';
    }

    $model = [
        'user_id' => $clientUserId,
        'cadence' => $cadence,
        'cadence_offset' => $cadenceOffset,
        'period_label' => $periodLabel,
        'nav' => $nav,
        'month' => $rangeStart->format('Y-m'),
        'month_label' => $periodLabel,
        'status' => $status,
        'type' => $type,
        'sort' => $sort,
        'dir' => $dir,
        'page' => $page,
        'page_size' => $pageSize,
        'offset' => 0,
        'total_rows' => 0,
        'total_pages' => 1,
        'entries' => [],
        'money_in' => 0.0,
        'money_out' => 0.0,
        'net' => 0.0,
        'surplus' => 0.0,
        'data_state' => 'ok',
        'failed' => false,
    ];

    $summarySql = 'SELECT COUNT(*) AS total_rows, ' . books_money_select_sql()
        . " FROM book_entries WHERE {$where}";

    $summaryStmt = $conn->prepare($summarySql);
    if (!$summaryStmt) {
        error_log('books tab: summary query prepare failed: ' . $conn->error);
        $model['failed'] = true;
        return $model;
    }

    $summaryStmt->bind_param($paramTypes, ...$params);
    $summaryStmt->execute();
    $summary = $summaryStmt->get_result()->fetch_assoc() ?: [];
    $summaryStmt->close();

    $model['total_rows'] = (int)($summary['total_rows'] ?? 0);
    $model['money_in'] = (float)($summary['money_in'] ?? 0);
    $model['money_out'] = (float)($summary['money_out'] ?? 0);
    $model['net'] = $model['money_in'] - $model['money_out'];
    $model['surplus'] = $model['money_in'] - $model['money_out'];

    if ($model['total_rows'] === 0) {
        $model['data_state'] = 'empty';
    } elseif ($model['money_in'] == 0 && $model['money_out'] == 0) {
        $model['data_state'] = 'low';
    }

    if ($forExport) {
        $listSql = '
            SELECT type, item_name, quantity, unit, amount, entry_date, status
            FROM book_entries
            WHERE ' . $where . '
            ORDER BY ' . $sort . ' ' . strtoupper($dir) . ', id DESC';

        $listStmt = $conn->prepare($listSql);
        if (!$listStmt) {
            error_log('books tab: entries query prepare failed: ' . $conn->error);
            $model['failed'] = true;
            return $model;
        }

        $listStmt->bind_param($paramTypes, ...$params);
        $listStmt->execute();
        $result = $listStmt->get_result();

        while ($entry = $result->fetch_assoc()) {
            $model['entries'][] = $entry;
        }

        $listStmt->close();

        return $model;
    }

    $model['total_pages'] = max(1, (int)ceil($model['total_rows'] / $pageSize));

    if ($model['page'] > $model['total_pages']) {
        $model['page'] = $model['total_pages'];
    }
    $model['offset'] = ($model['page'] - 1) * $pageSize;

    $listSql = '
        SELECT type, item_name, quantity, unit, amount, entry_date, status
        FROM book_entries
        WHERE ' . $where . '
        ORDER BY ' . $sort . ' ' . strtoupper($dir) . ', id DESC
        LIMIT ? OFFSET ?';

    $listStmt = $conn->prepare($listSql);
    if (!$listStmt) {
        error_log('books tab: entries query prepare failed: ' . $conn->error);
        $model['failed'] = true;
        return $model;
    }

    $listParams = array_merge($params, [$pageSize, $model['offset']]);
    $listStmt->bind_param($paramTypes . 'ii', ...$listParams);
    $listStmt->execute();
    $result = $listStmt->get_result();

    while ($entry = $result->fetch_assoc()) {
        $model['entries'][] = $entry;
    }

    $listStmt->close();

    return $model;
}
