<?php
declare(strict_types=1);

/**
 * Mobile API endpoint.
 *
 * Database credentials are loaded from a `.env` file in the same directory as
 * this script (DB_HOST, DB_PORT, DB_USER, DB_PASS, DB_NAME), matching the rest
 * of the application. Host-provided environment variables take precedence.
 */

// ── Load .env ───────────────────────────────────────────────────────────────
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

// ── Database configuration (from .env) ──────────────────────────────────────
$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbUser = getenv('DB_USER') ?: '';
$dbPassValue = getenv('DB_PASS');
$dbPass = $dbPassValue !== false ? (string)$dbPassValue : (string)(getenv('DB_PASSWORD') ?: '');
$dbName = getenv('DB_NAME') ?: '';
$dbPort = (int)(getenv('DB_PORT') ?: 3306);

require "config.php";
require "jwt.php";
require_once __DIR__ . '/includes/loan_workflow.php';

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
    $normalized = str_replace(["—", "–", "-"], "-", $normalized);
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
    $username = clean($input["username"] ?? "");
    $password = clean($input["password"] ?? "");

    if ($username === "" || $password === "") {
        respond(["status" => "error", "message" => "Username and password are required"], 422);
    }

    $stmt = $conn->prepare("SELECT id, password, role FROM user WHERE username = ? LIMIT 1");

    if (!$stmt) {
        respond(["status" => "error", "message" => "Login prepare failed: " . $conn->error], 500);
    }

    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows !== 1) {
        $stmt->close();
        respond(["status" => "error", "message" => "User not found"], 404);
    }

    $stmt->bind_result($id, $hash, $role);
    $stmt->fetch();
    $stmt->close();

    if (!password_verify($password, (string)$hash)) {
        respond(["status" => "error", "message" => "Invalid credentials"], 401);
    }

    $payload = [
        "sub"  => (int)$id,
        "role" => (int)$role,
        "iat"  => time(),
        "exp"  => time() + JWT_EXPIRE,
    ];

    respond([
        "status" => "success",
        "message" => "Login successful",
        "token"  => createJWT($payload),
        "user"   => [
            "id" => (int)$id,
            "role" => (int)$role,
            "username" => $username,
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
    $dob             = clean($input["dob"] ?? "");
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

    $stmt = $conn->prepare("SELECT id FROM user WHERE username = ? LIMIT 1");
    if (!$stmt) {
        respond(["status" => "error", "message" => "Username check failed: " . $conn->error], 500);
    }
    $stmt->bind_param("s", $username);
    $stmt->execute();

    if ($stmt->get_result()->num_rows > 0) {
        $stmt->close();
        respond(["status" => "error", "message" => "Username already exists"], 409);
    }

    $stmt->close();

    $stmt = $conn->prepare("SELECT id FROM customers WHERE phone = ? LIMIT 1");
    if (!$stmt) {
        respond(["status" => "error", "message" => "Phone check failed: " . $conn->error], 500);
    }
    $stmt->bind_param("s", $phone);
    $stmt->execute();

    if ($stmt->get_result()->num_rows > 0) {
        $stmt->close();
        respond(["status" => "error", "message" => "Phone already exists"], 409);
    }

    $stmt->close();

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

    $credit_score = businessScore($business_type);
    $avg = ($amount_last + $amount_this) / 2;
    $loan_limit = round($avg + (($avg * $credit_score) / 10), 0);

    $requiredImages = ["id_front", "id_back", "image_url", "license"];
    $imagePaths = [];

    foreach ($requiredImages as $field) {
        $imageData = clean($input[$field] ?? "");

        if ($imageData === "") {
            respond(["status" => "error", "message" => "Missing image: {$field}"], 422);
        }

        $imagePaths[$field] = saveBase64Image($imageData, $field);
    }

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
                    loan_limit, password, credit_score, active
                )
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            throw new Exception("Customer prepare failed: " . $conn->error);
        }

        $stmt->bind_param(
            "isssssssssssddsssssdsii",
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
            $active
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

            CASE
                WHEN l.id IS NULL THEN 'NONE'
                WHEN UPPER(COALESCE(l.status, '')) IN ('PENDING', 'INFO_REQUESTED', 'INFO_PROVIDED', 'REJECTED') THEN UPPER(l.status)
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
                    WHEN UPPER(COALESCE(lx.status, '')) NOT IN ('CLEARED', 'REJECTED')
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
    $row["due_date_formatted"] = formatDateOnly($row["due_date_formatted"] ?? $row["due_date"] ?? "");
    $row["next_payment"] = formatDateOnly($row["next_payment"] ?? $row["due_date"] ?? "");

    respond(["status" => "success", "data" => $row]);
}

/**
 * Get all client loans.
 */
function getLoans(mysqli $conn, array $user): void
{
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
            CASE
                WHEN UPPER(COALESCE(status, '')) IN ('PENDING', 'INFO_REQUESTED', 'INFO_PROVIDED', 'REJECTED') THEN UPPER(status)
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
    $dob = clean($input["dob"] ?? "");
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

    $credit_score = businessScore($business_type);
    $avg = ($amount_last + $amount_this) / 2;
    $loan_limit = round($avg + (($avg * $credit_score) / 10), 0);

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
 * Request loan.
 * Inserts proper balances and due_date into the loan table.
 */
function requestLoan(mysqli $conn, array $user, array $input): void
{
    $client_id = (int)$user["sub"];
    $amount = moneyValue($input["amount"] ?? 0);
    $loan_period = (int)clean($input["loan_period"] ?? $input["period"] ?? 1);
    $loan_method = clean($input["loan_method"] ?? "");
    $bank_name = clean($input["bank_name"] ?? "");
    $bank_account = clean($input["bank_account"] ?? "");
    $phone = clean($input["phone"] ?? "");

    if ($amount <= 0) {
        respond(["status" => "error", "message" => "Invalid amount"], 422);
    }

    if ($loan_period <= 0) {
        $loan_period = 1;
    }

    $stmt = $conn->prepare("SELECT loan_limit FROM customers WHERE user_id = ? LIMIT 1");

    if (!$stmt) {
        respond(["status" => "error", "message" => "Query preparation failed: " . $conn->error], 500);
    }

    $stmt->bind_param("i", $client_id);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $limit = (float)($row["loan_limit"] ?? 0);

    if ($amount > $limit) {
        respond(["status" => "error", "message" => "Amount exceeds your loan limit"], 422);
    }

    $stmt = $conn->prepare("
        SELECT id
        FROM loan
        WHERE client_id = ?
          AND UPPER(COALESCE(status, '')) NOT IN ('CLEARED', 'REJECTED')
          AND COALESCE(loan_balance, 0) > 0
        LIMIT 1
    ");

    if (!$stmt) {
        respond(["status" => "error", "message" => "Active loan check failed: " . $conn->error], 500);
    }

    $stmt->bind_param("i", $client_id);
    $stmt->execute();

    if ($stmt->get_result()->num_rows > 0) {
        $stmt->close();
        respond(["status" => "error", "message" => "You already have an active loan"], 422);
    }

    $stmt->close();

    // Match the web application: 10% for one month, 6.6% for longer periods.
    $interest_rate = $loan_period === 1 ? 10.0 : 6.6;
    $interest = round($amount * ($interest_rate / 100), 2);
    $sur_charge = 0.0;
    $balance = $amount + $interest + $sur_charge;
    $due_date = date("Y-m-d H:i:s", strtotime("+{$loan_period} month"));
    $status = "PENDING";

    $stmt = $conn->prepare("
        INSERT INTO loan
            (
                amount,
                principal_balance,
                interest_balance,
                outstanding_balance,
                interest_rate,
                client_id,
                status,
                datetime,
                updated_at,
                due_date,
                loan_balance,
                sur_charge,
                interest,
                loan_period,
                loan_method,
                bank_name,
                bank_account,
                phone
            )
        VALUES
            (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        respond(["status" => "error", "message" => "Loan prepare failed: " . $conn->error], 500);
    }


    $stmt->bind_param(
        "dddddisssdddissss",
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
        $phone
    );

    if (!$stmt->execute()) {
        respond(["status" => "error", "message" => "Failed to create loan: " . $stmt->error], 500);
    }

    $loan_id = (int)$stmt->insert_id;
    $stmt->close();

    // Tag the request correctly and auto-assign it to a loan officer so it
    // appears in the reviewer's Pending Loans queue immediately.
    $assignedOfficerId = loan_finalize_new_request($conn, $loan_id, "customer");

    // Give the applicant a visible request message and alert admins through
    // the same message stream used by the web portal.
    try {
        $nameStmt = $conn->prepare("SELECT fname, lname, username FROM user WHERE id = ? LIMIT 1");
        $senderName = "Client";

        if ($nameStmt) {
            $nameStmt->bind_param("i", $client_id);
            $nameStmt->execute();
            $clientRow = $nameStmt->get_result()->fetch_assoc();
            $nameStmt->close();

            if ($clientRow) {
                $senderName = trim(($clientRow["fname"] ?? "") . " " . ($clientRow["lname"] ?? ""));
                if ($senderName === "") {
                    $senderName = (string)($clientRow["username"] ?? "Client");
                }
            }
        }

        $msgStmt = $conn->prepare("
            INSERT INTO loan_messages
                (user_id, loan_id, sender_name, message_type, is_read, created_at)
            VALUES (?, ?, ?, 'loan_request', 0, NOW())
        ");

        if ($msgStmt) {
            $msgStmt->bind_param("iis", $client_id, $loan_id, $senderName);
            $msgStmt->execute();
            $msgStmt->close();
        }
    } catch (Throwable $e) {
        error_log("Loan request message failed: " . $e->getMessage());
    }

    respond([
        "status" => "success",
        "message" => "Loan request submitted for approval",
        "data" => [
            "loan_id" => $loan_id,
            "amount" => $amount,
            "principal_balance" => $amount,
            "interest_balance" => $interest,
            "outstanding_balance" => $balance,
            "interest" => $interest,
            "interest_rate" => $interest_rate,
            "loan_balance" => $balance,
            "loan_period" => $loan_period,
            "due_date" => $due_date,
            "due_date_formatted" => formatDateOnly($due_date),
            "loan_status" => "PENDING",
            "assigned_officer_id" => $assignedOfficerId,
        ],
    ]);
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

// Bookkeeping (ledger) actions — soft-loaded from books_api.php.
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

    case "request_loan":
        requestLoan($conn, requireAuth(), $input);
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
