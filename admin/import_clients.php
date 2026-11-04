<?php
$phead = 'Clients';
require "nav-head.php";
require "services_check.php";
require_once "client_helpers.php";

function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}


$columns = [
    'fname', 'lname', 'username', 'gender', 'dob', 'nin',
    'phone', 'email', 'bank_name', 'bank_account',
    'district', 'business_name', 'business_type', 'location',
    'business_period', 'amount_last', 'amount_this',
    'nok_name', 'nok_tel', 'nok_rel',
    'password', 'loan_limit',
];

$requiredCols = ['fname', 'lname', 'district'];


const IMPORT_STOP_MARKER = 'DELETE EVERYTHING BELOW THIS LINE';

$results   = [];  
$processed = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $results[] = ['row' => 0, 'label' => '-', 'ok' => false, 'message' => 'No file uploaded, or the upload failed.'];
    } else {

        $processed = true;
        $handle    = fopen($_FILES['csv_file']['tmp_name'], 'r');

        if ($handle === false) {
            $results[] = ['row' => 0, 'label' => '-', 'ok' => false, 'message' => 'Could not open the uploaded file.'];
        } else {

            // Strip UTF-8 BOM if present, then read header row.
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") rewind($handle);
            $header = fgetcsv($handle);

            if ($header === false) {
                $results[] = ['row' => 0, 'label' => '-', 'ok' => false, 'message' => 'The file appears to be empty.'];
            } else {

                $header = array_map(fn($h) => strtolower(trim((string)$h)), $header);
                $missingCols = array_diff($requiredCols, $header);

                if (!empty($missingCols)) {
                    $results[] = [
                        'row' => 0, 'label' => '-', 'ok' => false,
                        'message' => 'Template columns missing: ' . implode(', ', $missingCols)
                            . '. Please re-download the template and try again.',
                    ];
                } else {

                    $rowNum   = 1; // header was row 1
                    $dataSeen = false;

                    while (($line = fgetcsv($handle)) !== false) {
                        $rowNum++;

                        // Skip fully blank rows.
                        if (count(array_filter($line, fn($v) => trim((string)$v) !== '')) === 0) {
                            continue;
                        }

                        // Stop entirely once we hit the reference-block marker -
                        // everything from here on is instructions, not data.
                        $rowText = implode(' ', $line);
                        if (stripos($rowText, IMPORT_STOP_MARKER) !== false) {
                            break;
                        }

                        $data = [];
                        foreach ($header as $idx => $colName) {
                            $data[$colName] = trim((string)($line[$idx] ?? ''));
                        }

                        $dataSeen = true;
                        $label = trim(($data['fname'] ?? '') . ' ' . ($data['lname'] ?? '')) ?: "Row {$rowNum}";

                        try {
                            $result = importClientRow($conn, $data, $requiredCols);
                            $results[] = ['row' => $rowNum, 'label' => $label, 'ok' => true, 'message' => $result];
                        } catch (Throwable $e) {
                            $results[] = ['row' => $rowNum, 'label' => $label, 'ok' => false, 'message' => $e->getMessage()];
                        }
                    }

                    if (!$dataSeen) {
                        $results[] = ['row' => 0, 'label' => '-', 'ok' => false, 'message' => 'No data rows found below the header.'];
                    }
                }
            }

            fclose($handle);
        }
    }
}

/**
 * Generates a username from a client's name, falling back to random
 * suffixes until a free one is found (bounded attempts).
 */
function generateUsername(mysqli $conn, string $fname, string $lname): string
{
    $base = strtolower(preg_replace('/[^a-z0-9]/i', '', $fname . $lname));
    if ($base === '') $base = 'client';

    for ($i = 0; $i < 25; $i++) {
        $candidate = $i === 0 ? $base : $base . random_int(100, 9999);

        $s = $conn->prepare('SELECT id FROM user WHERE username = ? LIMIT 1');
        $s->bind_param('s', $candidate);
        $s->execute();
        $s->store_result();
        $exists = $s->num_rows > 0;
        $s->close();

        if (!$exists) return $candidate;
    }

    throw new RuntimeException('Could not generate a unique username after multiple attempts.');
}

/** Generates a readable random temporary password, e.g. "Client7f3k29". */
function generateTempPassword(): string
{
    return 'Client' . bin2hex(random_bytes(3));
}


