<?php
declare(strict_types=1);

/**
 * Books dashboard model + voice/manual ledger entry.
 * Requires includes/books_ledger.php and an authenticated session.
 * Usage from books.php (BEFORE any HTML output):
 *   require_once __DIR__ . '/path/to/books-dashboard.php';
 *   books_dash_handle_post($conn, (int)$_SESSION['user_id']);
 *   $booksModel = books_dashboard_view_model($conn, (int)$_SESSION['user_id'], $_GET);
 *   ... render existing dashboard ...
 *   books_dash_render_entry_widget();
 *   books_dash_render_voice_script();
 */
require_once __DIR__ . '/../../includes/books_ledger.php';

const BOOKS_DASH_SORT_COLUMNS = ['type', 'entry_date', 'item_name', 'quantity', 'unit', 'amount'];
const BOOKS_DASH_PAGE_SIZES = [25, 50, 100];
const BOOKS_DASH_DEFAULT_PAGE_SIZE = 25;
const BOOKS_DASH_ENTRY_ACTION = 'create_book_entry';
const BOOKS_DASH_UPDATE_ACTION = 'update_book_entry';
const BOOKS_DASH_DELETE_ACTION = 'delete_book_entry';

function books_dash_escape(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function books_dash_type_badge(string $type): string
{
    if (books_is_money_in($type)) return 'badge-success';
    return books_is_money_out($type) ? 'badge-warning' : 'badge-muted';
}

function books_dash_query(array $model, array $overrides = []): string
{
    $params = [
        'cadence' => $model['cadence'], 'offset' => $model['offset'],
        'book_type' => $model['type'], 'sort' => $model['sort'],
        'dir' => $model['dir'], 'page' => $model['page'],
        'page_size' => $model['page_size'],
    ];
    return 'books.php?' . http_build_query(array_merge($params, $overrides));
}

function books_dash_sort_href(array $model, string $column): string
{
    $nextDir = ($model['sort'] === $column && $model['dir'] === 'asc') ? 'desc' : 'asc';
    return books_dash_query($model, ['sort' => $column, 'dir' => $nextDir, 'page' => 1]);
}

function books_dash_sort_icon(array $model, string $column): string
{
    if ($model['sort'] !== $column) return 'fa-sort text-muted';
    return $model['dir'] === 'asc' ? 'fa-sort-up' : 'fa-sort-down';
}

function books_dash_csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('Start the authenticated PHP session before loading the books dashboard.');
    }
    if (empty($_SESSION['books_dash_csrf'])) {
        $_SESSION['books_dash_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['books_dash_csrf'];
}

function books_dash_entry_options(): array
{
    // The ledger's own list is authoritative: do not invent database enum values.
    return array_values(array_filter(BOOKS_ENTRY_TYPES, 'is_string'));
}

function books_dash_handle_post(mysqli $conn, int $userId): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
        || !in_array(($_POST['books_action'] ?? ''), [BOOKS_DASH_ENTRY_ACTION, BOOKS_DASH_UPDATE_ACTION, BOOKS_DASH_DELETE_ACTION], true)) return;

    if ($userId <= 0 || session_status() !== PHP_SESSION_ACTIVE) {
        http_response_code(403);
        exit('Please log in to add a ledger record.');
    }
    $token = (string)($_POST['csrf_token'] ?? '');
    if ($token === '' || !hash_equals(books_dash_csrf_token(), $token)) {
        http_response_code(403);
        exit('Security token expired. Refresh the page and try again.');
    }

    $action = (string)$_POST['books_action'];
    $entryId = filter_var($_POST['entry_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($action !== BOOKS_DASH_ENTRY_ACTION && !$entryId) {
        $_SESSION['books_dash_flash'] = ['kind' => 'danger', 'message' => 'Choose a valid record.'];
        header('Location: books.php', true, 303);
        exit;
    }
    if ($action === BOOKS_DASH_DELETE_ACTION) {
        try {
            $delete = $conn->prepare("DELETE FROM book_entries WHERE id = ? AND user_id = ? AND status = 'confirmed'");
            if (!$delete) throw new RuntimeException('Delete prepare failed');
            $delete->bind_param('ii', $entryId, $userId);
            $delete->execute();
            $affected = $delete->affected_rows;
            $delete->close();
            $_SESSION['books_dash_flash'] = ['kind' => $affected ? 'success' : 'warning', 'message' => $affected ? 'Record permanently deleted.' : 'Record was not found or is not available to delete.'];
        } catch (Throwable $e) {
            error_log('books delete: ' . $e->getMessage());
            $_SESSION['books_dash_flash'] = ['kind' => 'danger', 'message' => 'Record could not be deleted.'];
        }
        header('Location: books.php', true, 303);
        exit;
    }
    $type = trim((string)($_POST['type'] ?? ''));
    $item = trim((string)($_POST['item_name'] ?? ''));
    $unit = trim((string)($_POST['unit'] ?? ''));
    $quantityText = trim((string)($_POST['quantity'] ?? ''));
    $amountText = trim((string)($_POST['amount'] ?? ''));
    $dateText = trim((string)($_POST['entry_date'] ?? ''));
    $source = (string)($_POST['input_source'] ?? 'manual');

    $errors = [];
    if (!in_array($type, books_dash_entry_options(), true)) $errors[] = 'Choose a valid ledger type.';
    if ($item === '' || strlen($item) > 255) $errors[] = 'Item name must be between 1 and 255 characters.';
    if (strlen($unit) > 50) $errors[] = 'Unit must be at most 50 characters.';
    if (!is_numeric($quantityText) || (float)$quantityText <= 0 || (float)$quantityText > 999999999) {
        $errors[] = 'Quantity must be greater than zero.';
    }
    if (!is_numeric($amountText) || (float)$amountText <= 0 || (float)$amountText > 999999999999) {
        $errors[] = 'Amount must be greater than zero.';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateText);
    if (!$date || $date->format('Y-m-d') !== $dateText) {
        $errors[] = 'Choose a valid record date.';
    } elseif ($date > new DateTimeImmutable('today')) {
        $errors[] = 'The record date cannot be in the future.';
    }

    if ($errors) {
        $_SESSION['books_dash_flash'] = ['kind' => 'danger', 'message' => implode(' ', $errors)];
        $_SESSION['books_dash_old'] = $_POST;
    } else {
        try {
            // Store confirmation as required by the dashboard's existing WHERE condition.
            // Detect entry_date's column type to avoid relying on DB implicit date coercion.
            $entryDate = $dateText . ' ' . date('H:i:s');
            $sql = 'INSERT INTO book_entries '
                . '(user_id, type, item_name, quantity, unit, amount, entry_date, status) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
            $qty = (float)$quantityText;
            $amount = (float)$amountText;
            if ($action === BOOKS_DASH_UPDATE_ACTION) {
                $stmt = $conn->prepare("UPDATE book_entries SET type=?, item_name=?, quantity=?, unit=?, amount=?, entry_date=? WHERE id=? AND user_id=? AND status='confirmed'");
                if (!$stmt) throw new RuntimeException('Could not prepare ledger update.');
                $stmt->bind_param('ssdsdsii', $type, $item, $qty, $unit, $amount, $entryDate, $entryId, $userId);
            } else {
                $stmt = $conn->prepare($sql);
                if (!$stmt) throw new RuntimeException('Could not prepare ledger insert.');
                $status = 'confirmed';
                $stmt->bind_param('issdsdss', $userId, $type, $item, $qty, $unit, $amount, $entryDate, $status);
            }
            if (!$stmt->execute()) throw new RuntimeException('Could not save ledger record.');
            $affected = $stmt->affected_rows;
            $stmt->close();
            if ($action === BOOKS_DASH_UPDATE_ACTION && $affected === 0) {
                $check = $conn->prepare("SELECT id FROM book_entries WHERE id=? AND user_id=? AND status='confirmed' LIMIT 1");
                $check->bind_param('ii', $entryId, $userId);
                $check->execute();
                $exists = (bool)$check->get_result()->fetch_assoc();
                $check->close();
                if (!$exists) throw new RuntimeException('Record not found or no longer confirmed');
            }
            $_SESSION['books_dash_flash'] = [
                'kind' => 'success',
                'message' => ($action === BOOKS_DASH_UPDATE_ACTION ? 'Record updated successfully.' : 'Ledger record saved successfully' . ($source === 'voice' ? ' from reviewed voice input.' : '.')),
            ];
            unset($_SESSION['books_dash_old']);
        } catch (Throwable $e) {
            error_log('books dashboard ledger insert: ' . $e->getMessage());
            $_SESSION['books_dash_flash'] = [
                'kind' => 'danger', 'message' => 'Unable to save the record. Please check the ledger schema and try again.'
            ];
            $_SESSION['books_dash_old'] = $_POST;
        }
    }

    // PRG avoids duplicate financial entries on refresh. Only allow local GET destination.
    header('Location: books.php?cadence=monthly&offset=0', true, 303);
    exit;
}

