<?php
declare(strict_types=1);

$phead = 'Enquiries';
require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/includes/site_cms.php';
site_admin_guard();
site_cms_ensure_schema($conn);

$filter = in_array($_GET['type'] ?? '', ['contact', 'donor'], true) ? $_GET['type'] : '';
$statusFilter = in_array($_GET['status'] ?? '', ['new', 'read', 'handled'], true) ? $_GET['status'] : '';
$query = static fn(array $extra = []): string => 'enquiries' . (($q = http_build_query(array_filter(['type' => $filter, 'status' => $statusFilter] + $extra))) ? '?' . $q : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    site_csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'delete') {
        $stmt = $conn->prepare('DELETE FROM site_enquiries WHERE id=?');
        $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
        site_flash('success', 'Enquiry deleted.');
    } elseif (in_array($action, ['new', 'read', 'handled'], true)) {
        $stmt = $conn->prepare('UPDATE site_enquiries SET status=? WHERE id=?');
        $stmt->bind_param('si', $action, $id); $stmt->execute(); $stmt->close();
        if (empty($_POST['silent'])) site_flash('success', 'Marked as ' . $action . '.');
    }
    if (!empty($_POST['silent'])) { http_response_code(204); exit; }
    header('Location: ' . $query()); exit;
}

$where = []; $types = ''; $params = [];
if ($filter !== '') { $where[] = 'form_type = ?'; $types .= 's'; $params[] = $filter; }
if ($statusFilter !== '') { $where[] = 'status = ?'; $types .= 's'; $params[] = $statusFilter; }
$sql = 'SELECT * FROM site_enquiries' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY created_at DESC LIMIT 500';
$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute(); $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
$counts = $conn->query("SELECT form_type, status, COUNT(*) AS c FROM site_enquiries GROUP BY form_type, status")->fetch_all(MYSQLI_ASSOC);
$total = ['all' => 0, 'contact' => 0, 'donor' => 0, 'new' => 0];
foreach ($counts as $c) {
    $total['all'] += (int)$c['c']; $total[$c['form_type']] += (int)$c['c'];
    if ($c['status'] === 'new') $total['new'] += (int)$c['c'];
}

$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$partnerTypes = site_partner_types();
$interestLabels = site_partner_interests();
$csrf = $e(site_csrf_token());
$statusBadge = ['new' => 'badge-warning', 'read' => 'badge-info', 'handled' => 'badge-success'];
$pageScripts = ['../assets/js/site-admin.js'];
require __DIR__ . '/nav-head.php';
?>
<div class="page-header">
  <div><div class="title">Enquiries</div><div class="sub">Messages from the Contact form and sign-ups from Join as Donor / ESO. <?= $total['new'] ?> new.</div></div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline" href="/contact-us" target="_blank" rel="noopener"><i class="fas fa-external-link-alt"></i> Contact page</a>
    <a class="btn btn-outline" href="/join-us" target="_blank" rel="noopener"><i class="fas fa-external-link-alt"></i> Donor / ESO page</a>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <div class="cms-tabs" role="tablist" aria-label="Enquiry type" style="margin:0;border:0">
      <?php foreach (['' => ['All', $total['all']], 'contact' => ['Contact messages', $total['contact']], 'donor' => ['Donor / ESO', $total['donor']]] as $key => [$label, $n]): ?>
        <a role="tab" href="enquiries<?= $key !== '' ? '?type=' . $key : '' ?>" aria-selected="<?= $filter === $key ? 'true' : 'false' ?>"><?= $e($label) ?> <span class="badge badge-muted"><?= (int)$n ?></span></a>
      <?php endforeach; ?>
    </div>
    <form method="get" class="d-flex gap-2">
      <?php if ($filter !== ''): ?><input type="hidden" name="type" value="<?= $e($filter) ?>"><?php endif; ?>
      <select class="form-select" name="status" onchange="this.form.submit()" aria-label="Filter by status">
        <option value="">Any status</option>
        <?php foreach (['new' => 'New', 'read' => 'Read', 'handled' => 'Handled'] as $value => $label): ?>
          <option value="<?= $value ?>"<?= $statusFilter === $value ? ' selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Received</th><th>From</th><th>Type</th><th>Subject / interest</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row):
        $interests = array_map(static fn($k) => $interestLabels[$k] ?? $k, array_filter(explode(',', (string)$row['interests'])));
        $record = $row + [
          'type_label' => $row['form_type'] === 'donor' ? 'Join as Donor / ESO' : 'Contact message',
          'partner_label' => $partnerTypes[$row['partner_type']] ?? '',
          'interest_labels' => implode(', ', $interests),
          'received' => date('j M Y, g:ia', strtotime((string)$row['created_at'])),
        ];
        unset($record['ip_hash']);
        $summary = $row['form_type'] === 'donor' ? ($partnerTypes[$row['partner_type']] ?? '') : ($row['subject'] !== '' ? $row['subject'] : mb_strimwidth((string)$row['message'], 0, 60, '…'));
      ?>
        <tr<?= $row['status'] === 'new' ? ' style="font-weight:600"' : '' ?>>
          <td class="text-muted" style="white-space:nowrap"><?= $e(date('j M Y', strtotime((string)$row['created_at']))) ?></td>
          <td><strong><?= $e($row['name']) ?></strong><div class="text-muted" style="font-size:.75rem"><?= $e($row['organisation'] !== '' ? $row['organisation'] . ' · ' : '') ?><?= $e($row['email']) ?></div></td>
          <td><span class="badge <?= $row['form_type'] === 'donor' ? 'badge-purple' : 'badge-primary' ?>"><?= $row['form_type'] === 'donor' ? 'Donor / ESO' : 'Contact' ?></span></td>
          <td><?= $e($summary) ?></td>
          <td><span class="badge <?= $statusBadge[$row['status']] ?>"><?= $e(ucfirst($row['status'])) ?></span></td>
          <td class="text-right">
            <div class="d-flex gap-2 justify-end">
              <button type="button" class="btn btn-sm btn-outline" data-enquiry="<?= $e(json_encode($record)) ?>"><i class="fas fa-eye"></i> View</button>
              <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <?php if ($row['status'] !== 'handled'): ?>
                  <button class="btn btn-sm btn-success" name="action" value="handled" title="Mark handled"><i class="fas fa-check"></i></button>
                <?php else: ?>
                  <button class="btn btn-sm btn-ghost" name="action" value="new" title="Mark as new"><i class="fas fa-undo"></i></button>
                <?php endif; ?>
              </form>
              <form method="post" onsubmit="return confirm('Delete this enquiry permanently?')"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <button class="btn btn-sm btn-danger" name="action" value="delete" title="Delete"><i class="fas fa-trash"></i></button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="6"><div class="empty-state"><i class="fas fa-inbox"></i><h3>No enquiries</h3><p>New messages from the website will appear here.</p></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-overlay" id="enquiryModal" aria-hidden="true">
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="enquiryModalTitle">
    <div class="modal-header">
      <div class="modal-title"><div class="modal-ico"><i class="fas fa-envelope-open-text"></i></div><span class="modal-title-text" id="enquiryModalTitle">Enquiry</span></div>
      <button type="button" class="modal-close" data-modal-close aria-label="Close"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body"><dl class="cms-details" data-enquiry-details></dl><div class="cms-message" data-enquiry-message></div></div>
    <div class="modal-footer">
      <a class="btn btn-primary" data-enquiry-reply href="#"><i class="fas fa-reply"></i> Reply by email</a>
      <form method="post" data-enquiry-handled><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="id" value=""><button class="btn btn-success" name="action" value="handled"><i class="fas fa-check"></i> Mark handled</button></form>
      <button type="button" class="btn btn-ghost" data-modal-close>Close</button>
    </div>
  </div>
