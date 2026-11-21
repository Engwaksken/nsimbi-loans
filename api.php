<?php
declare(strict_types=1);

/**
 * Mobile API endpoint.
 *
 * Database credentials are loaded from a `.env` file in the same directory as
 * this script (DB_HOST, DB_PORT, DB_USER, DB_PASS, DB_NAME), matching the rest
 * of the application. Host-provided environment variables take precedence.
 */

// -- Load .env ---------------------------------------------------------------
$envFile = __DIR__ . DIRECTORY_SEPARATOR . '.env';
if (is_file($envFile) && is_readable($envFile)) {
    $envLines = file($envFile, FILE_IGNORE_NEW_LINES);
    if (is_array($envLines)) {
        foreach ($envLines as $index => $line) {
            if ($index === 0) {
                $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
            }
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$name, $value] = array_map('trim', explode('=', $line, 2));
            if (!preg_match('/^[A-Z_][A-Z0-9_]*$/', $name) || getenv($name) !== false) {
                continue;
            }
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }
            $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// -- Database configuration (from .env) --------------------------------------
$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbUser = getenv('DB_USER') ?: '';
$dbPassValue = getenv('DB_PASS');
$dbPass = $dbPassValue !== false ? (string)$dbPassValue : (string)(getenv('DB_PASSWORD') ?: '');
$dbName = getenv('DB_NAME') ?: '';
$dbPort = (int)(getenv('DB_PORT') ?: 3306);

require "config.php";
require "jwt.php";
require_once __DIR__ . '/includes/loan_workflow.php';
require_once __DIR__ . '/includes/auth_lookup.php';
require_once __DIR__ . '/includes/loan_assessment.php';
require_once __DIR__ . '/includes/loan_offers.php';

header("Content-Type: application/json; charset=utf-8");

if ($dbUser === '' || $dbName === '') {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Database configuration is missing (DB_USER/DB_NAME) in .env"
    ]);
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
    $conn->set_charset("utf8mb4");
} catch (Throwable $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Database connection failed. Check the .env database settings."
    ]);
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Database connection not found"
    ]);
    exit;
}


function respond(array $data, int $code = 200): void
{
    http_response_code($code);

    
    if (!array_key_exists("success", $data)) {
        $status = strtolower((string)($data["status"] ?? ""));
        $data["success"] = ($status === "success" || ($code >= 200 && $code < 300 && $status !== "error"));
    }

    if (!array_key_exists("status", $data)) {
        $data["status"] = !empty($data["success"]) ? "success" : "error";
    }

    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
    exit;
}


function clean(mixed $value): string
{
    if (is_array($value) || is_object($value)) {
        return "";
    }

    return trim((string)$value);
}


$rawInput = file_get_contents("php://input");
$input = json_decode($rawInput, true);

if (!is_array($input)) {
    $input = $_POST;
}

$action = clean($input["action"] ?? $_POST["action"] ?? $_GET["action"] ?? "");

function moneyValue(mixed $value): float
{
    $raw = clean($value);

    if ($raw === "") {
        return 0.0;
    }

    $normalized = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, "UTF-8");
    $normalized = str_replace(["UGX", "Ugx", "ugx", "/=", "SHS", "Shs", "shs"], "", $normalized);
    $normalized = str_replace(["\u{2014}", "\u{2013}", "-"], "-", $normalized);
    $normalized = preg_replace("/\s+/", " ", trim($normalized));

    $key = strtolower($normalized);
    $key = str_replace(",", "", $key);

    $known = [
        "10000 - 50000"     => 30000,
        "10 000 - 50 000"   => 30000,
        "50000 - 100000"    => 75000,
        "50 000 - 100 000"  => 75000,
        "100000 - 500000"   => 300000,
        "100 000 - 500 000" => 300000,
        "500000 - 1m"       => 750000,
        "500 000 - 1m"      => 750000,
        "1 - 2m"            => 1500000,
        "1m - 2m"           => 1500000,
        "1 m - 2 m"         => 1500000,
        "above 2m"          => 2000000,
        "over 2m"           => 2000000,
    ];

    if (isset($known[$key])) {
        return (float)$known[$key];
    }

    if (str_contains($key, "-")) {
        [$leftRaw, $rightRaw] = array_map("trim", explode("-", $key, 2));

        $rightHasMillion = preg_match("/(?:m|million)$/i", str_replace(" ", "", $rightRaw)) === 1;
        $leftHasMillion = preg_match("/(?:m|million)$/i", str_replace(" ", "", $leftRaw)) === 1;

        $left = parseMoneyPart($leftRaw, $rightHasMillion && !$leftHasMillion);
        $right = parseMoneyPart($rightRaw, false);

        if ($left > 0 && $right > 0) {
            return round(($left + $right) / 2);
        }

        if ($left > 0) {
            return $left;
        }

        if ($right > 0) {
            return $right;
        }
    }

    return parseMoneyPart($key, false);
}


function parseMoneyPart(string $part, bool $forceMillion = false): float
{
    $part = strtolower(trim($part));
    $part = str_replace(",", "", $part);
    $part = preg_replace("/\s+/", "", $part);

    if ($part === "") {
        return 0.0;
    }

    $part = preg_replace("/^(above|over)/", "", $part);
    $part = trim($part);

    if (preg_match("/^([0-9]+(?:\.[0-9]+)?)(m|million)$/", $part, $m)) {
        return (float)$m[1] * 1000000;
    }

    // "20k" / "20thousand" (spaces are already removed above)
    if (preg_match("/^([0-9]+(?:\.[0-9]+)?)(k|thousand)$/", $part, $m)) {
        return (float)$m[1] * 1000;
    }

    if (preg_match("/^([0-9]+(?:\.[0-9]+)?)$/", $part, $m)) {
        $number = (float)$m[1];
        return $forceMillion ? ($number * 1000000) : $number;
    }

    $digits = preg_replace("/[^0-9.]/", "", $part);

    if ($digits === "" || !is_numeric($digits)) {
        return 0.0;
    }

    return (float)$digits;
}


/**
 * The app shows dates of birth as DD-MM-YYYY (older versions sent DD/MM/YYYY
 * or YYYY-MM-DD). Store them as YYYY-MM-DD; anything else is kept as typed.
 */
