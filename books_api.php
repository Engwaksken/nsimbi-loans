<?php
declare(strict_types=1);

/**
 * Nsimbi Books ledger API — loaded soft from api.php.
 * Expects respond(), clean(), moneyValue(), requireAuth() to already exist.
 */

require_once __DIR__ . '/includes/books_ledger.php';

function booksPeriodBounds(string $period, string $clientDate = ""): array
{
    $period = strtolower(clean($period));
    if ($period === "") {
        $period = "today";
    }

    $allowed = ["today", "week", "month"];
    if (!in_array($period, $allowed, true)) {
        respond([
            "status" => "error",
            "success" => false,
            "message" => "Invalid period",
            "data" => null,
        ], 422);
    }

    $now = booksResolveNow($clientDate);
    $end = $now->format("Y-m-d 23:59:59");

    if ($period === "today") {
        $start = $now->format("Y-m-d 00:00:00");
    } elseif ($period === "week") {
        $start = $now->modify("monday this week")->format("Y-m-d 00:00:00");
    } else {
        $start = $now->format("Y-m-01 00:00:00");
    }

    return [$period, $start, $end];
}

/**
 * Full calendar-range bounds for the Reports screen (week/month), unlike
 * booksPeriodBounds() which is "to date" (WTD/MTD) for the Books home pills.
 * $offset counts periods back from the current one (0 = current, 1 = previous, ...).
 */
function booksReportPeriodBounds(string $period, string $clientDate, int $offset): array
{
    $period = strtolower(clean($period));

    $allowed = ["week", "month"];
    if (!in_array($period, $allowed, true)) {
        respond([
            "status" => "error",
            "success" => false,
            "message" => "Invalid period",
            "data" => null,
        ], 422);
    }

    if ($offset < 0) {
        respond([
            "status" => "error",
            "success" => false,
            "message" => "Invalid offset",
            "data" => null,
        ], 422);
    }
    $offset = min($offset, 24);

    $now = booksResolveNow($clientDate);

    if ($period === "week") {
        $anchor = $now->modify("monday this week")->modify("-{$offset} weeks");
        $start = $anchor->format("Y-m-d 00:00:00");
        $end = $anchor->modify("+6 days")->format("Y-m-d 23:59:59");
    } else {
        $anchor = $now->modify("first day of this month")->modify("-{$offset} months");
        $start = $anchor->format("Y-m-01 00:00:00");
        $end = $anchor->modify("last day of this month")->format("Y-m-d 23:59:59");
    }

    return [$period, $start, $end];
}

/**
 * Resolve the "now" used for period bounds. Prefers a client-supplied local
 * date ("Y-m-d" or "Y-m-d H:i:s") so the Books screen reflects the device's
 * date; falls back to the server clock when absent or invalid.
 */
function booksResolveNow(string $clientDate): DateTimeImmutable
{
    $clientDate = clean($clientDate);
    if ($clientDate !== "") {
        foreach (["Y-m-d H:i:s", "Y-m-d"] as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $clientDate);
            $errors = DateTimeImmutable::getLastErrors();
            $noErrors = $errors === false || ($errors["warning_count"] === 0 && $errors["error_count"] === 0);
            if ($parsed !== false && $noErrors) {
                return $parsed;
            }
        }
    }

    return new DateTimeImmutable("now");
}

/**
 * Validate a client-supplied entry_date, accepting only "Y-m-d" or "Y-m-d H:i:s".
 */
function booksIsValidEntryDate(string $value): bool
{
    foreach (["Y-m-d H:i:s", "Y-m-d"] as $format) {
        $parsed = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        // getLastErrors() returns false (no errors) on PHP 8.2+, or an array with
        // zero counts on older versions — both mean the parse was clean.
        $noErrors = $errors === false || ($errors["warning_count"] === 0 && $errors["error_count"] === 0);
        if ($parsed !== false && $noErrors) {
            return true;
        }
    }

    return false;
}

/**
 * Canonicalize spoken unit variants (kg / kilos / kilograms → kg).
 * Unknown units keep cleaned lowercase original.
 */
function booksNormalizeUnit(?string $unit): ?string
{
    $unit = clean($unit ?? "");
    if ($unit === "") {
        return null;
    }

    $key = strtolower($unit);
    $map = [
        "kg" => "kg",
        "kilo" => "kg",
        "kilos" => "kg",
        "kilogram" => "kg",
        "kilograms" => "kg",
        "g" => "g",
        "gram" => "g",
        "grams" => "g",
        "l" => "L",
        "litre" => "L",
        "liter" => "L",
        "litres" => "L",
        "liters" => "L",
        "pcs" => "pcs",
        "pc" => "pcs",
        "piece" => "pcs",
        "pieces" => "pcs",
        "count" => "pcs",
        "counts" => "pcs",
        "bag" => "bags",
        "bags" => "bags",
        "loaf" => "loaf",
        "loaves" => "loaf",
        "bar" => "bar",
        "bars" => "bar",
        "bunch" => "bunch",
        "bunches" => "bunch",
    ];

    return $map[$key] ?? $key;
}

/**
 * Shared field parsing for draft + confirm. Returns null if type/item invalid.
 * $requireAmountAndDate: when true, amount must be > 0 and entry_date required/valid.
 * $defaultEntryDate: client-local "now" (Y-m-d H:i:s). Prefer this over server date()
 * so drafts match the device calendar day (UTC servers are often a day ahead in the Americas).
 */
