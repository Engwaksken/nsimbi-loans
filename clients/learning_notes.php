<?php
/*
 * Financial / business learning notes the loan team has shared with this
 * client (admin/learning_notes.php). Opening one marks it as read.
 */
require "nav-head.php";
require_once dirname(__DIR__) . '/includes/client_learning.php';

$notes = [];
try {
    $notes = learning_notes_for_client($conn, $user_id);
} catch (Throwable $e) {
    error_log('Client learning notes: ' . $e->getMessage());
}
$e = static fn($v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
?>

<style>
.ln-list { display: grid; gap: .85rem; }
.ln-item { background: #fff; border: 1px solid #e5e7eb; border-left: 5px solid #2563eb; border-radius: 14px; padding: 1rem; display: flex; gap: 1rem; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; }
.ln-item.read { border-left-color: #cbd5e1; }
.ln-item h3 { font-size: 1rem; font-weight: 800; margin: 0 0 .25rem; }
.ln-muted { color: #64748b; font-size: .8rem; }
.ln-new { display: inline-block; font-size: .68rem; font-weight: 800; color: #fff; background: #2563eb; border-radius: 999px; padding: .1rem .5rem; margin-left: .35rem; vertical-align: middle; }
.ln-tip { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; border-radius: 12px; padding: .75rem 1rem; margin-bottom: 1rem; font-size: .9rem; }
</style>

<div class="page-header mb-4">
    <div class="title">Learning Notes</div>
    <div class="sub">Financial and business notes from your loan team, to help you grow your business.</div>
</div>

<div class="ln-tip">
    <i class="fas fa-lightbulb"></i>
    Tip: record your sales and expenses in <a href="books">My Books</a> every week. Good records help us assess your business and can raise your credit score for your next loan.
</div>

<?php if (!$notes): ?>
    <div class="card">
        <div class="card-body">
            <div class="empty-state">
                <i class="fas fa-book-open" style="opacity:.5"></i>
                <h3>No notes yet</h3>
                <p>When your loan team shares learning notes with you, they will appear here and you will get an email.</p>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="ln-list">
        <?php foreach ($notes as $note): ?>
            <?php $read = !empty($note['viewed_at']); ?>
            <div class="ln-item<?= $read ? ' read' : '' ?>">
                <div style="min-width:0;flex:1 1 260px">
                    <h3><?= $e($note['title']) ?><?php if (!$read): ?><span class="ln-new">New</span><?php endif; ?></h3>
                    <?php if (trim((string)$note['description']) !== ''): ?>
                        <div class="fs-sm"><?= nl2br($e($note['description'])) ?></div>
                    <?php endif; ?>
                    <div class="ln-muted mt-1">Shared <?= $e(date('M j, Y', strtotime((string)$note['shared_at']))) ?> | <?= $e($note['original_name']) ?> (<?= $e(learning_file_size((int)$note['file_size'])) ?>)</div>
                </div>
                <div class="d-flex gap-2">
                    <a class="btn btn-primary btn-sm" href="learning_note_file.php?id=<?= (int)$note['id'] ?>" target="_blank" rel="noopener"><i class="fas fa-book-reader"></i> Open</a>
                    <a class="btn btn-ghost btn-sm" href="learning_note_file.php?id=<?= (int)$note['id'] ?>&amp;download=1"><i class="fas fa-download"></i> Download</a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require "footer.php"; ?>