function normalizeDob(string $dob): string
{
    if (preg_match('~^(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})$~', $dob, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }
    if (preg_match('~^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})$~', $dob, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }
    return $dob;
}


function businessScore(string $businessType): int
{
    $businessType = trim($businessType);

    $scores = [
        "Beauty salon"             => 6,
        "Beauty Salon"             => 6,
        "Education"                => 8,
        "Electronics"              => 4,
        "Farming"                  => 5,
        "Healthcare"               => 9,
        "HealthCare"               => 9,
        "Market stall"             => 8,
        "Market Stall"             => 8,
        "Poultry keeping"          => 4,
        "Poultry Farming"          => 4,
        "Restaurant"               => 7,
        "Retail shop"              => 6,
        "Retail Shop"              => 6,
        "Sell second hand clothes" => 4,
        "Tailoring"                => 7,
        "Transportation"           => 5,
        "Other"                    => 3,
        "other"                    => 3,
    ];

    return $scores[$businessType] ?? 3;
}


function formatDateOnly(?string $value): string
{
    $value = clean($value);

    if ($value === "" || $value === "0000-00-00" || $value === "0000-00-00 00:00:00") {
        return "";
    }

    $ts = strtotime($value);

    if ($ts === false) {
        return $value;
    }

    return date("Y-m-d", $ts);
}

function saveBase64Image(string $base64, string $field, string $uploadDir = "uploads/"): string
{
    if ($base64 === "") {
        return "";
    }

    if (str_contains($base64, ",")) {
        [, $base64] = explode(",", $base64, 2);
    }

    $decoded = base64_decode($base64, true);

    if ($decoded === false) {
        respond(["status" => "error", "message" => "Invalid image data for {$field}"], 422);
    }

    $info = getimagesizefromstring($decoded);

    if ($info === false) {
        respond(["status" => "error", "message" => "Invalid image file for {$field}"], 422);
    }

    $ext = match ($info["mime"] ?? "") {
        "image/png"  => ".png",
        "image/gif"  => ".gif",
        "image/webp" => ".webp",
        default      => ".jpg",
    };

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        respond(["status" => "error", "message" => "Upload folder could not be created"], 500);
    }

    $filename = rtrim($uploadDir, "/") . "/" . date("YmdHis") . "_" . bin2hex(random_bytes(5)) . "_{$field}{$ext}";

    if (!file_put_contents($filename, $decoded)) {
        respond(["status" => "error", "message" => "Failed to save {$field}"], 500);
    }

    return $filename;
}


function requireAuth(): array
{
    $headers = function_exists("getallheaders") ? getallheaders() : [];
    $authHeader = $headers["Authorization"] ?? $headers["authorization"] ?? "";

    if ($authHeader === "") {
        respond(["status" => "error", "message" => "Unauthorized"], 401);
    }

    $token = trim(preg_replace("/^Bearer\s+/i", "", $authHeader));
    $data = verifyJWT($token);

    if (!$data) {
        respond(["status" => "error", "message" => "Invalid or expired token"], 401);
    }

    return $data;
}

function login(mysqli $conn, array $input): void
{
    // Accepts email, username or (clients) phone number, same as the website.
    // The field is still called "username" for older app versions.
    $identifier = clean($input["username"] ?? $input["login"] ?? "");
    $password = (string)($input["password"] ?? "");

    if ($identifier === "" || trim($password) === "") {
        respond(["status" => "error", "message" => "Enter your email, phone number or username, and your password"], 422);
    }

    try {
        $attempt = auth_attempt($conn, $identifier, $password);
    } catch (Throwable $e) {
        error_log("API login lookup failed: " . $e->getMessage());
        respond(["status" => "error", "message" => "Login is temporarily unavailable. Please try again."], 500);
    }

    if ($attempt["result"] === "inactive") {
        respond(["status" => "error", "message" => "Your account is not active. Please contact the office."], 403);
    }
    if ($attempt["result"] === "ambiguous") {
        respond(["status" => "error", "message" => "More than one account uses these details. Please sign in with your email or username."], 409);
    }
    if ($attempt["result"] !== "ok") {
        respond(["status" => "error", "message" => "Invalid login details or password"], 401);
    }

    $user = $attempt["user"];
    $id = (int)$user["id"];
    $role = (int)$user["role"];

    $payload = [
        "sub"  => $id,
        "role" => $role,
        "iat"  => time(),
        "exp"  => time() + JWT_EXPIRE,
    ];

    respond([
        "status" => "success",
        "message" => "Login successful",
        "token"  => createJWT($payload),
        "user"   => [
            "id" => $id,
            "role" => $role,
            "username" => (string)($user["username"] ?? ""),
            "email" => (string)($user["email"] ?? ""),
            "fname" => (string)($user["fname"] ?? ""),
            "lname" => (string)($user["lname"] ?? ""),
        ],
    ]);
}

/**
 * Register client.
 */
function addClient(mysqli $conn, array $input): void
{
    $fname           = clean($input["fname"] ?? "");
    $lname           = clean($input["lname"] ?? "");
    $username        = clean($input["username"] ?? "");
    $gender          = clean($input["gender"] ?? "");
    $dob             = normalizeDob(clean($input["dob"] ?? ""));
    $phone           = clean($input["phone"] ?? "");
    $email           = clean($input["email"] ?? "");
    $business_name   = clean($input["business_name"] ?? "");
    $business_type   = clean($input["business_type"] ?? "");
    $amount_last_raw = clean($input["amount_last"] ?? "");
    $amount_this_raw = clean($input["amount_this"] ?? "");
    $district        = clean($input["district"] ?? "");
    $location        = clean($input["location"] ?? "");
    $nok_name        = clean($input["nok_name"] ?? "");
    $nok_tel         = clean($input["nok_tel"] ?? "");
    $nok_rel         = clean($input["nok_rel"] ?? "");
    $business_period = clean($input["business_period"] ?? "");
    $nin             = clean($input["nin"] ?? "");
    $password_raw    = clean($input["password"] ?? "");

    if ($fname === "" || $lname === "" || $username === "" || $phone === "" || $nin === "" || $password_raw === "") {
        respond(["status" => "error", "message" => "Required fields missing"], 422);
    }

    if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(["status" => "error", "message" => "Invalid email address"], 422);
    }

    if (auth_phone_key($phone) === "") {
        respond(["status" => "error", "message" => "Please enter a valid phone number"], 422);
    }

    // Same rule as website sign-up: username, email and phone must not already
    // belong to another account, so every identifier signs in to one account.
    try {
        $conflict = auth_identity_conflict($conn, $username, $email, $phone);
    } catch (Throwable $e) {
        error_log("API sign-up duplicate check failed: " . $e->getMessage());
        respond(["status" => "error", "message" => "Registration is temporarily unavailable. Please try again."], 500);
    }
    if ($conflict !== "") {
        respond(["status" => "error", "message" => $conflict], 409);
    }

    $stmt = $conn->prepare("SELECT id FROM customers WHERE nin = ? LIMIT 1");
    if (!$stmt) {
        respond(["status" => "error", "message" => "NIN check failed: " . $conn->error], 500);
    }
    $stmt->bind_param("s", $nin);
    $stmt->execute();

    if ($stmt->get_result()->num_rows > 0) {
        $stmt->close();
        respond(["status" => "error", "message" => "NIN already exists"], 409);
    }

    $stmt->close();

    $amount_last = moneyValue($amount_last_raw);
    $amount_this = moneyValue($amount_this_raw);

    if ($amount_last_raw !== "" && $amount_last <= 0) {
        respond(["status" => "error", "message" => "Invalid amount_last value"], 422);
    }

    if ($amount_this_raw !== "" && $amount_this <= 0) {
        respond(["status" => "error", "message" => "Invalid amount_this value"], 422);
    }

    // Same loan assessment as the website and admin (includes/loan_assessment.php).
    $assessment = loan_assessment_limit($business_type, $amount_last_raw, $amount_this_raw);
    $credit_score = $assessment["score"];
    $loan_limit = $assessment["limit"];

    // Required: ID front/back, the selfie (image_url) and the two business
    // photos (in front of the business, and inside). The trading licence is
    // optional. Older app versions send no business photos, so those are only
    // required when the app says it collects them (business_photos=1).
    $required = ["id_front", "id_back", "image_url"];
    if (clean($input["business_photos"] ?? "") === "1") {
        $required[] = "business_front";
        $required[] = "business_inside";
    }
    $imageLabels = [
        "id_front" => "National ID (front)",
        "id_back" => "National ID (back)",
        "image_url" => "profile selfie",
        "business_front" => "photo in front of your business",
        "business_inside" => "photo inside your business",
    ];
    $imagePaths = [];

    foreach (["id_front", "id_back", "image_url", "license", "business_front", "business_inside"] as $field) {
        $imageData = clean($input[$field] ?? "");

        if ($imageData === "") {
            if (in_array($field, $required, true)) {
                foreach ($imagePaths as $saved) {
                    @unlink($saved);
                }
                respond(["status" => "error", "message" => "Please add the " . ($imageLabels[$field] ?? $field)], 422);
            }
            $imagePaths[$field] = "";
            continue;
        }

        $imagePaths[$field] = saveBase64Image($imageData, $field);
    }
    loan_offers_ensure_schema($conn);

    $password = password_hash($password_raw, PASSWORD_DEFAULT);

    $conn->begin_transaction();

    try {
        $role = 3;
        $status = 1;
        $active = 1;

        $stmt = $conn->prepare("
            INSERT INTO user
                (fname, lname, username, role, password, email, image_url, datetime, status)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, NOW(), ?)
        ");

        if (!$stmt) {
            throw new Exception("User prepare failed: " . $conn->error);
        }

        $stmt->bind_param(
            "sssisssi",
            $fname,
            $lname,
            $username,
            $role,
            $password,
            $email,
            $imagePaths["image_url"],
            $status
        );

        if (!$stmt->execute()) {
            throw new Exception("User insert failed: " . $stmt->error);
        }

        $user_id = (int)$stmt->insert_id;
        $stmt->close();

        $stmt = $conn->prepare("
            INSERT INTO customers
                (
                    user_id, gender, dob, nin, id_front, id_back, phone, email,
                    district, business_name, business_type, location,
                    amount_last, amount_this, license,
                    nok_name, nok_tel, nok_rel, business_period,
                    loan_limit, password, credit_score, active,
                    stock, stock_inside
                )
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            throw new Exception("Customer prepare failed: " . $conn->error);
        }

        $stmt->bind_param(
            "isssssssssssddsssssdsdiss",
            $user_id,
            $gender,
            $dob,
            $nin,
            $imagePaths["id_front"],
            $imagePaths["id_back"],
            $phone,
            $email,
            $district,
            $business_name,
            $business_type,
            $location,
            $amount_last,
            $amount_this,
            $imagePaths["license"],
            $nok_name,
            $nok_tel,
            $nok_rel,
            $business_period,
            $loan_limit,
            $password,
            $credit_score,
            $active,
            $imagePaths["business_front"],
            $imagePaths["business_inside"]
        );

        if (!$stmt->execute()) {
            throw new Exception("Customer insert failed: " . $stmt->error);
        }

        $customer_id = (int)$stmt->insert_id;
        $stmt->close();

        $conn->commit();

        respond([
            "status" => "success",
            "message" => "Client registered successfully",
            "data" => [
                "user_id" => $user_id,
                "customer_id" => $customer_id,
                "username" => $username,
                "amount_last" => $amount_last,
                "amount_this" => $amount_this,
                "loan_limit" => $loan_limit,
                "credit_score" => $credit_score,
            ],
        ]);
    } catch (Throwable $e) {
        $conn->rollback();

        foreach ($imagePaths as $path) {
            if ($path !== "" && file_exists($path)) {
                @unlink($path);
            }
        }

        respond([
            "status" => "error",
            "message" => $e->getMessage(),
        ], 500);
    }
}


