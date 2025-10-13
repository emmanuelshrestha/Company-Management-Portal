<?php
session_start();
require __DIR__ . '/../config/db.php'; 


// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: ../modules/user/login.php");
    exit();
}

// Get user data
$user_id = $_SESSION['user_id'];
$sql = "SELECT name, email, status, created_at FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($name, $email, $status, $created_at);
$stmt->fetch();
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Welcome <?php echo htmlspecialchars($name); ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="dashboard-page">
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="welcome-section">
                <h1>Welcome back, <?php echo htmlspecialchars($name); ?>! 👋</h1>
                <p>Here's your account overview and quick actions</p>
            </div>
            <div class="user-info">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($name, 0, 1)); ?>
                </div>
                <a href="logout.php" class="logout-btn">Logout</a>
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Account Status</h3>
                <div class="stat-number">
                    <span class="status-badge <?php echo $status === 'Verified' ? 'status-verified' : 'status-not-verified'; ?>">
                        <?php echo htmlspecialchars($status); ?>
                    </span>
                </div>
            </div>
            <div class="stat-card">
                <h3>Member Since</h3>
                <div class="stat-number">
                    <?php echo date('M j, Y', strtotime($created_at)); ?>
                </div>
            </div>
            <div class="stat-card">
                <h3>Email Verified</h3>
                <div class="stat-number">
                    <?php echo $status === 'Verified' ? '✅ Yes' : '❌ No'; ?>
                </div>
            </div>
        </div>

        <!-- Profile Information -->
        <div class="profile-card">
            <h2>Profile Information</h2>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Full Name</div>
                    <div class="info-value"><?php echo htmlspecialchars($name); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Email Address</div>
                    <div class="info-value"><?php echo htmlspecialchars($email); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Account Status</div>
                    <div class="info-value">
                        <span class="status-badge <?php echo $status === 'Verified' ? 'status-verified' : 'status-not-verified'; ?>">
                            <?php echo htmlspecialchars($status); ?>
                        </span>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-label">Registration Date</div>
                    <div class="info-value"><?php echo date('F j, Y g:i A', strtotime($created_at)); ?></div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <a href="edit-profile.php" class="action-btn">Edit Profile</a>
                <a href="change-password.php" class="action-btn secondary">Change Password</a>
                <?php if ($status !== 'Verified'): ?>
                <a href="resend-verification.php" class="action-btn secondary">Resend Verification Email</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>