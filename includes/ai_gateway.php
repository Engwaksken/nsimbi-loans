<?php
declare(strict_types=1);

/*
 * AI gateway: one place that talks to the AI provider the admin marked active
 * (System > AI Settings). Supports Anthropic Claude, OpenAI and Google Gemini.
 *
 * API keys are stored encrypted (AES-256-GCM) with a key derived from APP_KEY,
 * or JWT_SECRET when APP_KEY is not set, from the server .env file.
 *
 * Main entry point:
 *   ai_extract_document($conn, $prompt, $schema, $bytes, $mime)
 *     -> ['data' => array, 'provider' => string, 'model' => string]
 *   Throws RuntimeException with a message safe to show staff, or
 *   AiBusyException when every provider was busy (try again later).
 *
 * Busy replies (HTTP 429/5xx, "high demand", "overloaded") are retried after a
 * short pause, then the other saved providers that passed their test on AI
 * Settings are tried, so one provider's busy spell does not stop documents.
 */

require_once __DIR__ . '/env.php';

const AI_PROVIDERS = [
    'anthropic' => [
        'label'         => 'Anthropic Claude',
        'default_model' => 'claude-opus-5-5',
        'models_hint'   => 'claude-opus-5-5, claude-sonnet-5-5, claude-haiku-5-5',
        'key_hint'      => 'Starts with sk-ant-',
    ],
    'openai' => [
        'label'         => 'OpenAI',
        'default_model' => '',
        'models_hint'   => 'Any OpenAI model that accepts images and PDF files',
        'key_hint'      => 'Starts with sk-',
    ],
    'gemini' => [
        'label'         => 'Google Gemini',
        'default_model' => 'gemini-3.5-flash-lite',
        'models_hint'   => 'gemini-3.5-flash-lite, gemini-3.6-flash',
        'key_hint'      => 'From Google AI Studio',
    ],
];

const AI_DOCUMENT_MIMES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
/** Seconds to wait before each extra attempt when a provider is busy. */
const AI_BUSY_PAUSES = [4, 10];

/** The AI service is temporarily busy or unreachable; worth trying again later. */
class AiBusyException extends RuntimeException
{
}

/** Does this HTTP status / message mean "busy, try again later"? */
function ai_is_busy(int $code, string $message): bool
{
    if (in_array($code, [408, 429, 500, 502, 503, 504, 529], true)) {
        return true;
    }
    return (bool)preg_match('/high demand|overloaded|try again later|temporarily|unavailable|rate limit|capacity/i', $message);
}

function ai_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $conn->query("
        CREATE TABLE IF NOT EXISTS ai_providers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            provider VARCHAR(20) NOT NULL,
            label VARCHAR(100) NOT NULL,
            model VARCHAR(100) NOT NULL,
            api_key_enc TEXT NOT NULL,
            key_last4 VARCHAR(8) NOT NULL DEFAULT '',
            is_active TINYINT(1) NOT NULL DEFAULT 0,
            last_test_at DATETIME NULL,
            last_test_ok TINYINT(1) NULL,
            last_error VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $done = true;
}

/* ---------------- Key encryption ---------------- */

function ai_secret(): string
{
    $secret = (string)(getenv('APP_KEY') ?: getenv('JWT_SECRET') ?: '');
    if ($secret === '') {
        throw new RuntimeException('Set APP_KEY (or JWT_SECRET) in the server .env file before saving AI keys.');
    }
    return hash('sha256', 'nsimbi-ai-keys|' . $secret, true);
}

function ai_encrypt_key(string $plain): string
{
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', ai_secret(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        throw new RuntimeException('Could not encrypt the API key.');
    }
    return base64_encode($iv . $tag . $cipher);
}

function ai_decrypt_key(string $stored): string
{
    $raw = base64_decode($stored, true);
    if ($raw === false || strlen($raw) < 29) {
        throw new RuntimeException('Stored API key is unreadable. Enter it again on AI Settings.');
    }
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', ai_secret(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    if ($plain === false) {
        throw new RuntimeException('Stored API key cannot be decrypted (was APP_KEY/JWT_SECRET changed?). Enter it again on AI Settings.');
    }
    return $plain;
}

/* ---------------- Provider records ---------------- */

