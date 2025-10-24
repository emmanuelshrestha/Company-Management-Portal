<?php
session_start();
require __DIR__ . '/../config/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../modules/user/login.php');
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
$userSql = "SELECT name, profile_picture, status, created_at FROM users WHERE id = ?";
$userStmt = $conn->prepare($userSql);
$userStmt->bind_param("i", $user_id);
$userStmt->execute();
$userResult = $userStmt->get_result();
$user = $userResult->fetch_assoc();
$userStmt->close();

// Count friends for sidebar
$friendSql = "SELECT COUNT(*) as friend_count FROM friends WHERE (user_id = ? OR friend_id = ?) AND status = 'approved'";
$friendStmt = $conn->prepare($friendSql);
$friendStmt->bind_param("ii", $user_id, $user_id);
$friendStmt->execute();
$friend_count = $friendStmt->get_result()->fetch_assoc()['friend_count'];
$friendStmt->close();

// Count pending requests for sidebar
$pendingSql = "SELECT COUNT(*) as pending_count FROM friends WHERE friend_id = ? AND status = 'pending'";
$pendingStmt = $conn->prepare($pendingSql);
$pendingStmt->bind_param("i", $user_id);
$pendingStmt->execute();
$pending_count = $pendingStmt->get_result()->fetch_assoc()['pending_count'];
$pendingStmt->close();

// Count user posts for sidebar
$postCountSql = "SELECT COUNT(*) as post_count FROM posts WHERE user_id = ?";
$postCountStmt = $conn->prepare($postCountSql);
$postCountStmt->bind_param("i", $user_id);
$postCountStmt->execute();
$post_count = $postCountStmt->get_result()->fetch_assoc()['post_count'];
$postCountStmt->close();

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
                    // Refresh the page to update the list
                    header("Refresh:0");
                    exit;
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
    u.profile_picture,
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

// Fetch pending received for stats
$pendingReceivedSql = "SELECT COUNT(*) as count FROM friends WHERE friend_id = ? AND status = 'pending'";
$pendingStmt = $conn->prepare($pendingReceivedSql);
$pendingStmt->bind_param("i", $user_id);
$pendingStmt->execute();
$pending_received = $pendingStmt->get_result()->fetch_assoc()['count'];
$pendingStmt->close();

