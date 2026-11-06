<?php
declare(strict_types=1);

/**
 * Mobile API configuration (JWT).
 *
 * Loaded by api.php after the .env file has been read into the environment,
 * so values here come from .env (JWT_SECRET, JWT_EXPIRE).
 */

$jwtSecret = getenv('JWT_SECRET');
if ($jwtSecret === false || trim((string)$jwtSecret) === '') {
    // Fallback only for local development. Production must set JWT_SECRET in .env.
    $jwtSecret = 'nsimbi-impact-local-dev-secret-change-me';
    error_log('JWT_SECRET is not set in .env; using an insecure development secret.');
}

define('JWT_SECRET', (string)$jwtSecret);
define('JWT_EXPIRE', (int)(getenv('JWT_EXPIRE') ?: 86400));