function getClient(mysqli $conn, array $user): void
{
    loan_offers_ensure_schema($conn);
    $client_id = (int)$user["sub"];

    $sql = "
        SELECT
            u.id,
            u.fname,
            u.lname,
            u.username,
            u.email AS user_email,
            u.image_url,

            c.user_id,
            c.gender,
            c.dob,
            c.nin,
            c.phone,
            c.email AS customer_email,
            COALESCE(c.bank_name, '') AS bank_name,
            COALESCE(c.bank_account, '') AS bank_account,
            COALESCE(c.business_name, '') AS business_name,
            COALESCE(c.business_type, '') AS business_type,
            COALESCE(c.business_period, '') AS business_period,
            COALESCE(c.amount_last, 0) AS amount_last,
            COALESCE(c.amount_this, 0) AS amount_this,
            COALESCE(c.district, '') AS district,
            COALESCE(c.location, '') AS location,
            COALESCE(c.nok_name, '') AS nok_name,
            COALESCE(c.nok_tel, '') AS nok_tel,
            COALESCE(c.nok_rel, '') AS nok_rel,
            COALESCE(c.id_front, '') AS id_front,
            COALESCE(c.id_back, '') AS id_back,
            COALESCE(c.license, '') AS license,
            COALESCE(c.applicant_signature, '') AS applicant_signature,
            COALESCE(c.stock, '') AS stock,
            COALESCE(c.loan_limit, 0) AS loan_limit,
            COALESCE(c.credit_score, 0) AS credit_score,
            COALESCE(c.active, 0) AS active,
            c.created_at,

            l.id AS loan_id,
            COALESCE(l.amount, 0) AS loan_amount,
            COALESCE(l.principal_balance, 0) AS principal_balance,
            COALESCE(l.interest_balance, 0) AS interest_balance,
            COALESCE(l.outstanding_balance, 0) AS outstanding_balance,
            COALESCE(l.interest, 0) AS interest,
            COALESCE(l.interest_rate, 0) AS interest_rate,
            COALESCE(l.sur_charge, 0) AS sur_charge,
            COALESCE(l.amount + COALESCE(l.interest, 0) + COALESCE(l.sur_charge, 0), 0) AS approved_amount,
            COALESCE(l.loan_balance, 0) AS loan_balance,

            GREATEST(COALESCE(c.loan_limit, 0) - COALESCE(l.loan_balance, 0), 0) AS available_balance,

            CASE
                WHEN COALESCE(l.loan_period, 0) > 0
                THEN ROUND((COALESCE(l.amount, 0) + COALESCE(l.interest, 0) + COALESCE(l.sur_charge, 0)) / l.loan_period, 0)
                ELSE 0
            END AS monthly_installment,

            COALESCE(l.loan_period, 0) AS loan_period,
            l.datetime AS loan_start_date,
            l.updated_at AS loan_updated_at,
            l.due_date AS due_date,
            DATE_FORMAT(l.due_date, '%Y-%m-%d') AS due_date_formatted,
            DATE_FORMAT(l.due_date, '%Y-%m-%d') AS next_payment,
            COALESCE(l.loan_method, '') AS loan_method,
            COALESCE(l.bank_name, '') AS loan_bank_name,
            COALESCE(l.bank_account, '') AS loan_bank_account,
            COALESCE(l.phone, '') AS loan_phone,
            COALESCE(l.status, 'NONE') AS db_loan_status,
            COALESCE(l.requested_amount, l.amount, 0) AS requested_amount,
            COALESCE(l.offer_amount, 0) AS offer_amount,
            l.processing_fee AS processing_fee,
            l.net_disbursement AS net_disbursement,
            COALESCE(l.loan_period_unit, 'month') AS loan_period_unit,
            l.offered_at AS offered_at,

            CASE
                WHEN l.id IS NULL THEN 'NONE'
                WHEN UPPER(COALESCE(l.status, '')) IN ('PENDING', 'INFO_REQUESTED', 'INFO_PROVIDED', 'REJECTED', 'OFFERED', 'APPROVED', 'DECLINED') THEN UPPER(l.status)
                WHEN UPPER(COALESCE(l.status, '')) = 'CLEARED' THEN 'CLEARED'
                WHEN COALESCE(l.loan_balance, 0) <= 0 THEN 'CLEARED'
                WHEN l.due_date IS NOT NULL AND COALESCE(l.loan_balance, 0) > 0 AND l.due_date < NOW() THEN 'OVERDUE'
                WHEN COALESCE(l.loan_balance, 0) > 0 THEN 'OUTSTANDING'
                ELSE COALESCE(l.status, 'NONE')
            END AS loan_status
        FROM customers c
        INNER JOIN user u ON u.id = c.user_id
        LEFT JOIN loan l ON l.id = (
            SELECT lx.id
            FROM loan lx
            WHERE lx.client_id = c.user_id
            ORDER BY
                CASE
                    WHEN UPPER(COALESCE(lx.status, '')) NOT IN ('CLEARED', 'REJECTED', 'DECLINED', 'DELETED')
                         AND COALESCE(lx.loan_balance, 0) > 0 THEN 0
                    ELSE 1
                END,
                COALESCE(lx.due_date, lx.datetime) DESC,
                lx.id DESC
            LIMIT 1
        )
        WHERE c.user_id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        respond(["status" => "error", "message" => "Query preparation failed: " . $conn->error], 500);
    }

    $stmt->bind_param("i", $client_id);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        respond(["status" => "error", "message" => "Client not found"], 404);
    }

    $row["email"] = !empty($row["user_email"]) ? $row["user_email"] : ($row["customer_email"] ?? "");
    // Older app versions read credit_score as a whole number and fail on 8.9,
    // so send it rounded; credit_score_exact has the decimal (0-10).
    $row["credit_score_exact"] = round((float)($row["credit_score"] ?? 0), 1);
    $row["credit_score"] = (int)round((float)($row["credit_score"] ?? 0));
    $row["due_date_formatted"] = formatDateOnly($row["due_date_formatted"] ?? $row["due_date"] ?? "");
    $row["next_payment"] = formatDateOnly($row["next_payment"] ?? $row["due_date"] ?? "");

    // Offer waiting for the client: everything the accept/reject screen shows.
    $row["offer"] = null;
    if (strtoupper((string)($row["loan_status"] ?? "")) === "OFFERED" && !empty($row["loan_id"])) {
        $principal = (float)$row["offer_amount"] ?: (float)$row["loan_amount"];
        $quote = loan_quote($conn, $principal, (int)$row["loan_period"], (string)$row["loan_period_unit"]);
        if ($row["processing_fee"] !== null) {
            $quote["processing_fee"] = (float)$row["processing_fee"];
            $quote["net_disbursement"] = max(0.0, $principal - (float)$row["processing_fee"]);
        }
        $row["offer"] = $quote + [
            "loan_id" => (int)$row["loan_id"],
            "requested_amount" => (float)$row["requested_amount"],
            "approved_amount" => $principal,
            "loan_period" => (int)$row["loan_period"],
            "loan_period_unit" => (string)$row["loan_period_unit"],
        ];
    }
    $row["term_label"] = !empty($row["loan_id"])
        ? loan_term_label((int)$row["loan_period"], (string)$row["loan_period_unit"])
        : "";

    respond(["status" => "success", "data" => $row]);
}

