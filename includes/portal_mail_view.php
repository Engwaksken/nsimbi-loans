<?php
/**
 * Gmail-style mailbox UI shared by clients/messages.php, staff/messages.php
 * and admin/mailbox.php. Styles are scoped under .pm so it renders the same
 * inside each portal's layout.
 */

function pm_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function pm_initials(array $user): string
{
    $i = strtoupper(substr((string)($user['fname'] ?? ''), 0, 1) . substr((string)($user['lname'] ?? ''), 0, 1));
    return $i !== '' ? $i : strtoupper(substr((string)($user['username'] ?? 'U'), 0, 1));
}

function pm_avatar_color(int $id): string
{
    $palette = ['#2563eb', '#7c3aed', '#db2777', '#ea580c', '#0d9488', '#16a34a', '#0891b2', '#4f46e5'];
    return $palette[$id % count($palette)];
}

/** Render the mailbox for $me. $selfUrl is the page's own URL (e.g. "messages.php"). */
function pm_render(mysqli $conn, array $me, string $selfUrl): void
{
    pm_ensure_schema($conn);

    $meId     = (int)$me['id'];
    $folder   = array_key_exists((string)($_GET['folder'] ?? ''), PM_FOLDERS) ? (string)$_GET['folder'] : 'inbox';
    $search   = trim((string)($_GET['q'] ?? ''));
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $threadId = (int)($_GET['thread'] ?? 0);

    $thread = $threadId > 0 ? pm_thread($conn, $meId, $threadId) : null;
    if ($thread && !(int)$thread['is_read']) {
        pm_set_flag($conn, $meId, $threadId, 'is_read', 1);
        $thread['is_read'] = 1;
    }

    $list       = pm_list($conn, $meId, $folder, $search, $page);
    $counts     = pm_folder_counts($conn, $meId);
    $recipients = pm_recipients($conn, $me);
    $csrf       = pm_csrf();
    $isBorrower = in_array((int)$me['role'], PM_BORROWER_ROLES, true);

    $flash = $_SESSION['pm_flash'] ?? null;
    $old   = $_SESSION['pm_old'] ?? [];
    unset($_SESSION['pm_flash'], $_SESSION['pm_old']);

    $openCompose = !empty($old['compose']) || isset($_GET['compose']);
    $presetTo    = (int)($old['recipient_id'] ?? ($_GET['to'] ?? 0));

    $url = static function (array $extra = []) use ($selfUrl, $folder, $search): string {
        $q = array_filter(['folder' => $folder, 'q' => $search] + $extra, static fn($v) => $v !== '' && $v !== null && $v !== 0);
        return $selfUrl . ($q ? '?' . http_build_query($q) : '');
    };

    $icons = ['inbox' => 'fa-inbox', 'starred' => 'fa-star', 'sent' => 'fa-paper-plane', 'archive' => 'fa-archive'];
    ?>
    <style>
    .pm,.pm-modal-wrap,.pm-flash{--pm-primary:var(--primary,#2563eb);--pm-border:var(--border,#e5e7eb);--pm-muted:var(--text-muted,#6b7280);--pm-dark:var(--text-dark,#111827);--pm-bg:var(--body-bg,#f8fafc)}
    .pm .svg-inline--fa,.pm-modal-wrap .svg-inline--fa{width:1em;height:1em}
    .pm-modal-wrap *{box-sizing:border-box}
    .pm{display:grid;grid-template-columns:210px minmax(280px,380px) minmax(0,1fr);background:#fff;border:1px solid var(--pm-border);border-radius:16px;overflow:hidden;
        height:calc(100vh - 150px);min-height:560px;font-size:14px;color:var(--pm-dark);box-shadow:0 1px 3px rgba(15,23,42,.05)}
    .pm *{box-sizing:border-box}
    .pm a{text-decoration:none;color:inherit}
    .pm-rail{border-right:1px solid var(--pm-border);padding:14px 10px;background:var(--pm-bg);display:flex;flex-direction:column;gap:4px;overflow-y:auto}
    .pm-compose{display:flex;align-items:center;justify-content:center;gap:8px;padding:12px 16px;margin:0 2px 12px;border:0;border-radius:14px;background:var(--pm-primary);color:#fff;font-weight:700;font-size:14px;cursor:pointer;box-shadow:0 4px 12px rgba(37,99,235,.25)}
    .pm-compose:hover{filter:brightness(1.07)}
    .pm-folder{display:flex;align-items:center;gap:12px;padding:9px 14px;border-radius:0 20px 20px 0;margin-left:-10px;padding-left:24px;color:var(--pm-dark);font-weight:500}
    .pm-folder i{width:16px;text-align:center;color:var(--pm-muted)}
    .pm-folder:hover{background:rgba(15,23,42,.05)}
    .pm-folder.active{background:rgba(37,99,235,.12);color:var(--pm-primary);font-weight:700}
    .pm-folder.active i{color:var(--pm-primary)}
    .pm-folder .pm-count{margin-left:auto;font-size:12px;font-weight:700}
    .pm-help{margin-top:auto;padding:12px;font-size:12px;color:var(--pm-muted);line-height:1.45}
    .pm-list{display:flex;flex-direction:column;border-right:1px solid var(--pm-border);min-width:0}
    .pm-search{padding:12px;border-bottom:1px solid var(--pm-border)}
    .pm-search form{display:flex;align-items:center;gap:8px;background:var(--pm-bg);border:1px solid var(--pm-border);border-radius:24px;padding:0 6px 0 14px}
    .pm-search input{flex:1;border:0;background:transparent;padding:9px 0;font-size:14px;outline:none;min-width:0}
    .pm-search button{border:0;background:transparent;color:var(--pm-muted);cursor:pointer;padding:6px 8px;border-radius:50%}
    .pm-list-head{display:flex;align-items:center;justify-content:space-between;padding:8px 14px;font-size:12px;color:var(--pm-muted);border-bottom:1px solid var(--pm-border)}
    .pm-items{flex:1;overflow-y:auto}
    .pm-item{display:grid;grid-template-columns:auto 1fr auto;gap:10px;padding:11px 14px;border-bottom:1px solid var(--pm-border);cursor:pointer;position:relative}
    .pm-item:hover{background:var(--pm-bg);box-shadow:inset 0 -1px 0 var(--pm-border)}
    .pm-item.active{background:rgba(37,99,235,.08)}
    .pm-item.unread{background:#fff}
    .pm-item.unread::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--pm-primary)}
    .pm-item.unread .pm-from,.pm-item.unread .pm-subj{font-weight:800;color:var(--pm-dark)}
    .pm-item:not(.unread){background:#fafbfc}
    .pm-item:not(.unread).active{background:rgba(37,99,235,.08)}
    .pm-av{width:36px;height:36px;border-radius:50%;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0}
    .pm-item-main{min-width:0}
    .pm-from{font-size:13.5px;color:#374151;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .pm-from small{color:var(--pm-muted);font-weight:500}
    .pm-subj{font-size:13.5px;color:#374151;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:1px}
    .pm-snip{font-size:12.5px;color:var(--pm-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px}
    .pm-side{display:flex;flex-direction:column;align-items:flex-end;gap:6px;font-size:12px;color:var(--pm-muted)}
    .pm-item.unread .pm-side .pm-date{color:var(--pm-dark);font-weight:700}
    .pm-star{border:0;background:transparent;padding:0;cursor:pointer;color:#cbd5e1;font-size:14px;line-height:1}
    .pm-star.on{color:#f59e0b}
    .pm-star form{display:inline}
    .pm-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:8px;padding:40px 20px;color:var(--pm-muted);height:100%}
    .pm-empty > i{font-size:42px;opacity:.25}
    .pm-empty h3{margin:4px 0 0;font-size:16px;color:var(--pm-dark)}
    .pm-pager{display:flex;justify-content:space-between;padding:10px 14px;border-top:1px solid var(--pm-border);font-size:13px}
    .pm-pager a{color:var(--pm-primary);font-weight:600}
    .pm-read{display:flex;flex-direction:column;min-width:0;background:#fff}
    .pm-read-head{display:flex;align-items:flex-start;gap:12px;padding:16px 20px;border-bottom:1px solid var(--pm-border)}
    .pm-read-head h2{flex:1;margin:0;font-size:19px;font-weight:700;line-height:1.3;color:var(--pm-dark);word-break:break-word}
    .pm-tools{display:flex;gap:4px;flex-shrink:0}
    .pm-tools form{margin:0}
    .pm-tool{width:36px;height:36px;border-radius:50%;border:0;background:transparent;color:var(--pm-muted);cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:15px}
    .pm-tool:hover{background:var(--pm-bg);color:var(--pm-dark)}
    .pm-tool.on{color:#f59e0b}
    .pm-back{display:none}
    .pm-chips{padding:10px 20px 0;display:flex;flex-wrap:wrap;gap:6px;font-size:12px;color:var(--pm-muted)}
    .pm-chip{background:var(--pm-bg);border:1px solid var(--pm-border);border-radius:14px;padding:3px 10px}
    .pm-msgs{flex:1;overflow-y:auto;padding:8px 20px 20px}
    .pm-msg{display:grid;grid-template-columns:40px 1fr;gap:12px;padding:16px 0;border-bottom:1px solid var(--pm-border)}
    .pm-msg:last-child{border-bottom:0}
    .pm-msg .pm-av{width:40px;height:40px}
    .pm-msg-head{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:baseline}
    .pm-msg-name{font-weight:700;color:var(--pm-dark)}
    .pm-msg-name small{font-weight:500;color:var(--pm-muted);margin-left:4px}
    .pm-msg-time{font-size:12px;color:var(--pm-muted)}
    .pm-msg-body{margin-top:8px;white-space:pre-wrap;word-wrap:break-word;line-height:1.6;color:#1f2937}
    .pm-mine .pm-msg-name::after{content:'You';margin-left:6px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;background:var(--pm-bg);border:1px solid var(--pm-border);border-radius:8px;padding:1px 6px;color:var(--pm-muted)}
    .pm-reply{border-top:1px solid var(--pm-border);padding:14px 20px;background:var(--pm-bg)}
    .pm-reply-box{background:#fff;border:1px solid var(--pm-border);border-radius:14px;overflow:hidden;box-shadow:0 1px 2px rgba(15,23,42,.05)}
    .pm-reply-box:focus-within{border-color:var(--pm-primary);box-shadow:0 0 0 3px rgba(37,99,235,.12)}
    .pm-reply textarea,.pm-modal textarea{width:100%;border:0;padding:12px 14px;font:inherit;font-size:14px;resize:vertical;min-height:90px;outline:none;display:block}
    .pm-reply-foot{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 10px 8px 14px;border-top:1px solid var(--pm-border);font-size:12px;color:var(--pm-muted)}
    .pm-send{display:inline-flex;align-items:center;gap:8px;border:0;border-radius:20px;background:var(--pm-primary);color:#fff;font-weight:700;padding:9px 20px;cursor:pointer;font-size:14px}
    .pm-send:disabled{opacity:.6;cursor:wait}
    .pm-flash{display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:12px;margin-bottom:12px;font-size:14px}
    .pm-flash.ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
    .pm-flash.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
    .pm-modal-wrap{position:fixed;inset:0;background:rgba(15,23,42,.45);display:none;align-items:flex-end;justify-content:flex-end;padding:0 24px;z-index:10050}
    .pm-modal-wrap.open{display:flex}
    .pm-modal{width:560px;max-width:100%;max-height:90vh;background:#fff;border-radius:14px 14px 0 0;box-shadow:0 -10px 40px rgba(15,23,42,.25);display:flex;flex-direction:column;overflow:hidden}
    .pm-modal-head{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;background:#1f2937;color:#fff;font-weight:600}
    .pm-modal-head button{border:0;background:transparent;color:#fff;cursor:pointer;font-size:16px;opacity:.8}
    .pm-field{display:flex;align-items:center;gap:10px;border-bottom:1px solid var(--pm-border);padding:0 16px}
    .pm-field label{font-size:13px;color:var(--pm-muted);min-width:56px}
    .pm-field input,.pm-field select{flex:1;border:0;padding:12px 0;font:inherit;font-size:14px;outline:none;background:transparent;min-width:0}
    .pm-filter{border-bottom:1px solid var(--pm-border);padding:8px 16px;background:var(--pm-bg)}
    .pm-filter input{width:100%;border:1px solid var(--pm-border);border-radius:8px;padding:7px 10px;font-size:13px}
    .pm-modal-body{flex:1;overflow-y:auto}
    .pm-modal textarea{min-height:220px;padding:14px 16px}
    .pm-modal-foot{display:flex;align-items:center;justify-content:space-between;padding:10px 16px;border-top:1px solid var(--pm-border)}
    .pm-err{display:none;margin:10px 16px 0;padding:8px 12px;border-radius:8px;background:#fef2f2;color:#991b1b;font-size:13px}
    .pm-hint{font-size:12px;color:var(--pm-muted)}
    @media(max-width:1100px){.pm{grid-template-columns:64px minmax(260px,340px) minmax(0,1fr)}
        .pm-folder span,.pm-folder .pm-count,.pm-help,.pm-compose span{display:none}.pm-folder{justify-content:center;padding:10px;margin:0;border-radius:12px}.pm-compose{padding:12px;border-radius:16px}}
    @media(max-width:820px){.pm{grid-template-columns:1fr;height:auto;min-height:0}
        .pm-rail{flex-direction:row;overflow-x:auto;border-right:0;border-bottom:1px solid var(--pm-border);padding:8px;gap:6px}
        .pm-compose{margin:0;padding:8px 14px;border-radius:20px}.pm-compose span{display:inline}
        .pm-folder{margin:0;padding:8px 12px;border-radius:20px;white-space:nowrap}.pm-folder span,.pm-folder .pm-count{display:inline}
        .pm-list{border-right:0;max-height:none}.pm-items{max-height:none;overflow:visible}
        .pm[data-thread="1"] .pm-list,.pm[data-thread="1"] .pm-rail{display:none}
        .pm[data-thread="0"] .pm-read{display:none}
        .pm-back{display:inline-flex}.pm-msgs{overflow:visible}
        .pm-modal-wrap{padding:0;align-items:stretch}.pm-modal{width:100%;max-height:none;border-radius:0}}
    </style>

    <?php if ($flash): ?>
        <div class="pm-flash <?= $flash['type'] === 'error' ? 'err' : 'ok' ?>" role="status">
            <i class="fas <?= $flash['type'] === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle' ?>"></i>
            <span><?= pm_e($flash['message']) ?></span>
        </div>
    <?php endif; ?>

    <div class="pm" data-thread="<?= $thread ? '1' : '0' ?>">
        <aside class="pm-rail" aria-label="Folders">
            <button type="button" class="pm-compose" data-pm-compose><i class="fas fa-pen"></i> <span>Compose</span></button>
            <?php foreach (PM_FOLDERS as $key => $label): ?>
                <?php $count = $counts[$key] ?? 0; ?>
                <a class="pm-folder <?= $folder === $key ? 'active' : '' ?>" href="<?= pm_e($selfUrl . '?folder=' . $key) ?>" title="<?= pm_e($label) ?>">
                    <i class="fas <?= $icons[$key] ?>"></i><span><?= pm_e($label) ?></span>
                    <?php if ($count > 0 && in_array($key, ['inbox', 'starred'], true)): ?><span class="pm-count"><?= $count ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
            <div class="pm-help">
                <?php if ($isBorrower): ?>
                    Messages go to your loan team and are also emailed to them. Replies arrive here and in your email.
                <?php else: ?>
                    Messages are emailed to the client as well. Their replies arrive here.
                <?php endif; ?>
            </div>
        </aside>

        <section class="pm-list" aria-label="Conversations">
            <div class="pm-search">
                <form method="get" action="<?= pm_e($selfUrl) ?>" role="search">
                    <input type="hidden" name="folder" value="<?= pm_e($folder) ?>">
                    <i class="fas fa-search" style="color:var(--pm-muted)"></i>
                    <input type="search" name="q" value="<?= pm_e($search) ?>" placeholder="Search mail" aria-label="Search mail">
                    <?php if ($search !== ''): ?><a href="<?= pm_e($selfUrl . '?folder=' . $folder) ?>" class="pm-tool" title="Clear search"><i class="fas fa-times"></i></a><?php endif; ?>
                </form>
            </div>
            <div class="pm-list-head">
                <span><?= pm_e(PM_FOLDERS[$folder]) ?><?= $search !== '' ? ' | results for "' . pm_e($search) . '"' : '' ?></span>
                <?php if ($page > 1 || $list['has_more']): ?><span>Page <?= $page ?></span><?php endif; ?>
            </div>

            <div class="pm-items">
                <?php if (!$list['rows']): ?>
                    <div class="pm-empty">
                        <i class="fas <?= $search !== '' ? 'fa-search' : $icons[$folder] ?>"></i>
                        <h3><?= $search !== '' ? 'No matching conversations' : 'Nothing in ' . pm_e(strtolower(PM_FOLDERS[$folder])) ?></h3>
                        <?php if ($folder === 'inbox' && $search === ''): ?>
                            <p>Have a question about your loan? Write to your loan officer.</p>
                            <button type="button" class="pm-send" data-pm-compose><i class="fas fa-pen"></i> Compose</button>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($list['rows'] as $row): ?>
                        <?php
                        $rowId = (int)$row['id'];
                        $unread = !(int)$row['is_read'];
                        $names = trim((string)$row['other_names']) ?: 'Unknown';
                        $fromMe = (int)$row['last_sender'] === $meId;
                        $snippet = preg_replace('/\s+/', ' ', (string)$row['last_body']);
                        $href = $url(['thread' => $rowId, 'page' => $page > 1 ? $page : 0]);
                        ?>
                        <div class="pm-item <?= $unread ? 'unread' : '' ?> <?= $thread && (int)$thread['id'] === $rowId ? 'active' : '' ?>" data-href="<?= pm_e($href) ?>">
                            <div class="pm-av" style="background:<?= pm_avatar_color(crc32($names) & 0xffff) ?>"><?= pm_e(strtoupper(substr($names, 0, 1))) ?></div>
                            <a class="pm-item-main" href="<?= pm_e($href) ?>">
                                <div class="pm-from"><?= $folder === 'sent' || $fromMe ? '<small>To:</small> ' : '' ?><?= pm_e($names) ?><?= (int)$row['message_count'] > 1 ? ' <small>(' . (int)$row['message_count'] . ')</small>' : '' ?></div>
                                <div class="pm-subj"><?= pm_e($row['subject']) ?></div>
                                <div class="pm-snip"><?= $fromMe ? 'You: ' : '' ?><?= pm_e(function_exists('mb_substr') ? mb_substr($snippet, 0, 140) : substr($snippet, 0, 140)) ?></div>
                            </a>
                            <div class="pm-side">
                                <span class="pm-date" title="<?= pm_e(date('D, j M Y H:i', strtotime((string)$row['last_message_at']))) ?>"><?= pm_e(pm_short_date((string)$row['last_message_at'])) ?></span>
                                <form method="post" action="<?= pm_e($selfUrl) ?>">
                                    <input type="hidden" name="csrf" value="<?= pm_e($csrf) ?>">
                                    <input type="hidden" name="folder" value="<?= pm_e($folder) ?>">
                                    <input type="hidden" name="thread_id" value="<?= $rowId ?>">
                                    <input type="hidden" name="action" value="<?= (int)$row['is_starred'] ? 'unstar' : 'star' ?>">
                                    <button type="submit" class="pm-star <?= (int)$row['is_starred'] ? 'on' : '' ?>" title="<?= (int)$row['is_starred'] ? 'Unstar' : 'Star' ?>" aria-label="Star">
                                        <i class="<?= (int)$row['is_starred'] ? 'fas' : 'far' ?> fa-star"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if ($page > 1 || $list['has_more']): ?>
                <div class="pm-pager">
                    <?php if ($page > 1): ?><a href="<?= pm_e($url(['page' => $page - 1 > 1 ? $page - 1 : 0])) ?>"><i class="fas fa-chevron-left"></i> Newer</a><?php else: ?><span></span><?php endif; ?>
                    <?php if ($list['has_more']): ?><a href="<?= pm_e($url(['page' => $page + 1])) ?>">Older <i class="fas fa-chevron-right"></i></a><?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="pm-read" aria-label="Conversation">
            <?php if (!$thread): ?>
                <div class="pm-empty">
                    <i class="fas fa-envelope-open-text"></i>
                    <h3><?= $threadId > 0 ? 'Conversation not found' : 'Select a conversation' ?></h3>
                    <p>Choose a message from the list, or start a new one.</p>
                </div>
            <?php else: ?>
                <?php
                $tid = (int)$thread['id'];
                $hidden = '<input type="hidden" name="csrf" value="' . pm_e($csrf) . '"><input type="hidden" name="folder" value="' . pm_e($folder) . '"><input type="hidden" name="thread_id" value="' . $tid . '">';
                ?>
                <div class="pm-read-head">
                    <a class="pm-tool pm-back" href="<?= pm_e($url(['page' => $page > 1 ? $page : 0])) ?>" title="Back"><i class="fas fa-arrow-left"></i></a>
                    <h2><?= pm_e($thread['subject']) ?></h2>
                    <div class="pm-tools">
                        <form method="post" action="<?= pm_e($selfUrl) ?>"><?= $hidden ?><input type="hidden" name="stay" value="1">
                            <input type="hidden" name="action" value="<?= (int)$thread['is_starred'] ? 'unstar' : 'star' ?>">
                            <button class="pm-tool <?= (int)$thread['is_starred'] ? 'on' : '' ?>" title="<?= (int)$thread['is_starred'] ? 'Unstar' : 'Star' ?>"><i class="<?= (int)$thread['is_starred'] ? 'fas' : 'far' ?> fa-star"></i></button>
                        </form>
                        <form method="post" action="<?= pm_e($selfUrl) ?>"><?= $hidden ?>
                            <input type="hidden" name="action" value="<?= (int)$thread['is_archived'] ? 'unarchive' : 'archive' ?>">
                            <button class="pm-tool" title="<?= (int)$thread['is_archived'] ? 'Move to inbox' : 'Archive' ?>"><i class="fas <?= (int)$thread['is_archived'] ? 'fa-inbox' : 'fa-archive' ?>"></i></button>
                        </form>
                        <form method="post" action="<?= pm_e($selfUrl) ?>"><?= $hidden ?>
                            <input type="hidden" name="action" value="unread">
                            <button class="pm-tool" title="Mark as unread"><i class="fas fa-envelope"></i></button>
                        </form>
                    </div>
                </div>
                <div class="pm-chips">
                    <?php foreach ($thread['others'] as $other): ?>
                        <span class="pm-chip"><?= pm_e(pm_name($other)) ?> | <?= pm_e(pm_role_label((int)$other['role'])) ?></span>
                    <?php endforeach; ?>
                </div>
                <div class="pm-msgs" id="pmMsgs">
                    <?php foreach ($thread['messages'] as $msg): ?>
                        <?php $mine = (int)$msg['sender_id'] === $meId; ?>
                        <article class="pm-msg <?= $mine ? 'pm-mine' : '' ?>">
                            <div class="pm-av" style="background:<?= pm_avatar_color((int)$msg['sender_id']) ?>"><?= pm_e(pm_initials($msg)) ?></div>
                            <div>
                                <div class="pm-msg-head">
                                    <span class="pm-msg-name"><?= pm_e(pm_name($msg)) ?><small><?= pm_e(pm_role_label((int)$msg['role'])) ?></small></span>
                                    <time class="pm-msg-time" datetime="<?= pm_e(date('c', strtotime((string)$msg['created_at']))) ?>"><?= pm_e(date('D, j M Y, H:i', strtotime((string)$msg['created_at']))) ?></time>
                                </div>
                                <div class="pm-msg-body"><?= pm_e($msg['body']) ?></div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <form class="pm-reply" method="post" action="<?= pm_e($selfUrl) ?>" data-pm-form>
                    <?= $hidden ?>
                    <input type="hidden" name="action" value="reply">
                    <div class="pm-reply-box">
                        <textarea name="body" rows="3" maxlength="10000" placeholder="Reply to <?= pm_e(implode(', ', array_map('pm_name', $thread['others']))) ?>..." required><?= pm_e($old['body'] ?? '') ?></textarea>
                        <div class="pm-reply-foot">
                            <span>Ctrl + Enter to send | also emailed</span>
                            <button type="submit" class="pm-send"><i class="fas fa-paper-plane"></i> Send</button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </section>
    </div>

    <div class="pm-modal-wrap <?= $openCompose ? 'open' : '' ?>" id="pmCompose" role="dialog" aria-modal="true" aria-labelledby="pmComposeTitle">
        <form class="pm-modal" method="post" action="<?= pm_e($selfUrl) ?>" data-pm-form novalidate>
            <input type="hidden" name="csrf" value="<?= pm_e($csrf) ?>">
            <input type="hidden" name="folder" value="<?= pm_e($folder) ?>">
            <input type="hidden" name="action" value="send">
            <div class="pm-modal-head">
                <span id="pmComposeTitle">New message</span>
                <button type="button" data-pm-close aria-label="Close"><i class="fas fa-times"></i></button>
            </div>
            <div class="pm-err" id="pmComposeErr"></div>
            <?php if (!$isBorrower && count($recipients) > 8): ?>
                <div class="pm-filter"><input type="search" id="pmToFilter" placeholder="Search clients by name or email" aria-label="Filter recipients"></div>
            <?php endif; ?>
            <div class="pm-field">
                <label for="pmTo">To</label>
                <select name="recipient_id" id="pmTo" required>
                    <option value="">Choose <?= $isBorrower ? 'your loan officer' : 'a client' ?>...</option>
                    <?php if ($isBorrower): ?>
                        <?php foreach ($recipients as $r): ?>
                            <option value="<?= $r['id'] ?>" <?= ($presetTo === $r['id'] || (!$presetTo && $r['assigned'])) ? 'selected' : '' ?>>
                                <?= pm_e($r['name']) ?> - <?= pm_e($r['assigned'] ? 'Your loan officer' : $r['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php foreach ($recipients as $r): ?>
                            <option value="<?= $r['id'] ?>" data-search="<?= pm_e(strtolower($r['name'] . ' ' . $r['email'])) ?>" <?= $presetTo === $r['id'] ? 'selected' : '' ?>>
                                <?= pm_e($r['name']) ?> (<?= pm_e($r['label']) ?>)<?= $r['email'] ? ' - ' . pm_e($r['email']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div class="pm-field">
                <label for="pmSubject">Subject</label>
                <input type="text" name="subject" id="pmSubject" maxlength="200" value="<?= pm_e($old['subject'] ?? '') ?>" required>
            </div>
            <div class="pm-modal-body">
                <textarea name="body" id="pmBody" maxlength="10000" placeholder="Write your message..." required><?= pm_e(!empty($old['compose']) ? ($old['body'] ?? '') : '') ?></textarea>
            </div>
            <div class="pm-modal-foot">
                <button type="submit" class="pm-send"><i class="fas fa-paper-plane"></i> Send</button>
                <span class="pm-hint">Also sent by email</span>
            </div>
        </form>
    </div>

    <script>
    (function () {
        var wrap = document.getElementById('pmCompose');
        var err = document.getElementById('pmComposeErr');

        function openCompose() {
            wrap.classList.add('open');
            err.style.display = 'none';
            var to = document.getElementById('pmTo');
            (to.value ? document.getElementById('pmSubject') : to).focus();
        }
        function closeCompose() { wrap.classList.remove('open'); }

        document.addEventListener('click', function (e) {
            if (e.target.closest('[data-pm-compose]')) { e.preventDefault(); openCompose(); return; }
            if (e.target.closest('[data-pm-close]') || e.target === wrap) { closeCompose(); return; }
            // Whole row is clickable, except the star button.
            var row = e.target.closest('.pm-item');
            if (row && !e.target.closest('form') && !e.target.closest('a')) { window.location = row.dataset.href; }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && wrap.classList.contains('open')) closeCompose();
        });

        var filter = document.getElementById('pmToFilter');
        if (filter) {
            filter.addEventListener('input', function () {
                var q = this.value.trim().toLowerCase();
                Array.prototype.forEach.call(document.querySelectorAll('#pmTo option[data-search]'), function (o) {
                    o.hidden = q !== '' && o.dataset.search.indexOf(q) === -1;
                });
            });
        }

        Array.prototype.forEach.call(document.querySelectorAll('[data-pm-form]'), function (form) {
            var area = form.querySelector('textarea');
            area.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); form.requestSubmit ? form.requestSubmit() : form.submit(); }
            });
            form.addEventListener('submit', function (e) {
                var problem = '';
                if (form.recipient_id && !form.recipient_id.value) problem = 'Please choose who to send this to.';
                else if (form.subject && !form.subject.value.trim()) problem = 'Please add a subject.';
                else if (!area.value.trim()) problem = 'Please write a message.';
                if (problem) {
                    e.preventDefault();
                    if (form.closest('#pmCompose')) { err.textContent = problem; err.style.display = 'block'; }
                    else { area.focus(); }
                    return;
                }
                var btn = form.querySelector('.pm-send');
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
            });
        });

        var msgs = document.getElementById('pmMsgs');
        if (msgs) msgs.scrollTop = msgs.scrollHeight;

        window.addEventListener('pageshow', function () {
            Array.prototype.forEach.call(document.querySelectorAll('.pm-send'), function (b) {
                if (b.disabled) { b.disabled = false; b.innerHTML = '<i class="fas fa-paper-plane"></i> Send'; }
            });
        });
    })();
    </script>
    <?php
}
