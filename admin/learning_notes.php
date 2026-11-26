<?php
declare(strict_types=1);

/*
 * People > Learning Notes: financial / business literacy notes for clients.
 * Admins and loan officers upload a note, then send it to clients who have
 * had no training (the sign-up question), to everyone, or to one client
 * (client profile > "Send learning notes" opens this page with ?client=<id>).
 * Loan officers only reach their own clients. See includes/client_learning.php.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/db.php';
$role = (int)($_SESSION['role'] ?? 0);
if (empty($_SESSION['logged_in']) || !in_array($role, [1, 2], true)) {
    header('Location: ../login.php');
    exit;
}
$conn->set_charset('utf8mb4');
$actorId = (int)($_SESSION['user_id'] ?? 0);

require_once dirname(__DIR__) . '/includes/client_learning.php';
require_once dirname(__DIR__) . '/includes/officer_assignment.php';
learning_ensure_schema($conn);

// Loan officers: only their own clients.
$scopeSql = officer_client_sql('c');

if (empty($_SESSION['learning_csrf'])) {
    $_SESSION['learning_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['learning_csrf'];

function ln_done(string $message, string $query = ''): never
{
    $_SESSION['learning_flash'] = $message;
    header('Location: learning_notes.php' . $query, true, 303);
    exit;
}

function ln_e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/** One client (by user id) this user may send to, or null. */
function ln_client(mysqli $conn, int $userId, string $scopeSql): ?array
{
    if ($userId <= 0) {
        return null;
    }
    $stmt = $conn->prepare("
        SELECT c.user_id, u.fname, u.lname, c.phone, c.business_training
        FROM customers c JOIN user u ON u.id = c.user_id
        WHERE c.user_id = ?{$scopeSql} LIMIT 1
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

$singleClient = ln_client($conn, (int)($_GET['client'] ?? $_POST['client'] ?? 0), $scopeSql);
$clientQuery = $singleClient ? '?client=' . (int)$singleClient['user_id'] : '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } elseif ($action === 'upload') {
        try {
            $noteId = learning_store_note($conn, $actorId, (string)($_POST['title'] ?? ''), (string)($_POST['description'] ?? ''), $_FILES['note_file'] ?? []);
            ln_done('Note uploaded. Use "Send" to share it with clients.', $clientQuery);
        } catch (DomainException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            error_log('Learning note upload failed: ' . $e->getMessage());
            $error = 'Could not save the note. Please try again.';
        }
    } elseif ($action === 'send') {
        $note = learning_note_find($conn, (int)($_POST['note_id'] ?? 0));
        $audience = (string)($_POST['audience'] ?? '');
        if (!$note) {
            $error = 'That note no longer exists.';
        } elseif ($audience === 'client') {
            if (!$singleClient) {
                $error = 'That client was not found.';
            } else {
                $sent = learning_share_note($conn, $note, [(int)$singleClient['user_id']], $actorId);
                $name = trim($singleClient['fname'] . ' ' . $singleClient['lname']);
                ln_done($sent ? "\"{$note['title']}\" sent to {$name}." : "{$name} already has \"{$note['title']}\".", $clientQuery);
            }
        } elseif (!isset(LEARNING_AUDIENCES[$audience])) {
            $error = 'Choose who to send the note to.';
        } else {
            $ids = learning_audience_ids($conn, $audience, $scopeSql);
            $sent = learning_share_note($conn, $note, $ids, $actorId);
            $already = count($ids) - $sent;
            ln_done("\"{$note['title']}\" sent to {$sent} client(s)." . ($already > 0 ? " {$already} already had it." : '')
                . ($sent > 0 ? ' Clients with an email address are emailed.' : ''), $clientQuery);
        }
    } elseif ($action === 'delete') {
        $note = learning_note_find($conn, (int)($_POST['note_id'] ?? 0));
        if ($note && ($role === 1 || (int)$note['created_by'] === $actorId)) {
            learning_delete_note($conn, (int)$note['id']);
            ln_done('Note deleted.', $clientQuery);
        }
        $error = 'You can only delete notes you uploaded.';
    } else {
        $error = 'Invalid request.';
    }
}

