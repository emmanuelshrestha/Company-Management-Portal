<?php
session_start();
require __DIR__ . '/../config/db.php';

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
$msgClass = "";

// Fetch user details
$sql = "SELECT id, name, email, status, created_at FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $message = "User not found.";
    $msgClass = "msg-error";
    $user = null;
} else {
    $user = $result->fetch_assoc();
}
$stmt->close();

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = "Invalid CSRF token.";
        $msgClass = "msg-error";
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        
        // Validate inputs
        if (empty($name) || empty($email)) {
            $message = "Please fill in all required fields.";
            $msgClass = "msg-error";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = "Please enter a valid email address.";
            $msgClass = "msg-error";
        } else {
            // Check if email already exists (excluding current user)
            $checkEmailSql = "SELECT id FROM users WHERE email = ? AND id != ?";
            $checkStmt = $conn->prepare($checkEmailSql);
            $checkStmt->bind_param("si", $email, $user_id);
            $checkStmt->execute();
            $emailResult = $checkStmt->get_result();
            
            if ($emailResult->num_rows > 0) {
                $message = "This email is already registered.";
                $msgClass = "msg-error";
            } else {
                // Update user profile
                $updateSql = "UPDATE users SET name = ?, email = ? WHERE id = ?";
                $updateStmt = $conn->prepare($updateSql);
                $updateStmt->bind_param("ssi", $name, $email, $user_id);
                
                if ($updateStmt->execute()) {
                    $message = "Profile updated successfully!";
                    $msgClass = "msg-success";
                    
                    // Refresh user data
                    $refreshSql = "SELECT id, name, email, status, created_at FROM users WHERE id = ?";
                    $refreshStmt = $conn->prepare($refreshSql);
                    $refreshStmt->bind_param("i", $user_id);
                    $refreshStmt->execute();
                    $user = $refreshStmt->get_result()->fetch_assoc();
                    $refreshStmt->close();
                } else {
                    $message = "Error updating profile. Please try again.";
                    $msgClass = "msg-error";
                }
                $updateStmt->close();
            }
            $checkStmt->close();
        }
        
        // Regenerate CSRF token after successful form submission
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - <?php echo htmlspecialchars($user['name'] ?? 'User'); ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="dashboard-page">
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="welcome-section">
                <h1>Profile Settings</h1>
                <p>Manage your account information and preferences</p>
            </div>
            <div class="user-info">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($user['name'] ?? 'U', 0, 1)); ?>
                </div>
                <a href="dashboard.php" class="action-btn secondary">Back to Dashboard</a>
                <a href="logout.php" class="logout-btn">Logout</a>
            </div>
        </div>

        <!-- Navigation -->
        <div class="nav-links">
            <a href="dashboard.php">Dashboard</a> | 
            <a href="profile.php">Profile</a> | 
            <a href="add_friend.php">Add Friends</a> | 
            <a href="list_friends.php">Friends List</a> | 
            <a href="create_post.php">Create Post</a> | 
            <a href="news_feed.php">News Feed</a> | 
            <a href="logout.php">Logout</a>
        </div>

        <!-- Messages -->
        <?php if (!empty($message)): ?>
            <div class="message <?php echo $msgClass; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class="stats-grid">
            <!-- Profile Overview Card -->
            <div class="card">
                <h2>Profile Overview</h2>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">User ID</div>
                        <div class="info-value">#<?php echo htmlspecialchars($user['id'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Account Status</div>
                        <div class="info-value">
                            <span class="status-badge <?php echo ($user && $user['status'] === 'Verified') ? 'status-verified' : 'status-not-verified'; ?>">
                                <?php echo htmlspecialchars($user['status'] ?? 'Unknown'); ?>
                            </span>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Member Since</div>
                        <div class="info-value"><?php echo $user ? date('F j, Y', strtotime($user['created_at'])) : 'N/A'; ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Last Updated</div>
                        <div class="info-value"><?php echo date('F j, Y g:i A'); ?></div>
                    </div>
                </div>
            </div>

            <!-- Account Statistics Card -->
            <div class="card">
                <h2>Account Statistics</h2>
                <div class="info-grid">
                    <?php
                    // Count friends
                    $friendSql = "SELECT COUNT(*) as friend_count FROM friends WHERE (user_id = ? OR friend_id = ?) AND status = 'approved'";
                    $friendStmt = $conn->prepare($friendSql);
                    $friendStmt->bind_param("ii", $user_id, $user_id);
                    $friendStmt->execute();
                    $friend_count = $friendStmt->get_result()->fetch_assoc()['friend_count'];
                    $friendStmt->close();

                    // Count pending requests
                    $pendingSql = "SELECT COUNT(*) as pending_count FROM friends WHERE friend_id = ? AND status = 'pending'";
                    $pendingStmt = $conn->prepare($pendingSql);
                    $pendingStmt->bind_param("i", $user_id);
                    $pendingStmt->execute();
                    $pending_count = $pendingStmt->get_result()->fetch_assoc()['pending_count'];
                    $pendingStmt->close();

                    // Count sent requests
                    $sentSql = "SELECT COUNT(*) as sent_count FROM friends WHERE user_id = ? AND status = 'pending'";
                    $sentStmt = $conn->prepare($sentSql);
                    $sentStmt->bind_param("i", $user_id);
                    $sentStmt->execute();
                    $sent_count = $sentStmt->get_result()->fetch_assoc()['sent_count'];
                    $sentStmt->close();
                    ?>
                    <div class="info-item">
                        <div class="info-label">Total Friends</div>
                        <div class="info-value" style="font-size: 24px; color: #667eea;"><?php echo $friend_count; ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Pending Requests</div>
                        <div class="info-value" style="font-size: 24px; color: #ed8936;"><?php echo $pending_count; ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Sent Requests</div>
                        <div class="info-value" style="font-size: 24px; color: #9f7aea;"><?php echo $sent_count; ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Account Age</div>
                        <div class="info-value" style="font-size: 24px; color: #38a169;">
                            <?php 
                            if ($user) {
                                $accountAge = floor((time() - strtotime($user['created_at'])) / (60 * 60 * 24));
                                echo $accountAge . ' days';
                            } else {
                                echo 'N/A';
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit Profile Form -->
        <div class="card">
            <h2>Edit Profile Information</h2>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="update_profile" value="1">
                
                <div class="info-grid">
                    <div class="info-item">
                        <label for="name" class="info-label">Full Name *</label>
                        <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="info-item">
                        <label for="email" class="info-label">Email Address *</label>
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required>
                    </div>
                </div>

                <div class="quick-actions" style="margin-top: 30px;">
                    <button type="submit" class="action-btn">Update Profile</button>
                    <a href="change-password.php" class="action-btn secondary">Change Password</a>
                    <?php if ($user && $user['status'] !== 'Verified'): ?>
                        <a href="resend-verification.php" class="action-btn secondary">Resend Verification Email</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Danger Zone -->
        <div class="card" style="border-left: 4px solid #e53e3e;">
            <h2 style="color: #e53e3e;">Danger Zone</h2>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Account Deletion</div>
                    <div class="info-value">
                        <p style="color: #718096; margin-bottom: 15px;">Once you delete your account, there is no going back. Please be certain.</p>
                        <a href="delete-account.php" class="logout-btn" onclick="return confirm('Are you sure you want to delete your account? This action cannot be undone.')">Delete Account</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const name = document.getElementById('name').value.trim();
            const email = document.getElementById('email').value.trim();
            
            if (!name || !email) {
                e.preventDefault();
                alert('Please fill in all required fields.');
                return false;
            }
            
            if (!validateEmail(email)) {
                e.preventDefault();
                alert('Please enter a valid email address.');
                return false;
            }
        });
        
        function validateEmail(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        }
    </script>
</body>
</html>