function booksNormalizeEntryFields(array $raw, bool $requireAmountAndDate, ?string $defaultEntryDate = null): ?array
{
    $type = strtolower(clean($raw["type"] ?? ""));
    if (!in_array($type, ["sale", "purchase", "expense"], true)) {
        return null;
    }

    $itemName = clean($raw["item_name"] ?? $raw["item"] ?? "");
    if ($itemName === "") {
        return null;
    }
    // Capitalize first letter for consistent draft/confirmed display.
    if (function_exists("mb_substr") && function_exists("mb_strtoupper")) {
        $itemName = mb_strtoupper(mb_substr($itemName, 0, 1, "UTF-8"), "UTF-8")
            . mb_substr($itemName, 1, null, "UTF-8");
    } else {
        $itemName = strtoupper(substr($itemName, 0, 1)) . substr($itemName, 1);
    }

    $amountRaw = $raw["amount"] ?? null;
    if ($amountRaw === null || $amountRaw === "") {
        $amount = 0.0;
    } else {
        $amount = moneyValue($amountRaw);
    }
    if ($amount < 0) {
        return null;
    }

    $quantity = null;
    if (array_key_exists("quantity", $raw) && $raw["quantity"] !== null && clean((string)$raw["quantity"]) !== "") {
        $quantity = (float)$raw["quantity"];
        if ($quantity < 0) {
            return null;
        }
    }

    $unit = booksNormalizeUnit($raw["unit"] ?? null);

    $fallbackDate = ($defaultEntryDate !== null && $defaultEntryDate !== "")
        ? $defaultEntryDate
        : date("Y-m-d H:i:s");

    $entryDate = clean($raw["entry_date"] ?? "");
    if ($entryDate === "") {
        $entryDate = $requireAmountAndDate ? "" : $fallbackDate;
    } elseif (!booksIsValidEntryDate($entryDate)) {
        return null;
    }

    if ($requireAmountAndDate) {
        if ($amount <= 0) {
            return null;
        }
        if ($entryDate === "" || !booksIsValidEntryDate($entryDate)) {
            return null;
        }
    }

    $id = (int)($raw["id"] ?? 0);

    return [
        "id" => $id > 0 ? $id : null,
        "type" => $type,
        "item_name" => $itemName,
        "quantity" => $quantity,
        "unit" => $unit,
        "amount" => round($amount, 2),
        "entry_date" => $entryDate !== "" ? $entryDate : $fallbackDate,
    ];
}

/** Parse / Gemini path: allow missing amount (stored as 0 on draft). */
function booksNormalizeDraftEntry(array $raw, ?string $defaultEntryDate = null): ?array
{
    return booksNormalizeEntryFields($raw, false, $defaultEntryDate);
}

/** Confirm path: type, item_name, amount > 0, entry_date required. */
function booksNormalizeConfirmEntry(array $raw): ?array
{
    return booksNormalizeEntryFields($raw, true);
}

/**
 * Lightweight English fallback when Gemini returns no rows for an obvious
 * partial utterance like "I sold five kilograms of tomatoes".
 *
 * @return list<array<string,mixed>>
 */
function booksHeuristicDraftEntries(string $rawText, ?string $defaultEntryDate = null): array
{
    $text = strtolower(trim(preg_replace('/\s+/', ' ', $rawText) ?? ''));
    if ($text === "") {
        return [];
    }

    $words = [
        "zero" => 0, "one" => 1, "two" => 2, "three" => 3, "four" => 4,
        "five" => 5, "six" => 6, "seven" => 7, "eight" => 8, "nine" => 9,
        "ten" => 10, "eleven" => 11, "twelve" => 12, "fifteen" => 15,
        "twenty" => 20, "thirty" => 30, "forty" => 40, "fifty" => 50,
        "hundred" => 100,
    ];
    foreach ($words as $word => $num) {
        $text = preg_replace('/\b' . preg_quote($word, '/') . '\b/', (string)$num, $text) ?? $text;
    }

    $type = null;
    if (preg_match('/\b(sold|sell|sale|earned)\b/', $text)) {
        $type = "sale";
    } elseif (preg_match('/\b(bought|buy|purchase|purchased|stocked)\b/', $text)) {
        $type = "purchase";
    } elseif (preg_match('/\b(spent|spend|expense|paid for|paid)\b/', $text)) {
        $type = "expense";
    } else {
        return [];
    }

    $quantity = null;
    $unit = null;
    $itemName = "";
    $amount = 0.0;

    // "5 kg of tomatoes" / "5 kilograms of tomatoes"
    if (preg_match(
        '/\b(\d+(?:\.\d+)?)\s*([a-z]+)\s+of\s+([a-z][a-z\s]*?)(?:\s+for\s+(\d+(?:\.\d+)?))?$/u',
        $text,
        $m
    )) {
        $quantity = (float)$m[1];
        $unit = $m[2];
        $itemName = trim($m[3]);
        if (isset($m[4]) && $m[4] !== "") {
            $amount = (float)$m[4];
        }
    } elseif (preg_match(
        '/\b(\d+(?:\.\d+)?)\s+([a-z][a-z\s]*?)(?:\s+for\s+(\d+(?:\.\d+)?))?$/u',
        $text,
        $m
    )) {
        // "sold 5 tomatoes" / "sold 5 tomatoes for 10000"
        $quantity = (float)$m[1];
        $itemName = trim($m[2]);
        if (isset($m[3]) && $m[3] !== "") {
            $amount = (float)$m[3];
        }
    } elseif (preg_match('/\b(?:sold|sell|bought|buy|spent|paid)\s+(.+?)(?:\s+for\s+(\d+(?:\.\d+)?))?$/u', $text, $m)) {
        $itemName = trim($m[1]);
        if (isset($m[2]) && $m[2] !== "") {
            $amount = (float)$m[2];
        }
    }

    // Strip leading filler verbs left in item_name
    $itemName = trim(preg_replace(
        '/^(?:sold|sell|bought|buy|spent|paid|purchase|purchased)\s+/',
        '',
        $itemName
    ) ?? $itemName);
    $itemName = trim($itemName, " .,");

    if ($itemName === "") {
        return [];
    }

    $normalized = booksNormalizeDraftEntry([
        "type" => $type,
        "item_name" => $itemName,
        "quantity" => $quantity,
        "unit" => $unit,
        "amount" => $amount > 0 ? $amount : null,
    ], $defaultEntryDate);

    return $normalized !== null ? [$normalized] : [];
}

/**
 * @param callable(array):(?array) $normalizer
 */