$flash = (string)($_SESSION['learning_flash'] ?? '');
unset($_SESSION['learning_flash']);

$notes = learning_notes_all($conn);
$audienceCounts = learning_audience_counts($conn, $scopeSql);

$phead = 'Learning Notes';
require 'nav-head.php';
?>

<style>
.ln-page { padding: 1rem; }
.ln-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 1.1rem; margin-bottom: 1rem; }
.ln-muted { color: #64748b; font-size: .8rem; }
.ln-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: .75rem; }
.ln-grid .wide { grid-column: 1 / -1; }
.ln-table { width: 100%; border-collapse: collapse; font-size: .88rem; }
.ln-table th, .ln-table td { padding: .6rem; border-bottom: 1px solid #f0f1f3; text-align: left; vertical-align: top; }
.ln-table th { font-size: .74rem; text-transform: uppercase; color: #64748b; background: #f8fafc; }
.ln-send { display: flex; gap: .4rem; flex-wrap: wrap; align-items: center; }
.ln-send .form-select { width: auto; min-width: 230px; max-width: 100%; }
.ln-client { border-left: 5px solid #2563eb; background: #eff6ff; }
</style>

<div class="ln-page">
    <div class="mb-3">
        <h4 class="mb-0 fw-bold">Learning Notes</h4>
        <div class="ln-muted">Financial and business literacy notes for clients. Sign-up asks whether a client has had financial/business training; send notes to those who haven't, or to anyone. Clients read them under "Learning Notes" in their portal and get an email.</div>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?= ln_e($error) ?></div>
    <?php elseif ($flash !== ''): ?>
        <div class="alert alert-success"><?= ln_e($flash) ?></div>
    <?php endif; ?>

    <?php if ($singleClient): ?>
        <div class="ln-card ln-client">
            <strong>Sending to one client:</strong>
            <?= ln_e(trim($singleClient['fname'] . ' ' . $singleClient['lname'])) ?>
            <span class="ln-muted">(<?= ln_e($singleClient['phone']) ?>, training: <?= ln_e(learning_training_label($singleClient['business_training'])) ?>)</span>
            <div class="ln-muted mt-1">Use "Send to this client" on a note below. <a href="learning_notes.php">Send to groups instead</a> | <a href="client_profile.php?id=<?= (int)$singleClient['user_id'] ?>">Back to profile</a></div>
        </div>
    <?php endif; ?>

    <div class="stats-grid clients-stats-grid">
        <div class="stat-card c-orange">
            <div class="stat-head"><div class="stat-ico c-orange"><i class="fas fa-user-graduate"></i></div></div>
            <div class="stat-value sm"><?= number_format($audienceCounts['no_training']) ?></div>
            <div class="stat-label">Clients with no training</div>
        </div>
        <div class="stat-card c-indigo">
            <div class="stat-head"><div class="stat-ico c-indigo"><i class="fas fa-question-circle"></i></div></div>
            <div class="stat-value sm"><?= number_format($audienceCounts['unanswered']) ?></div>
            <div class="stat-label">Not answered (signed up earlier)</div>
        </div>
        <div class="stat-card c-blue">
            <div class="stat-head"><div class="stat-ico c-blue"><i class="fas fa-book-open"></i></div></div>
            <div class="stat-value sm"><?= number_format(count($notes)) ?></div>
            <div class="stat-label">Notes uploaded</div>
        </div>
    </div>

    <div class="ln-card">
        <h6 class="fw-bold mb-2"><i class="fas fa-upload me-1"></i> Upload a note</h6>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= ln_e($csrf) ?>">
            <input type="hidden" name="action" value="upload">
            <?php if ($singleClient): ?><input type="hidden" name="client" value="<?= (int)$singleClient['user_id'] ?>"><?php endif; ?>
            <div class="ln-grid">
                <div>
                    <label class="form-label fs-sm" for="ln_title">Title <span class="text-danger">*</span></label>
                    <input class="form-control" id="ln_title" name="title" maxlength="190" required placeholder="e.g. Keeping simple business records">
                </div>
                <div>
                    <label class="form-label fs-sm" for="ln_file">File <span class="text-danger">*</span></label>
                    <input class="form-control" id="ln_file" type="file" name="note_file" required
                           accept=".pdf,.doc,.docx,.ppt,.pptx,.jpg,.jpeg,.png">
                    <div class="ln-muted">PDF, Word, PowerPoint, JPG or PNG, up to 15 MB.</div>
                </div>
                <div class="wide">
                    <label class="form-label fs-sm" for="ln_desc">Short description (optional, included in the email)</label>
                    <textarea class="form-control" id="ln_desc" name="description" rows="2" maxlength="2000"></textarea>
                </div>
            </div>
            <div class="mt-2 text-end">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Upload note</button>
            </div>
        </form>
    </div>

    <div class="ln-card">
        <h6 class="fw-bold mb-2"><i class="fas fa-book-open me-1"></i> Notes</h6>
        <div class="table-responsive">
            <table class="ln-table">
                <thead><tr><th>Note</th><th>Sent to</th><th>Send</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                <?php if (!$notes): ?>
                    <tr><td colspan="4" class="text-center ln-muted py-4">No notes yet. Upload one above.</td></tr>
                <?php endif; ?>
                <?php foreach ($notes as $note): ?>
                    <tr>
                        <td>
                            <strong><?= ln_e($note['title']) ?></strong>
                            <?php if (trim((string)$note['description']) !== ''): ?>
                                <div class="ln-muted"><?= nl2br(ln_e($note['description'])) ?></div>
                            <?php endif; ?>
                            <div class="ln-muted"><?= ln_e($note['original_name']) ?> (<?= ln_e(learning_file_size((int)$note['file_size'])) ?>)
                                | <?= ln_e($note['author'] ?: 'Staff') ?>, <?= ln_e(date('M j, Y', strtotime((string)$note['created_at']))) ?></div>
                        </td>
                        <td class="text-nowrap">
                            <?= number_format((int)$note['shares']) ?> client(s)
                            <div class="ln-muted"><?= number_format((int)$note['viewed']) ?> opened it</div>
                        </td>
                        <td>
                            <form method="post" class="ln-send">
                                <input type="hidden" name="csrf_token" value="<?= ln_e($csrf) ?>">
                                <input type="hidden" name="action" value="send">
                                <input type="hidden" name="note_id" value="<?= (int)$note['id'] ?>">
                                <?php if ($singleClient): ?>
                                    <input type="hidden" name="client" value="<?= (int)$singleClient['user_id'] ?>">
                                    <input type="hidden" name="audience" value="client">
                                    <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-paper-plane me-1"></i> Send to this client</button>
                                <?php else: ?>
                                    <select name="audience" class="form-select form-select-sm" required aria-label="Send to">
                                        <?php foreach (LEARNING_AUDIENCES as $key => $label): ?>
                                            <option value="<?= ln_e($key) ?>"><?= ln_e($label) ?> (<?= number_format($audienceCounts[$key]) ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-success"
                                            onclick="return confirm('Send this note to the selected clients? Each client gets it once.')">
                                        <i class="fas fa-paper-plane me-1"></i> Send
                                    </button>
                                <?php endif; ?>
                            </form>
                        </td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-secondary" href="learning_note_file.php?id=<?= (int)$note['id'] ?>" target="_blank" rel="noopener" title="Open"><i class="fas fa-eye"></i></a>
                            <?php if ($role === 1 || (int)$note['created_by'] === $actorId): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this note? Clients will no longer see it.')">
                                    <input type="hidden" name="csrf_token" value="<?= ln_e($csrf) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="note_id" value="<?= (int)$note['id'] ?>">
                                    <?php if ($singleClient): ?><input type="hidden" name="client" value="<?= (int)$singleClient['user_id'] ?>"><?php endif; ?>
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($role === 2): ?>
            <div class="ln-muted mt-2">As a loan officer, "Send" reaches only your own clients.</div>
        <?php endif; ?>
    </div>
</div>

<?php require 'footer.php'; ?>
