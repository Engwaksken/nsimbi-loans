<?php
/**
 * Client-facing books dashboard view partial.
 * Expects $books (from books_dashboard_view_model()), money() and h() in scope.
 * @var array $books
 */
$dashColumns = [
    'entry_date' => ['label' => 'Date', 'class' => 'col-date'],
    'type' => ['label' => 'Type', 'class' => 'col-type'],
    'item_name' => ['label' => 'Description', 'class' => 'col-item'],
    'quantity' => ['label' => 'Qty', 'class' => 'col-qty'],
    'unit' => ['label' => 'Unit', 'class' => 'col-unit'],
    'amount' => ['label' => 'Amount', 'class' => 'col-amount'],
];
$cadenceLabels = ['weekly' => 'Weekly', 'biweekly' => 'Biweekly', 'monthly' => 'Monthly', 'annual' => 'Annual'];
$surplusClass = $books['surplus'] >= 0 ? 'amount-positive' : 'amount-negative';
$surplusColor = $books['surplus'] >= 0 ? 'c-blue' : 'c-red';
?>

<style>
.books-quick-bar{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;background:#fff;border:1px solid #e2e8f0;border-left:5px solid #2563eb;border-radius:14px;padding:1rem 1.1rem;margin:0 0 1rem;box-shadow:0 4px 16px rgba(15,23,42,.04)}
.books-quick-bar-title{font-size:1.05rem;font-weight:800;color:#0f172a;margin:0 0 .15rem}
.books-quick-bar-text{font-size:.84rem;color:#64748b;margin:0}
.books-quick-bar-actions{display:flex;gap:.55rem;flex-wrap:wrap}
.books-quick-bar-actions a,.books-quick-bar-actions button{display:inline-flex;align-items:center;justify-content:center;gap:.5rem;text-decoration:none;border-radius:9px;font-size:.84rem;font-weight:700;padding:.6rem .85rem}
.books-action-primary{background:#2563eb;color:#fff!important}
.books-action-secondary{background:#eff6ff;color:#1d4ed8!important;border:1px solid #bfdbfe}
.books-quick-help{display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;margin-top:.5rem;font-size:.78rem;color:#64748b}
.books-quick-help i{color:#2563eb}
@media (max-width:600px){.books-quick-bar-actions{width:100%}.books-quick-bar-actions a,.books-quick-bar-actions button{flex:1}.books-quick-bar{padding:.9rem}}
</style>

<!-- The voice and manual entry form is already rendered by books.php above this partial.
     Linking to it here avoids a duplicate form and duplicate element IDs. -->
<section class="books-quick-bar" aria-label="Record a transaction">
  <div>
    <h2 class="books-quick-bar-title"><i class="fas fa-book-open" aria-hidden="true"></i> Business Records</h2>
    <p class="books-quick-bar-text">Record a sale, purchase, business expense or other income by voice or manually.</p>
    <div class="books-quick-help"><i class="fas fa-circle-info" aria-hidden="true"></i> Speak your transaction, check the details, then save the record.</div>
  </div>
  <div class="books-quick-bar-actions">
    <button type="button" class="books-action-primary" id="booksDashVoiceShortcut" data-books-new="voice"><i class="fas fa-microphone" aria-hidden="true"></i> Voice Entry</button>
    <button type="button" class="books-action-secondary" id="booksDashManualShortcut" data-books-new="manual"><i class="fas fa-plus" aria-hidden="true"></i> Add Record</button>
  </div>
</section>

<div class="cadence-bar">
    <div class="cadence-selector">
        <?php foreach ($cadenceLabels as $v => $l): ?>
            <a href="<?= h(books_dash_query($books, ['cadence' => $v, 'offset' => 0, 'page' => 1])) ?>"
               class="cadence-btn<?= $books['cadence'] === $v ? ' active' : '' ?>"><?= h($l) ?></a>
        <?php endforeach; ?>
    </div>
    <div class="period-nav">
        <a class="period-nav-btn"
           href="<?= h(books_dash_query($books, ['offset' => $books['nav']['prev_offset'], 'page' => 1])) ?>"
           title="Previous period"><i class="fas fa-chevron-left"></i></a>
        <span class="period-label"><?= h($books['period_label']) ?></span>
        <?php if ($books['nav']['can_next']): ?>
            <a class="period-nav-btn"
               href="<?= h(books_dash_query($books, ['offset' => $books['nav']['next_offset'], 'page' => 1])) ?>"
               title="Next period"><i class="fas fa-chevron-right"></i></a>
        <?php else: ?>
            <span class="period-nav-btn dis" aria-disabled="true"><i class="fas fa-chevron-right"></i></span>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($books['failed'])): ?>
    <div class="alert alert-danger">Dashboard data is temporarily unavailable.</div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-header">
        <button type="button" class="books-filters-toggle" id="booksDashFiltersToggle"
                aria-expanded="true" aria-controls="booksDashFiltersBody">
            <span class="card-title"><i class="fas fa-filter"></i> Filter by Type</span>
            <i class="fas fa-chevron-down books-filters-chevron" aria-hidden="true"></i>
        </button>
    </div>
    <div class="card-body" id="booksDashFiltersBody">
        <form method="GET">
            <input type="hidden" name="cadence" value="<?= h($books['cadence']) ?>">
            <input type="hidden" name="offset" value="<?= (int)$books['offset'] ?>">
            <input type="hidden" name="sort" value="<?= h($books['sort']) ?>">
            <input type="hidden" name="dir" value="<?= h($books['dir']) ?>">
            <input type="hidden" name="page_size" value="<?= (int)$books['page_size'] ?>">
            <input type="hidden" name="page" value="1">
            <div class="grid-3 mb-3">
                <div class="form-group">
                    <label class="form-label" for="booksDashType">Transaction Type</label>
                    <select name="book_type" id="booksDashType" class="form-control">
                        <option value="all" <?= $books['type'] === 'all' ? 'selected' : '' ?>>All Types</option>
                        <?php foreach (BOOKS_ENTRY_TYPES as $t): ?>
                            <option value="<?= h($t) ?>"
                                    <?= $books['type'] === $t ? 'selected' : '' ?>><?= h(ucfirst($t)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="books-filter-actions">
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply</button>
                <a href="<?= h(books_dash_query($books, ['book_type' => 'all', 'page' => 1])) ?>"
                   class="btn btn-ghost"><i class="fas fa-rotate-left"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="stats-grid g3">
    <div class="stat-card c-green">
        <div class="stat-head"><div class="stat-ico c-green"><i class="fas fa-arrow-down"></i></div></div>
        <div class="stat-value"><?= money($books['money_in']) ?></div>
        <div class="stat-label">Total Income</div>
        <?php if ($books['data_state'] === 'expense_only'): ?>
            <div class="stat-note text-muted">No income recorded this period</div>
        <?php endif; ?>
    </div>
    <div class="stat-card c-orange">
        <div class="stat-head"><div class="stat-ico c-orange"><i class="fas fa-arrow-up"></i></div></div>
        <div class="stat-value"><?= money($books['money_out']) ?></div>
        <div class="stat-label">Total Expenses</div>
        <?php if ($books['data_state'] === 'income_only'): ?>
            <div class="stat-note text-muted">No expenses recorded this period</div>
        <?php endif; ?>
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
        Totals reflect all <?= number_format($books['total_rows']) ?> confirmed
        transaction<?= $books['total_rows'] !== 1 ? 's' : '' ?> for this period.
    </p>
<?php endif; ?>

<?php if ($books['data_state'] === 'empty'): ?>
    <div class="data-state-empty">
        <i class="fas fa-receipt"></i>
        <h3>No transactions for this period</h3>
        <p>When you save a sale, purchase, expense or other income, it will appear here.</p>
        <a class="btn btn-primary" href="#books-entry-form"><i class="fas fa-plus"></i> Record Your First Transaction</a>
    </div>
<?php else: ?>
    <div class="table-responsive client-table-scroll">
        <table class="table clients-table books-entries-table">
            <thead>
                <tr>
                    <?php foreach ($dashColumns as $col => $m): ?>
                        <th class="<?= h($m['class']) ?>">
                            <a href="<?= h(books_dash_sort_href($books, $col)) ?>" class="books-sort-link">
                                <?= h($m['label']) ?> <i class="fas <?= h(books_dash_sort_icon($books, $col)) ?>"></i>
                            </a>
                        </th>
                    <?php endforeach; ?>
                    <th class="col-actions">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($books['entries'] as $entry):
                    $entryType = (string)($entry['type'] ?? '');
                    $quantity = $entry['quantity'];
                    $quantityDisplay = ($quantity === null || $quantity === '')
                        ? '-'
                        : rtrim(rtrim(number_format((float)$quantity, 3, '.', ''), '0'), '.');
                    $isMoneyIn = books_is_money_in($entryType);
                    $signedAmount = ($isMoneyIn ? '+' : '-') . money(abs((float)($entry['amount'] ?? 0)));
                ?>
                    <tr>
                        <td class="col-date"><?= !empty($entry['entry_date']) ? h(date('Y-m-d', strtotime((string)$entry['entry_date']))) : '-' ?></td>
                        <td class="col-type"><span class="badge <?= books_dash_type_badge($entryType) ?>"><?= h(ucfirst($entryType)) ?></span></td>
                        <td class="col-item"><?= h($entry['item_name'] ?? '') ?></td>
                        <td class="col-qty"><?= h($quantityDisplay) ?></td>
                        <td class="col-unit"><?= h($entry['unit'] ?: '-') ?></td>
                        <td class="col-amount fw-700 <?= $isMoneyIn ? 'amount-in' : 'amount-out' ?>"><?= h($signedAmount) ?></td>
                        <td class="col-actions"><div class="books-row-actions">
                          <button type="button" class="btn btn-sm btn-outline-primary books-edit-btn" aria-label="Edit record" data-entry='<?= h(json_encode([
                            'id' => (int)$entry['id'], 'type' => $entryType, 'item_name' => $entry['item_name'],
                            'quantity' => $entry['quantity'], 'unit' => $entry['unit'], 'amount' => $entry['amount'],
                            'entry_date' => substr((string)$entry['entry_date'], 0, 10)
                          ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>'><i class="fas fa-pen"></i> Edit</button>
                          <button type="button" class="btn btn-sm btn-outline-danger books-delete-btn" data-id="<?= (int)$entry['id'] ?>" data-item="<?= h((string)$entry['item_name']) ?>"><i class="fas fa-trash"></i> Delete</button>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($books['total_rows'] > 0):
        $pageWindow = 2;
        $firstLinkedPage = max(1, $books['page'] - $pageWindow);
        $lastLinkedPage = min($books['total_pages'], $books['page'] + $pageWindow);
        $showingFrom = (($books['page'] - 1) * $books['page_size']) + 1;
        $showingTo = min($books['page'] * $books['page_size'], $books['total_rows']);
        $onFirstPage = $books['page'] <= 1;
        $onLastPage = $books['page'] >= $books['total_pages'];
    ?>
        <div class="pag-wrap">
            <div class="pag-info books-pag-info">
                <span>Showing <?= number_format($showingFrom) ?> - <?= number_format($showingTo) ?> of <?= number_format($books['total_rows']) ?> records</span>
                <label class="books-page-size" for="booksDashPageSizeSelect">
                    <span>Rows per page</span>
                    <select id="booksDashPageSizeSelect" class="form-control">
                        <?php foreach (BOOKS_DASH_PAGE_SIZES as $size): ?>
                            <option value="<?= (int)$size ?>"
                                    <?= $books['page_size'] === $size ? 'selected' : '' ?>
                                    data-href="<?= h(books_dash_query($books, ['page_size' => $size, 'page' => 1])) ?>"><?= (int)$size ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div class="pagination">
                <?php if ($onFirstPage): ?>
                    <span class="pg-btn dis" aria-disabled="true"><i class="fas fa-chevron-left"></i></span>
                <?php else: ?>
                    <a class="pg-btn" href="<?= h(books_dash_query($books, ['page' => $books['page'] - 1])) ?>" rel="prev"><i class="fas fa-chevron-left"></i></a>
                <?php endif; ?>
                <?php for ($p = $firstLinkedPage; $p <= $lastLinkedPage; $p++): ?>
                    <?php if ($p === $books['page']): ?>
                        <span class="pg-btn act" aria-current="page"><?= $p ?></span>
                    <?php else: ?>
                        <a class="pg-btn" href="<?= h(books_dash_query($books, ['page' => $p])) ?>"><?= $p ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($onLastPage): ?>
                    <span class="pg-btn dis" aria-disabled="true"><i class="fas fa-chevron-right"></i></span>
                <?php else: ?>
                    <a class="pg-btn" href="<?= h(books_dash_query($books, ['page' => $books['page'] + 1])) ?>" rel="next"><i class="fas fa-chevron-right"></i></a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<div class="books-modal" id="booksDeleteModal" role="dialog" aria-modal="true" aria-labelledby="booksDeleteTitle" hidden aria-hidden="true">
  <div class="books-modal-dialog books-delete-dialog">
    <div class="books-modal-header"><h3 id="booksDeleteTitle"><i class="fas fa-trash"></i> Delete Transaction</h3><button type="button" class="books-modal-close" data-books-close>&times;</button></div>
    <form method="post" action="books.php" class="books-modal-body">
      <input type="hidden" name="books_action" value="delete_book_entry">
      <input type="hidden" name="csrf_token" value="<?= h(books_dash_csrf_token()) ?>">
      <input type="hidden" name="entry_id" id="booksDeleteId">
      <p>Are you sure you want to permanently delete <strong id="booksDeleteItem"></strong>?</p>
      <p class="text-muted" style="font-size:.85rem">This action cannot be undone and will change your dashboard totals.</p>
      <div class="books-modal-actions"><button type="button" class="btn btn-ghost" data-books-close>Cancel</button><button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Delete permanently</button></div>
    </form>
  </div>
</div>
<style>
.books-modal[hidden]{display:none!important}
.books-modal{position:fixed;inset:0;z-index:99999;background:rgba(15,23,42,.68);display:flex;align-items:center;justify-content:center;padding:16px;overflow-y:auto}
.books-modal-dialog{width:min(100%,740px);max-height:calc(100dvh - 32px);overflow-y:auto;background:#fff;border-radius:16px;box-shadow:0 20px 70px rgba(0,0,0,.25)}
.books-delete-dialog{max-width:480px}
.books-modal-header{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:16px 22px;border-bottom:1px solid #e2e8f0}
.books-modal-header h3{font-size:1.12rem;font-weight:800;margin:0;color:#0f172a}
.books-modal-close{border:0;background:#f1f5f9;border-radius:8px;width:34px;height:34px;font-size:1.55rem;line-height:1;cursor:pointer}
.books-modal-body{padding:20px 22px}
.books-modal-intro{color:#64748b;font-size:.88rem;margin:0 0 1rem}
.books-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 18px}
.books-form-field{min-width:0}.books-form-field .form-control,.books-form-field .form-select{width:100%;min-width:0}
.books-modal-actions{display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap}
.books-row-actions{display:flex;gap:6px;white-space:nowrap}
.books-quick-bar-actions button{cursor:pointer}
body.books-modal-open{overflow:hidden}
@media(max-width:600px){.books-form-grid{grid-template-columns:1fr}.books-modal-body{padding:15px}.books-modal-header{padding:14px 15px}.books-row-actions{flex-wrap:wrap}}
</style>
<script>
(() => {
  const entryModal=document.getElementById('books-entry-form');
  const deleteModal=document.getElementById('booksDeleteModal');
  const form=document.getElementById('booksRecordForm');
  if(!entryModal||!deleteModal||!form) return;
  let previousFocus=null;
  const open=(modal)=>{previousFocus=document.activeElement;modal.hidden=false;modal.setAttribute('aria-hidden','false');document.body.classList.add('books-modal-open');modal.querySelector('button,input,select')?.focus();};
  const close=(modal)=>{modal.hidden=true;modal.setAttribute('aria-hidden','true');document.body.classList.remove('books-modal-open');previousFocus?.focus?.();};
  const field=name=>form.elements.namedItem(name);
  document.querySelectorAll('[data-books-new]').forEach(btn=>btn.addEventListener('click',()=>{
    form.reset();field('books_action').value='create_book_entry';field('entry_id').value='';
    document.getElementById('booksModalTitle').textContent='Add Business Record';
    document.getElementById('booksSaveButton').innerHTML='<i class="fas fa-save"></i> Save record';
    document.getElementById('booksRecordDate').value=(()=>{const d=new Date();return [d.getFullYear(),String(d.getMonth()+1).padStart(2,'0'),String(d.getDate()).padStart(2,'0')].join('-')})();
    document.getElementById('booksInputSource').value='manual';
    document.getElementById('booksVoiceTranscript').value='';
    open(entryModal);
    if(btn.dataset.booksNew==='voice') document.getElementById('booksVoiceStart')?.focus();
  }));
  document.querySelectorAll('.books-edit-btn').forEach(btn=>btn.addEventListener('click',()=>{
    const data=JSON.parse(btn.dataset.entry);form.reset();
    ['type','item_name','quantity','unit','amount','entry_date'].forEach(k=>{field(k).value=data[k]??''});
    field('books_action').value='update_book_entry';field('entry_id').value=data.id;
    document.getElementById('booksModalTitle').textContent='Edit Business Record';
    document.getElementById('booksSaveButton').innerHTML='<i class="fas fa-save"></i> Save changes';
    document.getElementById('booksVoiceTranscript').value='';
    open(entryModal);
  }));
  document.querySelectorAll('.books-delete-btn').forEach(btn=>btn.addEventListener('click',()=>{
    document.getElementById('booksDeleteId').value=btn.dataset.id;
    document.getElementById('booksDeleteItem').textContent=btn.dataset.item;
    open(deleteModal);
  }));
  document.querySelectorAll('[data-books-close]').forEach(btn=>btn.addEventListener('click',()=>close(btn.closest('.books-modal'))));
  [entryModal,deleteModal].forEach(modal=>modal.addEventListener('click',e=>{if(e.target===modal)close(modal)}));
  <?php if (!empty($GLOBALS['books_dash_reopen_modal'])): ?>open(entryModal);<?php endif; ?>
  document.addEventListener('keydown',e=>{if(e.key==='Escape'){[entryModal,deleteModal].forEach(m=>{if(!m.hidden)close(m)})}});
})();
</script>
<script src="../assets/js/books-dashboard.js"></script>