function booksNormalizeEntriesListWith(mixed $entries, callable $normalizer): array
{
    if (!is_array($entries)) {
        return [];
    }

    // Allow { "entries": [...] } wrapper from model
    if (isset($entries["entries"]) && is_array($entries["entries"])) {
        $entries = $entries["entries"];
    }

    $out = [];
    foreach ($entries as $row) {
        if (!is_array($row)) {
            continue;
        }
        $normalized = $normalizer($row);
        if ($normalized !== null) {
            $out[] = $normalized;
        }
    }

    return $out;
}

function booksNormalizeDraftEntriesList(mixed $entries, ?string $defaultEntryDate = null): array
{
    return booksNormalizeEntriesListWith($entries, static function (array $row) use ($defaultEntryDate) {
        return booksNormalizeDraftEntry($row, $defaultEntryDate);
    });
}

function booksNormalizeConfirmEntriesList(mixed $entries): array
{
    return booksNormalizeEntriesListWith($entries, "booksNormalizeConfirmEntry");
}

/** @deprecated use booksNormalizeDraftEntriesList / booksNormalizeConfirmEntriesList */
function booksNormalizeEntriesList(mixed $entries): array
{
    return booksNormalizeDraftEntriesList($entries);
}

function booksFormatEntryRow(array $row): array
{
    return [
        "id" => (int)$row["id"],
        "book_input_id" => $row["book_input_id"] !== null ? (int)$row["book_input_id"] : null,
        "type" => $row["type"],
        "item_name" => $row["item_name"],
        "quantity" => $row["quantity"] !== null ? (float)$row["quantity"] : null,
        "unit" => $row["unit"],
        "amount" => (float)$row["amount"],
        "currency" => $row["currency"] ?? "UGX",
        "language" => $row["language"] ?? null,
        "entry_date" => $row["entry_date"],
        "status" => $row["status"] ?? "confirmed",
        "created_at" => $row["created_at"],
    ];
}

function booksEntrySelectColumns(): string
{
    return "id, book_input_id, type, item_name, quantity, unit, amount, currency, language, entry_date, status, created_at";
}

/**
 * Maps the 2-letter code the app sends ("en"/"sw"/"lg") to a full language
 * name for the Gemini prompt. Raw ISO codes like "lg" are ambiguous to the
 * model on their own — spelling out the name makes the language hint (and
 * the "don't translate" instruction below) unambiguous.
 */
function booksLanguageName(string $language): string
{
    switch ($language) {
        case "sw":
            return "Swahili";
        case "lg":
            return "Luganda";
        case "en":
            return "English";
        default:
            return "";
    }
}

function booksCallGemini(string $rawText, string $language): array
{
    $apiKey = getenv("GEMINI_API_KEY") ?: "";
    if ($apiKey === "") {
        throw new RuntimeException(
            "Gemini API key is not configured. Please contact the app administrator.",
            503
        );
    }

    /*
     * Gemini 2.5 Flash is no longer available to some new API users.
     * For this bookkeeping extraction workload, Gemini 3.5 Flash-Lite is a
     * better default: it is designed for low-latency structured extraction.
     *
     * You can still override this from the server environment:
     *   GEMINI_MODEL=gemini-3.6-flash
     */
    $model = trim((string)(getenv("GEMINI_MODEL") ?: "gemini-3.5-flash-lite"));

    /*
     * Interactions API is the recommended Gemini API for new integrations.
     * This request is stateless because this function only parses one
     * bookkeeping utterance at a time.
     */
    $url = "https://generativelanguage.googleapis.com/v1beta/interactions";

    $languageName = booksLanguageName($language);
    $langHint = $languageName !== ""
        ? " The utterance is in {$languageName}."
        : "";

    $preserveLanguageInstruction = $languageName !== ""
        ? " Keep item_name and unit in {$languageName}, exactly as spoken; do not translate them into English."
        : " Keep item_name and unit in the same language as the input text; do not translate them into English.";

    $prompt = "Extract business bookkeeping entries from the following text."
        . $langHint
        . $preserveLanguageInstruction
        . " Currency is UGX."
        . " type must be one of: sale, purchase, expense."
        . " sale = money in; purchase and expense = money out."
        . " Extract every distinct transaction mentioned."
        . " Partial utterances are valid."
        . " If a price or amount was not spoken, omit amount; never invent a price."
        . " quantity and unit are optional."
        . " If the speaker names an item and a transaction verb such as"
        . " sold, sell, bought, buy, spent, or paid, return at least one entry"
        . " even when the price is missing."
        . " Convert spoken numbers such as five to digits when possible."
        . "

Text:
"
        . $rawText;

    /*
     * Interactions API structured output.
     * Optional fields are deliberately not listed in "required", so Gemini can
     * omit quantity/unit/amount when the user did not provide them.
     */
    $payload = [
        "model" => $model,
        "input" => $prompt,
        "store" => false,
        "response_format" => [
            "type" => "text",
            "mime_type" => "application/json",
            "schema" => [
                "type" => "object",
                "properties" => [
                    "entries" => [
                        "type" => "array",
                        "items" => [
                            "type" => "object",
                            "properties" => [
                                "type" => [
                                    "type" => "string",
                                    "enum" => ["sale", "purchase", "expense"],
                                ],
                                "item_name" => [
                                    "type" => "string",
                                ],
                                "quantity" => [
                                    "type" => "number",
                                ],
                                "unit" => [
                                    "type" => "string",
                                ],
                                "amount" => [
                                    "type" => "number",
                                ],
                            ],
                            "required" => ["type", "item_name"],
                        ],
                    ],
                ],
                "required" => ["entries"],
            ],
        ],
    ];

    $jsonPayload = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($jsonPayload === false) {
        throw new RuntimeException("Failed to encode Gemini request", 500);
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException("Failed to initialize Gemini request", 502);
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Accept: application/json",
            "x-goog-api-key: " . $apiKey,
        ],
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 45,
    ]);

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0 || $body === false) {
        error_log(
            "booksCallGemini curl error {$errno}: "
            . ($curlError !== "" ? $curlError : "unknown cURL error")
        );
        throw new RuntimeException(
            "Could not reach the bookkeeping AI service. Please try again.",
            502
        );
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        error_log("booksCallGemini invalid response: " . substr((string)$body, 0, 1000));
        throw new RuntimeException("Invalid response from the bookkeeping AI service", 502);
    }

    if ($httpCode >= 400) {
        $message = clean(
            (string)(
                $decoded["error"]["message"]
                ?? $decoded["message"]
                ?? "Gemini API error"
            )
        );

        error_log(
            "booksCallGemini HTTP {$httpCode} model={$model}: "
            . ($message !== "" ? $message : "Gemini API error")
        );

        /*
         * Preserve useful upstream HTTP status codes where possible.
         * 429 = quota/rate limit, 4xx = request/configuration problem,
         * 5xx = upstream Gemini problem.
         */
        $status = ($httpCode >= 400 && $httpCode <= 599) ? $httpCode : 502;

        throw new RuntimeException(
            $message !== "" ? $message : "Gemini API error",
            $status
        );
    }

    /*
     * Current Interactions API REST response uses "steps".
     * Find the latest model_output text part.
     */
    $outputText = "";

    if (isset($decoded["steps"]) && is_array($decoded["steps"])) {
        for ($i = count($decoded["steps"]) - 1; $i >= 0; $i--) {
            $step = $decoded["steps"][$i];
            if (!is_array($step) || ($step["type"] ?? "") !== "model_output") {
                continue;
            }

            $content = $step["content"] ?? [];
            if (!is_array($content)) {
                continue;
            }

            foreach ($content as $part) {
                if (
                    is_array($part)
                    && ($part["type"] ?? "") === "text"
                    && isset($part["text"])
                    && is_string($part["text"])
                    && trim($part["text"]) !== ""
                ) {
                    $outputText = $part["text"];
                    break 2;
                }
            }
        }
    }

    /*
     * Compatibility fallback for older Interactions response shapes.
     * This makes deployment safer if a proxy/API revision still returns
     * the previous "outputs" form.
     */
    if ($outputText === "" && isset($decoded["outputs"]) && is_array($decoded["outputs"])) {
        for ($i = count($decoded["outputs"]) - 1; $i >= 0; $i--) {
            $part = $decoded["outputs"][$i];
            if (
                is_array($part)
                && isset($part["text"])
                && is_string($part["text"])
                && trim($part["text"]) !== ""
            ) {
                $outputText = $part["text"];
                break;
            }
        }
    }

    /*
     * Defensive fallback in case a gateway exposes output_text directly.
     */
    if (
        $outputText === ""
        && isset($decoded["output_text"])
        && is_string($decoded["output_text"])
    ) {
        $outputText = $decoded["output_text"];
    }

    if (trim($outputText) === "") {
        error_log(
            "booksCallGemini empty model output: "
            . substr(json_encode($decoded, JSON_UNESCAPED_UNICODE) ?: "", 0, 1500)
        );
        throw new RuntimeException("Empty response from the bookkeeping AI service", 502);
    }

    $parsed = json_decode($outputText, true);
    if (!is_array($parsed)) {
        error_log("booksCallGemini non-JSON output: " . substr($outputText, 0, 1000));
        throw new RuntimeException("The bookkeeping AI returned invalid JSON", 502);
    }

    if (!isset($parsed["entries"]) || !is_array($parsed["entries"])) {
        $parsed["entries"] = [];
    }

    return $parsed;
}

