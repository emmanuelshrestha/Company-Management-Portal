<?php
session_start();
require __DIR__ . '/../config/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Fetch user details
$user_id = $_SESSION['user_id'];
$sql = "SELECT name, email, status, created_at, profile_picture FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    $message = "<p>User not found or not verified.</p>";
    $msgClass = "msg-error";
    $user = null;
} else {
    $user = $result->fetch_assoc();
}
$stmt->close();

// Fetch pending friend requests
$pendingSql = "SELECT u.id, u.name, u.profile_picture FROM friends f JOIN users u ON f.user_id = u.id WHERE f.friend_id = ? AND f.status = 'pending'";
$pendingStmt = $conn->prepare($pendingSql);
$pendingStmt->bind_param("i", $user_id);
$pendingStmt->execute();
$pendingResult = $pendingStmt->get_result();
$pendingRequests = $pendingResult->fetch_all(MYSQLI_ASSOC);
$pendingStmt->close();

// Count friends
$friendSql = "SELECT COUNT(*) as friend_count FROM friends WHERE (user_id = ? OR friend_id = ?) AND status = 'approved'";
$friendStmt = $conn->prepare($friendSql);
$friendStmt->bind_param("ii", $user_id, $user_id);
$friendStmt->execute();
$friend_count = $friendStmt->get_result()->fetch_assoc()['friend_count'];
$friendStmt->close();

// Handle friend request acceptance
$message = "";
$msgClass = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $friend_id = filter_var($_POST['friend_id'] ?? 0, FILTER_VALIDATE_INT);
        if ($friend_id && $_POST['action'] === 'accept') {
            $sql = "UPDATE friends SET status = 'approved' WHERE user_id = ? AND friend_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $friend_id, $user_id);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $message = "<p>Friend request accepted!</p>";
                $msgClass = "msg-success";
                header("Refresh:0"); // Reload to update pending requests
            } else {
                $message = "<p>No pending request found.</p>";
                $msgClass = "msg-error";
            }
            $stmt->close();
        }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Welcome <?php echo htmlspecialchars($user['name'] ?? 'User'); ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .pending-requests-list {
            margin-top: 15px;
        }

        .pending-request-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px;
            background: #f8fafc;
            border-radius: 8px;
            margin-bottom: 10px;
            border: 1px solid #e2e8f0;
        }

        .request-user {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
        }

        .user-avatar-small {
            width: 40px;
            height: 40px;
            background: #667eea;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 16px;
            border: 2px solid white;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .request-info {
            flex: 1;
        }

        .request-name {
            font-weight: 600;
            color: #2d3748;
            font-size: 16px;
        }

        .request-status {
            color: #718096;
            font-size: 14px;
        }

        .request-actions {
            margin: 0;
        }

        .accept-btn {
            background: #48bb78;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s;
        }

        .accept-btn:hover {
            background: #38a169;
            transform: translateY(-1px);
        }

        /* Ensure avatar images display properly */
        .user-avatar-small[style*="background-image"] {
            color: transparent !important;
            background-size: cover !important;
            background-position: center !important;
        }
    </style>
</head>
<body class="dashboard-page">
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="welcome-section">
                <h1>Welcome back, <?php echo htmlspecialchars($user['name'] ?? 'User'); ?>! 👋</h1>
                <p>Here's your account overview and quick actions</p>
            </div>
            <div class="user-info">
                <?php 
                if (!empty($user['profile_picture'])) {
                    echo '<div class="user-avatar" style="background-image: url(../../uploads/profile_pictures/' . htmlspecialchars($user['profile_picture']) . '); background-size: cover; background-position: center;"></div>';
                } else {
                    echo '<div class="user-avatar">' . strtoupper(substr($user['name'] ?? 'U', 0, 1)) . '</div>';
                }
                ?>
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
            <a href="../messages/messages.php">Messages</a>
        </div>

        <!-- Messages -->
        <?php if (!empty($message)) echo "<div class=\"message $msgClass\">$message</div>"; ?>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Account Status</h3>
                <div class="stat-number">
                    <span class="status-badge <?php echo ($user && $user['status'] === 'Verified') ? 'status-verified' : 'status-not-verified'; ?>">
                        <?php echo htmlspecialchars($user['status'] ?? 'Unknown'); ?>
                    </span>
                </div>
            </div>
            <div class="stat-card">
                <h3>Friends</h3>
                <div class="stat-number">
                    <?php echo $friend_count; ?> <a href="list_friends.php">View All</a>
                </div>
            </div>
            <div class="stat-card">
                <h3>Member Since</h3>
                <div class="stat-number">
                    <?php echo $user ? date('M j, Y', strtotime($user['created_at'])) : 'N/A'; ?>
                </div>
            </div>
            <div class="stat-card">
                <h3>Email Verified</h3>
                <div class="stat-number">
                    <?php echo ($user && $user['status'] === 'Verified') ? '✅ Yes' : '❌ No'; ?>
                </div>
            </div>
        </div>

        <!-- Pending Friend Requests -->
        <div class="card">
            <h2>Pending Friend Requests</h2>
            <?php if (empty($pendingRequests)): ?>
                <p>No pending friend requests.</p>
            <?php else: ?>
                <div class="pending-requests-list">
                    <?php foreach ($pendingRequests as $request): ?>
                        <div class="pending-request-item">
                            <div class="request-user">
                                <?php 
                                if (!empty($request['profile_picture'])) {
                                    echo '<div class="user-avatar-small" style="background-image: url(../../uploads/profile_pictures/' . htmlspecialchars($request['profile_picture']) . '); background-size: cover; background-position: center;"></div>';
                                } else {
                                    echo '<div class="user-avatar-small">' . strtoupper(substr($request['name'], 0, 1)) . '</div>';
                                }
                                ?>
                                <div class="request-info">
                                    <div class="request-name"><?php echo htmlspecialchars($request['name']); ?></div>
                                    <div class="request-status">Sent you a friend request</div>
                                </div>
                            </div>
                            <form method="POST" class="request-actions">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="accept">
                                <input type="hidden" name="friend_id" value="<?php echo $request['id']; ?>">
                                <button type="submit" class="accept-btn">Accept</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Profile Information -->
        <div class="profile-card">
            <h2>Profile Information</h2>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Full Name</div>
                    <div class="info-value"><?php echo htmlspecialchars($user['name'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Email Address</div>
                    <div class="info-value"><?php echo htmlspecialchars($user['email'] ?? 'N/A'); ?></div>
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
                    <div class="info-label">Registration Date</div>
                    <div class="info-value"><?php echo $user ? date('F j, Y g:i A', strtotime($user['created_at'])) : 'N/A'; ?></div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <a href="edit-profile.php" class="action-btn">Edit Profile</a>
                <a href="../modules/user/change_password.php" class="action-btn secondary">Change Password</a>
                <?php if ($user && $user['status'] !== 'Verified'): ?>
                    <a href="resend-verification.php" class="action-btn secondary">Resend Verification Email</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>