function ai_providers_all(mysqli $conn): array
{
    ai_ensure_schema($conn);
    $res = $conn->query('SELECT * FROM ai_providers ORDER BY is_active DESC, id ASC');
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

function ai_provider_find(mysqli $conn, int $id): ?array
{
    ai_ensure_schema($conn);
    $stmt = $conn->prepare('SELECT * FROM ai_providers WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function ai_active_provider(mysqli $conn): ?array
{
    ai_ensure_schema($conn);
    $res = $conn->query('SELECT * FROM ai_providers WHERE is_active = 1 ORDER BY id ASC LIMIT 1');
    $row = $res ? $res->fetch_assoc() : null;
    return $row ?: null;
}

function ai_provider_save(mysqli $conn, ?int $id, string $provider, string $label, string $model, string $apiKey): int
{
    ai_ensure_schema($conn);
    if (!isset(AI_PROVIDERS[$provider])) {
        throw new RuntimeException('Unknown AI provider.');
    }
    $label = trim($label) !== '' ? trim($label) : AI_PROVIDERS[$provider]['label'];
    $model = trim($model);
    if ($model === '' || !preg_match('/^[A-Za-z0-9._:\/-]{2,100}$/', $model)) {
        throw new RuntimeException('Enter a valid model name.');
    }
    $apiKey = trim($apiKey);

    if ($id === null) {
        if ($apiKey === '') {
            throw new RuntimeException('Enter the API key.');
        }
        $enc = ai_encrypt_key($apiKey);
        $last4 = substr($apiKey, -4);
        $stmt = $conn->prepare('INSERT INTO ai_providers (provider, label, model, api_key_enc, key_last4) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('sssss', $provider, $label, $model, $enc, $last4);
        $stmt->execute();
        $newId = (int)$stmt->insert_id;
        $stmt->close();
        // The first provider added becomes active automatically.
        if (!ai_active_provider($conn)) {
            ai_provider_activate($conn, $newId);
        }
        return $newId;
    }

    if ($apiKey !== '') {
        $enc = ai_encrypt_key($apiKey);
        $last4 = substr($apiKey, -4);
        $stmt = $conn->prepare('UPDATE ai_providers SET provider = ?, label = ?, model = ?, api_key_enc = ?, key_last4 = ?, last_test_ok = NULL, last_error = NULL WHERE id = ?');
        $stmt->bind_param('sssssi', $provider, $label, $model, $enc, $last4, $id);
    } else {
        $stmt = $conn->prepare('UPDATE ai_providers SET provider = ?, label = ?, model = ? WHERE id = ?');
        $stmt->bind_param('sssi', $provider, $label, $model, $id);
    }
    $stmt->execute();
    $stmt->close();
    return $id;
}

function ai_provider_activate(mysqli $conn, int $id): void
{
    ai_ensure_schema($conn);
    $conn->begin_transaction();
    $conn->query('UPDATE ai_providers SET is_active = 0');
    $stmt = $conn->prepare('UPDATE ai_providers SET is_active = 1 WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    $conn->commit();
}

function ai_provider_delete(mysqli $conn, int $id): void
{
    ai_ensure_schema($conn);
    $stmt = $conn->prepare('DELETE FROM ai_providers WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
}

function ai_provider_record_test(mysqli $conn, int $id, bool $ok, string $error = ''): void
{
    $error = mb_substr($error, 0, 500);
    $okInt = $ok ? 1 : 0;
    $stmt = $conn->prepare('UPDATE ai_providers SET last_test_at = NOW(), last_test_ok = ?, last_error = ? WHERE id = ?');
    $stmt->bind_param('isi', $okInt, $error, $id);
    $stmt->execute();
    $stmt->close();
}

/* ---------------- HTTP ---------------- */

function ai_http_post(string $url, array $headers, array $payload, int $timeout = 150): array
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Could not build the AI request.');
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT        => $timeout,
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0 || $body === false) {
        error_log("AI request to {$url} failed: {$errno} {$err}");
        throw new AiBusyException('Could not reach the AI service.');
    }
    $decoded = json_decode((string)$body, true);
    if (!is_array($decoded)) {
        error_log('AI invalid response: ' . substr((string)$body, 0, 800));
        if ($code >= 500 || $code === 429) {
            throw new AiBusyException('The AI service is busy (HTTP ' . $code . ').');
        }
        throw new RuntimeException('The AI service returned an unreadable response.');
    }
    if ($code >= 400) {
        $msg = (string)($decoded['error']['message'] ?? $decoded['message'] ?? ('HTTP ' . $code));
        error_log("AI HTTP {$code}: " . substr((string)$body, 0, 800));
        if (ai_is_busy($code, $msg)) {
            throw new AiBusyException('The AI service is busy: ' . mb_substr($msg, 0, 200));
        }
        throw new RuntimeException('AI service error: ' . mb_substr($msg, 0, 300));
    }
    return $decoded;
}

function ai_parse_json_text(string $text): array
{
    $text = trim($text);
    // Tolerate ```json fences from providers without strict JSON mode.
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text;
    $data = json_decode($text, true);
    if (!is_array($data)) {
        throw new RuntimeException('The AI did not return valid JSON.');
    }
    return $data;
}

/* ---------------- Providers ---------------- */

function ai_call_anthropic(string $key, string $model, string $system, string $prompt, ?array $schema, ?string $bytes, ?string $mime): array
{
    $content = [];
    if ($bytes !== null) {
        $b64 = base64_encode($bytes);
        $content[] = $mime === 'application/pdf'
            ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $b64]]
            : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $b64]];
    }
    $content[] = ['type' => 'text', 'text' => $prompt];

    $payload = [
        'model'      => $model,
        'max_tokens' => 16000,
        'system'     => $system,
        'messages'   => [['role' => 'user', 'content' => $content]],
    ];
    $headers = [
        'x-api-key: ' . $key,
        'anthropic-version: 2026-10-21',
    ];
    if ($schema !== null) {
        $payload['output_config'] = ['format' => ['type' => 'json_schema', 'schema' => $schema]];
    }
    // Current Claude 5.x models: set effort explicitly and opt into server-side
    // fallbacks so a classifier decline is re-run instead of failing.
    if (preg_match('/^claude-(opus|sonnet|fable|haiku)-5/', $model)) {
        $payload['output_config']['effort'] = 'medium';
        if (preg_match('/^claude-(opus-5-5|opus-5$|sonnet-5-5|fable-5-[12])/', $model)) {
            $payload['fallbacks'] = 'default';
            $headers[] = 'anthropic-beta: server-side-fallback-2026-07-01';
        }
    }

    $res = ai_http_post('https://api.anthropic.com/v1/messages', $headers, $payload);
    $stop = (string)($res['stop_reason'] ?? '');
    if ($stop === 'refusal') {
        throw new RuntimeException('The AI declined to read this document.');
    }
    if ($stop === 'max_tokens') {
        throw new RuntimeException('The AI response was cut off. Please try again.');
    }
    $text = '';
    foreach ((array)($res['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') {
            $text .= (string)$block['text'];
        }
    }
    return $schema !== null ? ai_parse_json_text($text) : ['text' => $text];
}

function ai_call_openai(string $key, string $model, string $system, string $prompt, ?array $schema, ?string $bytes, ?string $mime): array
{
    $content = [];
    if ($bytes !== null) {
        $dataUrl = 'data:' . $mime . ';base64,' . base64_encode($bytes);
        $content[] = $mime === 'application/pdf'
            ? ['type' => 'file', 'file' => ['filename' => 'document.pdf', 'file_data' => $dataUrl]]
            : ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]];
    }
    $content[] = ['type' => 'text', 'text' => $prompt];

    $payload = [
        'model'    => $model,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $content],
        ],
    ];
    if ($schema !== null) {
        $payload['response_format'] = [
            'type' => 'json_schema',
            'json_schema' => ['name' => 'extraction', 'strict' => true, 'schema' => $schema],
        ];
    }
    $res = ai_http_post('https://api.openai.com/v1/chat/completions', ['Authorization: Bearer ' . $key], $payload);
    $choice = $res['choices'][0] ?? [];
    if (!empty($choice['message']['refusal'])) {
        throw new RuntimeException('The AI declined to read this document.');
    }
    $text = (string)($choice['message']['content'] ?? '');
    return $schema !== null ? ai_parse_json_text($text) : ['text' => $text];
}