/**
 * Lightweight per-user cooldown so a client can't drive unlimited paid Gemini calls.
 * Reuses idx_book_inputs_user_created — no extra infra needed.
 */
function booksRateLimitExceeded(mysqli $conn, int $userId, int $maxRequests = 10, int $windowMinutes = 1): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c FROM book_inputs WHERE user_id = ? AND created_at >= (NOW() - INTERVAL ? MINUTE)"
    );
    if (!$stmt) {
        error_log("booksRateLimitExceeded: prepare failed: " . $conn->error);
        // Fail open on infra error — don't block legitimate users because of a DB hiccup.
        return false;
    }

    $stmt->bind_param("ii", $userId, $windowMinutes);
    $stmt->execute();
    $count = (int)($stmt->get_result()->fetch_assoc()["c"] ?? 0);
    $stmt->close();

    return $count >= $maxRequests;
}

function parse_book_input(mysqli $conn, array $user, array $input): void
{
    $userId = (int)($user["sub"] ?? 0);
    if ($userId <= 0) {
        respond([
            "status" => "error",
            "success" => false,
            "message" => "Unauthorized",
            "data" => null,
        ], 401);
    }

    $rawText = clean($input["raw_text"] ?? "");
    if ($rawText === "") {
        respond([
            "status" => "error",
            "success" => false,
            "message" => "raw_text is required",
            "data" => null,
        ], 422);
    }

    if (booksRateLimitExceeded($conn, $userId)) {
        respond([
            "status" => "error",
            "success" => false,
            "message" => "Too many requests. Please wait a moment and try again.",
            "data" => null,
        ], 429);
    }

    $language = strtolower(clean($input["language"] ?? ""));
    if ($language === "") {
        $language = null;
    }

    $stmt = $conn->prepare(
        "INSERT INTO book_inputs (user_id, raw_text, language, created_at) VALUES (?, ?, ?, NOW())"
    );
    if (!$stmt) {
        error_log("parse_book_input: insert prepare failed: " . $conn->error);
        respond([
            "status" => "error",
            "success" => false,
            "message" => "Something went wrong. Please try again.",
            "data" => null,
        ], 500);
    }

    $stmt->bind_param("iss", $userId, $rawText, $language);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        error_log("parse_book_input: insert failed: " . $err);
        respond([
            "status" => "error",
            "success" => false,
            "message" => "Something went wrong. Please try again.",
            "data" => null,
        ], 500);
    }

    $bookInputId = (int)$stmt->insert_id;
    $stmt->close();

    // Prefer the device's local clock — server UTC is often already "tomorrow" in the Americas.
    $defaultEntryDate = booksResolveNow(clean($input["client_date"] ?? ""))->format("Y-m-d H:i:s");

    try {
        $geminiRaw = booksCallGemini($rawText, $language ?? "");
        $entries = booksNormalizeDraftEntriesList($geminiRaw, $defaultEntryDate);
    } catch (Throwable $e) {
        $code = (int)$e->getCode();
        if ($code < 400 || $code > 599) {
            $code = 502;
        }
        respond([
            "status" => "error",
            "success" => false,
            "message" => $e->getMessage(),
            "data" => [
                "book_input_id" => $bookInputId,
                "raw_text" => $rawText,
                "entries" => [],
            ],
        ], $code);
    }

    // Gemini sometimes returns [] for price-less utterances; fall back locally.
    if ($entries === []) {
        $entries = booksHeuristicDraftEntries($rawText, $defaultEntryDate);
    }

    $suggestedJson = json_encode(["entries" => $entries], JSON_UNESCAPED_UNICODE);
    $upd = $conn->prepare("UPDATE book_inputs SET suggested_json = ? WHERE id = ? AND user_id = ?");
    if ($upd) {
        $upd->bind_param("sii", $suggestedJson, $bookInputId, $userId);
        $upd->execute();
        $upd->close();
    }

    $savedRows = [];
    if ($entries !== []) {
        $conn->begin_transaction();
        try {
            $insert = $conn->prepare(
                "INSERT INTO book_entries
                    (user_id, book_input_id, type, item_name, quantity, unit, amount, currency, language, entry_date, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'UGX', ?, COALESCE(?, NOW()), 'draft', NOW())"
            );
            if (!$insert) {
                throw new Exception("Draft insert prepare failed: " . $conn->error);
            }

            $savedIds = [];
            foreach ($entries as $entry) {
                $type = $entry["type"];
                $itemName = $entry["item_name"];
                $quantity = $entry["quantity"];
                $unit = $entry["unit"];
                $amount = $entry["amount"];
                $entryDate = $entry["entry_date"];
                $quantityStr = $quantity === null ? null : (string)$quantity;

                $insert->bind_param(
                    "iissssdss",
                    $userId,
                    $bookInputId,
                    $type,
                    $itemName,
                    $quantityStr,
                    $unit,
                    $amount,
                    $language,
                    $entryDate
                );

                if (!$insert->execute()) {
                    throw new Exception("Failed to save draft entry: " . $insert->error);
                }
                $savedIds[] = (int)$insert->insert_id;
            }
            $insert->close();
            $conn->commit();

            $cols = booksEntrySelectColumns();
            $placeholders = implode(",", array_fill(0, count($savedIds), "?"));
            $types = str_repeat("i", count($savedIds));
            $fetch = $conn->prepare(
                "SELECT {$cols}
                 FROM book_entries
                 WHERE user_id = ? AND id IN ($placeholders)
                 ORDER BY id ASC"
            );
            if ($fetch) {
                $bindTypes = "i" . $types;
                $bindParams = array_merge([$userId], $savedIds);
                $fetch->bind_param($bindTypes, ...$bindParams);
                $fetch->execute();
                $result = $fetch->get_result();
                while ($row = $result->fetch_assoc()) {
                    $savedRows[] = booksFormatEntryRow($row);
                }
                $fetch->close();
            }
        } catch (Throwable $e) {
            $conn->rollback();
            error_log("parse_book_input draft insert: " . $e->getMessage());
            respond([
                "status" => "error",
                "success" => false,
                "message" => "Something went wrong. Please try again.",
                "data" => [
                    "book_input_id" => $bookInputId,
                    "raw_text" => $rawText,
                    "entries" => [],
                ],
            ], 500);
        }
    }

    respond([
        "status" => "success",
        "success" => true,
        "message" => "Parsed successfully",
        "data" => [
            "book_input_id" => $bookInputId,
            "raw_text" => $rawText,
            "entries" => $savedRows,
        ],
    ]);
}