function importClientRow(mysqli $conn, array $data, array $requiredCols): string
{
    foreach ($requiredCols as $col) {
        if (($data[$col] ?? '') === '') {
            throw new RuntimeException("Missing value for '{$col}'.");
        }
    }

    $fname = $data['fname'];
    $lname = $data['lname'];

    $district = matchAllowedValue($data['district'], VALID_DISTRICTS);
    if ($district === null) {
        throw new RuntimeException("Unrecognized district '{$data['district']}'. See the allowed-values block in the template.");
    }

    // --- Everything below is optional: blank/0 is acceptable. ---

    $generatedUsername = false;
    $username = $data['username'] ?? '';
    if ($username === '') {
        $username = generateUsername($conn, $fname, $lname);
        $generatedUsername = true;
    } else {
        $s = $conn->prepare('SELECT id FROM user WHERE username = ? LIMIT 1');
        $s->bind_param('s', $username);
        $s->execute();
        $s->store_result();
        if ($s->num_rows > 0) { $s->close(); throw new RuntimeException('Username already taken.'); }
        $s->close();
    }

    $gender          = $data['gender'] ?? '';
    $dob             = ($data['dob'] ?? '') !== '' ? $data['dob'] : null;
    $nin             = $data['nin'] ?? '';
    $phone           = ($data['phone'] ?? '') !== '' ? formatPhone($data['phone']) : '';
    $email           = $data['email'] ?? '';
    $bank_name       = $data['bank_name'] ?? '';
    $bank_account    = $data['bank_account'] ?? '';
    $business_name   = $data['business_name'] ?? '';
    $business_type   = $data['business_type'] ?? '';
    $location        = $data['location'] ?? '';
    $nok_name        = $data['nok_name'] ?? '';
    $nok_tel         = ($data['nok_tel'] ?? '') !== '' ? formatPhone($data['nok_tel']) : '';
    $nok_rel         = $data['nok_rel'] ?? '';

    $business_period = ($data['business_period'] ?? '') !== ''
        ? (matchAllowedValue($data['business_period'], VALID_BUSINESS_PERIODS)
            ?? throw new RuntimeException("Unrecognized business_period '{$data['business_period']}'. Use one of the exact options from the template."))
        : '';

    $amount_last_raw = ($data['amount_last'] ?? '') !== ''
        ? (matchAllowedValue($data['amount_last'], VALID_AMOUNT_RANGES)
            ?? throw new RuntimeException("Unrecognized amount_last '{$data['amount_last']}'. Use one of the exact ranges from the template."))
        : '';

    $amount_this_raw = ($data['amount_this'] ?? '') !== ''
        ? (matchAllowedValue($data['amount_this'], VALID_AMOUNT_RANGES)
            ?? throw new RuntimeException("Unrecognized amount_this '{$data['amount_this']}'. Use one of the exact ranges from the template."))
        : '';

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Invalid email address format.');
    }

    if ($email !== '') {
        $s = $conn->prepare('SELECT id FROM user WHERE email = ? LIMIT 1');
        $s->bind_param('s', $email);
        $s->execute();
        $s->store_result();
        if ($s->num_rows > 0) { $s->close(); throw new RuntimeException('Email already registered.'); }
        $s->close();
    }

    if ($nin !== '') {
        $s = $conn->prepare('SELECT id FROM customers WHERE nin = ? LIMIT 1');
        $s->bind_param('s', $nin);
        $s->execute();
        $s->store_result();
        if ($s->num_rows > 0) { $s->close(); throw new RuntimeException('A client with that NIN already exists.'); }
        $s->close();
    }

    $generatedPassword = false;
    $password = $data['password'] ?? '';
    if ($password === '') {
        $password = generateTempPassword();
        $generatedPassword = true;
    } elseif (strlen($password) < 6) {
        throw new RuntimeException('Password must be at least 6 characters.');
    }

    $manualLoan = trim((string)($data['loan_limit'] ?? ''));
    $loan_limit = $manualLoan !== ''
        ? (float)str_replace(',', '', $manualLoan)
        : calcLoanLimit($amount_last_raw, $amount_this_raw, $business_type);

    $credit_score    = creditScore($business_type);
    $amount_last_num = parseAmountRange($amount_last_raw) * 0.9;
    $amount_this_num = parseAmountRange($amount_this_raw) * 1.1;

    $role   = 3;
    $active = 1;

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare('
            INSERT INTO user
                (fname, lname, username, role, password, email, image_url, datetime, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)
        ');
        if (!$stmt) throw new RuntimeException('Prepare user: ' . $conn->error);

        // Not NULL columns in the schema get an empty string, not null -
        // bulk import has no file uploads, but the columns still can't be NULL.
        $profile_img = '';
        $stmt->bind_param('sssisssi',
            $fname, $lname, $username,
            $role, $hashedPassword,
            $email, $profile_img, $active
        );
        if (!$stmt->execute()) throw new RuntimeException('Insert user: ' . $stmt->error);
        $user_id = (int)$stmt->insert_id;
        $stmt->close();

        $id_front            = '';
        $id_back             = '';
        $stock               = '';
        $license             = '';
        $applicant_signature = '';

        $stmt = $conn->prepare('
            INSERT INTO customers (
                user_id, gender, dob, nin,
                id_front, id_back,
                phone, bank_name, bank_account, email,
                district, business_name, business_type, location,
                stock, amount_last, amount_this, license,
                nok_name, nok_tel, nok_rel, business_period,
                loan_limit, password, credit_score,
                applicant_signature, active
            ) VALUES (
                ?, ?, ?, ?,
                ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?,
                ?, 1
            )
        ');
        if (!$stmt) throw new RuntimeException('Prepare customer: ' . $conn->error);

        $stmt->bind_param(
            'isssssssssssssddssssssdiss',
            $user_id,
            $gender, $dob, $nin,
            $id_front, $id_back,
            $phone, $bank_name, $bank_account, $email,
            $district, $business_name, $business_type, $location,
            $stock, $amount_last_num, $amount_this_num, $license,
            $nok_name, $nok_tel, $nok_rel, $business_period,
            $loan_limit, $hashedPassword, $credit_score,
            $applicant_signature
        );
        if (!$stmt->execute()) throw new RuntimeException('Insert customer: ' . $stmt->error);
        $stmt->close();

        $conn->commit();

        $extras = [];
        if ($generatedUsername) $extras[] = "username: {$username}";
        if ($generatedPassword) $extras[] = "temp password: {$password}";
        $extraNote = $extras ? ' (' . implode(', ', $extras) . ')' : '';

        return 'Imported - loan limit UGX ' . number_format($loan_limit) . ', credit score ' . $credit_score . '.' . $extraNote;

    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

$successCount = count(array_filter($results, fn($r) => $r['ok']));
$failCount    = count($results) - $successCount;
?>

<div class="page-header">
    <div>
        <div class="title">Import Clients</div>
        <div class="sub">Upload a CSV of client records to add them in bulk</div>
    </div>

    <div class="d-flex gap-2 flex-wrap">
        <a href="clients.php" class="btn btn-ghost">
            <i class="fas fa-arrow-left"></i> Back to Clients
        </a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <ol class="mb-4 ps-3">
            <li>Download the CSV template below - it includes a reference block at the bottom listing the allowed values for <code>district</code>, <code>business_period</code>, and the sales-amount ranges. Rows at or after the <code>--- DELETE EVERYTHING BELOW ---</code> marker are ignored automatically, but it's still cleanest to delete them.</li>
            <li>Only <code>fname</code>, <code>lname</code>, and <code>district</code> are required. Everything else can be left blank.</li>
            <li>If <code>username</code> or <code>password</code> are left blank, one is generated automatically and shown in the results below - make a note of it so the client can log in.</li>
            <li><code>district</code> must match one of the allowed options exactly (case-insensitive). If <code>business_period</code>, <code>amount_last</code>, or <code>amount_this</code> are filled in, they must also match one of the allowed options.</li>
            <li>Leave <code>loan_limit</code> blank to auto-calculate it from the amount range and business type (or 0 if those were left blank too).</li>
            <li>Upload the completed file below.</li>
        </ol>

        <div class="d-flex gap-2 flex-wrap mb-4">
            <a href="download_client_template.php" class="btn btn-primary">
                <i class="fas fa-file-csv"></i> Download CSV Template
            </a>
        </div>

        <form method="POST" enctype="multipart/form-data" class="d-flex gap-2 flex-wrap align-items-center">
            <input type="file" name="csv_file" accept=".csv" class="form-control" style="max-width: 360px;" required>
            <button type="submit" class="btn btn-success">
                <i class="fas fa-upload"></i> Upload &amp; Import
            </button>
        </form>
    </div>
</div>

<?php if ($processed): ?>
<div class="card mt-3">
    <div class="card-header">
        <strong>Import Results</strong>
        &nbsp;-&nbsp;
        <span class="badge badge-success"><?= $successCount ?> succeeded</span>
        <span class="badge badge-danger"><?= $failCount ?> failed</span>
    </div>

    <div class="card-body no-pad">
        <div class="table-responsive">
            <table class="table data-table">
                <thead>
                    <tr>
                        <th>Row</th>
                        <th>Client</th>
                        <th>Status</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $r): ?>
                        <tr>
                            <td><?= $r['row'] ?: '-' ?></td>
                            <td><?= e($r['label']) ?></td>
                            <td>
                                <?php if ($r['ok']): ?>
                                    <span class="badge badge-success">Imported</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Failed</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($r['message']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require "footer.php"; ?>