<?php
require 'nav-head.php';

if (empty($_SESSION['account_delete_csrf'])) {
    $_SESSION['account_delete_csrf'] = bin2hex(random_bytes(32));
}
$userId = (int) $_SESSION['logged_in'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if (!hash_equals((string) $_SESSION['account_delete_csrf'], $token)) {
        $error = 'This request could not be verified. Please try again.';
    } else {
        $stmt = $conn->prepare('SELECT password FROM user WHERE id = ? AND role = 3 LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $account = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$account || !password_verify($password, (string) $account['password'])) {
            $error = 'Your current password is incorrect.';
        } else {
            try {
                $conn->begin_transaction();
                // Retain loan/payment history for audit and regulatory purposes while
                // removing identifying information and disabling the login account.
                $stmt = $conn->prepare("UPDATE user SET fname = 'Deleted', lname = 'Account', username = CONCAT('deleted-', id), email = CONCAT('deleted-', id, '@invalid.local'), password = '', image_url = '', status = 0 WHERE id = ? AND role = 3");
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                if ($stmt->affected_rows !== 1) {
                    throw new RuntimeException('Account could not be closed.');
                }
                $stmt->close();

                // Clear customer profile fields; keep the row so linked loan records remain valid.
                $stmt = $conn->prepare("UPDATE customers SET phone = '', email = '', location = '', district = '', nin = '', nok_name = '', nok_tel = '' WHERE user_id = ?");
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $stmt->close();
                $conn->commit();

                session_unset();
                session_destroy();
                header('Location: ../login.php?account_deleted=1');
                exit;
            } catch (Throwable $exception) {
                $conn->rollback();
                error_log('Account deletion failed for user ' . $userId . ': ' . $exception->getMessage());
                $error = 'We could not close your account right now. Please contact support.';
            }
        }
    }
}
?>
<div class="row justify-content-center mt-4">
  <div class="col-lg-7 col-md-9">
    <div class="card shadow-sm border-danger">
      <div class="card-header"><h6 class="mb-0 text-danger">Delete your account</h6></div>
      <div class="card-body">
        <p>Closing your account disables sign-in and removes your personal profile details. Loan and payment records may be retained where required for financial, audit, or legal purposes.</p>
        <p class="fw-bold">This action cannot be undone.</p>
        <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <form method="post" action="delete-account.php" onsubmit="return confirm('Are you sure you want to permanently close your account?');">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['account_delete_csrf'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="mb-3">
            <label for="password" class="form-label">Current password</label>
            <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" required>
          </div>
          <button type="submit" class="btn btn-danger">Confirm account deletion</button>
          <a href="settings.php" class="btn btn-outline-secondary ms-2">Cancel</a>
        </form>
      </div>
    </div>
  </div>
</div>
<?php require 'footer.php'; ?>
