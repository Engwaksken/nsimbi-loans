<?php
/**
 * Staff review of client submissions (Documents tab on view_client.php):
 *   doc_approve / doc_reject     - decide on an uploaded document
 *   limit_approve / limit_decline - decide on a loan limit review request
 * The client is emailed each decision.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/includes/loan_workflow.php';
require_once dirname(__DIR__) . '/includes/client_documents.php';

date_default_timezone_set('Africa/Nairobi');

$staff_id = (int)($_SESSION['logged_in'] ?? 0);
if ($staff_id <= 0 || !in_array((int)($_SESSION['role'] ?? 0), [1, 2], true)) {
    header('Location: ../login.php');
    exit;
}

$client_id = (int)($_POST['user_id'] ?? 0);

function review_back(int $clientId, string $message, string $type = 'success'): void
{
    $_SESSION['review_message'] = $message;
    $_SESSION['review_message_type'] = $type;
    header('Location: view_client.php?user_id=' . $clientId . '&tab=documents');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $client_id <= 0) {
    header('Location: clients.php');
    exit;
}

if (!hash_equals((string)($_SESSION['review_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
    review_back($client_id, 'Your session expired. Please try again.', 'error');
}

$action = (string)($_POST['action'] ?? '');
$note   = trim((string)($_POST['note'] ?? ''));
$note   = function_exists('mb_substr') ? mb_substr($note, 0, 255) : substr($note, 0, 255);
$now    = date('Y-m-d H:i:s');
$portal = app_base_url() . '/clients/profile.php#documents';

try {
    client_docs_ensure_schema($conn);

    if ($action === 'doc_approve' || $action === 'doc_reject') {
        $doc = client_docs_find($conn, (int)($_POST['doc_id'] ?? 0));
        if (!$doc || (int)$doc['user_id'] !== $client_id) {
            throw new DomainException('Document not found.');
        }
        if ($action === 'doc_reject' && $note === '') {
            throw new DomainException('Please add a note telling the client why the document was rejected.');
        }

        $status = $action === 'doc_approve' ? 'approved' : 'rejected';
        $stmt = $conn->prepare("UPDATE client_documents SET status = ?, review_note = ?, reviewed_by = ?, reviewed_at = ? WHERE id = ?");
        $docId = (int)$doc['id'];
        $stmt->bind_param('ssisi', $status, $note, $staff_id, $now, $docId);
        $stmt->execute();
        $stmt->close();

        $label = htmlspecialchars((string)$doc['title']);
        client_docs_notify_client(
            $conn,
            $client_id,
            'Your document was ' . $status,
            '<p>Your document <strong>' . $label . '</strong> was <strong>' . $status . '</strong>.</p>'
            . ($note !== '' ? '<p>Note from our team: <em>' . htmlspecialchars($note) . '</em></p>' : '')
            . ($status === 'rejected' ? '<p>Please upload a new copy from your profile.</p>' : '')
            . '<p><a href="' . htmlspecialchars($portal) . '">Open my documents</a></p>'
        );

        review_back($client_id, 'Document ' . $status . '.');
    }

    if ($action === 'limit_approve' || $action === 'limit_decline') {
        $requestId = (int)($_POST['request_id'] ?? 0);

        $conn->begin_transaction();

        $stmt = $conn->prepare("SELECT * FROM limit_review_requests WHERE id = ? AND user_id = ? LIMIT 1 FOR UPDATE");
        $stmt->bind_param('ii', $requestId, $client_id);
        $stmt->execute();
        $request = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$request || $request['status'] !== 'pending') {
            $conn->rollback();
            throw new DomainException('This request was already reviewed.');
        }

        if ($action === 'limit_approve') {
            $raw = preg_replace('/[^\d.]/', '', (string)($_POST['approved_limit'] ?? ''));
            $approved = is_numeric($raw) ? round((float)$raw, 2) : 0.0;
            if ($approved <= 0) {
                $conn->rollback();
                throw new DomainException('Enter the new loan limit to approve.');
            }

            $stmt = $conn->prepare("UPDATE customers SET loan_limit = ? WHERE user_id = ?");
            $stmt->bind_param('di', $approved, $client_id);
            $stmt->execute();
            $stmt->close();

            $status = 'approved';
        } else {
            if ($note === '') {
                $conn->rollback();
                throw new DomainException('Please add a note explaining the decision to the client.');
            }
            $approved = null;
            $status = 'declined';
        }

        $stmt = $conn->prepare("
            UPDATE limit_review_requests
            SET status = ?, approved_limit = ?, staff_note = ?, reviewed_by = ?, reviewed_at = ?
            WHERE id = ?
        ");
        $stmt->bind_param('sdsisi', $status, $approved, $note, $staff_id, $now, $requestId);
        $stmt->execute();
        $stmt->close();

        $conn->commit();

        client_docs_notify_client(
            $conn,
            $client_id,
            'Your loan limit review: ' . $status,
            $status === 'approved'
                ? '<p>Good news! Your loan limit has been raised to <strong>UGX ' . number_format((float)$approved) . '</strong>.</p>'
                : '<p>Your request to raise your loan limit was not approved this time.</p>'
            . ($note !== '' ? '<p>Note from our team: <em>' . htmlspecialchars($note) . '</em></p>' : '')
            . '<p><a href="' . htmlspecialchars($portal) . '">Open my profile</a></p>'
        );

        review_back($client_id, $status === 'approved'
            ? 'Loan limit raised to UGX ' . number_format((float)$approved) . '.'
            : 'Limit review declined.');
    }

    review_back($client_id, 'Unknown action.', 'error');
} catch (DomainException $e) {
    review_back($client_id, $e->getMessage(), 'error');
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    error_log('Client review action "' . $action . '" failed: ' . $e->getMessage());
    review_back($client_id, 'Something went wrong. Please try again.', 'error');
}
