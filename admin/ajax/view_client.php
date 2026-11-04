<?php
require_once __DIR__.'/../db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['logged_in'])) { http_response_code(403); exit; }
$id=(int)($_GET['id']??0);
$c=$conn->query("SELECT u.*,c.* FROM customers c INNER JOIN user u ON c.user_id=u.id WHERE u.id=$id LIMIT 1")->fetch_assoc();
if (!$c) { echo '<div class="alert alert-danger">Client not found.</div>'; exit; }
$ls=$conn->query("SELECT COUNT(*) AS tot,COALESCE(SUM(amount),0) AS amt,COALESCE(SUM(loan_balance),0) AS bal,SUM(status='CLEARED') AS clr,SUM(status='OUTSTANDING') AS out,SUM(status='OVERDUE') AS ovr FROM loan WHERE client_id=$id AND status!='PENDING'")->fetch_assoc();
$in=strtoupper(substr($c['fname'],0,1).substr($c['lname'],0,1));
?>
<div class="d-flex align-center gap-3 mb-5">
  <div style="width:58px;height:58px;border-radius:50%;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-family:'Outfit',sans-serif;font-size:1.3rem;font-weight:800;flex-shrink:0"><?= $in ?></div>
  <div>
    <div style="font-family:'Outfit',sans-serif;font-size:1.22rem;font-weight:800;color:var(--text-dark)"><?= htmlspecialchars($c['fname'].' '.$c['lname']) ?></div>
    <div class="fs-sm text-muted"><?= htmlspecialchars($c['email']) ?></div>
    <span class="badge <?= $c['active']==1?'badge-success':'badge-danger' ?>" style="margin-top:3px"><?= $c['active']==1?'Active':'Blocked' ?></span>
  </div>
</div>
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.7rem;margin-bottom:1.3rem">
  <div style="background:var(--body-bg);border:1px solid var(--border);border-radius:var(--r);padding:.78rem;text-align:center">
    <div style="font-family:'Outfit',sans-serif;font-size:1.3rem;font-weight:800;color:var(--primary)"><?= $ls['tot'] ?></div>
    <div class="fs-xs text-muted">Total Loans</div>
  </div>
  <div style="background:var(--body-bg);border:1px solid var(--border);border-radius:var(--r);padding:.78rem;text-align:center">
    <div style="font-family:'Outfit',sans-serif;font-size:1.3rem;font-weight:800;color:var(--success)"><?= $ls['clr'] ?></div>
    <div class="fs-xs text-muted">Cleared</div>
  </div>
  <div style="background:var(--body-bg);border:1px solid var(--border);border-radius:var(--r);padding:.78rem;text-align:center">
    <div style="font-family:'Outfit',sans-serif;font-size:1.3rem;font-weight:800;color:var(--danger)"><?= $ls['ovr'] ?></div>
    <div class="fs-xs text-muted">Overdue</div>
  </div>
</div>
<div class="detail-grid">
  <div class="detail-item"><div class="lbl">Phone</div><div class="val"><?= htmlspecialchars($c['phone']??'—') ?></div></div>
  <div class="detail-item"><div class="lbl">NIN</div><div class="val"><?= htmlspecialchars($c['nin']??'—') ?></div></div>
  <div class="detail-item"><div class="lbl">Business</div><div class="val"><?= htmlspecialchars($c['business_name']??'—') ?></div></div>
  <div class="detail-item"><div class="lbl">Business Type</div><div class="val"><?= htmlspecialchars($c['business_type']??'—') ?></div></div>
  <div class="detail-item"><div class="lbl">District</div><div class="val"><?= htmlspecialchars($c['district']??'—') ?></div></div>
  <div class="detail-item"><div class="lbl">Loan Limit</div><div class="val text-primary">UGX <?= number_format($c['loan_limit']??0) ?></div></div>
  <div class="detail-item"><div class="lbl">Total Borrowed</div><div class="val">UGX <?= number_format($ls['amt']) ?></div></div>
  <div class="detail-item"><div class="lbl">Outstanding</div><div class="val text-warning">UGX <?= number_format($ls['bal']) ?></div></div>
  <?php if ($c['nok_name']): ?>
    <div class="detail-item"><div class="lbl">Next of Kin</div><div class="val"><?= htmlspecialchars($c['nok_name']) ?></div></div>
    <div class="detail-item"><div class="lbl">NOK Contact</div><div class="val"><?= htmlspecialchars($c['nok_contact']??'—') ?></div></div>
  <?php endif; ?>
</div>
<div class="d-flex gap-2 mt-5">
  <a href="client_loan_details.php?id=<?= $id ?>" class="btn btn-primary"><i class="fas fa-file-invoice-dollar"></i> View Loans</a>
  <a href="client_profile.php?id=<?= $id ?>" class="btn btn-ghost"><i class="fas fa-user"></i> Full Profile</a>
</div>
