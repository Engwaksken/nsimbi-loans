<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/db.php';
if (empty($_SESSION['logged_in'])) { http_response_code(403); exit('Sign in required.'); }
$conn->set_charset('utf8mb4');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = ['company_name','ceo_name','phone','email','bank','account_name','account_number','bank_branch','company_location'];
    $values = [];
    foreach ($fields as $field) $values[$field] = trim((string)($_POST[$field] ?? ''));
    foreach ($values as $value) if ($value === '') { $error = 'Please complete all organisation details.'; break; }
    if (!$error && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) $error = 'Enter a valid email address.';
    if (!$error) {
        $stmt = $conn->prepare('INSERT INTO organisations (company_name,ceo_name,phone,email,bank,account_name,account_number,bank_branch,company_location) VALUES (?,?,?,?,?,?,?,?,?)');
        $stmt->bind_param('sssssssss', $values['company_name'], $values['ceo_name'], $values['phone'], $values['email'], $values['bank'], $values['account_name'], $values['account_number'], $values['bank_branch'], $values['company_location']);
        if ($stmt->execute()) { header('Location: organisations.php?created=1'); exit; }
        $error = 'Could not save the organisation profile. Confirm the database setup script has been run.';
    }
}
$rows = $conn->query('SELECT id, company_name, ceo_name, phone, email, bank, account_number, bank_branch, company_location FROM organisations ORDER BY company_name');
function orgEscape(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Organisations</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light"><main class="container py-4"><h1 class="h3 mb-3">Organisation profiles</h1>
<?php if ($error): ?><div class="alert alert-danger"><?= orgEscape($error) ?></div><?php endif; ?>
<?php if (isset($_GET['created'])): ?><div class="alert alert-success">Organisation profile saved. It is now available when adding a loan.</div><?php endif; ?>
<div class="card mb-4"><div class="card-body"><h2 class="h5">Add organisation</h2><form method="post" class="row g-3">
<?php foreach (['company_name'=>'Company name','ceo_name'=>'Name of CEO','phone'=>'Phone number','email'=>'Email','bank'=>'Bank','account_name'=>'Account name','account_number'=>'Account number','bank_branch'=>'Bank branch','company_location'=>'Company location'] as $key=>$label): ?><div class="col-md-6"><label class="form-label" for="<?= $key ?>"><?= $label ?></label><input class="form-control" id="<?= $key ?>" name="<?= $key ?>" required type="<?= $key === 'email' ? 'email' : 'text' ?>" value="<?= orgEscape((string)($_POST[$key] ?? '')) ?>"></div><?php endforeach; ?>
<div class="col-12"><button class="btn btn-primary">Save organisation</button> <a class="btn btn-outline-secondary" href="pending_loans.php">Back to loans</a></div></form></div></div>
<h2 class="h5">Registered organisations</h2><div class="table-responsive bg-white"><table class="table table-striped"><thead><tr><th>Company</th><th>CEO</th><th>Phone</th><th>Email</th><th>Bank</th><th>Account</th><th>Branch</th><th>Location</th></tr></thead><tbody>
<?php if ($rows): while ($org = $rows->fetch_assoc()): ?><tr><td><?= orgEscape((string)$org['company_name']) ?></td><td><?= orgEscape((string)$org['ceo_name']) ?></td><td><?= orgEscape((string)$org['phone']) ?></td><td><?= orgEscape((string)$org['email']) ?></td><td><?= orgEscape((string)$org['bank']) ?></td><td><?= orgEscape((string)$org['account_number']) ?></td><td><?= orgEscape((string)$org['bank_branch']) ?></td><td><?= orgEscape((string)$org['company_location']) ?></td></tr><?php endwhile; endif; ?>
</tbody></table></div></main></body></html>