function confirm_book_entries(mysqli $conn, array $user, array $input): void
{
    $userId = (int)($user["sub"] ?? 0);
    if ($userId <= 0) {
        respond([
            "status" => "error",
            "success" => false,
            "message" => "Unauthorized",
            "data" => null,
        ], 401);
    }

    $bookInputId = (int)($input["book_input_id"] ?? 0);
    if ($bookInputId <= 0) {
        respond([
            "status" => "error",
            "success" => false,
            "message" => "book_input_id is required",
            "data" => null,
        ], 422);
    }

    $rawEntries = $input["entries"] ?? null;
    if (!is_array($rawEntries)) {
        respond([
            "status" => "error",
            "success" => false,
            "message" => "entries must contain at least one valid item",
            "data" => null,
        ], 422);
    }
    if (isset($rawEntries["entries"]) && is_array($rawEntries["entries"])) {
        $rawEntries = $rawEntries["entries"];
    }

    $entries = [];
    foreach ($rawEntries as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int)($row["id"] ?? 0);
        if ($id <= 0) {
            respond([
                "status" => "error",
                "success" => false,
                "message" => "Each entry must include a draft id",
                "data" => null,
            ], 422);
        }
        $normalized = booksNormalizeConfirmEntry($row);
        if ($normalized === null) {
            respond([
                "status" => "error",
                "success" => false,
                "message" => "Each entry requires type, item_name, amount > 0, and entry_date",
                "data" => null,
            ], 422);
        }
        $normalized["id"] = $id;
        $entries[] = $normalized;
    }

    if ($entries === []) {
        respond([
            "status" => "error",
            "success" => false,
            "message" => "entries must contain at least one valid item",
            "data" => null,
        ], 422);
    }

    $check = $conn->prepare("SELECT id, language FROM book_inputs WHERE id = ? AND user_id = ? LIMIT 1");
    if (!$check) {
        error_log("confirm_book_entries: lookup prepare failed: " . $conn->error);
        respond([
            "status" => "error",
            "success" => false,
            "message" => "Something went wrong. Please try again.",
            "data" => null,
        ], 500);
    }
    $check->bind_param("ii", $bookInputId, $userId);
    $check->execute();
    $exists = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$exists) {
        respond([
            "status" => "error",
            "success" => false,
            "message" => "Book input not found",
            "data" => null,
        ], 404);
    }

    $language = $exists["language"] ?? null;
    $confirmIds = array_map(static fn(array $e): int => (int)$e["id"], $entries);

    $conn->begin_transaction();
    try {
        $update = $conn->prepare(
            "UPDATE book_entries
             SET type = ?, item_name = ?, quantity = ?, unit = ?, amount = ?,
                 language = COALESCE(?, language), entry_date = COALESCE(?, entry_date), status = 'confirmed'
             WHERE id = ? AND user_id = ? AND book_input_id = ? AND status = 'draft'"
        );
        if (!$update) {
            throw new Exception("Update prepare failed: " . $conn->error);
        }

        $savedIds = [];
        foreach ($entries as $entry) {
            $id = (int)$entry["id"];
            $type = $entry["type"];
            $itemName = $entry["item_name"];
            $quantity = $entry["quantity"];
            $unit = $entry["unit"];
            $amount = $entry["amount"];
            $entryDate = $entry["entry_date"];
            $quantityStr = $quantity === null ? null : (string)$quantity;

            $update->bind_param(
                "ssssdssiii",
                $type,
                $itemName,
                $quantityStr,
                $unit,
                $amount,
                $language,
                $entryDate,
                $id,
                $userId,
                $bookInputId
            );

            if (!$update->execute()) {
                throw new Exception("Failed to confirm entry: " . $update->error);
            }
            if ($update->affected_rows < 1) {
                throw new Exception("Draft entry not found or already confirmed: id={$id}");
            }
            $savedIds[] = $id;
        }
        $update->close();

        // Void any sibling drafts for this input that were not confirmed.
        if ($confirmIds !== []) {
            $placeholders = implode(",", array_fill(0, count($confirmIds), "?"));
            $types = str_repeat("i", count($confirmIds));
            $void = $conn->prepare(
                "UPDATE book_entries
                 SET status = 'voided'
                 WHERE user_id = ? AND book_input_id = ? AND status = 'draft'
                   AND id NOT IN ($placeholders)"
            );
            if (!$void) {
                throw new Exception("Void prepare failed: " . $conn->error);
            }
            $bindTypes = "ii" . $types;
            $bindParams = array_merge([$userId, $bookInputId], $confirmIds);
            $void->bind_param($bindTypes, ...$bindParams);
            if (!$void->execute()) {
                throw new Exception("Void unused drafts failed: " . $void->error);
            }
            $void->close();
        }

        $conn->commit();

        $cols = booksEntrySelectColumns();
        $placeholders = implode(",", array_fill(0, count($savedIds), "?"));
        $types = str_repeat("i", count($savedIds));
        $fetch = $conn->prepare(
            "SELECT {$cols}
             FROM book_entries
             WHERE user_id = ? AND id IN ($placeholders)
             ORDER BY id ASC"
        );
        if (!$fetch) {
            throw new Exception("Fetch prepare failed: " . $conn->error);
        }

        $bindTypes = "i" . $types;
        $bindParams = array_merge([$userId], $savedIds);
        $fetch->bind_param($bindTypes, ...$bindParams);
        $fetch->execute();
        $result = $fetch->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = booksFormatEntryRow($row);
        }
        $fetch->close();

        respond([
            "status" => "success",
            "success" => true,
            "message" => "Entries saved",
            "data" => [
                "book_input_id" => $bookInputId,
                "entries" => $rows,
            ],
        ]);
    } catch (Throwable $e) {
        $conn->rollback();
        error_log("confirm_book_entries: " . $e->getMessage());
        $isMissingDraft = str_contains($e->getMessage(), "Draft entry not found");
        respond([
            "status" => "error",
            "success" => false,
            "message" => $isMissingDraft
                ? $e->getMessage()
                : "Something went wrong. Please try again.",
            "data" => null,
        ], $isMissingDraft ? 404 : 500);
    }
}