function ai_call_gemini(string $key, string $model, string $system, string $prompt, ?array $schema, ?string $bytes, ?string $mime): array
{
    $parts = [];
    if ($bytes !== null) {
        $parts[] = ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bytes)]];
    }
    $parts[] = ['text' => $prompt];

    $payload = [
        'system_instruction' => ['parts' => [['text' => $system]]],
        'contents' => [['role' => 'user', 'parts' => $parts]],
    ];
    if ($schema !== null) {
        $payload['generationConfig'] = [
            'responseMimeType' => 'application/json',
            'responseJsonSchema' => $schema,
        ];
    }
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
    $res = ai_http_post($url, ['x-goog-api-key: ' . $key], $payload);
    $text = '';
    foreach ((array)($res['candidates'][0]['content']['parts'] ?? []) as $part) {
        $text .= (string)($part['text'] ?? '');
    }
    if ($text === '') {
        $reason = (string)($res['candidates'][0]['finishReason'] ?? $res['promptFeedback']['blockReason'] ?? 'empty response');
        throw new RuntimeException('The AI returned no result (' . $reason . ').');
    }
    return $schema !== null ? ai_parse_json_text($text) : ['text' => $text];
}

function ai_call(array $provider, string $system, string $prompt, ?array $schema = null, ?string $bytes = null, ?string $mime = null): array
{
    $key = ai_decrypt_key((string)$provider['api_key_enc']);
    $model = (string)$provider['model'];
    return match ((string)$provider['provider']) {
        'anthropic' => ai_call_anthropic($key, $model, $system, $prompt, $schema, $bytes, $mime),
        'openai'    => ai_call_openai($key, $model, $system, $prompt, $schema, $bytes, $mime),
        'gemini'    => ai_call_gemini($key, $model, $system, $prompt, $schema, $bytes, $mime),
        default     => throw new RuntimeException('Unknown AI provider.'),
    };
}

