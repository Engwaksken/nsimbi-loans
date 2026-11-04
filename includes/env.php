<?php
declare(strict_types=1);

/** Load root .env values without replacing variables already provided by the host. */
$envFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
if (!is_file($envFile) || !is_readable($envFile)) {
    return;
}

$lines = file($envFile, FILE_IGNORE_NEW_LINES);
if (!is_array($lines)) {
    error_log('Unable to read application .env file.');
    return;
}

foreach ($lines as $lineNumber => $line) {
    if ($lineNumber === 0) {
        $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
    }
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
        continue;
    }

    [$name, $value] = explode('=', $line, 2);
    $name = trim($name);
    if (!preg_match('/^[A-Z_][A-Z0-9_]*$/i', $name) || getenv($name) !== false) {
        continue;
    }

    $value = trim($value);
    if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
        $quote = $value[0];
        $value = substr($value, 1, -1);
        if ($quote === '"') {
            $value = stripcslashes($value);
        }
    } else {
        $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;
    }

    putenv($name . '=' . $value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}