/**
 * Books entries list is paginated independently from the Today/Week/Month
 * stats: it's a flat, count-based feed (newest first) unrelated to calendar
 * windows. `offset` is the number of rows already loaded by the client;
 * each page returns up to PAGE_SIZE rows starting after that offset, with
 * has_more signalling whether another page is available.
 */
function get_book_entries(mysqli $conn, array $user, array $input): void
{
    $userId = (int)($user["sub"] ?? 0);
    if ($userId <= 0) {
        respond([
            "status" => "error",
            "success" => false,
            "message" => "Unauthorized",
            "data" => null,
        ], 401);
    }

    $pageSize = 25;
    $offset = max(0, (int)($input["offset"] ?? 0));

    $searchTerm = trim((string)($input["q"] ?? ""));
    $hasSearch = $searchTerm !== "";
    $likeTerm = "%" . str_replace(["%", "_"], ["\\%", "\\_"], $searchTerm) . "%";

    $entryFilter = $hasSearch
        ? "user_id = ? AND status = 'confirmed' AND item_name LIKE ?"
        : "user_id = ? AND status = 'confirmed'";

    // Fetch one extra row beyond the page size so has_more can be derived
    // without a second query; the extra row (if present) is trimmed below.
    $fetchLimit = $pageSize + 1;

    $cols = booksEntrySelectColumns();
    $stmt = $conn->prepare(
        "SELECT {$cols}
         FROM book_entries
         WHERE {$entryFilter}
         ORDER BY entry_date DESC, id DESC
         LIMIT ? OFFSET ?"
    );
    if (!$stmt) {
        error_log("get_book_entries: query prepare failed: " . $conn->error);
        respond([
            "status" => "error",
            "success" => false,
            "message" => "Something went wrong. Please try again.",
            "data" => null,
        ], 500);
    }

    if ($hasSearch) {
        $stmt->bind_param("isii", $userId, $likeTerm, $fetchLimit, $offset);
    } else {
        $stmt->bind_param("iii", $userId, $fetchLimit, $offset);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $entries = [];
    while ($row = $result->fetch_assoc()) {
        $entries[] = booksFormatEntryRow($row);
    }
    $stmt->close();

    $hasMore = count($entries) > $pageSize;
    if ($hasMore) {
        array_pop($entries);
    }

    respond([
        "status" => "success",
        "success" => true,
        "message" => "OK",
        "data" => [
            "entries" => $entries,
            "offset" => $offset,
            "limit" => $pageSize,
            "has_more" => $hasMore,
        ],
    ]);
}

function get_book_summary(mysqli $conn, array $user, array $input): void
{
    $userId = (int)($user["sub"] ?? 0);
    if ($userId <= 0) {
        respond([
            "status" => "error",
            "success" => false,
            "message" => "Unauthorized",
            "data" => null,
        ], 401);
    }

    [$period, $start, $end] = booksPeriodBounds($input["period"] ?? "today", $input["client_date"] ?? "");

    $stmt = $conn->prepare(
        "SELECT
            " . books_money_select_sql() . "
         FROM book_entries
         WHERE user_id = ? AND status = 'confirmed' AND entry_date BETWEEN ? AND ?"
    );
    if (!$stmt) {
        error_log("get_book_summary: query prepare failed: " . $conn->error);
        respond([
            "status" => "error",
            "success" => false,
            "message" => "Something went wrong. Please try again.",
            "data" => null,
        ], 500);
    }

    $stmt->bind_param("iss", $userId, $start, $end);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: ["money_in" => 0, "money_out" => 0];
    $stmt->close();

    $moneyIn = (float)$row["money_in"];
    $moneyOut = (float)$row["money_out"];

    respond([
        "status" => "success",
        "success" => true,
        "message" => "OK",
        "data" => [
            "period" => $period,
            "money_in" => $moneyIn,
            "money_out" => $moneyOut,
            "net" => $moneyIn - $moneyOut,
        ],
    ]);
}

/**
 * Full-period report for the Reports screen: totals, by-type breakdown, and
 * top 5 sales items for a full calendar week/month (optionally a past one via
 * "offset"). Distinct from get_book_summary(), which is "to date" (WTD/MTD).
 */
function get_book_report(mysqli $conn, array $user, array $input): void
{
    $userId = (int)($user["sub"] ?? 0);
    if ($userId <= 0) {
        respond([
            "status" => "error",
            "success" => false,
            "message" => "Unauthorized",
            "data" => null,
        ], 401);
    }

    $offsetInput = $input["offset"] ?? 0;
    if (!is_numeric($offsetInput)) {
        respond([
            "status" => "error",
            "success" => false,
            "message" => "Invalid offset",
            "data" => null,
        ], 422);
    }
    $offset = (int)$offsetInput;

    [$period, $start, $end] = booksReportPeriodBounds($input["period"] ?? "month", $input["client_date"] ?? "", $offset);

    $stmt = $conn->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN type = 'sale' THEN amount ELSE 0 END), 0) AS sales,
            COALESCE(SUM(CASE WHEN type = 'purchase' THEN amount ELSE 0 END), 0) AS purchases,
            COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS expenses
         FROM book_entries
         WHERE user_id = ? AND status = 'confirmed' AND entry_date BETWEEN ? AND ?"
    );
    if (!$stmt) {
        error_log("get_book_report: totals query prepare failed: " . $conn->error);
        respond([
            "status" => "error",
            "success" => false,
            "message" => "Something went wrong. Please try again.",
            "data" => null,
        ], 500);
    }

    $stmt->bind_param("iss", $userId, $start, $end);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: ["sales" => 0, "purchases" => 0, "expenses" => 0];
    $stmt->close();

    $sales = (float)$row["sales"];
    $purchases = (float)$row["purchases"];
    $expenses = (float)$row["expenses"];

    $totals = books_roll_up([
        "sale" => $sales,
        "purchase" => $purchases,
        "expense" => $expenses,
    ]);
    $moneyIn = $totals["money_in"];
    $moneyOut = $totals["money_out"];

    $topStmt = $conn->prepare(
        "SELECT
            item_name,
            SUM(amount) AS total_amount,
            SUM(quantity) AS total_quantity,
            COUNT(*) AS entry_count,
            MAX(currency) AS currency
         FROM book_entries
         WHERE user_id = ? AND status = 'confirmed' AND entry_date BETWEEN ? AND ? AND type = 'sale'
         GROUP BY item_name
         ORDER BY total_amount DESC, item_name ASC
         LIMIT 5"
    );
    if (!$topStmt) {
        error_log("get_book_report: top items query prepare failed: " . $conn->error);
        respond([
            "status" => "error",
            "success" => false,
            "message" => "Something went wrong. Please try again.",
            "data" => null,
        ], 500);
    }

    $topStmt->bind_param("iss", $userId, $start, $end);
    $topStmt->execute();
    $topResult = $topStmt->get_result();
    $topItems = [];
    while ($topRow = $topResult->fetch_assoc()) {
        $topItems[] = [
            "item_name" => $topRow["item_name"],
            "total_amount" => (float)$topRow["total_amount"],
            "total_quantity" => $topRow["total_quantity"] !== null ? (float)$topRow["total_quantity"] : null,
            "entry_count" => (int)$topRow["entry_count"],
            "currency" => $topRow["currency"] ?? "UGX",
        ];
    }
    $topStmt->close();

    respond([
        "status" => "success",
        "success" => true,
        "message" => "OK",
        "data" => [
            "period" => $period,
            "offset" => $offset,
            "start_date" => substr($start, 0, 10),
            "end_date" => substr($end, 0, 10),
            "money_in" => $moneyIn,
            "money_out" => $moneyOut,
            "net" => $moneyIn - $moneyOut,
            "currency" => "UGX",
            "by_type" => [
                "sales" => $sales,
                "purchases" => $purchases,
                "expenses" => $expenses,
            ],
            "top_items" => $topItems,
        ],
    ]);
}

