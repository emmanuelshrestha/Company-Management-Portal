<?php
require __DIR__ . '/../../config/db.php';
require __DIR__ . '/../../lib/phpmailer/Exception.php';
require __DIR__ . '/../../lib/phpmailer/PHPMailer.php';
require __DIR__ . '/../../lib/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$message = "";
$messageType = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if ($email === '') {
        $message = "Please enter your email address.";
        $messageType = "error";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Invalid email format.";
        $messageType = "error";
    } else {
        // Check if email exists
        $sql = "SELECT id, name FROM users WHERE email = ? LIMIT 1";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $stmt->store_result();
            
            if ($stmt->num_rows === 1) {
                $stmt->bind_result($user_id, $name);
                $stmt->fetch();
                
                // Generate reset token
                $reset_token = bin2hex(random_bytes(32));
                $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));
                
                // Store token in database
                $updateSql = "UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE id = ?";
                if ($updateStmt = $conn->prepare($updateSql)) {
                    $updateStmt->bind_param("ssi", $reset_token, $expires_at, $user_id);
                    
                    if ($updateStmt->execute()) {
                        // Send reset email
                        $resetLink = "http://localhost/company-portal/modules/user/reset-password.php?token=" . $reset_token;
                        
                        $mail = new PHPMailer(true);
                        try {
                            $mail->isSMTP();
                            $mail->Host       = $env['SMTP_HOST'];
                            $mail->SMTPAuth   = true;
                            $mail->Username   = $env['SMTP_USERNAME'];
                            $mail->Password   = $env['SMTP_PASSWORD'];
                            $mail->Port       = $env['SMTP_PORT'];
                            $mail->SMTPSecure = $env['SMTP_SECURE'];

                            $mail->setFrom($env['SMTP_FROM_EMAIL'], $env['SMTP_FROM_NAME']);
                            $mail->addAddress($email, $name);

                            $mail->isHTML(true);
                            $mail->Subject = 'Password Reset Request';
                            $mail->Body    = "
                                <h3>Password Reset Request</h3>
                                <p>Hi $name,</p>
                                <p>You requested to reset your password. Click the link below to reset it:</p>
                                <a href='$resetLink' style='padding: 10px 20px; background: #667eea; color: white; text-decoration: none; border-radius: 5px;'>Reset Password</a>
                                <p>This link will expire in 1 hour.</p>
                                <p>If you didn't request this, please ignore this email.</p>
                            ";

                            $mail->send();
                            $message = "Password reset link sent to your email!";
                            $messageType = "success";
                        } catch (Exception $e) {
                            $message = "Failed to send email. Please try again.";
                            $messageType = "error";
                        }
                    }
                    $updateStmt->close();
                }
            } else {
                $message = "If this email exists, a reset link will be sent.";
                $messageType = "success"; // Don't reveal if email exists
            }
            $stmt->close();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="form-card">
            <div class="header">
                <h1>Reset Your Password</h1>
                <p>Enter your email to receive a reset link</p>
            </div>

            <?php if (!empty($message)): ?>
            <div class="message <?php echo $messageType; ?> show">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" placeholder="Enter your email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required>
                </div>

                <button type="submit" class="login-btn">Send Reset Link</button>

                <div class="signup-link">
                    <a href="login.php">Back to Login</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>