/**
 * Get all client loans.
 */
function getLoans(mysqli $conn, array $user): void
{
    loan_offers_ensure_schema($conn);
    $client_id = (int)$user["sub"];

    $stmt = $conn->prepare("
        SELECT
            id AS loan_id,
            COALESCE(amount, 0) AS loan_amount,
            COALESCE(principal_balance, 0) AS principal_balance,
            COALESCE(interest_balance, 0) AS interest_balance,
            COALESCE(outstanding_balance, 0) AS outstanding_balance,
            COALESCE(interest, 0) AS interest,
            COALESCE(interest_rate, 0) AS interest_rate,
            COALESCE(sur_charge, 0) AS sur_charge,
            COALESCE(amount + COALESCE(interest, 0) + COALESCE(sur_charge, 0), 0) AS approved_amount,
            COALESCE(loan_balance, 0) AS loan_balance,
            COALESCE(loan_period, 0) AS loan_period,
            datetime AS loan_start_date,
            updated_at AS loan_updated_at,
            due_date AS due_date,
            DATE_FORMAT(due_date, '%Y-%m-%d') AS due_date_formatted,
            DATE_FORMAT(due_date, '%Y-%m-%d') AS next_payment,
            COALESCE(loan_method, '') AS loan_method,
            COALESCE(bank_name, '') AS bank_name,
            COALESCE(bank_account, '') AS bank_account,
            COALESCE(phone, '') AS loan_phone,
            COALESCE(status, '') AS db_loan_status,
            COALESCE(requested_amount, amount, 0) AS requested_amount,
            COALESCE(loan_period_unit, 'month') AS loan_period_unit,
            processing_fee,
            net_disbursement,
            CASE
                WHEN UPPER(COALESCE(status, '')) IN ('PENDING', 'INFO_REQUESTED', 'INFO_PROVIDED', 'REJECTED', 'OFFERED', 'APPROVED', 'DECLINED') THEN UPPER(status)
                WHEN UPPER(COALESCE(status, '')) = 'CLEARED' THEN 'CLEARED'
                WHEN COALESCE(loan_balance, 0) <= 0 THEN 'CLEARED'
                WHEN due_date IS NOT NULL AND COALESCE(loan_balance, 0) > 0 AND due_date < NOW() THEN 'OVERDUE'
                WHEN COALESCE(loan_balance, 0) > 0 THEN 'OUTSTANDING'
                ELSE COALESCE(status, 'CLEARED')
            END AS loan_status,
            CASE
                WHEN COALESCE(loan_period, 0) > 0
                THEN ROUND((COALESCE(amount, 0) + COALESCE(interest, 0) + COALESCE(sur_charge, 0)) / loan_period, 0)
                ELSE 0
            END AS monthly_installment
        FROM loan
        WHERE client_id = ?
        ORDER BY COALESCE(due_date, datetime) DESC, id DESC
    ");

    if (!$stmt) {
        respond(["status" => "error", "message" => "Query preparation failed: " . $conn->error], 500);
    }

    $stmt->bind_param("i", $client_id);
    $stmt->execute();

    $loans = [];
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $row["due_date_formatted"] = formatDateOnly($row["due_date_formatted"] ?? $row["due_date"] ?? "");
        $row["next_payment"] = formatDateOnly($row["next_payment"] ?? $row["due_date"] ?? "");
        $loans[] = $row;
    }

    $stmt->close();

    respond(["status" => "success", "loans" => $loans]);
}