</div>
<script>
document.addEventListener('click', function (event) {
  var button = event.target.closest('[data-enquiry]');
  if (!button) return;
  var r = JSON.parse(button.dataset.enquiry);
  var fields = [['Received', r.received], ['Form', r.type_label], ['Joining as', r.partner_label], ['Name', r.name], ['Organisation', r.organisation],
    ['Email', r.email], ['Phone', r.phone], ['Country', r.country], ['Website', r.website], ['Subject', r.subject], ['Interests', r.interest_labels]];
  var list = document.querySelector('[data-enquiry-details]');
  list.innerHTML = '';
  fields.forEach(function (f) {
    if (!f[1]) return;
    var dt = document.createElement('dt'); dt.textContent = f[0];
    var dd = document.createElement('dd');
    if (f[0] === 'Email') { var a = document.createElement('a'); a.href = 'mailto:' + f[1]; a.textContent = f[1]; dd.appendChild(a); }
    else if (f[0] === 'Phone') { var t = document.createElement('a'); t.href = 'tel:' + f[1].replace(/[^+0-9]/g, ''); t.textContent = f[1]; dd.appendChild(t); }
    else if (f[0] === 'Website' && /^https?:\/\//i.test(f[1])) { var w = document.createElement('a'); w.href = f[1]; w.target = '_blank'; w.rel = 'noopener noreferrer'; w.textContent = f[1]; dd.appendChild(w); }
    else dd.textContent = f[1];
    list.appendChild(dt); list.appendChild(dd);
  });
  document.querySelector('[data-enquiry-message]').textContent = r.message || '';
  document.querySelector('[data-enquiry-message]').hidden = !r.message;
  var subject = r.form_type === 'donor' ? 'Partnering with ' + <?= json_encode(site_settings()['site_name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?> : ('Re: ' + (r.subject || 'your message'));
  document.querySelector('[data-enquiry-reply]').href = 'mailto:' + encodeURIComponent(r.email) + '?subject=' + encodeURIComponent(subject);
  var handled = document.querySelector('[data-enquiry-handled]');
  handled.elements.id.value = r.id;
  handled.hidden = r.status === 'handled';
  document.getElementById('enquiryModalTitle').textContent = r.name;
  window.Modal.open('enquiryModal');
  document.getElementById('enquiryModal').setAttribute('aria-hidden', 'false');
  if (r.status === 'new') {
    var data = new FormData(); data.append('csrf', '<?= $csrf ?>'); data.append('id', r.id); data.append('action', 'read'); data.append('silent', '1');
    fetch('enquiries.php', { method: 'POST', body: data, credentials: 'same-origin' });
  }
});
</script>
<?php require __DIR__ . '/footer.php'; ?>
