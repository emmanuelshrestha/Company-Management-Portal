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
    $searchSql = "SELECT id, name, email, profile_picture, status FROM users 
                  WHERE (name LIKE ? OR email LIKE ?) 
                  AND id != ? 
                  AND status = 'Verified'
                  ORDER BY name LIMIT 20";
    $searchStmt = $conn->prepare($searchSql);
    $searchStmt->bind_param("ssi", $search_term, $search_term, $user_id);
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

// Count statistics
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search Users - <?php echo htmlspecialchars($user['name']); ?> - Manexis</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        /* Styles copied from add_friend.php */
        .add-friends-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .add-friends-header {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
            text-align: center;
        }

        .add-friends-header h1 {
            margin: 0 0 10px 0;
            color: #2d3748;
            font-size: 32px;
            font-weight: 700;
        }

        .add-friends-header p {
            margin: 0;
            color: #718096;
            font-size: 16px;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        /* Stats Grid */
        .friends-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
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

        .friends-stat-actions {
            margin-top: 15px;
        }

        /* Search Section */
        .search-hero {
            background: white;
            border-radius: 12px;
            padding: 40px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
            text-align: center;
        }

        .search-hero h2 {
            margin: 0 0 20px 0;
            color: #2d3748;
            font-size: 24px;
            font-weight: 600;
        }

        .search-container {
            max-width: 600px;
            margin: 0 auto;
        }

        .search-form-group {
            display: flex;
            gap: 10px;
        }

        .search-input {
            flex: 1;
            padding: 15px 20px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 16px;
            transition: all 0.3s;
        }

        .search-input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            outline: none;
        }

        /* Results Section */
        .results-section {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
            overflow: hidden;
        }

        .section-header {
            padding: 25px 30px;
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
            padding: 25px 30px;
            border-bottom: 1px solid #f7fafc;
            transition: background-color 0.2s;
            gap: 20px;
        }

        .user-card:hover {
            background: #f8fafc;
        }

        .user-card:last-child {
            border-bottom: none;
        }

        .user-avatar-med {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: #667eea;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            font-weight: bold;
            background-size: cover;
            background-position: center;
            border: 3px solid white;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            flex-shrink: 0;
        }

        .user-info {
            flex: 1;
            min-width: 0;
        }

        .user-name {
            font-size: 18px;
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
            font-size: 13px;
            font-weight: 500;
        }

        .user-actions {
            display: flex;
            gap: 10px;
            flex-shrink: 0;
        }

        /* Empty States */
        .empty-state {
            padding: 60px 30px;
            text-align: center;
        }

        .empty-icon {
            font-size: 80px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        /* Pending Badge */
        .pending-badge {
            background: #ed8936;
            color: white;
            padding: 6px 12px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
        }

        /* Top Navigation Styles */
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

        .search-icon-main {
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

        /* Responsive Design */
        @media (max-width: 1024px) {
            .friends-stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .add-friends-container {
                padding: 15px;
            }

            .search-hero {
                padding: 30px 20px;
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

            .main-header {
                padding: 15px 20px;
            }

            .search-bar-container {
                display: none;
            }

            .logo-text {
                font-size: 20px;
            }
        }

        @media (max-width: 480px) {
            .friends-stats-grid {
                grid-template-columns: 1fr;
            }

            .user-actions {
                flex-direction: column;
                width: 100%;
            }

            .user-actions .action-btn {
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>
<body class="dashboard-page">
    <!-- Top Navigation -->
    <div class="top-header-bar"></div>

    <div class="main-header">
        <h1 class="logo-text">Manexis</h1>
        <form method="GET" action="search.php" class="search-bar-container">
            <span class="search-icon-main">🔍</span>
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

    <!-- Main Search Content -->
    <div class="add-friends-container">
        <!-- Messages -->
        <?php if (!empty($message)): ?>
            <div class="message <?php echo $msgClass; ?>" style="margin-bottom: 20px;">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <!-- Header -->
        <div class="add-friends-header">
            <h1>Connect with Friends</h1>
            <p>Search for users by name or email address to send friend requests and grow your network</p>
        </div>

        <!-- Quick Stats -->
        <div class="friends-stats-grid">
            <div class="friends-stat-card">
                <span class="friends-stat-number"><?php echo $friends_count; ?></span>
                <div class="friends-stat-label">Total Friends</div>
                <div class="friends-stat-actions">
                    <a href="list_friends.php" class="action-btn btn-small">View All</a>
                </div>
            </div>
            
            <div class="friends-stat-card">
                <span class="friends-stat-number"><?php echo count($sent_requests); ?></span>
                <div class="friends-stat-label">Pending Sent</div>
                <div class="friends-stat-actions">
                    <a href="#sent-requests" class="action-btn secondary btn-small">View Below</a>
                </div>
            </div>
            
            <div class="friends-stat-card">
                <span class="friends-stat-number"><?php echo $pending_received; ?></span>
                <div class="friends-stat-label">Pending Received</div>
                <div class="friends-stat-actions">
                    <a href="dashboard.php" class="action-btn secondary btn-small">View in Dashboard</a>
                </div>
            </div>
            
            <div class="friends-stat-card">
                <span class="friends-stat-number">👥</span>
                <div class="friends-stat-label">Your Network</div>
                <div class="friends-stat-actions">
                    <a href="list_friends.php" class="action-btn secondary btn-small">Manage Friends</a>
                </div>
            </div>
        </div>

        <!-- Search Section -->
        <div class="search-hero">
            <h2>Find People You Know</h2>
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

        <!-- Search Results -->
        <?php if (!empty($search_results)): ?>
            <div class="results-section">
                <div class="section-header">
                    <h2>Search Results</h2>
                    <span class="results-count"><?php echo count($search_results); ?> found</span>
                </div>
                <div class="users-grid">
                    <?php foreach ($search_results as $result): ?>
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
                                <a href="friend_profile.php?id=<?php echo $result['id']; ?>" class="action-btn secondary btn-small">
                                    👀 View Profile
                                </a>
                                <form method="POST" action="" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="send_request" value="1">
                                    <input type="hidden" name="friend_id" value="<?php echo $result['id']; ?>">
                                    <button type="submit" class="action-btn btn-small" data-user-name="<?php echo htmlspecialchars($result['name']); ?>">
                                        ➕ Add Friend
                                    </button>
                                </form>
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
                    <div class="empty-icon">🔍</div>
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
                    <div class="empty-icon">📤</div>
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
                                <a href="friend_profile.php?id=<?php echo $request['friend_id']; ?>" class="action-btn secondary btn-small">
                                    👀 View Profile
                                </a>
                                <span class="pending-badge">⏳ Pending</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="js/search.js"></script>
</body>
</html>