<?php
session_start();
require __DIR__ . '/../config/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../modules/user/login.php');
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

// Generate CSRF token if not set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Fetch posts from user and their friends for the news feed
$postsSql = "SELECT DISTINCT p.id, p.content, p.created_at, p.image_filename, p.image_caption, u.name, u.id as user_id, u.profile_picture
        FROM posts p
        JOIN users u ON p.user_id = u.id
        WHERE p.user_id = ?
           OR p.user_id IN (
               SELECT friend_id FROM friends WHERE user_id = ? AND status = 'approved'
               UNION
               SELECT user_id FROM friends WHERE friend_id = ? AND status = 'approved'
           )
        ORDER BY p.created_at DESC
        LIMIT 10";

$postsStmt = $conn->prepare($postsSql);
$posts = [];
if ($postsStmt) {
    $postsStmt->bind_param("iii", $user_id, $user_id, $user_id);
    $postsStmt->execute();
    $posts_result = $postsStmt->get_result();
    
    while ($post = $posts_result->fetch_assoc()) {
        // Get like count and check if current user liked this post
        $likeSql = "SELECT COUNT(*) as like_count, 
                           EXISTS(SELECT 1 FROM post_likes WHERE post_id = ? AND user_id = ?) as user_liked
                    FROM post_likes 
                    WHERE post_id = ?";
        $likeStmt = $conn->prepare($likeSql);
        $likeStmt->bind_param("iii", $post['id'], $user_id, $post['id']);
        $likeStmt->execute();
        $likeData = $likeStmt->get_result()->fetch_assoc();
        $likeStmt->close();
        
        // Get comment count
        $commentSql = "SELECT COUNT(*) as comment_count FROM post_comments WHERE post_id = ?";
        $commentStmt = $conn->prepare($commentSql);
        $commentStmt->bind_param("i", $post['id']);
        $commentStmt->execute();
        $commentData = $commentStmt->get_result()->fetch_assoc();
        $commentStmt->close();
        
        $post['like_count'] = $likeData['like_count'] ?? 0;
        $post['user_liked'] = (bool)($likeData['user_liked'] ?? false);
        $post['comment_count'] = $commentData['comment_count'] ?? 0;
        
        $posts[] = $post;
    }
    $postsStmt->close();
}

// Handle AJAX requests for likes and comments
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
    
    $response = [];
    
    switch ($_POST['action']) {
        case 'load_comments':
            $post_id = filter_var($_POST['post_id'] ?? 0, FILTER_VALIDATE_INT);
            if ($post_id) {
                $commentsSql = "SELECT pc.*, u.name, u.profile_picture 
                                FROM post_comments pc 
                                JOIN users u ON pc.user_id = u.id 
                                WHERE pc.post_id = ? 
                                ORDER BY pc.created_at ASC 
                                LIMIT 50";
                $commentsStmt = $conn->prepare($commentsSql);
                $commentsStmt->bind_param("i", $post_id);
                $commentsStmt->execute();
                $comments = $commentsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $commentsStmt->close();
                
                $response = ['success' => true, 'comments' => $comments];
            } else {
                $response = ['success' => false, 'message' => 'Invalid post ID'];
            }
            break;

        case 'like_post':
            $post_id = filter_var($_POST['post_id'] ?? 0, FILTER_VALIDATE_INT);
            
            if ($post_id) {
                // Check if already liked
                $checkSql = "SELECT id FROM post_likes WHERE post_id = ? AND user_id = ?";
                $checkStmt = $conn->prepare($checkSql);
                $checkStmt->bind_param("ii", $post_id, $user_id);
                $checkStmt->execute();
                
                if ($checkStmt->get_result()->num_rows > 0) {
                    // Unlike the post
                    $deleteSql = "DELETE FROM post_likes WHERE post_id = ? AND user_id = ?";
                    $deleteStmt = $conn->prepare($deleteSql);
                    $deleteStmt->bind_param("ii", $post_id, $user_id);
                    $deleteStmt->execute();
                    $response = ['success' => true, 'liked' => false];
                    $deleteStmt->close();
                } else {
                    // Like the post
                    $insertSql = "INSERT INTO post_likes (post_id, user_id) VALUES (?, ?)";
                    $insertStmt = $conn->prepare($insertSql);
                    $insertStmt->bind_param("ii", $post_id, $user_id);
                    if ($insertStmt->execute()) {
                        $response = ['success' => true, 'liked' => true];
                    } else {
                        $response = ['success' => false, 'message' => 'Database error: ' . $insertStmt->error];
                    }
                    $insertStmt->close();
                }
                $checkStmt->close();
                
                // Get updated like count
                $countSql = "SELECT COUNT(*) as like_count FROM post_likes WHERE post_id = ?";
                $countStmt = $conn->prepare($countSql);
                $countStmt->bind_param("i", $post_id);
                $countStmt->execute();
                $like_count = $countStmt->get_result()->fetch_assoc()['like_count'];
                $countStmt->close();
                
                $response['like_count'] = $like_count;
            } else {
                $response = ['success' => false, 'message' => 'Invalid post ID'];
            }
            break;
            
        case 'add_comment':
            $post_id = filter_var($_POST['post_id'] ?? 0, FILTER_VALIDATE_INT);
            $content = trim($_POST['content'] ?? '');
            
            if ($post_id && !empty($content)) {
                if (strlen($content) > 500) {
                    $response = ['success' => false, 'message' => 'Comment too long'];
                } else {
                    $insertSql = "INSERT INTO post_comments (post_id, user_id, content) VALUES (?, ?, ?)";
                    $insertStmt = $conn->prepare($insertSql);
                    $insertStmt->bind_param("iis", $post_id, $user_id, $content);
                    
                    if ($insertStmt->execute()) {
                        $comment_id = $insertStmt->insert_id;
                        $response = ['success' => true, 'comment_id' => $comment_id];
                    } else {
                        $response = ['success' => false, 'message' => 'Failed to add comment: ' . $insertStmt->error];
                    }
                    $insertStmt->close();
                }
            } else {
                $response = ['success' => false, 'message' => 'Invalid post ID or empty comment'];
            }
            break;
            
        default:
            $response = ['success' => false, 'message' => 'Invalid action'];
    }
    
    echo json_encode($response);
    exit;
}