function books_dashboard_view_model(mysqli $conn, int $userId, array $params): array
{
    $cadence = trim((string)($params['cadence'] ?? 'monthly'));
    if (!in_array($cadence, BOOKS_CADENCES, true)) $cadence = 'monthly';
    $offset = (int)($params['offset'] ?? 0);
    if ($offset > 0) $offset = 0;
    $type = trim((string)($params['book_type'] ?? 'all'));
    if ($type !== 'all' && !in_array($type, BOOKS_ENTRY_TYPES, true)) $type = 'all';
    $sort = trim((string)($params['sort'] ?? 'entry_date'));
    if (!in_array($sort, BOOKS_DASH_SORT_COLUMNS, true)) $sort = 'entry_date';
    $dir = strtolower(trim((string)($params['dir'] ?? 'desc')));
    if (!in_array($dir, ['asc', 'desc'], true)) $dir = 'desc';
    $pageSize = (int)($params['page_size'] ?? BOOKS_DASH_DEFAULT_PAGE_SIZE);
    if (!in_array($pageSize, BOOKS_DASH_PAGE_SIZES, true)) $pageSize = BOOKS_DASH_DEFAULT_PAGE_SIZE;
    $page = max(1, (int)($params['page'] ?? 1));

    [$rangeStart, $rangeEnd] = books_cadence_range($cadence, $offset);
    $model = [
        'cadence' => $cadence, 'offset' => $offset,
        'period_label' => books_cadence_label($cadence, $rangeStart, $rangeEnd),
        'period_start' => $rangeStart->format('Y-m-d'),
        'period_end' => $rangeEnd->format('Y-m-d'),
        'nav' => books_cadence_nav($cadence, $offset),
        'type' => $type, 'sort' => $sort, 'dir' => $dir,
        'page' => $page, 'page_size' => $pageSize,
        'total_rows' => 0, 'total_pages' => 1, 'entries' => [],
        'money_in' => 0.0, 'money_out' => 0.0, 'surplus' => 0.0,
        'data_state' => 'empty', 'failed' => false,
    ];
    $where = "user_id = ? AND entry_date >= ? AND entry_date < ? AND status = 'confirmed'";
    $queryParams = [$userId, $rangeStart->format('Y-m-d H:i:s'), $rangeEnd->format('Y-m-d H:i:s')];
    $paramTypes = 'iss';
    if ($type !== 'all') {
        $where .= ' AND type = ?';
        $queryParams[] = $type;
        $paramTypes .= 's';
    }
    try {
        $summarySql = 'SELECT COUNT(*) AS total_rows, ' . books_money_select_sql()
            . " FROM book_entries WHERE {$where}";
        $summaryStmt = $conn->prepare($summarySql);
        if (!$summaryStmt) throw new RuntimeException($conn->error);
        $summaryStmt->bind_param($paramTypes, ...$queryParams);
        $summaryStmt->execute();
        $summary = $summaryStmt->get_result()->fetch_assoc() ?: [];
        $summaryStmt->close();
        $model['total_rows'] = (int)($summary['total_rows'] ?? 0);
        $model['money_in'] = (float)($summary['money_in'] ?? 0);
        $model['money_out'] = (float)($summary['money_out'] ?? 0);
        $model['surplus'] = $model['money_in'] - $model['money_out'];
        if ($model['total_rows'] === 0) $model['data_state'] = 'empty';
        elseif ($model['money_in'] > 0 && $model['money_out'] <= 0) $model['data_state'] = 'income_only';
        elseif ($model['money_out'] > 0 && $model['money_in'] <= 0) $model['data_state'] = 'expense_only';
        else $model['data_state'] = 'normal';
        $model['total_pages'] = max(1, (int)ceil($model['total_rows'] / $pageSize));
        $model['page'] = min($model['page'], $model['total_pages']);
        $listOffset = ($model['page'] - 1) * $pageSize;
        $listSql = 'SELECT id, type, item_name, quantity, unit, amount, entry_date FROM book_entries '
            . 'WHERE ' . $where . ' ORDER BY ' . $sort . ' ' . strtoupper($dir) . ', id DESC LIMIT ? OFFSET ?';
        $listStmt = $conn->prepare($listSql);
        if (!$listStmt) throw new RuntimeException($conn->error);
        $listParams = array_merge($queryParams, [$pageSize, $listOffset]);
        $listStmt->bind_param($paramTypes . 'ii', ...$listParams);
        $listStmt->execute();
        $result = $listStmt->get_result();
        while ($entry = $result->fetch_assoc()) $model['entries'][] = $entry;
        $listStmt->close();
    } catch (Throwable $e) {
        error_log('books dashboard: ' . $e->getMessage());
        $model['failed'] = true;
    }
    return $model;
}

