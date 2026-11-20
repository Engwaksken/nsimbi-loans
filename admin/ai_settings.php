<?php
declare(strict_types=1);

/*
 * System > AI Settings: add AI providers (Anthropic Claude, OpenAI, Google
 * Gemini), test them and choose the one the system uses to read clients'
 * credit documents. Reviewers (role 1) only. Keys are stored encrypted and
 * never shown again after saving.
 */

$phead = 'AI Settings';
require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/includes/ai_gateway.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['logged_in']) || (int)($_SESSION['role'] ?? 0) !== 1) {
    header('Location: dashboard.php');
    exit();
}

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (empty($_SESSION['ai_settings_csrf'])) {
    $_SESSION['ai_settings_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['ai_settings_csrf'];

function ai_flash(string $message, string $type = 'success'): void
{
    $_SESSION['ai_flash'] = [$message, $type];
}

/* ---------- Actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        ai_flash('Your session expired. Please try again.', 'error');
        header('Location: ai_settings.php');
        exit;
    }
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    try {
        switch ($action) {
            case 'save':
                $savedId = ai_provider_save(
                    $conn,
                    $id > 0 ? $id : null,
                    (string)($_POST['provider'] ?? ''),
                    (string)($_POST['label'] ?? ''),
                    (string)($_POST['model'] ?? ''),
                    (string)($_POST['api_key'] ?? '')
                );
                ai_flash($id > 0 ? 'Provider updated.' : 'Provider added. Use Test to check the key works.');
                break;
            case 'activate':
                ai_provider_activate($conn, $id);
                ai_flash('Active AI provider changed.');
                break;
            case 'test':
                $p = ai_provider_find($conn, $id);
                if (!$p) {
                    throw new RuntimeException('Provider not found.');
                }
                ai_test_provider($conn, $p);
                ai_flash('Connection works: ' . $p['label'] . ' (' . $p['model'] . ') answered correctly.');
                break;
            case 'delete':
                $p = ai_provider_find($conn, $id);
                if ($p && (int)$p['is_active'] === 1 && count(ai_providers_all($conn)) > 1) {
                    throw new RuntimeException('Make another provider active before deleting the active one.');
                }
                ai_provider_delete($conn, $id);
                ai_flash('Provider deleted.');
                break;
        }
    } catch (Throwable $e) {
        ai_flash($e->getMessage(), 'error');
    }
    header('Location: ai_settings.php');
    exit;
}

/* ---------- View ---------- */
$schemaError = '';
try {
    $providers = ai_providers_all($conn);
} catch (Throwable $e) {
    $providers = [];
    $schemaError = 'Could not load AI providers: ' . $e->getMessage();
}
$editId = (int)($_GET['edit'] ?? 0);
$editing = $editId > 0 ? ai_provider_find($conn, $editId) : null;
$secretMissing = (string)(getenv('APP_KEY') ?: getenv('JWT_SECRET') ?: '') === '';

[$flashMsg, $flashType] = $_SESSION['ai_flash'] ?? ['', ''];
unset($_SESSION['ai_flash']);

require "nav-head.php";
?>

<style>
.ai-page { padding: 1rem; }
.ai-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 1.1rem; margin-bottom: 1rem; }
.ai-card h5 { font-weight: 800; margin: 0 0 .25rem; }
.ai-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1rem; }
.ai-provider { border: 1px solid #e5e7eb; border-radius: 14px; padding: 1rem; display: flex; flex-direction: column; gap: .5rem; }
.ai-provider.active { border-color: #16a34a; box-shadow: 0 0 0 2px rgba(22,163,74,.15); }
.ai-provider .top { display: flex; justify-content: space-between; align-items: start; gap: .5rem; }
.ai-provider .name { font-weight: 800; }
.ai-meta { font-size: .85rem; color: #475569; }
.ai-meta code { background: #f1f5f9; padding: .05rem .35rem; border-radius: 6px; }
.ai-badge { font-size: .72rem; font-weight: 700; border-radius: 999px; padding: .15rem .6rem; }
.ai-badge.on { background: #dcfce7; color: #166534; }
.ai-badge.ok { background: #dbeafe; color: #1d4ed8; }
.ai-badge.bad { background: #fee2e2; color: #b91c1c; }
.ai-actions { display: flex; gap: .4rem; flex-wrap: wrap; margin-top: auto; }
.ai-actions form { margin: 0; }
.ai-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: .8rem; }
.ai-form label { font-weight: 700; font-size: .85rem; display: block; margin-bottom: .25rem; }
.ai-form input, .ai-form select { width: 100%; border: 1px solid #d1d5db; border-radius: 10px; padding: .55rem .7rem; }
.ai-form small { color: #64748b; }
.ai-flash { padding: .7rem 1rem; border-radius: 10px; margin-bottom: 1rem; }
.ai-flash.success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.ai-flash.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.ai-warn { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; border-radius: 10px; padding: .7rem 1rem; margin-bottom: 1rem; }
</style>

<div class="container-fluid ai-page">
    <?php if ($flashMsg !== ''): ?>
        <div class="ai-flash <?= h($flashType) ?>"><?= h($flashMsg) ?></div>
    <?php endif; ?>
    <?php if ($schemaError !== ''): ?>
        <div class="ai-flash error"><?= h($schemaError) ?></div>
    <?php endif; ?>
    <?php if ($secretMissing): ?>
        <div class="ai-warn"><i class="fas fa-exclamation-triangle me-1"></i>
            Add <code>APP_KEY=</code> with a long random value to the server <code>.env</code> file before saving API keys. It is used to encrypt them.
        </div>
    <?php endif; ?>

    <div class="ai-card">
        <h5><i class="fas fa-microchip text-primary me-2"></i>AI Settings</h5>
        <p class="text-muted mb-0">
            The active provider reads clients' mobile money statements, utility bills and business records and suggests a credit score.
            Staff still approve every result under Loans &gt; Credit Documents. Only one provider is active at a time.
        </p>
    </div>

    <div class="ai-card">
        <h5><?= count($providers) ?> provider<?= count($providers) === 1 ? '' : 's' ?></h5>
        <?php if (!$providers): ?>
            <p class="text-muted mb-0">No AI provider yet. Add one below; the first one you add becomes active.</p>
        <?php else: ?>
            <div class="ai-grid mt-2">
                <?php foreach ($providers as $p): ?>
                    <?php $isActive = (int)$p['is_active'] === 1; ?>
                    <div class="ai-provider<?= $isActive ? ' active' : '' ?>">
                        <div class="top">
                            <div>
                                <div class="name"><?= h($p['label']) ?></div>
                                <div class="ai-meta"><?= h(AI_PROVIDERS[$p['provider']]['label'] ?? $p['provider']) ?></div>
                            </div>
                            <?php if ($isActive): ?><span class="ai-badge on">Active</span><?php endif; ?>
                        </div>
                        <div class="ai-meta">Model: <code><?= h($p['model']) ?></code></div>
                        <div class="ai-meta">Key: <code>....<?= h($p['key_last4']) ?></code></div>
                        <div class="ai-meta">
                            <?php if ($p['last_test_ok'] === null): ?>
                                Not tested yet
                            <?php elseif ((int)$p['last_test_ok'] === 1): ?>
                                <span class="ai-badge ok">Test passed</span> <?= h(date('d M Y H:i', strtotime((string)$p['last_test_at']))) ?>
                            <?php else: ?>
                                <span class="ai-badge bad">Test failed</span> <?= h($p['last_error']) ?>
                            <?php endif; ?>
                        </div>
                        <div class="ai-actions">
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                <button class="btn btn-sm btn-outline-primary" name="action" value="test" type="submit"><i class="fas fa-plug me-1"></i>Test</button>
                            </form>
                            <?php if (!$isActive): ?>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                    <button class="btn btn-sm btn-success" name="action" value="activate" type="submit"><i class="fas fa-check me-1"></i>Make active</button>
                                </form>
                            <?php endif; ?>
                            <a class="btn btn-sm btn-light" href="ai_settings.php?edit=<?= (int)$p['id'] ?>#providerForm"><i class="fas fa-edit me-1"></i>Edit</a>
                            <form method="post" onsubmit="return confirm('Delete this AI provider?');">
                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" name="action" value="delete" type="submit"><i class="fas fa-trash"></i></button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="ai-card" id="providerForm">
        <h5><?= $editing ? 'Edit ' . h($editing['label']) : 'Add a provider' ?></h5>
        <form method="post" class="mt-2">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
            <div class="ai-form">
                <div>
                    <label for="aiProvider">Provider</label>
                    <select id="aiProvider" name="provider" required>
                        <?php foreach (AI_PROVIDERS as $key => $meta): ?>
                            <option value="<?= h($key) ?>"
                                    data-model="<?= h($meta['default_model']) ?>"
                                    data-models="<?= h($meta['models_hint']) ?>"
                                    data-key="<?= h($meta['key_hint']) ?>"
                                <?= ($editing['provider'] ?? 'anthropic') === $key ? 'selected' : '' ?>><?= h($meta['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="aiLabel">Name (optional)</label>
                    <input id="aiLabel" name="label" maxlength="100" value="<?= h($editing['label'] ?? '') ?>" placeholder="e.g. Claude production">
                </div>
                <div>
                    <label for="aiModel">Model</label>
                    <input id="aiModel" name="model" maxlength="100" required value="<?= h($editing['model'] ?? AI_PROVIDERS['anthropic']['default_model']) ?>">
                    <small id="aiModelHint"></small>
                </div>
                <div>
                    <label for="aiKey">API key</label>
                    <input id="aiKey" name="api_key" type="password" autocomplete="new-password" <?= $editing ? '' : 'required' ?>
                           placeholder="<?= $editing ? 'Leave blank to keep the current key' : '' ?>">
                    <small id="aiKeyHint"></small>
                </div>
            </div>
            <div class="mt-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i><?= $editing ? 'Save changes' : 'Add provider' ?></button>
                <?php if ($editing): ?><a href="ai_settings.php" class="btn btn-light">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var sel = document.getElementById('aiProvider');
    var model = document.getElementById('aiModel');
    var editing = <?= $editing ? 'true' : 'false' ?>;
    function sync(changed) {
        var opt = sel.options[sel.selectedIndex];
        document.getElementById('aiModelHint').textContent = 'e.g. ' + opt.dataset.models;
        document.getElementById('aiKeyHint').textContent = opt.dataset.key;
        if (changed && !editing) { model.value = opt.dataset.model || ''; }
    }
    sel.addEventListener('change', function () { sync(true); });
    sync(false);
})();
</script>

<?php require "footer.php"; ?>
