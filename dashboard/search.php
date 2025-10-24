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
$userSql = "SELECT name, profile_picture FROM users WHERE id = ?";
$userStmt = $conn->prepare($userSql);
$userStmt->bind_param("i", $user_id);
$userStmt->execute();
$userResult = $userStmt->get_result();
$user = $userResult->fetch_assoc();
$userStmt->close();

// Handle search for users
$search_results = [];
$search_query = isset($_GET['search_query']) ? trim($_GET['search_query']) : '';

if (!empty($search_query)) {
    $search_term = "%$search_query%";
    $searchSql = "SELECT u.id, u.name, u.email, u.profile_picture, u.status, 
                  f.status AS friendship_status, f.user_id AS friendship_initiator
                  FROM users u 
                  LEFT JOIN friends f ON 
                      (u.id = f.friend_id AND f.user_id = ?) OR (u.id = f.user_id AND f.friend_id = ?)
                  WHERE (u.name LIKE ? OR u.email LIKE ?) 
                  AND u.id != ? 
                  AND u.status = 'Verified'
                  ORDER BY u.name LIMIT 20";
    $searchStmt = $conn->prepare($searchSql);
    $searchStmt->bind_param("iissi", $user_id, $user_id, $search_term, $search_term, $user_id);
    $searchStmt->execute();
    $search_results = $searchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $searchStmt->close();
    
    if (empty($search_results)) {
        $message = "No users found matching your search.";
        $msgClass = "msg-info";
    }
} elseif (isset($_GET['search_query'])) {
    $message = "Please enter a name or email to search.";
    $msgClass = "msg-error";
}