/** Place this inside the existing books dashboard markup. */
function books_dash_render_entry_widget(): void
{
    $flash = $_SESSION['books_dash_flash'] ?? null;
    $old = $_SESSION['books_dash_old'] ?? [];
    $GLOBALS['books_dash_reopen_modal'] = !empty($old);
    unset($_SESSION['books_dash_flash'], $_SESSION['books_dash_old']);
    $today = date('Y-m-d');
    $types = books_dash_entry_options();
    ?>
    <?php if (is_array($flash)): ?>
      <div class="alert alert-<?= books_dash_escape($flash['kind'] ?? 'info') ?>" role="alert"><?= books_dash_escape($flash['message'] ?? '') ?></div>
    <?php endif; ?>
    <section id="books-entry-form" class="books-modal" role="dialog" aria-modal="true" aria-labelledby="booksModalTitle" aria-hidden="true" hidden>
      <div class="books-modal-dialog">
      <div class="books-modal-header">
        <h3 id="booksModalTitle"><i class="fas fa-book-open"></i> Add Book Record</h3>
        <button type="button" class="books-modal-close" data-books-close aria-label="Close">&times;</button>
      </div>
      <div class="books-modal-body">
        <p class="books-modal-intro">Record your transaction manually or use the microphone, then verify all fields before saving.</p>
        <div class="mb-3 p-3 rounded" style="background:#f8fafc;border:1px solid #e2e8f0">
          <label for="booksVoiceLanguage" class="form-label">Speech language</label>
          <select id="booksVoiceLanguage" class="form-select form-select-sm mb-2" style="max-width:240px">
            <option value="en-UG">English</option>
            <option value="lg-UG">Luganda</option>
            <option value="sw-KE">Kiswahili</option>
          </select>
          <div class="d-flex gap-2 flex-wrap mb-2">
            <button id="booksVoiceStart" class="btn btn-primary btn-sm" type="button"><i class="fas fa-microphone"></i> Start recording</button>
            <button id="booksVoiceStop" class="btn btn-outline-secondary btn-sm" type="button" disabled><i class="fas fa-stop"></i> Stop</button>
            <button id="booksVoiceParse" class="btn btn-outline-primary btn-sm" type="button">Extract details</button>
          </div>
          <label for="booksVoiceTranscript" class="form-label">Recognized words (editable)</label>
          <textarea id="booksVoiceTranscript" rows="2" class="form-control" placeholder="Example: Bought 5 bags of cement for 250000 shillings today"></textarea>
          <div class="small text-muted mb-2">English: "Bought 5 bags of cement for 250000 shillings."<br>
            Luganda: "Naguze ensawo 5 eza sementi ku 250000."<br>
            Kiswahili: "Nimenunua mifuko 5 ya saruji kwa shilingi 250000."</div>
          <div id="booksVoiceStatus" class="small text-muted mt-2" role="status" aria-live="polite">Nothing is saved until you review and submit the form.</div>
        </div>
        <form method="post" action="books.php#books-entry-form" id="booksRecordForm" autocomplete="off">
          <input type="hidden" id="booksAction" name="books_action" value="<?= BOOKS_DASH_ENTRY_ACTION ?>">
          <input type="hidden" id="booksEntryId" name="entry_id" value="">
          <input type="hidden" name="csrf_token" value="<?= books_dash_escape(books_dash_csrf_token()) ?>">
          <input type="hidden" id="booksInputSource" name="input_source" value="<?= books_dash_escape($old['input_source'] ?? 'manual') ?>">
          <div class="books-form-grid">
            <div class="books-form-field">
              <label for="booksRecordType" class="form-label">Record type *</label>
              <select class="form-select" id="booksRecordType" name="type" required>
                <option value="">Choose type...</option>
                <?php foreach ($types as $type): ?>
                  <option value="<?= books_dash_escape($type) ?>" <?= ($old['type'] ?? '') === $type ? 'selected' : '' ?>><?= books_dash_escape(ucwords(str_replace('_', ' ', $type))) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="books-form-field">
              <label for="booksRecordItem" class="form-label">Item / description *</label>
              <input class="form-control" id="booksRecordItem" name="item_name" maxlength="255" required value="<?= books_dash_escape($old['item_name'] ?? '') ?>" placeholder="e.g. cement, transport, maize sales">
            </div>
            <div class="books-form-field">
              <label for="booksRecordDate" class="form-label">Date *</label>
              <input class="form-control" id="booksRecordDate" type="date" name="entry_date" max="<?= $today ?>" required value="<?= books_dash_escape($old['entry_date'] ?? $today) ?>">
            </div>
            <div class="books-form-field">
              <label for="booksRecordQty" class="form-label">Quantity *</label>
              <input class="form-control" id="booksRecordQty" name="quantity" type="number" min="0.001" max="999999999" step="any" required value="<?= books_dash_escape($old['quantity'] ?? '1') ?>">
            </div>
            <div class="books-form-field">
              <label for="booksRecordUnit" class="form-label">Unit</label>
              <input class="form-control" id="booksRecordUnit" name="unit" maxlength="50" value="<?= books_dash_escape($old['unit'] ?? '') ?>" placeholder="bags, kg, items, service">
            </div>
            <div class="books-form-field">
              <label for="booksRecordAmount" class="form-label">Total amount (UGX) *</label>
              <input class="form-control" id="booksRecordAmount" name="amount" type="number" min="0.01" max="999999999999" step="0.01" required value="<?= books_dash_escape($old['amount'] ?? '') ?>">
            </div>
          </div>
          <div class="d-flex flex-wrap justify-content-between gap-2 align-items-center mt-3">
            <small class="text-muted">Review type, item, quantity and total before saving. The amount is the transaction total, not price per unit.</small>
            <div class="books-modal-actions"><button type="button" class="btn btn-ghost" data-books-close>Cancel</button><button type="submit" class="btn btn-success" id="booksSaveButton"><i class="fas fa-save me-1"></i> Save record</button></div>
          </div>
        </form>
      </div>
      </div>
    </section>
    <?php
}