// Handle friend request acceptance
$message = "";
$msgClass = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'accept') {
        $friend_id = filter_var($_POST['friend_id'] ?? 0, FILTER_VALIDATE_INT);
        if ($friend_id) {
            $sql = "UPDATE friends SET status = 'approved' WHERE user_id = ? AND friend_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $friend_id, $user_id);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $message = "<p>Friend request accepted!</p>";
                $msgClass = "msg-success";
                header("Refresh:0");
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
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        /* Additional inline styles for social media layout */
        body {
            margin: 0;
            padding: 0;
            background: #f5f5f5;
        }

        /* Top dark header bar */
        .top-header-bar {
            background: #4a4a4a;
            height: 40px;
            width: 100%;
        }

        /* Main header with logo and search */
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

        /* Main layout container */
        .dashboard-container {
            display: flex;
            max-width: 1400px;
            margin: 20px auto;
            gap: 20px;
            padding: 0 20px;
        }

        /* Left sidebar */
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

        /* News Feed Styling */
        .news-feed-section {
            margin-bottom: 20px;
        }

        .create-post-prompt {
            background: var(--success-bg);
            border: 1px solid var(--success-border);
            border-radius: 12px;
            padding: 25px;
            text-align: center;
            margin-bottom: 20px;
        }

        .posts-feed {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        /* Quick Stats in sidebar */
        .quick-stats-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            background: #f8fafc;
            border-radius: 8px;
            margin-bottom: 8px;
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
        }
    </style>
</head>
<body class="dashboard-page">
    <input type="hidden" id="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
    
    <!-- Top dark header bar -->
    <div class="top-header-bar"></div>

    <!-- Main header -->
    <div class="main-header">
        <h1 class="logo-text">
            <a href="dashboard.php" class="logo-link">Manexis</a>
        </h1>
        <form method="GET" action="search.php" class="search-bar-container">
            <span class="search-icon"><img src="../assets/images/search.png" alt="Home" style="width:20px;height:20px;margin-top:10px"></span>
            <input type="text" name="search_query" placeholder="Search for friends, posts, and more...">
        </form>
        <div class="header-right">
            <a href="create_post.php" class="action-btn">Create Post</a>
            <?php 
            if (!empty($user['profile_picture'])) {
                echo '<a href="profile.php" class="profile-link">';
                echo '<div class="user-avatar" style="background-image: url(../../uploads/profile_pictures/' . htmlspecialchars($user['profile_picture']) . ');"></div>';
                echo '</a>';
            } else {
                echo '<a href="profile.php" class="profile-link">';
                echo '<div class="user-avatar">' . strtoupper(substr($user['name'] ?? 'U', 0, 1)) . '</div>';
                echo '</a>';
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
            <!-- Welcome Message -->
            <?php if (!empty($message)): ?>
                <div class="message <?php echo $msgClass; ?>" style="margin-bottom: 20px;">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <!-- News Feed Section -->
            <div class="news-feed-section">
                <!-- Create Post Prompt -->
                <div class="create-post-prompt">
                    <h3>Share what's on your mind</h3>
                    <p>Your friends would love to hear from you!</p>
                    <a href="create_post.php" class="action-btn">Create a Post</a>
                </div>

                <!-- Posts Feed -->
                <?php if (empty($posts)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📝</div>
                        <h3>No posts yet</h3>
                        <p>Be the first to share something, or make some friends to see their posts!</p>
                        <div style="margin-top: 20px;">
                            <a href="create_post.php" class="action-btn" style="margin-right: 10px;">Create First Post</a>
                            <a href="add_friend.php" class="action-btn secondary">Find Friends</a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="posts-feed">
                        <?php foreach ($posts as $post): ?>
                            <div class="post-card">
                                <div class="post-header">
                                    <div class="post-author">
                                        <?php 
                                        if (!empty($post['profile_picture'])) {
                                            echo '<div class="author-avatar" style="background-image: url(../../uploads/profile_pictures/' . htmlspecialchars($post['profile_picture']) . '); background-size: cover; background-position: center;"></div>';
                                        } else {
                                            echo '<div class="author-avatar">' . strtoupper(substr($post['name'], 0, 1)) . '</div>';
                                        }
                                        ?>
                                        <div class="author-info">
                                            <h3><?php echo htmlspecialchars($post['name']); ?></h3>
                                            <p>
                                                <?php if ($post['user_id'] == $user_id): ?>
                                                    You
                                                <?php else: ?>
                                                    Friend
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="post-date">
                                        <?php 
                                        $postTime = strtotime($post['created_at']);
                                        $currentTime = time();
                                        $timeDiff = $currentTime - $postTime;
                                        
                                        if ($timeDiff < 60) {
                                            echo 'Just now';
                                        } elseif ($timeDiff < 3600) {
                                            echo floor($timeDiff / 60) . ' min ago';
                                        } elseif ($timeDiff < 86400) {
                                            echo floor($timeDiff / 3600) . ' hr ago';
                                        } else {
                                            echo date('M j, Y g:i A', $postTime);
                                        }
                                        ?>
                                    </div>
                                </div>
                                
                                <div class="post-content">
                                    <?php echo htmlspecialchars($post['content']); ?>
                                </div>

                                <!-- Image Display -->
                                <?php if (!empty($post['image_filename'])): ?>
                                    <div class="post-image-full">
                                        <img src="../../uploads/posts/<?php echo htmlspecialchars($post['image_filename']); ?>" 
                                            alt="<?php echo !empty($post['image_caption']) ? htmlspecialchars($post['image_caption']) : 'Post image'; ?>"
                                            class="post-image-auto">
                                        <?php if (!empty($post['image_caption'])): ?>
                                            <div class="image-caption-full"><?php echo htmlspecialchars($post['image_caption']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="post-stats">
                                    <span class="like-count"><?php echo $post['like_count']; ?> likes</span>
                                    <span class="comment-count"><?php echo $post['comment_count']; ?> comments</span>
                                </div>

                                <div class="post-actions">
                                    <button class="post-action like-btn <?php echo $post['user_liked'] ? 'liked' : ''; ?>" 
                                            data-post-id="<?php echo $post['id']; ?>">
                                        <span class="like-icon"><?php echo $post['user_liked'] ? '❤️' : '🤍'; ?></span>
                                        Like
                                    </button>
                                    <button class="post-action comment-btn" data-post-id="<?php echo $post['id']; ?>">
                                        <span class="comment-icon">💬</span>
                                        Comment
                                    </button>
                                </div>

                                <div class="comments-section" id="comments-<?php echo $post['id']; ?>" style="display: none;">
                                    <div class="comment-form">
                                        <input type="text" class="comment-input" placeholder="Write a comment..." 
                                            data-post-id="<?php echo $post['id']; ?>">
                                        <button class="btn-comment" data-post-id="<?php echo $post['id']; ?>">Post</button>
                                    </div>
                                    <div class="comments-list" id="comments-list-<?php echo $post['id']; ?>">
                                        <!-- Comments will be loaded here -->
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Pending Friend Requests -->
            <?php if (!empty($pendingRequests)): ?>
                <div class="card">
                    <h2>Pending Friend Requests (<?php echo count($pendingRequests); ?>)</h2>
                    <div class="pending-requests-list">
                        <?php foreach ($pendingRequests as $request): ?>
                            <div class="pending-request-item">
                                <div class="request-user">
                                    <?php 
                                    if (!empty($request['profile_picture'])) {
                                        echo '<div class="user-avatar-small" style="background-image: url(../../uploads/profile_pictures/' . htmlspecialchars($request['profile_picture']) . ');"></div>';
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
                </div>
            <?php endif; ?>
        </main>

        <!-- Right Sidebar -->
        <aside class="right-sidebar">
            <!-- Quick Stats -->
            <div class="sidebar-card">
                <h3>Quick Stats</h3>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <div class="quick-stats-item">
                        <span style="color: #718096; font-size: 14px;">👥 Friends</span>
                        <span style="font-weight: 600; color: #2d3748;"><?php echo $friend_count; ?></span>
                    </div>
                    <div class="quick-stats-item">
                        <span style="color: #718096; font-size: 14px;">📬 Requests</span>
                        <span style="font-weight: 600; color: #2d3748;"><?php echo count($pendingRequests); ?></span>
                    </div>
                    <div class="quick-stats-item">
                        <span style="color: #718096; font-size: 14px;">✉️ Status</span>
                        <span style="font-weight: 600; color: <?php echo ($user && $user['status'] === 'Verified') ? '#48bb78' : '#e53e3e'; ?>;">
                            <?php echo ($user && $user['status'] === 'Verified') ? 'Verified' : 'Not Verified'; ?>
                        </span>
                    </div>
                    <div class="quick-stats-item">
                        <span style="color: #718096; font-size: 14px;">📝 Posts</span>
                        <span style="font-weight: 600; color: #2d3748;"><?php echo count($posts); ?></span>
                    </div>
                </div>
            </div>

            <!-- Quick Links -->
            <div class="sidebar-card">
                <h3>Quick Links</h3>
                <div style="display: flex; flex-direction: column; gap: 10px;">
                    <a href="create_post.php" style="display: flex; align-items: center; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 8px; text-decoration: none; color: #2d3748; transition: background 0.2s;">
                        <span>✏️</span>
                        <span>Create Post</span>
                    </a>
                    <a href="news_feed.php" style="display: flex; align-items: center; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 8px; text-decoration: none; color: #2d3748; transition: background 0.2s;">
                        <span>📰</span>
                        <span>News Feed</span>
                    </a>
                    <a href="add_friend.php" style="display: flex; align-items: center; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 8px; text-decoration: none; color: #2d3748; transition: background 0.2s;">
                        <span>➕</span>
                        <span>Find Friends</span>
                    </a>
                    <a href="../messages/messages.php" style="display: flex; align-items: center; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 8px; text-decoration: none; color: #2d3748; transition: background 0.2s;">
                        <span>💬</span>
                        <span>Messages</span>
                    </a>
                </div>
            </div>

            <!-- Account Info -->
            <div class="sidebar-card">
                <h3>Account Info</h3>
                <div style="font-size: 13px; color: #718096; line-height: 1.6;">
                    <p style="margin: 0 0 10px 0;">Member since: <strong><?php echo $user ? date('M Y', strtotime($user['created_at'])) : 'N/A'; ?></strong></p>
                    <p style="margin: 0;">Your account is <strong><?php echo ($user && $user['status'] === 'Verified') ? 'verified' : 'not verified'; ?></strong></p>
                </div>
            </div>
        </aside>
    </div>

    <!-- Image Modal -->
    <div id="imageModal" class="image-modal">
        <span class="close-modal" onclick="closeImageModal()">&times;</span>
        <img class="modal-content" id="modalImage">
        <div class="modal-caption" id="modalCaption"></div>
        <div class="modal-controls">
            <button class="modal-btn" onclick="downloadImage()">Download</button>
            <button class="modal-btn" onclick="shareImage()">Share</button>
        </div>
    </div>

    <script src="js/dashboard.js"></script>
</body>
</html>