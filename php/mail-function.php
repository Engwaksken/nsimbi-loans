<?php
require_once __DIR__ . '/../mail/PHPMailerAutoload.php';
require_once __DIR__ . '/db.php';

function getEmailSettings($conn) {
    $query = "
        SELECT smtp_host, smtp_port, smtp_user, smtp_password, smtp_secure, 
               from_email, from_name 
        FROM email_settings 
        LIMIT 1
    ";
    $result = $conn->query($query);

    if ($result && $row = $result->fetch_assoc()) {
        return $row;
    }

    throw new Exception("Email settings not found in the database.");
}

// Universal Email Sender Function (FIXED $mail)

function sendEmail($to, $subject, $body, $altBody = '', $bccList = []) {
    global $conn;

    try {
        $settings = getEmailSettings($conn);

        $mail = new PHPMailer(true); 

       
        if (empty($settings['smtp_host']) || empty($settings['smtp_user']) || empty($settings['smtp_password'])) {
            throw new Exception("Missing SMTP configuration.");
        }

     
        $mail->isSMTP();
        $mail->Host       = $settings['smtp_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $settings['smtp_user'];
        $mail->Password   = $settings['smtp_password'];

      
        $encryption = strtolower(trim($settings['smtp_secure'] ?? 'tls'));
        if ($encryption === 'ssl') {
            $mail->SMTPSecure = 'ssl';
        } else {
            $mail->SMTPSecure = 'tls';
        }

        $mail->Port = (int)($settings['smtp_port'] ?? 465);

       
        $mail->setFrom(
            $settings['from_email'] ?? $settings['smtp_user'],
            $settings['from_name'] ?? 'Nsimbi Impact'
        );

      
        if (is_array($to)) {
            foreach ($to as $recipient) {
                $mail->addAddress(trim($recipient));
            }
        } else {
            $mail->addAddress(trim($to));
        }

        if (!empty($bccList) && is_array($bccList)) {
            foreach ($bccList as $bcc) {
                $mail->addBCC(trim($bcc));
            }
        }

       
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = $altBody ?: strip_tags($body);

       
        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("Email send failed to {$to}: " . ($mail->ErrorInfo ?? 'PHPMailer not initialized') . " | Exception: " . $e->getMessage());
        return false;
    }
}


// Send Registration Confirmation

function sendRegistrationEmail($toEmail, $userName, $loginLink) {
    $subject = 'Registration Confirmation - Nsimbi Impact';
    $body = "
        <h3>Welcome to Nsimbi Impact, {$userName}!</h3>
        <p>Thank you for registering. You can now log in to apply for a loan using the link below:</p>
        <p><a href='{$loginLink}' style='padding:10px 20px; background-color:#28A745; color:white; text-decoration:none;'>Login</a></p>
        <p>Best regards,<br>Nsimbi Impact Team</p>
    ";

    return sendEmail($toEmail, $subject, $body, strip_tags($body));
}


// Send Password Reset Email

function sendPasswordResetEmail($toEmail, $resetLink) {
    $subject = 'Reset Your Password - Nsimbi Impact';
    $body = "
        <h3>Password Reset Request</h3>
        <p>We received a request to reset your password. Click below to set a new one:</p>
        <p><a href='{$resetLink}' style='padding:10px 20px; background-color:#28A745; color:white; text-decoration:none;'>Reset Password</a></p>
        <p>If you didn’t request this, please ignore this email.</p>
    ";

    return sendEmail($toEmail, $subject, $body, strip_tags($body));
}


// Notify Admins of New Registration

function sendAdminNotification($newUserName, $newUserEmail, $conn) {
    try {
        $stmt = $conn->prepare("
            SELECT email FROM user 
            WHERE role IN (1, 2) AND status = 1 AND email LIKE '%@%'
        ");
        $stmt->execute();
        $result = $stmt->get_result();

        $adminEmails = [];
        while ($row = $result->fetch_assoc()) {
            $adminEmails[] = $row['email'];
        }
        $stmt->close();

        if (empty($adminEmails)) {
            error_log("No admins found for registration alert.");
            return false;
        }

        $subject = "New Client Registration - Nsimbi Impact";
        $dashboardLink = "https://nsimbiimpact.com/loans/index.php";
        $body = "
            <h3>New Client Registration</h3>
            <p>A new client has registered on <strong>Nsimbi Impact Loan System</strong>.</p>
            <p><strong>Name:</strong> {$newUserName}<br><strong>Email:</strong> {$newUserEmail}</p>
            <p><a href='{$dashboardLink}' style='padding:10px 20px; background-color:#007BFF; color:white; text-decoration:none;'>View Dashboard</a></p>
        ";

        foreach ($adminEmails as $email) {
            sendEmail($email, $subject, $body, strip_tags($body));
        }

        return true;
    } catch (Exception $e) {
        error_log("Admin registration notification error: " . $e->getMessage());
        return false;
    }
}


// Notify Admins of New Loan Request

function sendAdminLoanRequest($userName, $userEmail, $conn) {
    try {
        $stmt = $conn->prepare("
            SELECT email FROM user 
            WHERE role IN (1, 2) AND status = 1 AND email LIKE '%@%'
        ");
        $stmt->execute();
        $result = $stmt->get_result();

        $adminEmails = [];
        while ($row = $result->fetch_assoc()) {
            $adminEmails[] = $row['email'];
        }
        $stmt->close();

        if (empty($adminEmails)) {
            error_log("No admins found for loan request alert.");
            return false;
        }

        $subject = "New Loan Request - Nsimbi Impact";
        $dashboardLink = "https://nsimbiimpact.com/loans/index.php";
        $body = "
            <p>A client has submitted a new loan request on <strong>Nsimbi Impact Loan System</strong>.</p>
            <p><strong>Name:</strong> {$userName}<br></p>
            <p><a href='{$dashboardLink}' style='padding:10px 20px; background-color:#007BFF; color:white; text-decoration:none;'>Go to Dashboard</a></p>
        ";

        foreach ($adminEmails as $email) {
            sendEmail($email, $subject, $body, strip_tags($body));
        }

        return true;
    } catch (Exception $e) {
        error_log("Loan request notification error: " . $e->getMessage());
        return false;
    }
}


// Notify Client (Role=3) & Staff (Role=4): Loan Approved or Rejected

function notifyLoanStatusChange($loan_id, $status, $conn) {
    try {
        // Fetch loan and client details
        $loanStmt = $conn->prepare("
            SELECT l.id, l.client_id, l.amount, l.status, c.email AS client_email, c.phone AS phone,
                   CONCAT(u.fname, ' ', u.lname) AS client_name
            FROM loan l 
            JOIN customers c ON l.client_id = c.user_id
            JOIN user u ON c.user_id = u.id
            WHERE l.id = ?
        ");
        $loanStmt->bind_param("i", $loan_id);
        $loanStmt->execute();
        $loan = $loanStmt->get_result()->fetch_assoc();
        $loanStmt->close();

        if (!$loan) {
            throw new Exception("Loan not found for ID {$loan_id}");
        }

        $loanAmount = number_format($loan['amount'], 2);
        $statusText = strtoupper($status);
        $clientPhone = "{$loan['phone']}";
        $clientName = $loan['client_name']; // now defined

        // === Notify Client ===
        $clientSubject = ($statusText === 'APPROVED')
            ? "Loan Approved - Nsimbi Impact"
            : "Loan Request Rejected - Nsimbi Impact";

        $clientBody = ($statusText === 'APPROVED')
            ? "<h3>Congratulations, {$clientName}!</h3>
               <p>Your loan request of <strong>UGX {$loanAmount}</strong> has been <strong>APPROVED</strong>.</p>
               <p>Our team will contact you shortly for disbursement details.</p>
               <p>Best regards,<br>Nsimbi Impact Loan Department</p>"
            : "<h3>Hello, {$clientName}</h3>
               <p>We regret to inform you that your loan request of <strong>UGX {$loanAmount}</strong> has been <strong>REJECTED</strong>.</p>
               <p>For more details, please contact support or visit your dashboard.</p>
               <p>Best regards,<br>Nsimbi Impact Loan Department</p>";

        sendEmail($loan['client_email'], $clientSubject, $clientBody, strip_tags($clientBody));

        // === Notify Staff (Role=4) ===
        $staffStmt = $conn->prepare("
            SELECT fname, lname, email 
            FROM user 
            WHERE role = 4 AND status = 1 AND email LIKE '%@%'
        ");
        $staffStmt->execute();
        $staffResult = $staffStmt->get_result();

        while ($staff = $staffResult->fetch_assoc()) {
            $staffName = "{$staff['fname']} {$staff['lname']}";
            $staffSubject = "Loan {$statusText} Notification - Nsimbi Impact";
            $staffBody = "
                <h3>Dear {$staffName},</h3>
                <p>A loan request from <strong>{$clientName}</strong> (Amount: <strong>UGX {$loanAmount}</strong>) has been <strong>{$statusText}</strong>.</p>
                <p>Please log in to review the details.</p>
                <p>Best regards,<br>Nsimbi Impact Loan Department</p>
            ";

            sendEmail($staff['email'], $staffSubject, $staffBody, strip_tags($staffBody));
        }

        $staffStmt->close();
        return true;
    } catch (Exception $e) {
        error_log("Loan status notification error: " . $e->getMessage());
        return false;
    }
}

?>