/** Include once, after the widget. No external speech API key is required. */
function books_dash_render_voice_script(): void
{
    $types = books_dash_entry_options();
    $incomeTypes = array_values(array_filter($types, 'books_is_money_in'));
    $outTypes = array_values(array_filter($types, 'books_is_money_out'));
    ?>
    <script>
    (() => {
      'use strict';
      const root = document.getElementById('books-entry-form');
      if (!root || root.dataset.voiceReady === '1') return;
      root.dataset.voiceReady = '1';
      const el = id => document.getElementById(id);
      const start = el('booksVoiceStart'), stop = el('booksVoiceStop'), status = el('booksVoiceStatus');
      const transcript = el('booksVoiceTranscript');
      const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
      const allowed = <?= json_encode($types, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const incomeTypes = <?= json_encode($incomeTypes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const outTypes = <?= json_encode($outTypes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const say = message => status.textContent = message;
      const typeByWord = (words, candidates) => candidates.find(type => type.toLowerCase().replace(/_/g, ' ').includes(words)) || candidates[0] || '';
      let recognition = null;
      if (!SpeechRecognition) {
        start.disabled = true;
        say('Speech recognition is unavailable in this browser. Type or paste your words into the box, then choose Extract details.');
      } else {
        recognition = new SpeechRecognition();
        recognition.continuous = false;
        recognition.interimResults = false;
        recognition.maxAlternatives = 1;
        recognition.onresult = event => {
          const words = [...event.results].map(result => result[0].transcript).join(' ').trim();
          transcript.value = words;
          say('Speech captured. Review the recognized words and extracted fields.');
          parseWords();
        };
        recognition.onerror = event => say('Microphone recognition error: ' + event.error + '. You can enter details manually.');
        recognition.onend = () => { start.disabled = false; stop.disabled = true; };
        start.addEventListener('click', () => {
          recognition.lang = el('booksVoiceLanguage').value;
          try { recognition.start(); start.disabled = true; stop.disabled = false; say('Listening... speak one transaction.'); }
          catch (_) { say('Unable to start the microphone. Check browser microphone permission.'); }
        });
        stop.addEventListener('click', () => recognition.stop());
      }
      const language = el('booksVoiceLanguage');
      const examples = {
        'en-UG': 'Bought 5 bags of cement for 250000 shillings',
        'lg-UG': 'Naguze ensawo 5 eza sementi ku 250000',
        'sw-KE': 'Nimenunua mifuko 5 ya saruji kwa shilingi 250000'
      };
      language.addEventListener('change', () => {
        transcript.placeholder = examples[language.value] || examples['en-UG'];
        say('Language selected. Recognition depends on browser support; you can edit the transcript manually.');
      });
      const normalizeNumber = raw => {
        const match = String(raw).toLowerCase().replace(/[,\s](?=\d{3}\b)/g, '')
          .replace(/\b(?:shillings?|shilingi|ugx)\b/g, '').trim()
          .match(/^(\d+(?:\.\d+)?)\s*(million|thousand|milioni|elfu|m|k)?$/);
        if (!match) return '';
        const factor = /^(million|milioni|m)$/.test(match[2] || '') ? 1000000 :
          /^(thousand|elfu|k)$/.test(match[2] || '') ? 1000 : 1;
        return String(Number(match[1]) * factor);
      };
      function parseWords() {
        const raw = transcript.value.trim();
        const text = raw.toLowerCase().replace(/(\d),(?=\d{3}\b)/g, '$1').replace(/\s+/g, ' ');
        if (!text) { say('Say or type a transaction first.'); return; }
        // Language-specific matching is intentionally conservative. Unknown expressions require review.
        const sale = /\b(sold|sale|sales|received|earned|income|revenue|collected|nauza|nimeuza|tumeuza|mauzo|kuuza|natunze|ntunze|natundye|nakatunda|natunda|ntunda|atunda)\b/i.test(text);
        const purchase = /\b(bought|buy|purchased|purchase|nimenunua|nilinunua|tumenunua|kununua|naguze|nguze|nagula|okugula|nguzeeko)\b/i.test(text);
        const expense = /\b(spent|expense|expenses|paid|cost|rent|transport|fuel|salary|wages|electricity|gharama|matumizi|nimelipa|nililipa|nimetumia|kulipa|malipo|ensaasaanya|nsaasanyizza|nsasudde|sasula|mpadde)\b/i.test(text);
        let chosen = '';
        const choose = (word, candidates) => candidates.find(t => t.toLowerCase().includes(word)) || '';
        if (sale) chosen = choose('sale', incomeTypes) || choose('income', incomeTypes) || incomeTypes[0] || '';
        else if (purchase) chosen = choose('purchase', outTypes) || outTypes[0] || '';
        else if (expense) chosen = choose('expense', outTypes) || outTypes[0] || '';
        if (chosen && allowed.includes(chosen)) el('booksRecordType').value = chosen;
        // Monetary marker + digits, covering speech-to-text output from each language.
        let amount = '';
        const matches = [
          /(?:for|worth|total(?: of)?|amount(?: of)?|cost(?:ing)?|at|kwa|ku|ugx|shillings?|shilingi|ssente)\s*(?:ugx|shillings?|shilingi)?\s*(\d[\d,]*(?:\.\d+)?\s*(?:million|thousand|milioni|elfu|m|k)?)/i,
          /(\d[\d,]*(?:\.\d+)?\s*(?:million|thousand|milioni|elfu|m|k)?)\s*(?:ugx|shillings?|shilingi|ssente)/i
        ];
        for (const re of matches) {
          const match = text.match(re);
          if (match) { amount = normalizeNumber(match[1]); if (amount) break; }
        }
        // For phrases such as "naguze ensawo 5 ... ku 250000", find quantity by a unit nearby.
        const knownUnits = '(bags?|ensawo|mifuko|kg|kilograms?|kilo|lit(?:re|er)s?|lita|items?|pieces?|ebikopo|vikombe|boxes|cartons?|ekika|pcs)';
        const qtyBefore = text.match(new RegExp('\\b(\\d+(?:\\.\\d+)?)\\s+' + knownUnits + '\\b', 'i'));
        const qtyAfter = text.match(new RegExp('\\b' + knownUnits + '\\s+(\\d+(?:\\.\\d+)?)\\b', 'i'));
        let qty = null;
        if (qtyBefore) qty = { value: qtyBefore[1], unit: qtyBefore[2], fragment: qtyBefore[0] };
        else if (qtyAfter) qty = { value: qtyAfter[2], unit: qtyAfter[1], fragment: qtyAfter[0] };
        if (qty) {
          el('booksRecordQty').value = qty.value;
          el('booksRecordUnit').value = qty.unit;
        }
        let item = text
          .replace(/\b(today|yesterday|leero|ggulo|leo|jana)\b/g, '')
          .replace(/\b(?:for|worth|total(?: of)?|amount(?: of)?|cost(?:ing)?|at|kwa|ku|ugx|shillings?|shilingi|ssente)\s*(?:ugx|shillings?|shilingi)?\s*\d[\d,]*(?:\.\d+)?\s*(?:million|thousand|milioni|elfu|m|k)?\b/g, '')
          .replace(/\b\d[\d,]*(?:\.\d+)?\s*(?:million|thousand|milioni|elfu|m|k)?\s*(?:ugx|shillings?|shilingi|ssente)\b/g, '')
          .replace(/^(?:i\s+)?(?:bought|buy|purchased|purchase|sold|sale of|received|earned|spent|paid|record|add|expense for|income from|nimenunua|nilinunua|nimeuza|nauza|nimelipa|nimetumia|naguze|nguze|nagula|nakatunda|natunda|nsasudde|nsaasanyizza)\s+/,'')
          .replace(/\s+/g, ' ').trim();
        if (qty) item = item.replace(qty.fragment, '').trim();
        item = item.replace(/^(of|ya|za|eza|wa|kwa)\s+/,'').trim();
        if (item && item.length <= 255) el('booksRecordItem').value = item;
        const date = new Date();
        const localDate = d => [d.getFullYear(), String(d.getMonth()+1).padStart(2,'0'), String(d.getDate()).padStart(2,'0')].join('-');
        if (/\b(yesterday|ggulo|jana)\b/.test(text)) date.setDate(date.getDate()-1);
        if (/\b(today|yesterday|leero|ggulo|leo|jana)\b/.test(text)) el('booksRecordDate').value = localDate(date);
        el('booksInputSource').value = 'voice';
        say('Draft extracted. Check the transaction type, amount, quantity, and date carefully before saving.');
      }
      el('booksVoiceParse').addEventListener('click', parseWords);
      el('booksRecordForm').addEventListener('submit', event => {
        if (!confirm('Save this ledger transaction with the displayed type, date, item and UGX amount?')) event.preventDefault();
      });
    })();
    </script>
    <?php
}