/** Send a small request to check the key and model work. */
function ai_test_provider(mysqli $conn, array $provider): void
{
    $schema = [
        'type' => 'object',
        'properties' => ['ok' => ['type' => 'boolean']],
        'required' => ['ok'],
        'additionalProperties' => false,
    ];
    try {
        $out = ai_call($provider, 'You answer health checks.', 'Reply with {"ok": true}.', $schema);
        if (($out['ok'] ?? null) !== true) {
            throw new RuntimeException('Unexpected test reply.');
        }
        ai_provider_record_test($conn, (int)$provider['id'], true);
    } catch (Throwable $e) {
        ai_provider_record_test($conn, (int)$provider['id'], false, $e->getMessage());
        throw $e;
    }
}

/**
 * Read a document with the active provider.
 * @return array{data: array, provider: string, model: string}
 */
function ai_extract_document(mysqli $conn, string $system, string $prompt, array $schema, string $bytes, string $mime): array
{
    if (!in_array($mime, AI_DOCUMENT_MIMES, true)) {
        throw new RuntimeException('Only PDF, JPG, PNG or WebP files can be read.');
    }
    $active = ai_active_provider($conn);
    if (!$active) {
        throw new RuntimeException('No AI provider is active. An admin must add one under System > AI Settings.');
    }
    // Active provider first, then other saved providers whose test passed.
    $queue = [$active];
    foreach (ai_providers_all($conn) as $p) {
        if ((int)$p['id'] !== (int)$active['id'] && (string)($p['last_test_ok'] ?? '') === '1') {
            $queue[] = $p;
        }
    }
    $busy = null;
    foreach ($queue as $n => $provider) {
        // The active provider gets extra attempts; a backup gets one.
        $pauses = $n === 0 ? AI_BUSY_PAUSES : [];
        for ($try = 0; ; $try++) {
            try {
                $data = ai_call($provider, $system, $prompt, $schema, $bytes, $mime);
                return ['data' => $data, 'provider' => (string)$provider['provider'], 'model' => (string)$provider['model']];
            } catch (AiBusyException $e) {
                $busy = $e;
                error_log('AI busy (' . $provider['provider'] . ' ' . $provider['model'] . ', try ' . ($try + 1) . '): ' . $e->getMessage());
                if (!isset($pauses[$try])) {
                    break;
                }
                sleep($pauses[$try]);
            } catch (Throwable $e) {
                if ($n === 0) {
                    throw $e; // a real error with the active provider (bad key, unreadable file...)
                }
                error_log('AI backup provider ' . $provider['provider'] . ' failed: ' . $e->getMessage());
                break;
            }
        }
    }
    throw new AiBusyException($busy ? $busy->getMessage() : 'The AI service is busy.');
}