function delete_book_entry(mysqli $conn, array $user, array $input): void
{
    $userId = (int)($user["sub"] ?? 0);
    if ($userId <= 0) {
        respond([
            "status" => "error",
            "success" => false,
            "message" => "Unauthorized",
            "data" => null,
        ], 401);
    }

    $id = (int)($input["id"] ?? 0);
    if ($id <= 0) {
        respond([
            "status" => "error",
            "success" => false,
            "message" => "id is required",
            "data" => null,
        ], 422);
    }

    $stmt = $conn->prepare(
        "SELECT id FROM book_entries WHERE id = ? AND user_id = ? AND status = 'confirmed' LIMIT 1"
    );
    if (!$stmt) {
        error_log("delete_book_entry: lookup prepare failed: " . $conn->error);
        respond([
            "status" => "error",
            "success" => false,
            "message" => "Something went wrong. Please try again.",
            "data" => null,
        ], 500);
    }
    $stmt->bind_param("ii", $id, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        respond([
            "status" => "error",
            "success" => false,
            "message" => "Entry not found",
            "data" => null,
        ], 404);
    }

    $upd = $conn->prepare(
        "UPDATE book_entries SET status = 'voided' WHERE id = ? AND user_id = ? AND status = 'confirmed'"
    );
    if (!$upd) {
        error_log("delete_book_entry: update prepare failed: " . $conn->error);
        respond([
            "status" => "error",
            "success" => false,
            "message" => "Something went wrong. Please try again.",
            "data" => null,
        ], 500);
    }
    $upd->bind_param("ii", $id, $userId);
    if (!$upd->execute() || $upd->affected_rows < 1) {
        $upd->close();
        respond([
            "status" => "error",
            "success" => false,
            "message" => "Something went wrong. Please try again.",
            "data" => null,
        ], 500);
    }
    $upd->close();

    respond([
        "status" => "success",
        "success" => true,
        "message" => "Entry deleted",
        "data" => [
            "id" => $id,
            "deleted" => true,
            "status" => "voided",
        ],
    ]);
}

