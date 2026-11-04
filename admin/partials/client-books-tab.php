<?php
/**
 * Books History tab body for view_client.php.
 * Expects $books (see books_tab_view_model()) and $user_id in scope.
 *
 * @var array $books
 * @var int $user_id
 */

$booksColumns = [
    'type' => ['label' => 'Type', 'class' => 'col-type'],
    'entry_date' => ['label' => 'Entry Date', 'class' => 'col-date'],
    'item_name' => ['label' => 'Item Name', 'class' => 'col-item'],
    'quantity' => ['label' => 'Quantity', 'class' => 'col-qty'],
    'unit' => ['label' => 'Unit', 'class' => 'col-unit'],
    'amount' => ['label' => 'Amount', 'class' => 'col-amount'],
    'status' => ['label' => 'Status', 'class' => 'col-status'],
];
$cadenceLabels = ['weekly' => 'Weekly', 'biweekly' => 'Biweekly', 'monthly' => 'Monthly', 'annual' => 'Annual'];
$surplusClass = $books['surplus'] >= 0 ? 'amount-positive' : 'amount-negative';
$surplusColor = $books['surplus'] >= 0 ? 'c-blue' : 'c-red';
?>
<div class="profile-section-title books-section-title">
    <div>
        <h4>Books History</h4>
        <p>Bookkeeping entries for this client for <?= h($books['period_label']) ?>.</p>
    </div>
    <a
        href="<?= h(books_query($books, ['export' => 'pdf', 'page' => 1])) ?>"
        target="_blank"
        class="btn btn-danger btn-sm"
    >
        <i class="fas fa-file-pdf"></i> Export PDF
    </a>
</div>

<?php if (!empty($books['failed'])): ?>
    <div class="alert alert-danger">
        Books history is temporarily unavailable. Please try again shortly.
    </div>
<?php endif; ?>

