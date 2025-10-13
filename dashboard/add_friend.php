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

// Handle search for users
$search_results = [];
$search_query = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search_users'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = "Invalid CSRF token.";
        $msgClass = "msg-error";
    } else {
        $search_query = trim($_POST['search_query'] ?? '');
        
        if (empty($search_query)) {
            $message = "Please enter a name or email to search.";
            $msgClass = "msg-error";
        } else {
            $search_term = "%$search_query%";
            $searchSql = "SELECT id, name, email, status FROM users 
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
        }
        
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
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
$sentRequestsSql = "SELECT f.friend_id, u.name, u.email, f.created_at 
                   FROM friends f 
                   JOIN users u ON f.friend_id = u.id 
                   WHERE f.user_id = ? AND f.status = 'pending' 
                   ORDER BY f.created_at DESC";
$sentRequestsStmt = $conn->prepare($sentRequestsSql);
$sentRequestsStmt->bind_param("i", $user_id);
$sentRequestsStmt->execute();
$sent_requests = $sentRequestsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$sentRequestsStmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Friends - <?php echo htmlspecialchars($user['name']); ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .user-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .user-info-small {
            flex: 1;
        }
        
        .user-name {
            font-weight: 600;
            color: #2d3748;
        }
        
        .user-email {
            color: #718096;
            font-size: 14px;
        }
        
        .request-date {
            color: #718096;
            font-size: 12px;
            margin-top: 5px;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
        }
        
        .btn-small {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        .search-form {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }
        
        .search-input-group {
            display: flex;
            gap: 10px;
        }
        
        .search-input-group input {
            flex: 1;
        }
        
        .search-results {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .no-results {
            text-align: center;
            color: #718096;
            padding: 20px;
        }
    </style>
</head>
<body class="dashboard-page">
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="welcome-section">
                <h1>Add Friends</h1>
                <p>Search for users and connect with friends</p>
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
            <a href="../index.php">Home</a> | 
            <a href="dashboard.php">Dashboard</a> | 
            <a href="profile.php">Profile</a> | 
            <a href="add_friend.php">Add Friends</a> | 
            <a href="list_friends.php">Friends List</a>
        </div>

        <!-- Messages -->
        <?php if (!empty($message)): ?>
            <div class="message <?php echo $msgClass; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <!-- Search Section -->
        <div class="search-form">
            <h2>Search for Users</h2>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="search_users" value="1">
                
                <div class="search-input-group">
                    <input type="text" name="search_query" value="<?php echo htmlspecialchars($search_query); ?>" 
                           placeholder="Enter name or email address..." required>
                    <button type="submit" class="action-btn">Search</button>
                </div>
            </form>
        </div>

        <!-- Search Results -->
        <?php if (!empty($search_results)): ?>
            <div class="card">
                <h2>Search Results (<?php echo count($search_results); ?>)</h2>
                <div class="search-results">
                    <?php foreach ($search_results as $result): ?>
                        <div class="user-card">
                            <div class="user-info-small">
                                <div class="user-name"><?php echo htmlspecialchars($result['name']); ?></div>
                                <div class="user-email"><?php echo htmlspecialchars($result['email']); ?></div>
                                <div class="request-date">Status: <?php echo htmlspecialchars($result['status']); ?></div>
                            </div>
                            <div class="action-buttons">
                                <form method="POST" action="" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="send_request" value="1">
                                    <input type="hidden" name="friend_id" value="<?php echo $result['id']; ?>">
                                    <button type="submit" class="action-btn btn-small">Send Request</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search_users']) && empty($search_results) && empty($message)): ?>
            <div class="card">
                <h2>Search Results</h2>
                <div class="no-results">
                    <p>No users found matching "<?php echo htmlspecialchars($search_query); ?>"</p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Pending Sent Requests -->
        <div class="card">
            <h2>Pending Sent Requests (<?php echo count($sent_requests); ?>)</h2>
            <?php if (empty($sent_requests)): ?>
                <p>You haven't sent any friend requests yet.</p>
            <?php else: ?>
                <div class="search-results">
                    <?php foreach ($sent_requests as $request): ?>
                        <div class="user-card">
                            <div class="user-info-small">
                                <div class="user-name"><?php echo htmlspecialchars($request['name']); ?></div>
                                <div class="user-email"><?php echo htmlspecialchars($request['email']); ?></div>
                                <div class="request-date">Sent: <?php echo date('M j, Y g:i A', strtotime($request['created_at'])); ?></div>
                            </div>
                            <div class="action-buttons">
                                <span style="color: #ed8936; font-weight: 500;">Pending</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Quick Stats -->
        <div class="stats-grid">
            <?php
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
            
            <div class="stat-card">
                <h3>Total Friends</h3>
                <div class="stat-number"><?php echo $friends_count; ?></div>
                <a href="list_friends.php" style="font-size: 14px;">View All</a>
            </div>
            
            <div class="stat-card">
                <h3>Pending Sent</h3>
                <div class="stat-number"><?php echo count($sent_requests); ?></div>
            </div>
            
            <div class="stat-card">
                <h3>Pending Received</h3>
                <div class="stat-number"><?php echo $pending_received; ?></div>
                <a href="dashboard.php" style="font-size: 14px;">View in Dashboard</a>
            </div>
            
            <div class="stat-card">
                <h3>Quick Actions</h3>
                <div style="margin-top: 10px;">
                    <a href="list_friends.php" class="action-btn btn-small" style="display: block; text-align: center; margin-bottom: 5px;">My Friends</a>
                    <a href="dashboard.php" class="action-btn secondary btn-small" style="display: block; text-align: center;">Dashboard</a>
                </div>
            </div>
        </div>
    </div>

    <script src="js/add_friend.js"></script>
</body>
</html>