<?php
declare(strict_types=1);

/**
 * Receives the public Contact and Donor / ESO forms (JSON response).
 * Stores the enquiry for Website → Enquiries and emails a notification when SMTP is configured.
 */

require_once __DIR__ . '/site_cms.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function enquiry_reply(bool $ok, string $message, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(['success' => $ok, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') enquiry_reply(false, 'Method not allowed.', 405);

$type = ($_POST['form_type'] ?? '') === 'donor' ? 'donor' : 'contact';
$field = static fn(string $key, int $max): string => mb_substr(trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string)($_POST[$key] ?? '')) ?? ''), 0, $max);

// Bots fill the hidden field or submit instantly; pretend success so they move on.
$started = (int)($_POST['started'] ?? 0);
if ($field('company_url', 200) !== '' || ($started > 0 && time() - $started < 3)) {
    enquiry_reply(true, 'Thank you. We will be in touch soon.');
}

try {
    site_cms_ensure_schema($conn);
} catch (Throwable $e) {
    error_log('Enquiry schema error: ' . $e->getMessage());
    enquiry_reply(false, 'We could not send your message right now. Please email us instead.', 500);
}

$ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . (getenv('DB_NAME') ?: 'nsimbi'));
$recent = $conn->prepare('SELECT COUNT(*) AS c FROM site_enquiries WHERE ip_hash = ? AND created_at > (NOW() - INTERVAL 1 HOUR)');
$recent->bind_param('s', $ipHash); $recent->execute();
$count = (int)$recent->get_result()->fetch_assoc()['c']; $recent->close();
if ($count >= 5) enquiry_reply(false, 'You have sent several messages already. Please try again later or email us directly.', 429);

$name = $field('name', 150);
$email = $field('email', 190);
$phone = $field('phone', 40);
$message = $field('message', 5000);
$errors = [];
if ($name === '') $errors[] = 'your name';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'a valid email address';
if (empty($_POST['consent'])) $errors[] = 'your agreement to the privacy policy';

$organisation = $country = $website = $subject = $partnerType = $interests = '';
if ($type === 'donor') {
    $partnerType = (string)($_POST['partner_type'] ?? '');
    if (!isset(site_partner_types()[$partnerType])) { $errors[] = 'how you are joining'; $partnerType = ''; }
    $organisation = $field('organisation', 200);
    $country = $field('country', 100);
    $website = $field('website', 255);
    if ($website !== '' && !preg_match('#^https?://[^\s<>"]+$#i', $website)) $website = 'https://' . preg_replace('#^[a-z]+://#i', '', $website);
    $chosen = array_values(array_intersect((array)($_POST['interests'] ?? []), array_keys(site_partner_interests())));
    $interests = implode(',', $chosen);
} else {
    $subject = $field('subject', 200);
    if ($message === '') $errors[] = 'a message';
}
if ($errors) enquiry_reply(false, 'Please add ' . implode(', ', $errors) . '.', 422);

$stmt = $conn->prepare('INSERT INTO site_enquiries (form_type, partner_type, name, organisation, email, phone, country, website, subject, interests, message, ip_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
$stmt->bind_param('ssssssssssss', $type, $partnerType, $name, $organisation, $email, $phone, $country, $website, $subject, $interests, $message, $ipHash);
$stmt->execute();
$stmt->close();

// Best-effort email to the team; the enquiry is already saved.
$notify = site_settings($conn)['enquiry_email'];
if (filter_var($notify, FILTER_VALIDATE_EMAIL)) {
    try {
        require_once dirname(__DIR__) . '/php/mail-function.php';
        $rows = [
            'Form' => $type === 'donor' ? 'Join as Donor / ESO' : 'Contact us',
            'Joining as' => $partnerType !== '' ? site_partner_types()[$partnerType] : '',
            'Name' => $name, 'Organisation' => $organisation, 'Email' => $email, 'Phone' => $phone,
            'Country' => $country, 'Website' => $website, 'Subject' => $subject,
            'Interests' => implode(', ', array_map(static fn($k) => site_partner_interests()[$k], array_filter(explode(',', $interests)))),
        ];
        $body = '<h3>New website enquiry</h3><table cellpadding="4">';
        foreach ($rows as $label => $value) {
            if ($value !== '') $body .= '<tr><td><b>' . site_e($label) . '</b></td><td>' . site_e($value) . '</td></tr>';
        }
        $body .= '</table>' . ($message !== '' ? '<p>' . nl2br(site_e($message)) . '</p>' : '') . '<p>View all enquiries in the admin dashboard under Website → Enquiries.</p>';
        $title = ($type === 'donor' ? 'New donor / ESO sign-up: ' : 'New contact message: ') . $name;
        if (function_exists('sendEmail')) sendEmail($notify, $title, $body);
    } catch (Throwable $e) {
        error_log('Enquiry notification email failed: ' . $e->getMessage());
    }
}

enquiry_reply(true, $type === 'donor'
    ? 'Thank you for your interest in partnering with us. Our team will contact you soon.'
    : 'Thank you for your message. We will get back to you soon.');
