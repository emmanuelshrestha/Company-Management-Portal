<?php
require __DIR__ . '/../../config/db.php';

$message = "";
$messageType = "";
$valid_token = false;

// Check if token is provided and valid
if (isset($_GET['token'])) {
    $token = $_GET['token'];
    
    $sql = "SELECT id, reset_token_expires FROM users WHERE reset_token = ? LIMIT 1";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $stmt->store_result();
        
        if ($stmt->num_rows === 1) {
            $stmt->bind_result($user_id, $expires);
            $stmt->fetch();
            
            // Check if token is still valid
            if (strtotime($expires) > time()) {
                $valid_token = true;
            } else {
                $message = "Reset link has expired. Please request a new one.";
                $messageType = "error";
            }
        } else {
            $message = "Invalid reset link.";
            $messageType = "error";
        }
        $stmt->close();
    }
} else {
    $message = "No reset token provided.";
    $messageType = "error";
}

// Handle password reset form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid_token) {
    $new_password = trim($_POST['password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');
    
    if ($new_password === '' || $confirm_password === '') {
        $message = "All fields are required.";
        $messageType = "error";
    } elseif ($new_password !== $confirm_password) {
        $message = "Passwords do not match.";
        $messageType = "error";
    } elseif (strlen($new_password) < 8) {
        $message = "Password must be at least 8 characters long.";
        $messageType = "error";
    } elseif (!preg_match('/[0-9]/', $new_password)) {
        $message = "Password must contain at least one number.";
        $messageType = "error";
    } elseif (!preg_match('/[a-z]/', $new_password)) {
        $message = "Password must contain at least one lowercase letter.";
        $messageType = "error";
    } elseif (!preg_match('/[A-Z]/', $new_password)) {
        $message = "Password must contain at least one uppercase letter.";
        $messageType = "error";
    } else {
        // Update password and clear reset token
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $updateSql = "UPDATE users SET password = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?";
        
        if ($updateStmt = $conn->prepare($updateSql)) {
            $updateStmt->bind_param("si", $hashed_password, $user_id);
            
            if ($updateStmt->execute()) {
                $message = "Password updated successfully! You can now login with your new password.";
                $messageType = "success";
                $valid_token = false; // Token used, no longer valid
            } else {
                $message = "Error updating password. Please try again.";
                $messageType = "error";
            }
            $updateStmt->close();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="form-card">
            <div class="header">
                <h1>Create New Password</h1>
                <p>Enter your new password below</p>
            </div>

            <?php if (!empty($message)): ?>
            <div class="message <?php echo $messageType; ?> show" id="serverMessage">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>

            <?php if ($valid_token): ?>
            <form method="POST" id="resetForm">
                <div class="form-group">
                    <label for="password">New Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="password" name="password" placeholder="Enter new password" required>
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
                    <label for="confirm-password">Confirm New Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="confirm-password" name="confirm_password" placeholder="Confirm new password" required>
                        <span class="toggle-password" onclick="togglePassword('confirm-password')">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                        </span>
                    </div>
                    <div class="error-message" id="confirmPasswordError">Passwords do not match</div>
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

                <button type="submit" class="login-btn">Reset Password</button>

                <div class="signup-link">
                    <a href="login.php">Back to Login</a>
                </div>
            </form>
            <?php else: ?>
            <div class="signup-link">
                <a href="forgot-password.php">Request new reset link</a> | 
                <a href="login.php">Back to Login</a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script src='js/reset-password.js'></script>
</body>
</html>