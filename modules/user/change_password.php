<?php
session_start();
require __DIR__ . '/../../config/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Generate CSRF token if not set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$user_id = $_SESSION['user_id'];
$message = "";
$messageType = "";

// Fetch user details for header
$userSql = "SELECT name, profile_picture FROM users WHERE id = ?";
$userStmt = $conn->prepare($userSql);
$userStmt->bind_param("i", $user_id);
$userStmt->execute();
$userResult = $userStmt->get_result();
$user = $userResult->fetch_assoc();
$userStmt->close();

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = "Invalid CSRF token.";
        $messageType = "error";
    } else {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        // Validate inputs
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $message = "All fields are required.";
            $messageType = "error";
        } elseif ($new_password !== $confirm_password) {
            $message = "New passwords do not match.";
            $messageType = "error";
        } elseif (strlen($new_password) < 8) {
            $message = "New password must be at least 8 characters long.";
            $messageType = "error";
        } else {
            // Verify current password
            $verifySql = "SELECT password FROM users WHERE id = ?";
            $verifyStmt = $conn->prepare($verifySql);
            $verifyStmt->bind_param("i", $user_id);
            $verifyStmt->execute();
            $verifyResult = $verifyStmt->get_result();
            
            if ($verifyResult->num_rows === 0) {
                $message = "User not found.";
                $messageType = "error";
            } else {
                $userData = $verifyResult->fetch_assoc();
                
                if (!password_verify($current_password, $userData['password'])) {
                    $message = "Current password is incorrect.";
                    $messageType = "error";
                } else {
                    // Update password
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $updateSql = "UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?";
                    $updateStmt = $conn->prepare($updateSql);
                    $updateStmt->bind_param("si", $hashed_password, $user_id);
                    
                    if ($updateStmt->execute()) {
                        $message = "Password changed successfully!";
                        $messageType = "success";
                        
                        // Clear form fields
                        $_POST = array();
                    } else {
                        $message = "Error changing password. Please try again.";
                        $messageType = "error";
                    }
                    $updateStmt->close();
                }
            }
            $verifyStmt->close();
        }
        
        // Regenerate CSRF token
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - <?php echo htmlspecialchars($user['name']); ?></title>
    <link rel="stylesheet" href="../../dashboard/style.css">
    <style>
        .change-password-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
        }

        .form-card {
            background: white;
            border-radius: 12px;
            padding: 40px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 500px;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
        }

        .header h1 {
            color: #2d3748;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .header p {
            color: #718096;
            font-size: 16px;
        }

        .message {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
            display: none;
        }

        .message.show {
            display: block;
        }

        .message.success {
            background-color: #f0fff4;
            color: #276749;
            border: 1px solid #9ae6b4;
        }

        .message.error {
            background-color: #fff5f5;
            color: #c53030;
            border: 1px solid #fc8181;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #2d3748;
            font-weight: 500;
            font-size: 14px;
        }

        .password-wrapper {
            position: relative;
        }

        .password-wrapper input {
            width: 100%;
            padding: 12px 45px 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .password-wrapper input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .password-wrapper input.input-error {
            border-color: #fc8181;
        }

        .toggle-password {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #718096;
            background: none;
            border: none;
            padding: 0;
        }

        .toggle-password:hover {
            color: #4a5568;
        }

        .error-message {
            color: #e53e3e;
            font-size: 12px;
            margin-top: 5px;
            display: none;
        }

        .error-message.show {
            display: block;
        }

        .password-requirements {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }

        .password-requirements p {
            margin: 0 0 15px 0;
            color: #2d3748;
            font-weight: 500;
            font-size: 14px;
        }

        .requirements-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .requirement {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: #718096;
        }

        .requirement::before {
            content: "✗";
            color: #e53e3e;
            font-weight: bold;
        }

        .requirement.valid {
            color: #38a169;
        }

        .requirement.valid::before {
            content: "✓";
            color: #38a169;
        }

        .change-password-btn {
            width: 100%;
            padding: 12px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-bottom: 20px;
        }

        .change-password-btn:hover {
            background: #5a67d8;
            transform: translateY(-1px);
        }

        .back-link {
            text-align: center;
        }

        .back-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }

        .back-link a:hover {
            text-decoration: underline;
        }

        .forgot-password {
            text-align: right;
            margin: -10px 0 20px 0;
        }

        .forgot-password a {
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
        }

        .forgot-password a:hover {
            text-decoration: underline;
        }

        @media (max-width: 768px) {
            .form-card {
                padding: 30px 20px;
            }
            
            .requirements-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="dashboard-page">
    <div class="change-password-container">
        <div class="form-card">
            <div class="header">
                <h1>Change Password</h1>
                <p>Update your account password securely</p>
            </div>

            <?php if (!empty($message)): ?>
                <div class="message <?php echo $messageType; ?> show" id="serverMessage">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="changePasswordForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="change_password" value="1">
                
                <div class="form-group">
                    <label for="current_password">Current Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="current_password" name="current_password" placeholder="Enter your current password" required>
                        <span class="toggle-password" onclick="togglePassword('current_password')">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                        </span>
                    </div>
                    <div class="error-message" id="currentPasswordError">Please enter your current password</div>
                </div>
                
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="new_password" name="new_password" placeholder="Enter new password" required>
                        <span class="toggle-password" onclick="togglePassword('new_password')">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                        </span>
                    </div>
                    <div class="error-message" id="newPasswordError">Password doesn't meet requirements</div>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm new password" required>
                        <span class="toggle-password" onclick="togglePassword('confirm_password')">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                        </span>
                    </div>
                    <div class="error-message" id="confirmPasswordError">Passwords do not match</div>
                </div>

                <div class="forgot-password">
                    <a href="forgot-password.php">Forgot your password?</a>
                </div>

                <div class="password-requirements">
                    <p>Your new password must contain:</p>
                    <div class="requirements-grid">
                        <div class="requirement" id="req-length">A minimum of 8 characters</div>
                        <div class="requirement" id="req-number">At least one number</div>
                        <div class="requirement" id="req-lowercase">At least one lowercase letter</div>
                        <div class="requirement" id="req-uppercase">At least one uppercase letter</div>
                    </div>
                </div>

                <button type="submit" class="change-password-btn">Change Password</button>

                <div class="back-link">
                    <a href="profile.php">← Back to Profile</a>
                </div>
            </form>
        </div>
    </div>

    <script src="js/change_password.js"></script>
</body>
</html>