<?php
/*
 * Loan requests now live on My Loans (the "Request New Loan" modal).
 * Kept as a redirect so old links and bookmarks still work. Any flash
 * message in the session is shown by my_loans.php.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Location: my_loans.php?request=1', true, 301);
exit;
