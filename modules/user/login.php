<?php
require __DIR__ . '/../../config/db.php'; 
require __DIR__ . '/../../lib/phpmailer/Exception.php'; 
require __DIR__ . '/../../lib/phpmailer/PHPMailer.php'; 
require __DIR__ . '/../../lib/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Development helpers
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
error_reporting(E_ALL);
ini_set('display_errors', 1);

$message = "";
$messageType = "";

// Only handle when form submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Trim + basic sanitization
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    // Basic validation
    if ($email === '' || $password === '') {
        $message = "Email and password are required.";
        $messageType = "error";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Invalid email format.";
        $messageType = "error";
    } else {
        // Check if user exists and password matches
        $sql = "SELECT id, name, email, password, status FROM users WHERE email = ? LIMIT 1";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $stmt->store_result();
            
            if ($stmt->num_rows === 1) {
                $stmt->bind_result($id, $name, $db_email, $hashed_password, $status);
                $stmt->fetch();
                
                if (password_verify($password, $hashed_password)) {
                    if ($status === 'Verified') {
                        // Login successful
                        session_start();
                        $_SESSION['user_id'] = $id;
                        $_SESSION['user_name'] = $name;
                        $_SESSION['user_email'] = $db_email;
                        
                        $message = "Login successful! Welcome back, " . htmlspecialchars($name) . "!";
                        $messageType = "success";
                        
                        // Redirect to dashboard after 2 seconds
                        header("refresh:2;url=dashboard.php");
                    } else {
                        $message = "Please verify your email address before logging in.";
                        $messageType = "error";
                    }
                } else {
                    $message = "Invalid email or password.";
                    $messageType = "error";
                }
            } else {
                $message = "Invalid email or password.";
                $messageType = "error";
            }
            $stmt->close();
        } else {
            $message = "Database error. Please try again.";
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
    <title>Login to Your Account</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="form-card">
            <div class="header">
                <h1>Welcome Back</h1>
                <p>Sign in to your account to continue</p>
            </div>

            <?php if (!empty($message)): ?>
            <div class="message <?php echo $messageType; ?> show" id="serverMessage">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>

            <form method="POST" id="loginForm">
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" placeholder="Enter your email address" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required>
                    <div class="error-message" id="emailError">Please enter a valid email address</div>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="password" name="password" placeholder="Enter your password" required>
                        <span class="toggle-password" onclick="togglePassword('password')">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                        </span>
                    </div>
                    <div class="error-message" id="passwordError">Please enter your password</div>
                </div>

                <div class="forgot-password">
                    <a href="forgot-password.php">Forgot your password?</a>
                </div>

                <button type="submit" class="login-btn">Login</button>

                <div class="divider">
                    <span>Or</span>
                </div>

                <button class="google-btn" type="button" onclick="alert('Google login not implemented yet')">
                    <svg class="google-icon" viewBox="0 0 24 24">
                        <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                        <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                        <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                        <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                    </svg>
                    Login with Google
                </button>

                <div class="signup-link">
                    Don't have an account? <a href="register.php">Sign up</a>
                </div>
            </form>
        </div>
    </div>
    <script src="js/login.js"></script>
</body>
</html>