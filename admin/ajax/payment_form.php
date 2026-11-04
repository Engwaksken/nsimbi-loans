<?php
require_once __DIR__.'/../db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['logged_in'])) { http_response_code(403); exit; }
$lid=(int)($_GET['loan_id']??0);
$loan=$conn->query("SELECT * FROM loan WHERE id=$lid LIMIT 1")->fetch_assoc();
if (!$loan) { echo '<div class="alert alert-danger">Loan not found.</div>'; exit; }
?>
<div style="background:var(--body-bg);border:1px solid var(--border);border-radius:var(--r);padding:.9rem 1.1rem;margin-bottom:1.2rem;display:grid;grid-template-columns:1fr 1fr 1fr;gap:.75rem">
  <div><div class="fs-xs text-muted" style="text-transform:uppercase;letter-spacing:.05em">Loan Amount</div><div style="font-weight:700;color:var(--text-dark)">UGX <?= number_format($loan['amount']) ?></div></div>
  <div><div class="fs-xs text-muted" style="text-transform:uppercase;letter-spacing:.05em">Balance</div><div style="font-weight:700;color:var(--warning)">UGX <?= number_format($loan['loan_balance']) ?></div></div>
  <div><div class="fs-xs text-muted" style="text-transform:uppercase;letter-spacing:.05em">Status</div><span class="badge badge-warning"><?= $loan['status'] ?></span></div>
</div>
<form id="form-add-payment">
  <div class="form-group">
    <label class="form-label">Payment Amount (UGX) *</label>
    <input type="number" name="amount" class="form-control" min="1" max="<?= $loan['loan_balance'] ?>" placeholder="e.g. <?= number_format($loan['loan_balance']) ?>" required>
    <div class="form-help">Maximum: UGX <?= number_format($loan['loan_balance']) ?></div>
  </div>
  <div class="grid-2">
    <div class="form-group">
      <label class="form-label">Payment Date *</label>
      <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
    </div>
    <div class="form-group">
      <label class="form-label">Method</label>
      <select name="payment_method" class="form-select">
        <option value="cash">Cash</option>
        <option value="mobile_money">Mobile Money</option>
        <option value="bank_transfer">Bank Transfer</option>
        <option value="cheque">Cheque</option>
      </select>
    </div>
  </div>
  <div class="form-group">
    <label class="form-label">Notes (Optional)</label>
    <textarea name="notes" class="form-control" rows="2" placeholder="Any notes about this payment…"></textarea>
  </div>
  <div class="d-flex gap-3 justify-end mt-4">
    <button type="button" class="btn btn-ghost" onclick="Modal.close('m-add-payment')">Cancel</button>
    <button type="submit" class="btn btn-secondary"><i class="fas fa-save"></i> Save Payment</button>
  </div>
</form>