// Handle send friend request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_request'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = "Invalid CSRF token.";
        $msgClass = "msg-error";
    } else {
        $friend_id = filter_var($_POST['friend_id'] ?? 0, FILTER_VALIDATE_INT);
        
        if (!$friend_id) {
            $message = "Invalid user selected.";
            $msgClass = "msg-error";
        } else {
            // Check if user exists and is verified
            $checkUserSql = "SELECT id, name FROM users WHERE id = ? AND status = 'Verified'";
            $checkStmt = $conn->prepare($checkUserSql);
            $checkStmt->bind_param("i", $friend_id);
            $checkStmt->execute();
            $friendResult = $checkStmt->get_result();
            
            if ($friendResult->num_rows === 0) {
                $message = "User not found or not verified.";
                $msgClass = "msg-error";
            } else {
                $friend = $friendResult->fetch_assoc();
                
                // Check if friendship already exists
                $checkFriendshipSql = "SELECT status FROM friends 
                                      WHERE (user_id = ? AND friend_id = ?) 
                                      OR (user_id = ? AND friend_id = ?)";
                $checkFriendStmt = $conn->prepare($checkFriendshipSql);
                $checkFriendStmt->bind_param("iiii", $user_id, $friend_id, $friend_id, $user_id);
                $checkFriendStmt->execute();
                $friendshipResult = $checkFriendStmt->get_result();
                
                if ($friendshipResult->num_rows > 0) {
                    $friendship = $friendshipResult->fetch_assoc();
                    if ($friendship['status'] === 'pending') {
                        if ($friendshipResult->num_rows > 0) {
                            // Determine who sent the request
                            $checkFriendStmt->close();
                            $checkDirectionSql = "SELECT user_id FROM friends 
                                                WHERE ((user_id = ? AND friend_id = ?) 
                                                OR (user_id = ? AND friend_id = ?)) 
                                                AND status = 'pending'";
                            $directionStmt = $conn->prepare($checkDirectionSql);
                            $directionStmt->bind_param("iiii", $user_id, $friend_id, $friend_id, $user_id);
                            $directionStmt->execute();
                            $directionResult = $directionStmt->get_result();
                            $direction = $directionResult->fetch_assoc();
                            
                            if ($direction['user_id'] == $user_id) {
                                $message = "You have already sent a friend request to " . htmlspecialchars($friend['name']) . ".";
                            } else {
                                $message = htmlspecialchars($friend['name']) . " has already sent you a friend request. Check your pending requests!";
                            }
                            $directionStmt->close();
                        }
                    } else {
                        $message = "You are already friends with " . htmlspecialchars($friend['name']) . "!";
                    }
                    $msgClass = "msg-info";
                } else {
                    // Send friend request
                    $insertSql = "INSERT INTO friends (user_id, friend_id, status) VALUES (?, ?, 'pending')";
                    $insertStmt = $conn->prepare($insertSql);
                    $insertStmt->bind_param("ii", $user_id, $friend_id);
                    
                    if ($insertStmt->execute()) {
                        $message = "Friend request sent to " . htmlspecialchars($friend['name']) . "!";
                        $msgClass = "msg-success";
                        // Refresh to update sent requests list
                        header("Location: search.php?search_query=" . urlencode($search_query));
                        exit;
                    } else {
                        $message = "Error sending friend request. Please try again.";
                        $msgClass = "msg-error";
                    }
                    $insertStmt->close();
                }
                $checkFriendStmt->close();
            }
            $checkStmt->close();
        }
        
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

// Fetch pending sent requests
$sentRequestsSql = "SELECT f.friend_id, u.name, u.email, u.profile_picture, f.created_at 
                   FROM friends f 
                   JOIN users u ON f.friend_id = u.id 
                   WHERE f.user_id = ? AND f.status = 'pending' 
                   ORDER BY f.created_at DESC";
$sentRequestsStmt = $conn->prepare($sentRequestsSql);
$sentRequestsStmt->bind_param("i", $user_id);
$sentRequestsStmt->execute();
$sent_requests = $sentRequestsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$sentRequestsStmt->close();

// Count statistics for sidebar
$friendsCountSql = "SELECT COUNT(*) as count FROM friends WHERE (user_id = ? OR friend_id = ?) AND status = 'approved'";
$friendsStmt = $conn->prepare($friendsCountSql);
$friendsStmt->bind_param("ii", $user_id, $user_id);
$friendsStmt->execute();
$friends_count = $friendsStmt->get_result()->fetch_assoc()['count'];
$friendsStmt->close();

$pendingReceivedSql = "SELECT COUNT(*) as count FROM friends WHERE friend_id = ? AND status = 'pending'";
$pendingStmt = $conn->prepare($pendingReceivedSql);
$pendingStmt->bind_param("i", $user_id);
$pendingStmt->execute();
$pending_received = $pendingStmt->get_result()->fetch_assoc()['count'];
$pendingStmt->close();

// Count user posts for sidebar
$postCountSql = "SELECT COUNT(*) as post_count FROM posts WHERE user_id = ?";
$postCountStmt = $conn->prepare($postCountSql);
$postCountStmt->bind_param("i", $user_id);
$postCountStmt->execute();
$post_count = $postCountStmt->get_result()->fetch_assoc()['post_count'];
$postCountStmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search Users - <?php echo htmlspecialchars($user['name']); ?> - Manexis</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        /* Dashboard-style layout with subtle differences */
        body {
            margin: 0;
            padding: 0;
            background: #f5f5f5;
        }

        /* Top header bar - slightly different shade */
        .top-header-bar {
            background: #34495e;  /* Subtle change from #4a4a4a */
            height: 40px;
            width: 100%;
        }

        /* Main header with logo and search - adjusted colors */
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
            color: #2c3e50;  /* Darker blue for distinction */
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
            background: #ecf0f1;  /* Lighter search bar */
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

        /* Main layout container */
        .dashboard-container {
            display: flex;
            max-width: 1400px;
            margin: 20px auto;
            gap: 20px;
            padding: 0 20px;
        }

        /* Left sidebar - kept but with profile adjustments */
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
            background: #ecf0f1;  /* Lighter hover for profile */
        }

        .sidebar-nav-item.active {
            border-left: 4px solid #3498db;  /* Blue border for active */
            background: #f0f4f8;
            color: #3498db;
        }

        .nav-icon {
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        /* Main content area */
        .main-content {
            flex: 1;
            max-width: 800px;
        }

        /* Right sidebar */
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

        /* Search-specific styles */
        .search-hero {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            margin-bottom: 20px;
            text-align: center;
        }

        .search-hero h1 {
            margin: 0 0 10px 0;
            color: #2d3748;
            font-size: 28px;
            font-weight: 700;
        }

        .search-hero p {
            margin: 0 0 20px 0;
            color: #718096;
            font-size: 16px;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        .search-container {
            max-width: 500px;
            margin: 0 auto;
        }

        .search-form-group {
            display: flex;
            gap: 10px;
        }

        .search-input {
            flex: 1;
            padding: 12px 20px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s;
        }

        .search-input:focus {
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
            outline: none;
        }

        /* Results Section */
        .results-section {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            margin-bottom: 20px;
            overflow: hidden;
        }

        .section-header {
            padding: 20px 25px;
            border-bottom: 2px solid #f7fafc;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .section-header h2 {
            margin: 0;
            color: #2d3748;
            font-size: 20px;
            font-weight: 600;
        }

        .results-count {
            background: #edf2f7;
            color: #4a5568;
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 14px;
            font-weight: 600;
        }

        .users-grid {
            padding: 0;
        }

        .user-card {
            display: flex;
            align-items: center;
            padding: 20px 25px;
            border-bottom: 1px solid #f7fafc;
            transition: background-color 0.2s;
            gap: 15px;
        }

        .user-card:hover {
            background: #f8fafc;
        }

        .user-card:last-child {
            border-bottom: none;
        }

        .user-avatar-med {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: #3498db;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
            font-weight: bold;
            background-size: cover;
            background-position: center;
            border: 2px solid white;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            flex-shrink: 0;
        }

        .user-info {
            flex: 1;
            min-width: 0;
        }

        .user-name {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 4px;
        }

        .user-email {
            color: #718096;
            font-size: 14px;
            margin-bottom: 6px;
        }

        .user-status {
            color: #48bb78;
            font-size: 12px;
            font-weight: 500;
        }

        .user-actions {
            display: flex;
            gap: 8px;
            flex-shrink: 0;
        }

        /* Quick Stats in sidebar - blue accents */
        .quick-stats-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            background: #f8fafc;
            border-radius: 8px;
            margin-bottom: 8px;
        }

        /* Empty States */
        .empty-state {
            padding: 40px 20px;
            text-align: center;
        }

        .empty-state-icon {
            font-size: 60px;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        /* Pending Badge */
        .pending-badge {
            background: #ed8936;
            color: white;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            text-align: center;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.12);
        }

        .stat-number {
            font-size: 28px;
            font-weight: 700;
            color: #3498db;
            display: block;
            margin-bottom: 5px;
        }

        .stat-label {
            color: #718096;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Scrollbar styling */
        .left-sidebar::-webkit-scrollbar,
        .right-sidebar::-webkit-scrollbar {
            width: 6px;
        }

        .left-sidebar::-webkit-scrollbar-track,
        .right-sidebar::-webkit-scrollbar-track {
            background: #f1f1f1;
        }

        .left-sidebar::-webkit-scrollbar-thumb,
        .right-sidebar::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 3px;
        }

        /* Responsive design */
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

            .stats-grid {
                grid-template-columns: 1fr;
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

            .search-form-group {
                flex-direction: column;
            }

            .user-card {
                flex-direction: column;
                text-align: center;
                gap: 15px;
                padding: 20px;
            }

            .user-actions {
                width: 100%;
                justify-content: center;
                flex-wrap: wrap;
            }

            .search-hero {
                padding: 20px;
            }

            .search-hero h1 {
                font-size: 24px;
            }
        }

        @media (max-width: 480px) {
            .user-actions {
                flex-direction: column;
                width: 100%;
            }

            .user-actions .action-btn {
                width: 100%;
                text-align: center;
            }

            .stats-grid {
                grid-template-columns: 1fr;
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
            <input type="text" name="search_query" placeholder="Search for friends, posts, and more..." value="<?php echo htmlspecialchars($search_query); ?>">
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
                <div class="message <?php echo $msgClass; ?>" style="margin-bottom: 20px;">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <!-- Search Hero Section -->
            <div class="search-hero">
                <h1>Connect with Friends</h1>
                <p>Search for users by name or email address to send friend requests and grow your network</p>
                <div class="search-container">
                    <form method="GET" action="search.php">
                        <div class="search-form-group">
                            <input type="text" name="search_query" value="<?php echo htmlspecialchars($search_query); ?>" 
                                   class="search-input" placeholder="Enter name or email address..." required>
                            <button type="submit" class="action-btn">🔍 Search</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Quick Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <span class="stat-number"><?php echo $friends_count; ?></span>
                    <div class="stat-label">Total Friends</div>
                    <div style="margin-top: 10px;">
                        <a href="list_friends.php" class="action-btn btn-small">View All</a>
                    </div>
                </div>
                
                <div class="stat-card">
                    <span class="stat-number"><?php echo count($sent_requests); ?></span>
                    <div class="stat-label">Pending Sent</div>
                    <div style="margin-top: 10px;">
                        <a href="#sent-requests" class="action-btn secondary btn-small">View Below</a>
                    </div>
                </div>
            </div>

            <!-- Search Results -->
            <?php if (!empty($search_results)): ?>
                <div class="results-section">
                    <div class="section-header">
                        <h2>Search Results</h2>
                        <span class="results-count"><?php echo count($search_results); ?> found</span>
                    </div>
                    <div class="users-grid">
                        <?php foreach ($search_results as $result): ?>
                            <?php
                            $profile_page = ($result['friendship_status'] === 'approved') ? 'friend_profile.php' : 'public_profile.php';
                            ?>
                            <div class="user-card">
                                <div class="user-avatar-med" style="<?php echo !empty($result['profile_picture']) ? 'background-image: url(../../uploads/profile_pictures/' . htmlspecialchars($result['profile_picture']) . ');' : ''; ?>">
                                    <?php if (empty($result['profile_picture'])) echo strtoupper(substr($result['name'], 0, 1)); ?>
                                </div>
                                
                                <div class="user-info">
                                    <div class="user-name"><?php echo htmlspecialchars($result['name']); ?></div>
                                    <div class="user-email"><?php echo htmlspecialchars($result['email']); ?></div>
                                    <div class="user-status">✅ Verified User</div>
                                </div>
                                
                                <div class="user-actions">
                                    <a href="<?php echo $profile_page; ?>?id=<?php echo $result['id']; ?>" class="action-btn secondary btn-small">
                                        👀 View Profile
                                    </a>
                                    <?php if ($result['friendship_status'] === 'approved'): ?>
                                        <span class="pending-badge" style="background: #48bb78;">✅ Friends</span>
                                    <?php elseif ($result['friendship_status'] === 'pending'): ?>
                                        <?php
                                        $direction = ($result['friendship_initiator'] == $user_id) ? ' (Sent)' : ' (Received)';
                                        ?>
                                        <span class="pending-badge">⏳ Pending<?php echo $direction; ?></span>
                                    <?php else: ?>
                                        <form method="POST" action="" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="send_request" value="1">
                                            <input type="hidden" name="friend_id" value="<?php echo $result['id']; ?>">
                                            <button type="submit" class="action-btn btn-small" data-user-name="<?php echo htmlspecialchars($result['name']); ?>">
                                                ➕ Add Friend
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php elseif (!empty($search_query) && empty($search_results) && empty($message)): ?>
                <div class="results-section">
                    <div class="section-header">
                        <h2>Search Results</h2>
                        <span class="results-count">0 found</span>
                    </div>
                    <div class="empty-state">
                        <div class="empty-state-icon">🔍</div>
                        <h3 style="color: #2d3748; margin-bottom: 10px;">No users found</h3>
                        <p style="color: #718096;">
                            No users found matching "<strong><?php echo htmlspecialchars($search_query); ?></strong>"<br>
                            Try searching with a different name or email address.
                        </p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Pending Sent Requests -->
            <div class="results-section" id="sent-requests">
                <div class="section-header">
                    <h2>Pending Sent Requests</h2>
                    <span class="results-count"><?php echo count($sent_requests); ?> pending</span>
                </div>
                
                <?php if (empty($sent_requests)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📤</div>
                        <h3 style="color: #2d3748; margin-bottom: 10px;">No pending requests</h3>
                        <p style="color: #718096;">
                            You haven't sent any friend requests yet.<br>
                            Use the search above to find people and send requests!
                        </p>
                    </div>
                <?php else: ?>
                    <div class="users-grid">
                        <?php foreach ($sent_requests as $request): ?>
                            <div class="user-card">
                                <div class="user-avatar-med" style="<?php echo !empty($request['profile_picture']) ? 'background-image: url(../../uploads/profile_pictures/' . htmlspecialchars($request['profile_picture']) . ');' : ''; ?>">
                                    <?php if (empty($request['profile_picture'])) echo strtoupper(substr($request['name'], 0, 1)); ?>
                                </div>
                                
                                <div class="user-info">
                                    <div class="user-name"><?php echo htmlspecialchars($request['name']); ?></div>
                                    <div class="user-email"><?php echo htmlspecialchars($request['email']); ?></div>
                                    <div class="user-status">
                                        Request sent: <?php echo date('M j, Y', strtotime($request['created_at'])); ?>
                                    </div>
                                </div>
                                
                                <div class="user-actions">
                                    <a href="public_profile.php?id=<?php echo $request['friend_id']; ?>" class="action-btn secondary btn-small">
                                        👀 View Profile
                                    </a>
                                    <span class="pending-badge">⏳ Pending</span>
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
                <h3>Network Stats</h3>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <div class="quick-stats-item">
                        <span style="color: #718096; font-size: 14px;">👥 Friends</span>
                        <span style="font-weight: 600; color: #2d3748;"><?php echo $friends_count; ?></span>
                    </div>
                    <div class="quick-stats-item">
                        <span style="color: #718096; font-size: 14px;">📬 Sent Requests</span>
                        <span style="font-weight: 600; color: #2d3748;"><?php echo count($sent_requests); ?></span>
                    </div>
                    <div class="quick-stats-item">
                        <span style="color: #718096; font-size: 14px;">📥 Received Requests</span>
                        <span style="font-weight: 600; color: #2d3748;"><?php echo $pending_received; ?></span>
                    </div>
                    <div class="quick-stats-item">
                        <span style="color: #718096; font-size: 14px;">📝 Your Posts</span>
                        <span style="font-weight: 600; color: #2d3748;"><?php echo $post_count; ?></span>
                    </div>
                </div>
            </div>

            <!-- Quick Links -->
            <div class="sidebar-card">
                <h3>Quick Actions</h3>
                <div style="display: flex; flex-direction: column; gap: 10px;">
                    <a href="list_friends.php" style="display: flex; align-items: center; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 8px; text-decoration: none; color: #2d3748; transition: background 0.2s;">
                        <span>👥</span>
                        <span>All Friends</span>
                    </a>
                    <a href="dashboard.php" style="display: flex; align-items: center; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 8px; text-decoration: none; color: #2d3748; transition: background 0.2s;">
                        <span>🏠</span>
                        <span>Dashboard</span>
                    </a>
                    <a href="profile.php" style="display: flex; align-items: center; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 8px; text-decoration: none; color: #2d3748; transition: background 0.2s;">
                        <span>👤</span>
                        <span>My Profile</span>
                    </a>
                    <a href="create_post.php" style="display: flex; align-items: center; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 8px; text-decoration: none; color: #2d3748; transition: background 0.2s;">
                        <span>✏️</span>
                        <span>Create Post</span>
                    </a>
                </div>
            </div>

            <!-- Search Tips -->
            <div class="sidebar-card">
                <h3>Search Tips</h3>
                <div style="font-size: 13px; color: #718096; line-height: 1.6;">
                    <p style="margin: 0 0 10px 0;">🔍 <strong>Search by name:</strong> Enter full or partial names</p>
                    <p style="margin: 0 0 10px 0;">📧 <strong>Search by email:</strong> Enter complete email addresses</p>
                    <p style="margin: 0;">👥 <strong>Find friends:</strong> Connect with verified users only</p>
                </div>
            </div>
        </aside>
    </div>

    <script src="js/search.js"></script>
</body>
</html>