/**
 * Update client profile.
 */
function updateClient(mysqli $conn, array $user, array $input): void
{
    $client_id = (int)$user["sub"];

    $fname = clean($input["fname"] ?? "");
    $lname = clean($input["lname"] ?? "");
    $username = clean($input["username"] ?? "");
    $gender = clean($input["gender"] ?? "");
    $dob = normalizeDob(clean($input["dob"] ?? ""));
    $phone = clean($input["phone"] ?? "");
    $email = clean($input["email"] ?? "");
    $nin = clean($input["nin"] ?? "");
    $business_name = clean($input["business_name"] ?? "");
    $business_type = clean($input["business_type"] ?? "");
    $business_period = clean($input["business_period"] ?? "");
    $amount_last_raw = clean($input["amount_last"] ?? "");
    $amount_this_raw = clean($input["amount_this"] ?? "");
    $district = clean($input["district"] ?? "");
    $location = clean($input["location"] ?? "");
    $nok_name = clean($input["nok_name"] ?? "");
    $nok_tel = clean($input["nok_tel"] ?? "");
    $nok_rel = clean($input["nok_rel"] ?? "");

    if ($fname === "" || $lname === "" || $phone === "") {
        respond(["status" => "error", "message" => "First name, last name and phone are required"], 422);
    }

    if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(["status" => "error", "message" => "Invalid email address"], 422);
    }

    $amount_last = moneyValue($amount_last_raw);
    $amount_this = moneyValue($amount_this_raw);

    if ($amount_last_raw !== "" && $amount_last <= 0) {
        respond(["status" => "error", "message" => "Invalid amount_last value"], 422);
    }

    if ($amount_this_raw !== "" && $amount_this <= 0) {
        respond(["status" => "error", "message" => "Invalid amount_this value"], 422);
    }

    // Same loan assessment as the website and admin (includes/loan_assessment.php).
    $assessment = loan_assessment_limit($business_type, $amount_last_raw, $amount_this_raw, $client_id);
    $credit_score = $assessment["score"];
    $loan_limit = $assessment["limit"];

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("UPDATE user SET fname = ?, lname = ?, username = ?, email = ? WHERE id = ?");

        if (!$stmt) {
            throw new Exception("User update prepare failed: " . $conn->error);
        }

        $stmt->bind_param("ssssi", $fname, $lname, $username, $email, $client_id);

        if (!$stmt->execute()) {
            throw new Exception("User update failed: " . $stmt->error);
        }

        $stmt->close();

        $stmt = $conn->prepare("
            UPDATE customers
            SET gender = ?,
                dob = ?,
                phone = ?,
                email = ?,
                nin = ?,
                business_name = ?,
                business_type = ?,
                business_period = ?,
                amount_last = ?,
                amount_this = ?,
                district = ?,
                location = ?,
                nok_name = ?,
                nok_tel = ?,
                nok_rel = ?,
                loan_limit = ?,
                credit_score = ?
            WHERE user_id = ?
        ");

        if (!$stmt) {
            throw new Exception("Customer update prepare failed: " . $conn->error);
        }

        $stmt->bind_param(
            "ssssssssddsssssiii",
            $gender,
            $dob,
            $phone,
            $email,
            $nin,
            $business_name,
            $business_type,
            $business_period,
            $amount_last,
            $amount_this,
            $district,
            $location,
            $nok_name,
            $nok_tel,
            $nok_rel,
            $loan_limit,
            $credit_score,
            $client_id
        );

        if (!$stmt->execute()) {
            throw new Exception("Customer update failed: " . $stmt->error);
        }

        $stmt->close();
        $conn->commit();

        respond([
            "status" => "success",
            "message" => "Profile updated successfully",
            "data" => [
                "amount_last" => $amount_last,
                "amount_this" => $amount_this,
                "loan_limit" => $loan_limit,
                "credit_score" => $credit_score,
            ],
        ]);
    } catch (Throwable $e) {
        $conn->rollback();
        respond(["status" => "error", "message" => $e->getMessage()], 500);
    }
}

