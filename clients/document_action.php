<?php
/**
 * Client document actions (Documents tab on profile.php):
 *   upload        - add a supporting document (pending review)
 *   delete        - remove one of your own pending documents
 *   limit_request - ask staff to review your loan limit
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require "db.php";
require_once __DIR__ . '/../includes/loan_workflow.php';
require_once __DIR__ . '/../includes/client_documents.php';

date_default_timezone_set('Africa/Nairobi');

function docs_back(string $message, string $type = 'error'): void
{
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $type;
    header('Location: profile.php#documents');
    exit;
}

$user_id   = (int)($_SESSION['user_id'] ?? $_SESSION['logged_in'] ?? 0);
$user_role = (int)($_SESSION['role'] ?? 0);

if ($user_id <= 0 || $user_role !== 3) {
    header('Location: ../login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: profile.php#documents');
    exit;
}

// A file bigger than post_max_size arrives with an empty $_POST.
if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    docs_back('The file is too large. The maximum size is 5 MB.');
}

if (!hash_equals((string)($_SESSION['docs_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
    docs_back('Your session expired. Please try again.');
}

$action = (string)($_POST['action'] ?? '');

try {
    $stmt = $conn->prepare("
        SELECT c.*, u.fname, u.lname, u.email AS user_email
        FROM customers c INNER JOIN user u ON u.id = c.user_id
        WHERE c.user_id = ? LIMIT 1
    ");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$customer) {
        docs_back('Customer profile not found.');
    }

    $client_name = trim(($customer['fname'] ?? '') . ' ' . ($customer['lname'] ?? '')) ?: 'A client';
    $staff_link = app_base_url() . '/admin/view_client.php?user_id=' . $user_id . '&tab=documents';

    if ($action === 'upload') {
        $type = (string)($_POST['doc_type'] ?? '');
        client_docs_store_upload($conn, $user_id, $type, (string)($_POST['title'] ?? ''), $_FILES['document'] ?? []);

        $label = client_doc_types()[$type]['label'] ?? 'Document';
        client_docs_notify_staff(
            $conn,
            $user_id,
            'New document to review: ' . $client_name,
            '<p><strong>' . htmlspecialchars($client_name) . '</strong> uploaded a <strong>' . htmlspecialchars($label) . '</strong> for review.</p>'
            . '<p><a href="' . htmlspecialchars($staff_link) . '">Review documents</a></p>'
        );

        docs_back($label . ' uploaded. Our team will review it shortly.', 'success');
    }

    if ($action === 'delete') {
        client_docs_delete_pending($conn, $user_id, (int)($_POST['doc_id'] ?? 0));
        docs_back('Document removed.', 'success');
    }

    if ($action === 'limit_request') {
        $current   = (float)($customer['loan_limit'] ?? 0);
        $raw       = preg_replace('/[^\d.]/', '', (string)($_POST['requested_limit'] ?? ''));
        $requested = is_numeric($raw) ? round((float)$raw, 2) : 0.0;
        $reason    = trim((string)($_POST['reason'] ?? ''));

        if ((int)($customer['active'] ?? 0) !== 1) {
            throw new DomainException('Your profile is inactive. Please contact the office.');
        }
        if ($requested <= 0 || $requested <= $current) {
            throw new DomainException('The requested limit must be higher than your current limit of UGX ' . number_format($current) . '.');
        }
        if ($requested > 1000000000) {
            throw new DomainException('Please enter a realistic amount.');
        }
        if (strlen($reason) < 10) {
            throw new DomainException('Please tell us briefly why you need a higher limit (at least 10 characters).');
        }
        $reason = function_exists('mb_substr') ? mb_substr($reason, 0, 2000) : substr($reason, 0, 2000);

        $summary = client_docs_summary(client_docs_for_user($conn, $user_id), $customer);
        $missing = client_docs_missing_required($summary);
        if ($missing) {
            throw new DomainException('Please upload these documents first: ' . implode(', ', $missing) . '.');
        }
        if (client_limit_pending(client_limit_requests($conn, $user_id))) {
            throw new DomainException('You already have a limit review in progress.');
        }

        client_limit_submit($conn, $user_id, $current, $requested, $reason);

        client_docs_notify_staff(
            $conn,
            $user_id,
            'Loan limit review requested: ' . $client_name,
            '<p><strong>' . htmlspecialchars($client_name) . '</strong> asked for their loan limit to be raised from UGX '
            . number_format($current) . ' to <strong>UGX ' . number_format($requested) . '</strong>.</p>'
            . '<p><em>' . nl2br(htmlspecialchars($reason)) . '</em></p>'
            . '<p><a href="' . htmlspecialchars($staff_link) . '">Review request and documents</a></p>'
        );

        docs_back('Limit review submitted. We will email you once it has been reviewed.', 'success');
    }

    docs_back('Unknown action.');
} catch (DomainException $e) {
    docs_back($e->getMessage());
} catch (Throwable $e) {
    error_log('Client document action "' . $action . '" failed for user ' . $user_id . ': ' . $e->getMessage());
    docs_back('Something went wrong. Please try again.');
}
