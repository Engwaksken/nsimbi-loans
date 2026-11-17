<?php
/*
 * Password changes now live on My Profile (Change Password modal).
 * Kept as a redirect so old links still work.
 */
header('Location: profile.php?password=1', true, 301);
exit;