/**
 * Get credit score.
 */
function getCreditScore(mysqli $conn, array $user): void
{
    $client_id = (int)$user["sub"];

    $stmt = $conn->prepare("SELECT credit_score, business_name, business_type, loan_limit FROM customers WHERE user_id = ? LIMIT 1");

    if (!$stmt) {
        respond(["status" => "error", "message" => "Query preparation failed: " . $conn->error], 500);
    }

    $stmt->bind_param("i", $client_id);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        respond(["status" => "error", "message" => "Client not found"], 404);
    }

    respond([
        "status" => "success",
        "credit_score" => (float)($row["credit_score"] ?? 0),
        "loan_limit" => (float)($row["loan_limit"] ?? 0),
        "business_name" => $row["business_name"] ?? "",
        "business_type" => $row["business_type"] ?? "",
    ]);
}

/**
 * Loan terms for the application screen: the repayment periods, the interest
 * rate for each, and the processing fee. The app shows these instead of
 * hard-coding a rate.
 */
function getLoanTerms(mysqli $conn, array $user): void
{
    loan_offers_ensure_schema($conn);
    $terms = [];
    foreach (loan_term_options() as $opt) {
        $terms[] = $opt + ["interest_rate" => loan_interest_rate($opt["count"], $opt["unit"])];
    }
    $fee = loan_processing_fee($conn);
    respond([
        "status" => "success",
        "data" => [
            "terms" => $terms,
            "processing_fee" => $fee,
            "fee_note" => "A processing fee of " . loan_money($fee) . " is taken from the amount sent to you. You repay the full approved amount plus interest.",
        ],
    ]);
}

/**
 * Loan application. The client asks for the amount they want; staff decide
 * the amount to offer (see admin/review_loan_action.php).
 */
