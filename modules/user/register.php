<?php
require __DIR__ . '/../../config/db.php'; 
require __DIR__ . '/../../lib/phpmailer/Exception.php'; 
require __DIR__ . '/../../lib/phpmailer/PHPMailer.php'; 
require __DIR__ . '/../../lib/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Development helpers (enable in dev only)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
error_reporting(E_ALL);
ini_set('display_errors', 1);

$message = "";
$messageType = "";

// Only handle when form submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Trim + basic sanitization
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    // prevent CR/LF in name/email (avoid injection)
    $name  = str_replace(["\r", "\n"], ['', ''], $name);
    $email = str_replace(["\r", "\n"], ['', ''], $email);

    // Basic validation
    if ($name === '' || $email === '' || $password === '') {
        $message = "All fields are required.";
        $messageType = "error";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Invalid email format.";
        $messageType = "error";
    } elseif (!PHPMailer::validateAddress($email)) {
        // PHPMailer has its own validator — extra safety
        $message = "Email address not valid (PHPMailer check).";
        $messageType = "error";
    } else {
        // All good - proceed
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $token = bin2hex(random_bytes(16));

        // Check if email already exists (optional but recommended)
        $checkSql = "SELECT id FROM users WHERE email = ? LIMIT 1";
        if ($checkStmt = $conn->prepare($checkSql)) {
            $checkStmt->bind_param("s", $email);
            $checkStmt->execute();
            $checkStmt->store_result();
            if ($checkStmt->num_rows > 0) {
                $message = "Email already registered.";
                $messageType = "error";
                $checkStmt->close();
            } else {
                $checkStmt->close();

                // Insert new user
                $sql = "INSERT INTO users (name, email, password, status, verification_token) VALUES (?, ?, ?, 'Not Verified', ?)";
                $stmt = $conn->prepare($sql);

                if ($stmt === false) {
                    $message = "Prepare failed: " . htmlspecialchars($conn->error);
                    $messageType = "error";
                } else {
                    $stmt->bind_param("ssss", $name, $email, $hashed_password, $token);

                    if ($stmt->execute()) {
                        // Build verification link (use your real domain / https in production)
                        $verificationLink = "http://localhost/Email_verification/verify.php?token=" . $token;

                        // Send email via SMTP (Mailtrap used for dev)
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
                            $mail->Subject = 'Confirm your email';
                            $mail->Body    = '
                                <html>
                                <body style="font-family: Poppins, sans-serif; background-color:#f7f7f7; margin:0; padding:0;">
                                    <table width="100%" cellpadding="0" cellspacing="0">
                                        <tr>
                                            <td align="center" style="padding:30px 0;">
                                                <table width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.1);">
                                                    <tr>
                                                        <td style="padding:30px; text-align:center;">
                                                            <h2 style="color:#333;">Confirm your email</h2>
                                                            <p>Hi '.htmlspecialchars($name).',</p>
                                                            <p>Before you get started, please confirm your email address to improve account security.</p>
                                                            <a href="'.$verificationLink.'" style="display:inline-block; padding:12px 25px; background-color:#FFFC00; color:#000; text-decoration:none; font-weight:bold; border-radius:5px; margin-top:20px;">Confirm Email</a>
                                                            <p style="margin-top:30px; font-size:12px; color:#888;">If this is not your account or you did not sign up, please ignore this email.</p>
                                                            <hr style="border:none; border-top:1px solid #eee; margin:30px 0;">
                                                            <p style="font-size:12px; color:#888;">SXC, Maitighar, Kathmandu, Nepal</p>
                                                            <p style="font-size:12px; color:#888;">
                                                                <a href="#" style="color:#888; text-decoration:none;">Privacy Policy</a> | 
                                                                <a href="#" style="color:#888; text-decoration:none;">Terms of Service</a>
                                                            </p>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                    </table>
                                </body>
                                </html>
                                ';

                            $mail->send();
                            $message = "User registered successfully! Please check your email to verify your account. 🎉";
                            $messageType = "success";
                        } catch (Exception $e) {
                            // Show PHPMailer-specific error (safe-escaped)
                            $message = "Email could not be sent. Mailer Error: " . htmlspecialchars($mail->ErrorInfo);
                            $messageType = "error";
                        }
                    } else {
                        $message = "Execute failed: " . htmlspecialchars($stmt->error);
                        $messageType = "error";
                    }

                    $stmt->close();
                }
            }
        } else {
            $message = "Prepare failed: " . htmlspecialchars($conn->error);
            $messageType = "error";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <title>Create Your Account</title>
    
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <link rel="stylesheet" href="style.css">
</head>

<body class="register-page">
    <div class="register-container">
        <div class="form-card">
            <div class="header">
                <h1>Create Your Account</h1>
                <p>Welcome! Please enter your details.</p>
            </div>

            <?php if (!empty($message)): ?>
            <div class="message <?php echo $messageType; ?> show" id="serverMessage">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>

            <button class="google-btn" type="button" onclick="alert('Google signup not implemented yet')">
                <svg class="google-icon" viewBox="0 0 24 24">
                    <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                    <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                    <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                    <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                </svg>
                Signup with Google
            </button>

            <div class="divider">
                <span>Or</span>
            </div>

            <form method="POST" id="signupForm">
                <div class="form-group">
                    <label for="name">Full Name</label>
                    <input type="text" id="name" name="name" placeholder="Enter your full name" value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>" required>
                    <div class="error-message" id="nameError">Please enter your full name</div>
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" placeholder="Enter your email address" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required>
                    <div class="error-message" id="emailError">Please enter a valid email address</div>
                </div>

                <div class="password-row">
                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="password" name="password" placeholder="Enter password" required>
                            <span class="toggle-password" onclick="togglePassword('password')">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </span>
                        </div>
                        <div class="error-message" id="passwordError">Password doesn't meet requirements</div>
                    </div>

                    <div class="form-group">
                        <label for="confirm-password">Confirm password</label>
                        <div class="password-wrapper">
                            <input type="password" id="confirm-password" name="confirm_password" placeholder="Confirm password" required>
                            <span class="toggle-password" onclick="togglePassword('confirm-password')">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </span>
                        </div>
                        <div class="error-message" id="confirmPasswordError">Passwords do not match</div>
                    </div>
                </div>

                <div class="password-requirements">
                    <p>Your password must contain:</p>
                    <div class="requirements-grid">
                        <div class="requirement" id="req-length">A minimum of 8 characters.</div>
                        <div class="requirement" id="req-number">At least one number</div>
                        <div class="requirement" id="req-lowercase">At least one lowercase letter</div>
                        <div class="requirement" id="req-uppercase">At least one uppercase letter</div>
                    </div>
                </div>

                <button type="submit" class="signup-btn">Signup</button>

                <div class="login-link">
                    Already have an account? <a href="login.php">Login</a>
                </div>
            </form>
        </div>
    </div>
    <script src="js/register.js"></script>
</body>
</html>