// Fetch pending sent for stats
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
    <title>Friends List - <?php echo htmlspecialchars($user['name']); ?> - Manexis</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        /* Styles copied from list_friends.php, removing search-related styles */
        body {
            margin: 0;
            padding: 0;
            background: #f5f5f5;
        }

        .top-header-bar {
            background: #4a4a4a;
            height: 40px;
            width: 100%;
        }

        .main-header {
            background: white;
            padding: 15px 40px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 100;
            margin-bottom: 0;
            border-radius: 0;
            border: none;
            border-bottom: 1px solid #e2e8f0;
        }

        .logo-text {
            font-size: 24px;
            font-weight: bold;
            color: #333;
            margin: 0;
        }

        .search-bar-container {
            flex: 1;
            max-width: 500px;
            margin: 0 40px;
            position: relative;
        }

        .search-bar-container input {
            width: 100%;
            padding: 12px 20px 12px 45px;
            border: none;
            background: #f0f2f5;
            border-radius: 25px;
            font-size: 14px;
            margin-bottom: 0;
        }

        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #666;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .dashboard-container {
            display: flex;
            max-width: 1400px;
            margin: 20px auto;
            gap: 20px;
            padding: 0 20px;
        }

        .left-sidebar {
            width: 280px;
            position: sticky;
            top: 90px;
            height: calc(100vh - 110px);
            overflow-y: auto;
        }

        .profile-section-sidebar {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }

        .sidebar-nav {
            background: white;
            border-radius: 12px;
            padding: 10px 0;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }

        .sidebar-nav-item {
            padding: 15px 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            cursor: pointer;
            transition: background 0.2s;
            text-decoration: none;
            color: var(--text-secondary);
        }

        .sidebar-nav-item:hover {
            background: #f5f5f5;
        }

        .sidebar-nav-item.active {
            border-left: 4px solid #6c5ce7;
            background: #f5f5f5;
            color: #6c5ce7;
        }

        .nav-icon {
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .main-content {
            flex: 1;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            min-height: calc(100vh - 150px);
        }

        .right-sidebar {
            width: 320px;
            position: sticky;
            top: 90px;
            height: calc(100vh - 110px);
            overflow-y: auto;
        }

        .sidebar-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }

        .sidebar-card h3 {
            font-size: 18px;
            margin-bottom: 15px;
            color: #1a202c;
            border-bottom: 2px solid #f7fafc;
            padding-bottom: 10px;
        }

        .quick-stats-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            background: #f8fafc;
            border-radius: 8px;
            margin-bottom: 8px;
        }

        .friends-header {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .friends-title-section h1 {
            margin: 0 0 8px 0;
            color: #2d3748;
            font-size: 28px;
            font-weight: 700;
        }

        .friends-title-section p {
            margin: 0;
            color: #718096;
            font-size: 16px;
        }

        .friends-count-badge {
            background: #667eea;
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 16px;
            font-weight: 600;
            margin-left: 15px;
        }

        .friends-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .friends-stat-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            text-align: center;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .friends-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.12);
        }

        .friends-stat-number {
            font-size: 36px;
            font-weight: 700;
            color: #667eea;
            display: block;
            margin-bottom: 8px;
        }

        .friends-stat-label {
            color: #718096;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .friends-list-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            flex: 1;
        }

        .friends-list-header {
            padding: 25px 30px;
            border-bottom: 2px solid #f7fafc;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .friends-list-header h2 {
            margin: 0;
            color: #2d3748;
            font-size: 24px;
            font-weight: 600;
        }

        .friends-count-small {
            background: #edf2f7;
            color: #4a5568;
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 14px;
            font-weight: 600;
        }

        .friends-grid {
            display: grid;
            gap: 0;
            max-height: 600px;
            overflow-y: auto;
        }

        .friend-card {
            display: flex;
            align-items: center;
            padding: 25px 30px;
            border-bottom: 1px solid #f7fafc;
            transition: background-color 0.2s;
            gap: 20px;
        }

        .friend-card:hover {
            background: #f8fafc;
        }

        .friend-card:last-child {
            border-bottom: none;
        }

        .friend-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: #667eea;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            font-weight: bold;
            background-size: cover;
            background-position: center;
            border: 3px solid white;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            flex-shrink: 0;
        }

        .friend-info {
            flex: 1;
            min-width: 0;
        }

        .friend-name {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 4px;
        }

        .friend-email {
            color: #718096;
            font-size: 14px;
            margin-bottom: 8px;
        }

        .friend-meta {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .friend-meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
            color: #718096;
            font-size: 13px;
        }

        .friend-actions {
            display: flex;
            gap: 10px;
            flex-shrink: 0;
        }

        .friends-empty-state {
            padding: 60px 30px;
            text-align: center;
        }

        .friends-empty-icon {
            font-size: 80px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .left-sidebar::-webkit-scrollbar,
        .right-sidebar::-webkit-scrollbar,
        .friends-grid::-webkit-scrollbar {
            width: 6px;
        }

        .left-sidebar::-webkit-scrollbar-track,
        .right-sidebar::-webkit-scrollbar-track,
        .friends-grid::-webkit-scrollbar-track {
            background: #f1f1f1;
        }

        .left-sidebar::-webkit-scrollbar-thumb,
        .right-sidebar::-webkit-scrollbar-thumb,
        .friends-grid::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 3px;
        }

        @media (max-width: 1200px) {
            .right-sidebar {
                display: none;
            }
        }

        @media (max-width: 900px) {
            .left-sidebar {
                display: none;
            }
            
            .dashboard-container {
                padding: 0 15px;
            }
            
            .main-content {
                max-width: 100%;
            }

            .friends-stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .main-header {
                padding: 15px 20px;
            }

            .search-bar-container {
                display: none;
            }

            .logo-text {
                font-size: 20px;
            }

            .friends-header {
                flex-direction: column;
                gap: 20px;
                text-align: center;
                padding: 25px;
            }

            .friends-stats-grid {
                grid-template-columns: 1fr;
            }

            .friend-card {
                flex-direction: column;
                text-align: center;
                gap: 15px;
                padding: 20px;
            }

            .friend-actions {
                width: 100%;
                justify-content: center;
                flex-wrap: wrap;
            }

            .friend-meta {
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .friend-actions {
                flex-direction: column;
                width: 100%;
            }

            .friend-actions .action-btn,
            .friend-actions .btn-remove {
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>
<body class="dashboard-page">
    <input type="hidden" id="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
    
    <!-- Top dark header bar -->
    <div class="top-header-bar"></div>

    <!-- Main header -->
    <div class="main-header">
        <h1 class="logo-text">Manexis</h1>
        <form method="GET" action="search.php" class="search-bar-container">
            <span class="search-icon"><img src="../assets/images/search.png" alt="Home" style="width:20px;height:20px;margin-top:10px"></span>
            <input type="text" name="search_query" placeholder="Search for friends, posts, and more...">
        </form>
        <div class="header-right">
            <a href="create_post.php" class="action-btn">Create Post</a>
            <?php 
            if (!empty($user['profile_picture'])) {
                echo '<div class="user-avatar" style="background-image: url(../../uploads/profile_pictures/' . htmlspecialchars($user['profile_picture']) . ');"></div>';
            } else {
                echo '<div class="user-avatar">' . strtoupper(substr($user['name'] ?? 'U', 0, 1)) . '</div>';
            }
            ?>
        </div>
    </div>

    <!-- Main dashboard container -->
    <div class="dashboard-container">
        <!-- Left Sidebar -->
        <aside class="left-sidebar">
            <div class="profile-section-sidebar">
                <?php 
                if (!empty($user['profile_picture'])) {
                    echo '<div class="user-avatar" style="background-image: url(../../uploads/profile_pictures/' . htmlspecialchars($user['profile_picture']) . ');"></div>';
                } else {
                    echo '<div class="user-avatar">' . strtoupper(substr($user['name'] ?? 'U', 0, 1)) . '</div>';
                }
                ?>
                <div>
                    <div style="font-weight: 600; font-size: 16px; color: #2d3748;"><?php echo htmlspecialchars($user['name'] ?? 'User'); ?></div>
                    <div style="font-size: 14px; color: #718096;">@<?php echo htmlspecialchars(strtolower(str_replace(' ', '', $user['name'] ?? 'user'))); ?></div>
                </div>
            </div>

            <nav class="sidebar-nav">
                <a href="dashboard.php" class="sidebar-nav-item active">
                    <div class="nav-icon"><img src ="../assets/images/home-icon.png" alt="Home" style="width: 20px; height: 20px;"></div>
                    <span>Home</span>
                </a>
                <a href="profile.php" class="sidebar-nav-item">
                    <div class="nav-icon"><img src ="../assets/images/profile.png" alt="Home" style="width: 20px; height: 20px;"></div>
                    <span>Profile</span>
                </a>
                <a href="list_friends.php" class="sidebar-nav-item">
                    <div class="nav-icon"><img src ="../assets/images/friends.png" alt="Home" style="width: 20px; height: 20px;"></div>
                    <span>Friends List</span>
                    <?php if ($friend_count > 0): ?>
                        <span class="friends-count"><?php echo $friend_count; ?></span>
                    <?php endif; ?>
                </a>
                <a href="../messages/messages.php" class="sidebar-nav-item">
                    <div class="nav-icon"><img src ="../assets/images/messages.png" alt="Home" style="width: 20px; height: 20px;"></div>
                    <span>Messages</span>
                </a>
                <a href="../settings/settings.php" class="sidebar-nav-item">
                    <div class="nav-icon"><img src ="../assets/images/setting.png" alt="Home" style="width: 20px; height: 20px;"></div>
                    <span>Settings</span>
                </a>
                <a href="logout.php" class="sidebar-nav-item" style="color: #e53e3e;">
                    <div class="nav-icon"><img src ="../assets/images/logout.png" alt="Home" style="width: 20px; height: 20px;"></div>
                    <span>Logout</span>
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Messages -->
            <?php if (!empty($message)): ?>
                <div class="message <?php echo $msgClass; ?>" style="margin: 20px;">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <!-- Friends Header -->
            <div class="friends-header">
                <div class="friends-title-section">
                    <h1>
                        My Friends
                        <?php if ($total_friends > 0): ?>
                            <span class="friends-count-badge"><?php echo $total_friends; ?> friends</span>
                        <?php endif; ?>
                    </h1>
                    <p>Manage your connections and stay in touch with friends</p>
                </div>
                <div class="friends-header-actions">
                    <a href="search.php" class="action-btn">➕ Add Friends</a>
                    <a href="dashboard.php" class="action-btn secondary">🏠 Dashboard</a>
                </div>
            </div>

            <!-- Quick Stats -->
            <div class="friends-stats-grid">
                <div class="friends-stat-card">
                    <span class="friends-stat-number"><?php echo $total_friends; ?></span>
                    <div class="friends-stat-label">Total Friends</div>
                </div>
                
                <div class="friends-stat-card">
                    <span class="friends-stat-number"><?php echo $pending_received; ?></span>
                    <div class="friends-stat-label">Pending Requests</div>
                </div>
                
                <div class="friends-stat-card">
                    <span class="friends-stat-number"><?php echo $pending_sent; ?></span>
                    <div class="friends-stat-label">Sent Requests</div>
                </div>
                
                <div class="friends-stat-card">
                    <span class="friends-stat-number">👥</span>
                    <div class="friends-stat-label">Your Network</div>
                </div>
            </div>

            <!-- Friends List -->
            <div class="friends-list-container">
                <div class="friends-list-header">
                    <h2>
                        Friends List
                        <?php if ($total_friends > 0): ?>
                            <span class="friends-count-small"><?php echo $total_friends; ?> friends</span>
                        <?php endif; ?>
                    </h2>
                </div>
                
                <?php if ($total_friends === 0): ?>
                    <div class="friends-empty-state">
                        <div class="friends-empty-icon">👥</div>
                        <h3 style="color: #2d3748; margin-bottom: 10px;">No friends yet</h3>
                        <p style="color: #718096; margin-bottom: 25px; max-width: 400px; margin-left: auto; margin-right: auto;">
                            Start building your network by adding friends! Connect with people you know and expand your social circle.
                        </p>
                        <a href="search.php" class="action-btn" style="margin-right: 10px;">Add Your First Friend</a>
                        <a href="dashboard.php" class="action-btn secondary">Back to Dashboard</a>
                    </div>
                <?php else: ?>
                    <div class="friends-grid" id="friendsContainer">
                        <?php foreach ($friends as $friend): ?>
                            <div class="friend-card" data-name="<?php echo htmlspecialchars(strtolower($friend['name'])); ?>" data-email="<?php echo htmlspecialchars(strtolower($friend['email'])); ?>">
                                <div class="friend-avatar" style="<?php echo !empty($friend['profile_picture']) ? 'background-image: url(../../uploads/profile_pictures/' . htmlspecialchars($friend['profile_picture']) . ');' : ''; ?>">
                                    <?php if (empty($friend['profile_picture'])) echo strtoupper(substr($friend['name'], 0, 1)); ?>
                                </div>
                                
                                <div class="friend-info">
                                    <div class="friend-name"><?php echo htmlspecialchars($friend['name']); ?></div>
                                    <div class="friend-email"><?php echo htmlspecialchars($friend['email']); ?></div>
                                    <div class="friend-meta">
                                        <div class="friend-meta-item">
                                            <span class="status-badge status-verified">Verified</span>
                                        </div>
                                        <div class="friend-meta-item">
                                            📅 Friends since <?php echo date('M j, Y', strtotime($friend['friends_since'])); ?>
                                        </div>
                                        <div class="friend-meta-item">
                                            🤝 <?php echo htmlspecialchars($friend['request_direction']); ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="friend-actions">
                                    <a href="../messages/messages.php?friend_id=<?php echo $friend['id']; ?>" class="action-btn btn-small" title="Send Message">
                                        💬 Message
                                    </a>
                                    <a href="profile.php?user_id=<?php echo $friend['id']; ?>" class="action-btn secondary btn-small" title="View Profile">
                                        👀 View
                                    </a>
                                    <form method="POST" action="" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="remove_friend" value="1">
                                        <input type="hidden" name="friend_id" value="<?php echo $friend['id']; ?>">
                                        <button type="submit" class="btn-remove btn-small" title="Remove Friend" data-friend-name="<?php echo htmlspecialchars($friend['name']); ?>">
                                            🗑️ Remove
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>

        <!-- Right Sidebar -->
        <aside class="right-sidebar">
            <!-- Quick Stats -->
            <div class="sidebar-card">
                <h3>Friends Stats</h3>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <div class="quick-stats-item">
                        <span style="color: #718096; font-size: 14px;">👥 Total Friends</span>
                        <span style="font-weight: 600; color: #2d3748;"><?php echo $total_friends; ?></span>
                    </div>
                    <div class="quick-stats-item">
                        <span style="color: #718096; font-size: 14px;">📬 Pending Received</span>
                        <span style="font-weight: 600; color: #2d3748;"><?php echo $pending_received; ?></span>
                    </div>
                    <div class="quick-stats-item">
                        <span style="color: #718096; font-size: 14px;">📤 Pending Sent</span>
                        <span style="font-weight: 600; color: #2d3748;"><?php echo $pending_sent; ?></span>
                    </div>
                    <div class="quick-stats-item">
                        <span style="color: #718096; font-size: 14px;">✉️ Status</span>
                        <span style="font-weight: 600; color: <?php echo ($user && $user['status'] === 'Verified') ? '#48bb78' : '#e53e3e'; ?>;">
                            <?php echo ($user && $user['status'] === 'Verified') ? 'Verified' : 'Not Verified'; ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Quick Links -->
            <div class="sidebar-card">
                <h3>Quick Actions</h3>
                <div style="display: flex; flex-direction: column; gap: 10px;">
                    <a href="search.php" style="display: flex; align-items: center; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 8px; text-decoration: none; color: #2d3748; transition: background 0.2s;">
                        <span>➕</span>
                        <span>Add Friends</span>
                    </a>
                    <a href="dashboard.php" style="display: flex; align-items: center; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 8px; text-decoration: none; color: #2d3748; transition: background 0.2s;">
                        <span>🏠</span>
                        <span>Dashboard</span>
                    </a>
                    <a href="../messages/messages.php" style="display: flex; align-items: center; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 8px; text-decoration: none; color: #2d3748; transition: background 0.2s;">
                        <span>💬</span>
                        <span>Messages</span>
                    </a>
                    <a href="create_post.php" style="display: flex; align-items: center; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 8px; text-decoration: none; color: #2d3748; transition: background 0.2s;">
                        <span>✏️</span>
                        <span>Create Post</span>
                    </a>
                </div>
            </div>

            <!-- Account Info -->
            <div class="sidebar-card">
                <h3>Account Info</h3>
                <div style="font-size: 13px; color: #718096; line-height: 1.6;">
                    <p style="margin: 0 0 10px 0;">Member since: <strong><?php echo $user ? date('M Y', strtotime($user['created_at'])) : 'N/A'; ?></strong></p>
                    <p style="margin: 0;">Currently viewing <strong>Friends List</strong></p>
                </div>
            </div>
        </aside>
    </div>

    <script>
        // Updated list_friends.js
        document.addEventListener('DOMContentLoaded', function() {
            // Remove friend confirmation with friend's name
            document.querySelectorAll('.btn-remove').forEach(button => {
                button.addEventListener('click', function(e) {
                    const friendName = this.getAttribute('data-friend-name');
                    if (!confirm(`Are you sure you want to remove ${friendName} from your friends?`)) {
                        e.preventDefault();
                    }
                });
            });
            
            // Add smooth animations
            const friendCards = document.querySelectorAll('.friend-card');
            friendCards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                card.style.transition = `opacity 0.3s ease ${index * 0.05}s, transform 0.3s ease ${index * 0.05}s`;
                
                setTimeout(() => {
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, 50);
            });
            
            // Auto-dismiss messages
            setTimeout(() => {
                document.querySelectorAll('.message').forEach(msg => {
                    if (msg.classList.contains('msg-success') || msg.classList.contains('msg-error')) {
                        msg.style.opacity = '0';
                        msg.style.transition = 'opacity 0.5s ease';
                        setTimeout(() => {
                            if (msg.parentNode) {
                                msg.remove();
                            }
                        }, 500);
                    }
                });
            }, 5000);
        });
    </script>
</body>
</html>