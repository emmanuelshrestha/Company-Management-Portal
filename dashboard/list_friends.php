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

// Fetch user details for display
$userSql = "SELECT name FROM users WHERE id = ?";
$userStmt = $conn->prepare($userSql);
$userStmt->bind_param("i", $user_id);
$userStmt->execute();
$userResult = $userStmt->get_result();
$user = $userResult->fetch_assoc();
$userStmt->close();

// Handle remove friend action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_friend'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = "Invalid CSRF token.";
        $msgClass = "msg-error";
    } else {
        $friend_id = filter_var($_POST['friend_id'] ?? 0, FILTER_VALIDATE_INT);
        
        if (!$friend_id) {
            $message = "Invalid friend selected.";
            $msgClass = "msg-error";
        } else {
            // Verify the friendship exists
            $verifySql = "SELECT id FROM friends 
                         WHERE ((user_id = ? AND friend_id = ?) 
                         OR (user_id = ? AND friend_id = ?)) 
                         AND status = 'approved'";
            $verifyStmt = $conn->prepare($verifySql);
            $verifyStmt->bind_param("iiii", $user_id, $friend_id, $friend_id, $user_id);
            $verifyStmt->execute();
            
            if ($verifyStmt->get_result()->num_rows === 0) {
                $message = "Friendship not found.";
                $msgClass = "msg-error";
            } else {
                // Remove the friendship (delete both directions if they exist)
                $deleteSql = "DELETE FROM friends 
                             WHERE (user_id = ? AND friend_id = ?) 
                             OR (user_id = ? AND friend_id = ?)";
                $deleteStmt = $conn->prepare($deleteSql);
                $deleteStmt->bind_param("iiii", $user_id, $friend_id, $friend_id, $user_id);
                
                if ($deleteStmt->execute()) {
                    $message = "Friend removed successfully.";
                    $msgClass = "msg-success";
                } else {
                    $message = "Error removing friend. Please try again.";
                    $msgClass = "msg-error";
                }
                $deleteStmt->close();
            }
            $verifyStmt->close();
        }
        
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

// Fetch all approved friends
$friendsSql = "SELECT 
    u.id,
    u.name,
    u.email,
    u.status as user_status,
    u.created_at as user_joined,
    CASE 
        WHEN f.user_id = ? THEN 'You sent request'
        ELSE 'They sent request'
    END as request_direction,
    f.created_at as friends_since
FROM friends f
JOIN users u ON (
    (f.user_id = ? AND u.id = f.friend_id) OR 
    (f.friend_id = ? AND u.id = f.user_id)
)
WHERE (f.user_id = ? OR f.friend_id = ?) 
AND f.status = 'approved'
AND u.id != ?
ORDER BY u.name";

$friendsStmt = $conn->prepare($friendsSql);
$friendsStmt->bind_param("iiiiii", $user_id, $user_id, $user_id, $user_id, $user_id, $user_id);
$friendsStmt->execute();
$friends_result = $friendsStmt->get_result();
$friends = $friends_result->fetch_all(MYSQLI_ASSOC);
$friendsStmt->close();

// Count total friends
$total_friends = count($friends);

// Fetch pending received requests for stats
$pendingReceivedSql = "SELECT COUNT(*) as count FROM friends WHERE friend_id = ? AND status = 'pending'";
$pendingStmt = $conn->prepare($pendingReceivedSql);
$pendingStmt->bind_param("i", $user_id);
$pendingStmt->execute();
$pending_received = $pendingStmt->get_result()->fetch_assoc()['count'];
$pendingStmt->close();

// Fetch pending sent requests for stats
$pendingSentSql = "SELECT COUNT(*) as count FROM friends WHERE user_id = ? AND status = 'pending'";
$pendingSentStmt = $conn->prepare($pendingSentSql);
$pendingSentStmt->bind_param("i", $user_id);
$pendingSentStmt->execute();
$pending_sent = $pendingSentStmt->get_result()->fetch_assoc()['count'];
$pendingSentStmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Friends List - <?php echo htmlspecialchars($user['name']); ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .friends-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .friend-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .friend-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .friend-header {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }

        .friend-avatar {
            width: 50px;
            height: 50px;
            background: #667eea;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 18px;
            margin-right: 15px;
        }

        .friend-info {
            flex: 1;
        }

        .friend-name {
            font-weight: 600;
            color: #2d3748;
            font-size: 18px;
            margin-bottom: 5px;
        }

        .friend-email {
            color: #718096;
            font-size: 14px;
        }

        .friend-details {
            border-top: 1px solid #f7fafc;
            padding-top: 15px;
        }

        .detail-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .detail-label {
            color: #718096;
        }

        .detail-value {
            color: #2d3748;
            font-weight: 500;
        }

        .friend-actions {
            margin-top: 15px;
            display: flex;
            gap: 10px;
        }

        .btn-remove {
            background: #e53e3e;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            transition: background 0.2s;
            flex: 1;
        }

        .btn-remove:hover {
            background: #c53030;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #718096;
        }

        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        .search-box {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .search-input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 16px;
        }

        .search-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .friends-count {
            background: #667eea;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            margin-left: 10px;
        }

        @media (max-width: 768px) {
            .friends-grid {
                grid-template-columns: 1fr;
            }
            
            .friend-header {
                flex-direction: column;
                text-align: center;
            }
            
            .friend-avatar {
                margin-right: 0;
                margin-bottom: 10px;
            }
            
            .friend-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body class="dashboard-page">
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="welcome-section">
                <h1>My Friends <span class="friends-count"><?php echo $total_friends; ?></span></h1>
                <p>Manage your friends list and connections</p>
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

        <!-- Quick Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Total Friends</h3>
                <div class="stat-number"><?php echo $total_friends; ?></div>
            </div>
            
            <div class="stat-card">
                <h3>Pending Received</h3>
                <div class="stat-number"><?php echo $pending_received; ?></div>
                <a href="dashboard.php" style="font-size: 14px;">View Requests</a>
            </div>
            
            <div class="stat-card">
                <h3>Pending Sent</h3>
                <div class="stat-number"><?php echo $pending_sent; ?></div>
                <a href="add_friend.php" style="font-size: 14px;">View Sent</a>
            </div>
            
            <div class="stat-card">
                <h3>Quick Actions</h3>
                <div style="margin-top: 10px;">
                    <a href="add_friend.php" class="action-btn btn-small" style="display: block; text-align: center; margin-bottom: 5px;">Add Friends</a>
                    <a href="dashboard.php" class="action-btn secondary btn-small" style="display: block; text-align: center;">Dashboard</a>
                </div>
            </div>
        </div>

        <!-- Search Box -->
        <div class="search-box">
            <input type="text" class="search-input" id="friendSearch" placeholder="Search friends by name or email...">
        </div>

        <!-- Friends List -->
        <div class="card">
            <h2>Friends List <?php echo $total_friends > 0 ? '(' . $total_friends . ')' : ''; ?></h2>
            
            <?php if ($total_friends === 0): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">👥</div>
                    <h3>No friends yet</h3>
                    <p>Start building your network by adding friends!</p>
                    <a href="add_friend.php" class="action-btn" style="margin-top: 15px;">Add Your First Friend</a>
                </div>
            <?php else: ?>
                <div class="friends-grid" id="friendsContainer">
                    <?php foreach ($friends as $friend): ?>
                        <div class="friend-card" data-name="<?php echo htmlspecialchars(strtolower($friend['name'])); ?>" data-email="<?php echo htmlspecialchars(strtolower($friend['email'])); ?>">
                            <div class="friend-header">
                                <div class="friend-avatar">
                                    <?php echo strtoupper(substr($friend['name'], 0, 1)); ?>
                                </div>
                                <div class="friend-info">
                                    <div class="friend-name"><?php echo htmlspecialchars($friend['name']); ?></div>
                                    <div class="friend-email"><?php echo htmlspecialchars($friend['email']); ?></div>
                                </div>
                            </div>
                            
                            <div class="friend-details">
                                <div class="detail-item">
                                    <span class="detail-label">Status:</span>
                                    <span class="detail-value">
                                        <span class="status-badge status-verified">Verified</span>
                                    </span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Friends Since:</span>
                                    <span class="detail-value"><?php echo date('M j, Y', strtotime($friend['friends_since'])); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Member Since:</span>
                                    <span class="detail-value"><?php echo date('M j, Y', strtotime($friend['user_joined'])); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Connection:</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($friend['request_direction']); ?></span>
                                </div>
                            </div>
                            
                            <div class="friend-actions">
                                <form method="POST" action="" style="flex: 1;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="remove_friend" value="1">
                                    <input type="hidden" name="friend_id" value="<?php echo $friend['id']; ?>">
                                    <button type="submit" class="btn-remove" onclick="return confirm('Are you sure you want to remove <?php echo htmlspecialchars($friend['name']); ?> from your friends?')">Remove Friend</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="js/list_friends.js"></script>
</body>
</html>