/**
 * Full reject / start over: void draft entries for a book_input, then delete the input.
 */
function reject_book_input(mysqli $conn, array $user, array $input): void
{
    $userId = (int)($user["sub"] ?? 0);
    if ($userId <= 0) {
        respond([
            "status" => "error",
            "success" => false,
            "message" => "Unauthorized",
            "data" => null,
        ], 401);
    }

    $bookInputId = (int)($input["book_input_id"] ?? 0);
    if ($bookInputId <= 0) {
        respond([
            "status" => "error",
            "success" => false,
            "message" => "book_input_id is required",
            "data" => null,
        ], 422);
    }

    $check = $conn->prepare("SELECT id FROM book_inputs WHERE id = ? AND user_id = ? LIMIT 1");
    if (!$check) {
        error_log("reject_book_input: lookup prepare failed: " . $conn->error);
        respond([
            "status" => "error",
            "success" => false,
            "message" => "Something went wrong. Please try again.",
            "data" => null,
        ], 500);
    }
    $check->bind_param("ii", $bookInputId, $userId);
    $check->execute();
    $exists = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$exists) {
        respond([
            "status" => "error",
            "success" => false,
            "message" => "Book input not found",
            "data" => null,
        ], 404);
    }

    $confirmed = $conn->prepare(
        "SELECT COUNT(*) AS c FROM book_entries
         WHERE book_input_id = ? AND user_id = ? AND status = 'confirmed'"
    );
    if (!$confirmed) {
        error_log("reject_book_input: confirmed count prepare failed: " . $conn->error);
        respond([
            "status" => "error",
            "success" => false,
            "message" => "Something went wrong. Please try again.",
            "data" => null,
        ], 500);
    }
    $confirmed->bind_param("ii", $bookInputId, $userId);
    $confirmed->execute();
    $confirmedCount = (int)($confirmed->get_result()->fetch_assoc()["c"] ?? 0);
    $confirmed->close();

    if ($confirmedCount > 0) {
        respond([
            "status" => "error",
            "success" => false,
            "message" => "Cannot reject: entries already confirmed",
            "data" => null,
        ], 409);
    }

    $voided = 0;
    $conn->begin_transaction();
    try {
        $void = $conn->prepare(
            "UPDATE book_entries SET status = 'voided'
             WHERE book_input_id = ? AND user_id = ? AND status = 'draft'"
        );
        if (!$void) {
            throw new Exception("Void prepare failed: " . $conn->error);
        }
        $void->bind_param("ii", $bookInputId, $userId);
        if (!$void->execute()) {
            throw new Exception("Void failed: " . $void->error);
        }
        $voided = (int)$void->affected_rows;
        $void->close();

        // Null out FK before deleting input (ON DELETE SET NULL also works, but be explicit).
        $clearFk = $conn->prepare(
            "UPDATE book_entries SET book_input_id = NULL
             WHERE book_input_id = ? AND user_id = ?"
        );
        if (!$clearFk) {
            throw new Exception("Clear FK prepare failed: " . $conn->error);
        }
        $clearFk->bind_param("ii", $bookInputId, $userId);
        if (!$clearFk->execute()) {
            throw new Exception("Clear FK failed: " . $clearFk->error);
        }
        $clearFk->close();

        $delInput = $conn->prepare("DELETE FROM book_inputs WHERE id = ? AND user_id = ?");
        if (!$delInput) {
            throw new Exception("Input delete prepare failed: " . $conn->error);
        }
        $delInput->bind_param("ii", $bookInputId, $userId);
        if (!$delInput->execute()) {
            throw new Exception("Input delete failed: " . $delInput->error);
        }
        $delInput->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log("reject_book_input: " . $e->getMessage());
        respond([
            "status" => "error",
            "success" => false,
            "message" => "Something went wrong. Please try again.",
            "data" => null,
        ], 500);
    }

    respond([
        "status" => "success",
        "success" => true,
        "message" => "Draft discarded",
        "data" => [
            "book_input_id" => $bookInputId,
            "voided" => $voided,
            "rejected" => true,
        ],
    ]);
}