function requestLoan(mysqli $conn, array $user, array $input): void
{
    loan_offers_ensure_schema($conn);

    $client_id = (int)$user["sub"];
    $amount = moneyValue($input["amount"] ?? 0);
    $loan_period = (int)clean($input["loan_period"] ?? $input["period"] ?? 1);
    $period_unit = loan_term_unit(clean($input["loan_period_unit"] ?? $input["period_unit"] ?? "month"));
    $loan_method = clean($input["loan_method"] ?? "");
    $bank_name = clean($input["bank_name"] ?? "");
    $bank_account = clean($input["bank_account"] ?? "");
    $phone = clean($input["phone"] ?? "");

    if ($amount <= 0) {
        respond(["status" => "error", "message" => "Please enter the amount you want to borrow"], 422);
    }
    if ($amount < 10000) {
        respond(["status" => "error", "message" => "The smallest loan is UGX 10,000"], 422);
    }
    if (!loan_term_is_valid($loan_period, $period_unit)) {
        respond(["status" => "error", "message" => "Please choose a repayment period between 2 weeks and 6 months"], 422);
    }

    $stmt = $conn->prepare("SELECT loan_limit FROM customers WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        respond(["status" => "error", "message" => "Client not found"], 404);
    }
    // The system limit is kept for staff to compare; it does not cap the request.
    $system_amount = (float)($row["loan_limit"] ?? 0);

    // One application or loan at a time.
    $stmt = $conn->prepare("
        SELECT id, UPPER(COALESCE(status, '')) AS status
        FROM loan
        WHERE client_id = ?
          AND (
                UPPER(COALESCE(status, '')) IN ('PENDING', 'INFO_REQUESTED', 'INFO_PROVIDED', 'OFFERED', 'APPROVED')
             OR (UPPER(COALESCE(status, '')) NOT IN ('CLEARED', 'REJECTED', 'DECLINED', 'DELETED') AND COALESCE(loan_balance, 0) > 0)
          )
        LIMIT 1
    ");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $open = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($open) {
        $message = match ($open["status"]) {
            "OFFERED" => "You have a loan offer waiting. Please accept or reject it first.",
            "PENDING", "INFO_REQUESTED", "INFO_PROVIDED" => "Your loan application is still being reviewed.",
            "APPROVED" => "Your accepted loan is being sent to you.",
            default => "You already have an active loan",
        };
        respond(["status" => "error", "message" => $message], 422);
    }

    // Provisional figures for the requested amount; recalculated on the offer.
    $quote = loan_quote($conn, $amount, $loan_period, $period_unit);
    $interest_rate = $quote["interest_rate"];
    $interest = $quote["interest"];
    $sur_charge = 0.0;
    $balance = $quote["total_repayment"];
    $due_date = loan_term_due_date($loan_period, $period_unit);
    $status = "PENDING";

    $stmt = $conn->prepare("
        INSERT INTO loan
            (
                amount, principal_balance, interest_balance, outstanding_balance,
                interest_rate, client_id, status, datetime, updated_at, due_date,
                loan_balance, sur_charge, interest, loan_period,
                loan_method, bank_name, bank_account, phone,
                requested_amount, system_amount, loan_period_unit
            )
        VALUES
            (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    // 19 placeholders. The previous version had one type letter too many,
    // which made bind_param throw and every request return HTTP 500.
    $stmt->bind_param(
        "dddddissdddissssdds",
        $amount,
        $amount,
        $interest,
        $balance,
        $interest_rate,
        $client_id,
        $status,
        $due_date,
        $balance,
        $sur_charge,
        $interest,
        $loan_period,
        $loan_method,
        $bank_name,
        $bank_account,
        $phone,
        $amount,
        $system_amount,
        $period_unit
    );
    $stmt->execute();
    $loan_id = (int)$stmt->insert_id;
    $stmt->close();

    // Tag the request and assign a loan officer so it shows in Pending Loans.
    $assignedOfficerId = loan_finalize_new_request($conn, $loan_id, "customer");

    try {
        $nameStmt = $conn->prepare("SELECT fname, lname, username FROM user WHERE id = ? LIMIT 1");
        $nameStmt->bind_param("i", $client_id);
        $nameStmt->execute();
        $clientRow = $nameStmt->get_result()->fetch_assoc();
        $nameStmt->close();
        $senderName = trim(($clientRow["fname"] ?? "") . " " . ($clientRow["lname"] ?? ""));
        if ($senderName === "") {
            $senderName = (string)($clientRow["username"] ?? "Client");
        }

        $msgStmt = $conn->prepare("
            INSERT INTO loan_messages (user_id, loan_id, sender_name, message_type, is_read, created_at)
            VALUES (?, ?, ?, 'loan_request', 0, NOW())
        ");
        $msgStmt->bind_param("iis", $client_id, $loan_id, $senderName);
        $msgStmt->execute();
        $msgStmt->close();
    } catch (Throwable $e) {
        error_log("Loan request message failed: " . $e->getMessage());
    }

    respond([
        "status" => "success",
        "message" => "Application sent. We will review it and send you an offer.",
        "data" => [
            "loan_id" => $loan_id,
            "requested_amount" => $amount,
            "loan_period" => $loan_period,
            "loan_period_unit" => $period_unit,
            "term_label" => loan_term_label($loan_period, $period_unit),
            "loan_status" => "PENDING",
            "assigned_officer_id" => $assignedOfficerId,
        ],
    ]);
}

/**
 * Client accepts or rejects the amount staff offered
 * (logic in includes/loan_offers.php, shared with the web portal).
 */
function respondLoanOffer(mysqli $conn, array $user, array $input): void
{
    $result = loan_offer_respond(
        $conn,
        (int)$user["sub"],
        (int)clean($input["loan_id"] ?? 0),
        clean($input["decision"] ?? "")
    );
    respond([
        "status" => $result["ok"] ? "success" : "error",
        "message" => $result["message"],
        "data" => $result["data"],
    ], $result["code"]);
}

/**
 * Pay loan.
 */
function payLoan(mysqli $conn, array $user, array $input): void
{
    $client_id = (int)$user["sub"];
    $amount = moneyValue($input["amount"] ?? 0);

    if ($amount <= 0) {
        respond(["status" => "error", "message" => "Invalid payment amount"], 422);
    }

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("
            SELECT id, loan_balance, principal_balance, interest_balance, outstanding_balance
            FROM loan
            WHERE client_id = ?
              AND COALESCE(loan_balance, 0) > 0
              AND UPPER(COALESCE(status, '')) NOT IN ('CLEARED', 'REJECTED')
            ORDER BY COALESCE(due_date, datetime) ASC, id ASC
            LIMIT 1
            FOR UPDATE
        ");

        if (!$stmt) {
            throw new Exception("Loan lookup prepare failed: " . $conn->error);
        }

        $stmt->bind_param("i", $client_id);
        $stmt->execute();

        $loan = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$loan) {
            throw new Exception("No active loan found");
        }

        $current_balance = (float)$loan["loan_balance"];

        if ($amount > $current_balance) {
            throw new Exception("Overpayment not allowed. Balance is UGX " . number_format($current_balance));
        }

        $new_balance = max(0.0, $current_balance - $amount);
        $new_outstanding = $new_balance;

        $interest_balance = max(0.0, (float)($loan["interest_balance"] ?? 0));
        $principal_balance = max(0.0, (float)($loan["principal_balance"] ?? 0));

        $remaining_payment = $amount;

        if ($interest_balance > 0) {
            $interest_paid = min($interest_balance, $remaining_payment);
            $interest_balance -= $interest_paid;
            $remaining_payment -= $interest_paid;
        }

        if ($remaining_payment > 0 && $principal_balance > 0) {
            $principal_paid = min($principal_balance, $remaining_payment);
            $principal_balance -= $principal_paid;
        }

        $stmt = $conn->prepare("
            UPDATE loan
            SET loan_balance = ?,
                outstanding_balance = ?,
                interest_balance = ?,
                principal_balance = ?,
                updated_at = NOW()
            WHERE id = ?
        ");

        if (!$stmt) {
            throw new Exception("Loan update prepare failed: " . $conn->error);
        }

        $loan_id = (int)$loan["id"];
        $stmt->bind_param("ddddi", $new_balance, $new_outstanding, $interest_balance, $principal_balance, $loan_id);

        if (!$stmt->execute()) {
            throw new Exception("Loan update failed: " . $stmt->error);
        }

        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO payments (amount, loan_id, user_id, status) VALUES (?, ?, ?, 'SUCCESSFUL')");

        if (!$stmt) {
            throw new Exception("Payment prepare failed: " . $conn->error);
        }

        $stmt->bind_param("dii", $amount, $loan_id, $client_id);

        if (!$stmt->execute()) {
            throw new Exception("Payment insert failed: " . $stmt->error);
        }

        $stmt->close();

        if ($new_balance <= 0) {
            $stmt = $conn->prepare("
                UPDATE loan
                SET status = 'CLEARED',
                    loan_balance = 0,
                    outstanding_balance = 0,
                    principal_balance = 0,
                    interest_balance = 0,
                    updated_at = NOW()
                WHERE id = ?
            ");

            if (!$stmt) {
                throw new Exception("Clear loan prepare failed: " . $conn->error);
            }

            $stmt->bind_param("i", $loan_id);

            if (!$stmt->execute()) {
                throw new Exception("Clear loan failed: " . $stmt->error);
            }

            $stmt->close();
        }

        $conn->commit();

        respond([
            "status" => "success",
            "message" => $new_balance <= 0 ? "Loan fully cleared!" : "Payment recorded",
            "balance" => $new_balance,
            "loan_balance" => $new_balance,
        ]);
    } catch (Throwable $e) {
        $conn->rollback();
        respond(["status" => "error", "message" => $e->getMessage()], 500);
    }
}

/**
 * Credit documents: the client uploads a mobile money statement, utility bill
 * or business records. The active AI provider reads it and staff approve the
 * result before the score/limit change (includes/credit_scoring.php).
 *
 * Accepts either:
 *  - multipart files named mobileMoneyTransactions, personalRecords (utility
 *    bill) and/or businessRecords (one or more in one request), or
 *  - doc_type (mobile_money|utility_bill|business_records) with a file field
 *    "file", or base64 "file_base64" (+ optional "file_name").
 */
function uploadCreditEvidence(mysqli $conn, array $user, array $input): void
{
    $userId = (int)$user["sub"];
    $fieldToType = [];
    foreach (CREDIT_EVIDENCE_TYPES as $type => $meta) {
        $fieldToType[$meta["field"]] = $type;
    }

    $uploads = []; // [doc_type, bytes, name]
    foreach ($fieldToType as $field => $type) {
        $f = $_FILES[$field] ?? null;
        if (is_array($f) && (int)($f["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ((int)$f["error"] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f["tmp_name"])) {
                respond(["status" => "error", "message" => "The {$type} upload did not complete. Please try again."], 422);
            }
            $uploads[] = [$type, (string)file_get_contents((string)$f["tmp_name"]), (string)($f["name"] ?? "")];
        }
    }

    $docType = clean($input["doc_type"] ?? "");
    if ($docType !== "") {
        if (isset($_FILES["file"]) && (int)($_FILES["file"]["error"] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string)$_FILES["file"]["tmp_name"])) {
            $uploads[] = [$docType, (string)file_get_contents((string)$_FILES["file"]["tmp_name"]), (string)($_FILES["file"]["name"] ?? "")];
        } elseif (($b64 = (string)($input["file_base64"] ?? "")) !== "") {
            $b64 = preg_replace("#^data:[^;]+;base64,#", "", $b64);
            $bytes = base64_decode((string)$b64, true);
            if ($bytes === false) {
                respond(["status" => "error", "message" => "The file could not be read."], 422);
            }
            $uploads[] = [$docType, $bytes, clean($input["file_name"] ?? "")];
        }
    }

    if (!$uploads) {
        respond(["status" => "error", "message" => "Choose at least one document to upload."], 422);
    }

    $results = [];
    $messages = [];
    foreach ($uploads as [$type, $bytes, $name]) {
        try {
            $row = credit_evidence_submit($conn, $userId, $type, $bytes, $name, "app");
            $results[] = credit_evidence_public($row);
            $messages[] = credit_evidence_result_message($row);
        } catch (DomainException $e) {
            if (count($uploads) === 1) {
                respond(["status" => "error", "message" => $e->getMessage()], 422);
            }
            $messages[] = (CREDIT_EVIDENCE_TYPES[$type]["label"] ?? "Document") . ": " . $e->getMessage();
        } catch (Throwable $e) {
            error_log("uploadCreditEvidence: " . $e->getMessage());
            respond(["status" => "error", "message" => "The document could not be uploaded. Please try again."], 500);
        }
    }

    if (!$results) {
        respond(["status" => "error", "message" => implode(" ", $messages)], 422);
    }

    $stmt = $conn->prepare("SELECT credit_score, loan_limit FROM customers WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $c = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    respond([
        "status" => "success",
        "message" => implode(" ", $messages),
        "creditScore" => (float)($c["credit_score"] ?? 0),
        "credit_score" => (float)($c["credit_score"] ?? 0),
        "loan_limit" => (float)($c["loan_limit"] ?? 0),
        "documents" => $results,
    ]);
}

/** The client's credit documents, score and loan limit. */
function getCreditEvidence(mysqli $conn, array $user): void
{
    $userId = (int)$user["sub"];
    $docs = array_map("credit_evidence_public", credit_evidence_for_user($conn, $userId));
    $profile = credit_profile_for_user($conn, $userId);

    $stmt = $conn->prepare("SELECT credit_score, loan_limit FROM customers WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $c = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $types = [];
    foreach (CREDIT_EVIDENCE_TYPES as $type => $meta) {
        $types[] = ["doc_type" => $type, "label" => $meta["label"], "hint" => $meta["hint"], "field" => $meta["field"]];
    }

    respond([
        "status" => "success",
        "data" => [
            "credit_score" => (float)($c["credit_score"] ?? 0),
            "loan_limit" => (float)($c["loan_limit"] ?? 0),
            "limit_reduced" => $profile !== null && $profile["repayment_factor"] < 1,
            "limit_note" => $profile !== null && $profile["repayment_factor"] < 1
                ? "Your loan limit is lower than usual because of late or missed repayments. Paying your next loans on time brings it back to the full amount."
                : "",
            "document_types" => $types,
            "documents" => $docs,
            // The score breakdown (components and weights) is for staff only.
            // Kept as an empty list so older app versions still parse the response.
            "components" => [],
        ],
    ]);
}

// Bookkeeping (ledger) actions - soft-loaded from books_api.php.
require_once __DIR__ . '/books_api.php';

/**
 * Route request.
 */
switch ($action) {
    case "login":
        login($conn, $input);
        break;

    case "add_client":
    case "register_client":
    case "register":
        addClient($conn, $input);
        break;

    case "get_client":
        getClient($conn, requireAuth());
        break;

    case "get_loans":
        getLoans($conn, requireAuth());
        break;

    case "update_client":
        updateClient($conn, requireAuth(), $input);
        break;

    case "get_credit_score":
        getCreditScore($conn, requireAuth());
        break;

    case "upload_credit_evidence":
    case "upload_credit_documents":
        uploadCreditEvidence($conn, requireAuth(), $input);
        break;

    case "get_credit_evidence":
        getCreditEvidence($conn, requireAuth());
        break;

    case "get_loan_terms":
        getLoanTerms($conn, requireAuth());
        break;

    case "request_loan":
        requestLoan($conn, requireAuth(), $input);
        break;

    case "respond_loan_offer":
        respondLoanOffer($conn, requireAuth(), $input);
        break;

    case "pay_loan":
        payLoan($conn, requireAuth(), $input);
        break;

    case "parse_book_input":
        parse_book_input($conn, requireAuth(), $input);
        break;

    case "confirm_book_entries":
        confirm_book_entries($conn, requireAuth(), $input);
        break;

    case "get_book_entries":
        get_book_entries($conn, requireAuth(), $input);
        break;

    case "get_book_summary":
        get_book_summary($conn, requireAuth(), $input);
        break;

    case "get_book_report":
        get_book_report($conn, requireAuth(), $input);
        break;

    case "delete_book_entry":
        delete_book_entry($conn, requireAuth(), $input);
        break;

    case "reject_book_input":
        reject_book_input($conn, requireAuth(), $input);
        break;

    default:
        respond(["status" => "error", "message" => "Invalid action"], 400);
}