<div class="cadence-bar">
    <div class="cadence-selector">
        <?php foreach ($cadenceLabels as $v => $l): ?>
            <a href="<?= h(books_query($books, ['cadence' => $v, 'offset' => 0, 'page' => 1])) ?>"
               class="cadence-btn<?= $books['cadence'] === $v ? ' active' : '' ?>"><?= h($l) ?></a>
        <?php endforeach; ?>
    </div>
    <div class="period-nav">
        <a class="period-nav-btn"
           href="<?= h(books_query($books, ['offset' => $books['nav']['prev_offset'], 'page' => 1])) ?>"
           title="Previous period"><i class="fas fa-chevron-left"></i></a>
        <span class="period-label"><?= h($books['period_label']) ?></span>
        <?php if ($books['nav']['can_next']): ?>
            <a class="period-nav-btn"
               href="<?= h(books_query($books, ['offset' => $books['nav']['next_offset'], 'page' => 1])) ?>"
               title="Next period"><i class="fas fa-chevron-right"></i></a>
        <?php else: ?>
            <span class="period-nav-btn dis" aria-disabled="true"><i class="fas fa-chevron-right"></i></span>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header">
        <button
            type="button"
            class="books-filters-toggle"
            id="booksFiltersToggle"
            aria-expanded="true"
            aria-controls="booksFiltersBody"
        >
            <span class="card-title">
                <i class="fas fa-filter"></i> Filters
            </span>
            <i class="fas fa-chevron-down books-filters-chevron" aria-hidden="true"></i>
        </button>
    </div>

    <div class="card-body" id="booksFiltersBody">
        <form method="GET" id="booksFilterForm">
            <input type="hidden" name="user_id" value="<?= (int)$user_id ?>">
            <input type="hidden" name="tab" value="books">
            <input type="hidden" name="cadence" value="<?= h($books['cadence']) ?>">
            <input type="hidden" name="offset" value="<?= (int)$books['cadence_offset'] ?>">
            <input type="hidden" name="sort" value="<?= h($books['sort']) ?>">
            <input type="hidden" name="dir" value="<?= h($books['dir']) ?>">
            <input type="hidden" name="page_size" value="<?= (int)$books['page_size'] ?>">
            <input type="hidden" name="page" value="1">

            <div class="grid-3 mb-3">
                <div class="form-group">
                    <label class="form-label" for="booksStatus">Status</label>
                    <select name="book_status" id="booksStatus" class="form-control">
                        <?php foreach (BOOKS_TAB_STATUSES as $value => $label): ?>
                            <option value="<?= h($value) ?>" <?= $books['status'] === $value ? 'selected' : '' ?>>
                                <?= h($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="booksType">Type</label>
                    <select name="book_type" id="booksType" class="form-control">
                        <option value="all" <?= $books['type'] === 'all' ? 'selected' : '' ?>>All</option>
                        <?php foreach (BOOKS_ENTRY_TYPES as $typeOption): ?>
                            <option value="<?= h($typeOption) ?>" <?= $books['type'] === $typeOption ? 'selected' : '' ?>>
                                <?= h(ucfirst($typeOption)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="books-filter-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-filter"></i> Apply Filter
                </button>
                <a href="view_client.php?user_id=<?= (int)$user_id ?>&tab=books" class="btn btn-ghost">
                    <i class="fas fa-rotate-left"></i> Reset
                </a>
            </div>
        </form>
    </div>
</div>

<div class="stats-grid g3">
    <div class="stat-card c-green">
        <div class="stat-head"><div class="stat-ico c-green"><i class="fas fa-arrow-down"></i></div></div>
        <div class="stat-value"><?= money($books['money_in']) ?></div>
        <div class="stat-label">Total Income</div>
    </div>

    <div class="stat-card c-orange">
        <div class="stat-head"><div class="stat-ico c-orange"><i class="fas fa-arrow-up"></i></div></div>
        <div class="stat-value"><?= money($books['money_out']) ?></div>
        <div class="stat-label">Total Expenses</div>
    </div>

    <div class="stat-card surplus-card <?= $surplusColor ?>">
        <div class="stat-head"><div class="stat-ico <?= $surplusColor ?>"><i class="fas fa-wallet"></i></div></div>
        <div class="stat-value <?= $surplusClass ?>"><?= money($books['surplus']) ?></div>
        <div class="stat-label">Available for Loan Repayment</div>
    </div>
</div>

<?php if ($books['total_rows'] > 0): ?>
    <p class="reconciliation-note">
        <i class="fas fa-check-circle"></i>
        Totals reflect all <?= number_format($books['total_rows']) ?>
        <?= $books['status'] === 'confirmed' ? 'confirmed' : '' ?>
        transaction<?= $books['total_rows'] !== 1 ? 's' : '' ?> for this period.
    </p>
<?php endif; ?>

<?php if ($books['data_state'] === 'empty'): ?>
    <div class="data-state-empty">
        <i class="fas fa-receipt"></i>
        <h3>No transactions for this period</h3>
        <p>No book entries match the current filters for this period.</p>
    </div>
<?php else: ?>

<div class="table-responsive client-table-scroll">
    <table class="table clients-table books-entries-table">
        <thead>
            <tr>
                <?php foreach ($booksColumns as $column => $meta): ?>
                    <th class="<?= h($meta['class']) ?>">
                        <a href="<?= h(books_sort_href($books, $column)) ?>" class="books-sort-link">
                            <?= h($meta['label']) ?>
                            <i class="fas <?= h(books_sort_icon($books, $column)) ?>"></i>
                        </a>
                    </th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($books['entries'])): ?>
                <?php foreach ($books['entries'] as $entry): ?>
                    <?php
                    $entryType = (string)($entry['type'] ?? '');
                    $entryStatus = (string)($entry['status'] ?? '');
                    $quantity = $entry['quantity'];
                    $quantityDisplay = ($quantity === null || $quantity === '')
                        ? '-'
                        : rtrim(rtrim(number_format((float)$quantity, 3, '.', ''), '0'), '.');
                    $isMoneyIn = books_is_money_in($entryType);
                    $signedAmount = ($isMoneyIn ? '+' : '-') . money(abs((float)($entry['amount'] ?? 0)));
                    ?>
                    <tr>
                        <td class="col-type">
                            <span class="badge <?= books_type_badge($entryType) ?>">
                                <?= h(ucfirst($entryType)) ?>
                            </span>
                        </td>
                        <td class="col-date">
                            <?= !empty($entry['entry_date']) ? h(date('Y-m-d H:i', strtotime((string)$entry['entry_date']))) : '-' ?>
                        </td>
                        <td class="col-item"><?= h($entry['item_name'] ?? '') ?></td>
                        <td class="col-qty"><?= h($quantityDisplay) ?></td>
                        <td class="col-unit"><?= h($entry['unit'] ?: '-') ?></td>
                        <td class="col-amount fw-700 <?= $isMoneyIn ? 'amount-in' : 'amount-out' ?>">
                            <?= h($signedAmount) ?>
                        </td>
                        <td class="col-status"><span class="badge badge-muted"><?= h(ucfirst($entryStatus)) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="<?= count($booksColumns) ?>">
                        <div class="empty-state">
                            <i class="fas fa-book"></i>
                            <h3>No book entries found</h3>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($books['total_rows'] > 0): ?>
    <?php
    $pageWindow = 2;
    $firstLinkedPage = max(1, $books['page'] - $pageWindow);
    $lastLinkedPage = min($books['total_pages'], $books['page'] + $pageWindow);
    $showingFrom = $books['offset'] + 1;
    $showingTo = min($books['offset'] + $books['page_size'], $books['total_rows']);
    $onFirstPage = $books['page'] <= 1;
    $onLastPage = $books['page'] >= $books['total_pages'];
    ?>
    <div class="pag-wrap">
        <div class="pag-info books-pag-info">
            <span>
                Showing <?= number_format($showingFrom) ?> -
                <?= number_format($showingTo) ?>
                of <?= number_format($books['total_rows']) ?> records
            </span>

            <label class="books-page-size" for="booksPageSizeSelect">
                <span>Rows per page</span>
                <select id="booksPageSizeSelect" class="form-control">
                    <?php foreach (BOOKS_TAB_PAGE_SIZES as $size): ?>
                        <option
                            value="<?= (int)$size ?>"
                            <?= $books['page_size'] === $size ? 'selected' : '' ?>
                            data-href="<?= h(books_query($books, ['page_size' => $size, 'page' => 1])) ?>"
                        >
                            <?= (int)$size ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <div class="pagination">
            <?php if ($onFirstPage): ?>
                <span class="pg-btn dis" aria-disabled="true"><i class="fas fa-chevron-left"></i></span>
            <?php else: ?>
                <a class="pg-btn" href="<?= h(books_query($books, ['page' => $books['page'] - 1])) ?>" rel="prev">
                    <i class="fas fa-chevron-left"></i>
                </a>
            <?php endif; ?>

            <?php for ($p = $firstLinkedPage; $p <= $lastLinkedPage; $p++): ?>
                <?php if ($p === $books['page']): ?>
                    <span class="pg-btn act" aria-current="page"><?= $p ?></span>
                <?php else: ?>
                    <a class="pg-btn" href="<?= h(books_query($books, ['page' => $p])) ?>"><?= $p ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($onLastPage): ?>
                <span class="pg-btn dis" aria-disabled="true"><i class="fas fa-chevron-right"></i></span>
            <?php else: ?>
                <a class="pg-btn" href="<?= h(books_query($books, ['page' => $books['page'] + 1])) ?>" rel="next">
                    <i class="fas fa-chevron-right"></i>
                </a>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php endif; /* data_state !== empty */ ?>
