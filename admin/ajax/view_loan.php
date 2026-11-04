<?php
require_once __DIR__.'/../db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['logged_in'])) { http_response_code(403); exit; }
$id = (int)($_GET['id']??0);
$loan = $conn->query("SELECT * FROM loan WHERE id=$id LIMIT 1")->fetch_assoc();
if (!$loan) { echo '<div class="alert alert-danger">Loan not found.</div>'; exit; }
$cid = (int)$loan['client_id'];
$cfect = null; $ctype = 'Unknown';
$cr = $conn->query("SELECT u.fname,u.lname,c.phone,c.nin,c.business_name,c.location,c.district FROM customers c INNER JOIN user u ON c.user_id=u.id WHERE c.user_id=$cid LIMIT 1");
if ($cr && $cr->num_rows) { $cfect=$cr->fetch_assoc(); $ctype='Business Client'; }
else {
    $mr=$conn->query("SELECT u.fname,u.lname,m.phone,m.nin,m.employer,m.department FROM members m INNER JOIN user u ON m.user_id=u.id WHERE m.user_id=$cid LIMIT 1");
    if ($mr && $mr->num_rows) { $cfect=$mr->fetch_assoc(); $ctype='Staff Member'; }
    else $cfect=['fname'=>'','lname'=>'','phone'=>'','nin'=>''];
}
$full = trim(($cfect['fname']??'').' '.($cfect['lname']??'')) ?: 'N/A';
$pays=[]; $paid=0;
$pr=$conn->query("SELECT amount,datetime FROM payments WHERE loan_id=$id ORDER BY datetime DESC");
while ($p=$pr->fetch_assoc()) { $pays[]=$p; $paid+=$p['amount']; }
$prog = $loan['amount']>0 ? min(100,round(($paid/$loan['amount'])*100)) : 0;
$pfc  = $loan['status']==='CLEARED'?'green':($loan['status']==='OVERDUE'?'red':'blue');
$m=['CLEARED'=>'badge-success','OUTSTANDING'=>'badge-warning','OVERDUE'=>'badge-danger','ACTIVE'=>'badge-primary','PENDING'=>'badge-muted'];
$badge='<span class="badge '.($m[$loan['status']]??'badge-muted').'">'.htmlspecialchars($loan['status']).'</span>';
?>
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.2rem">
  <div>
    <div style="font-family:'Outfit',sans-serif;font-size:1.5rem;font-weight:800;color:var(--text-dark)">UGX <?= number_format($loan['amount']) ?></div>
    <div class="fs-sm text-muted"><?= $ctype ?> · <?= htmlspecialchars($full) ?></div>
  </div>
  <?= $badge ?>
</div>

<!-- Progress -->
<div style="margin-bottom:1.4rem">
  <div class="d-flex justify-between fs-xs text-muted mb-2">
    <span>Repayment Progress</span><span><?= $prog ?>%</span>
  </div>
  <div class="progress-wrap"><div class="progress-fill <?= $pfc ?>" style="width:<?= $prog ?>%"></div></div>
  <div class="d-flex justify-between fs-xs mt-2">
    <span class="text-success fw-600">Paid: UGX <?= number_format($paid) ?></span>
    <span class="text-warning fw-600">Balance: UGX <?= number_format($loan['loan_balance']) ?></span>
  </div>
</div>

<!-- Detail Grid -->
<div class="detail-grid">
  <div class="detail-item"><div class="lbl">Amount</div><div class="val">UGX <?= number_format($loan['amount']) ?></div></div>
  <div class="detail-item"><div class="lbl">Interest</div><div class="val">UGX <?= number_format($loan['interest']) ?></div></div>
  <div class="detail-item"><div class="lbl">Issued</div><div class="val"><?= date('M j, Y',strtotime($loan['datetime'])) ?></div></div>
  <div class="detail-item"><div class="lbl">Due Date</div>
    <div class="val <?= strtotime($loan['due_date'])<time()&&$loan['status']!=='CLEARED'?'text-danger':'' ?>"><?= date('M j, Y',strtotime($loan['due_date'])) ?></div>
  </div>
  <!--<div class="detail-item"><div class="lbl">Duration</div><div class="val"><?= $loan['duration'] ?> <?= $loan['duration_type']??'months' ?></div></div>-->
  <div class="detail-item"><div class="lbl">Rate</div><div class="val"><?= $loan['interest_rate']??'N/A' ?>%</div></div>
  <div class="detail-item full"><div class="lbl">Client</div><div class="val"><?= htmlspecialchars($full) ?> · <?= htmlspecialchars($cfect['phone']??'') ?></div></div>
  <?php if (!empty($cfect['business_name'])): ?><div class="detail-item full"><div class="lbl">Business</div><div class="val"><?= htmlspecialchars($cfect['business_name']) ?></div></div><?php endif; ?>
  <?php if (!empty($loan['purpose'])): ?><div class="detail-item full"><div class="lbl">Purpose</div><div class="val"><?= htmlspecialchars($loan['purpose']) ?></div></div><?php endif; ?>
</div>

<!-- Payments -->
<?php if (!empty($pays)): ?>
<div style="margin-top:1.35rem">
  <div style="font-family:'Outfit',sans-serif;font-weight:700;color:var(--text-dark);font-size:.88rem;margin-bottom:.7rem">Payment History (<?= count($pays) ?>)</div>
  <div class="timeline">
    <?php foreach ($pays as $p): ?>
      <div class="timeline-item">
        <div class="tl-date"><?= date('M j, Y · h:i A',strtotime($p['datetime'])) ?></div>
        <div class="tl-amount">+ UGX <?= number_format($p['amount']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Actions -->
<div class="d-flex gap-2 flex-wrap mt-5">
  <?php if ($loan['status']!=='CLEARED'): ?>
    <button class="btn btn-secondary" onclick="Modal.close('m-view-loan');addPaymentModal(<?= $id ?>)"><i class="fas fa-money-bill-wave"></i> Add Payment</button>
  <?php endif; ?>
  <a href="view_loan.php?id=<?= $id ?>&export=pdf" class="btn btn-ghost" target="_blank"><i class="fas fa-file-pdf"></i> PDF</a>
  <a href="repayment_schedule.php?id=<?= $id ?>" class="btn btn-ghost"><i class="fas fa-calendar-alt"></i> Schedule</a>
  <?php if ($loan['status']!=='CLEARED'): ?>
    <a href="edit_loan.php?id=<?= $id ?>" class="btn btn-outline"><i class="fas fa-edit"></i> Edit</a>
  <?php endif; ?>
</div>
