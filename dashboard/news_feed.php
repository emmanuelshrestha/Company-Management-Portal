<?php
session_start();
require __DIR__ . '/../config/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Debug logging
file_put_contents('debug.log', "[" . date('Y-m-d H:i:s') . "] Request: " . $_SERVER['REQUEST_METHOD'] . " - " . print_r($_POST, true) . "\n", FILE_APPEND);

// Generate CSRF token if not set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Fetch user details for header
$userSql = "SELECT name, profile_picture FROM users WHERE id = ?";
$userStmt = $conn->prepare($userSql);
$userStmt->bind_param("i", $_SESSION['user_id']);
$userStmt->execute();
$userResult = $userStmt->get_result();
$user = $userResult->fetch_assoc();
$userStmt->close();

// Handle AJAX requests for likes and comments
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    file_put_contents('debug.log', "[" . date('Y-m-d H:i:s') . "] AJAX Action: " . $_POST['action'] . "\n", FILE_APPEND);
    
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        file_put_contents('debug.log', "[" . date('Y-m-d H:i:s') . "] CSRF Token mismatch\n", FILE_APPEND);
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
    
    $response = [];
    $user_id = $_SESSION['user_id']; // Add this line
    
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
                file_put_contents('debug.log', "[" . date('Y-m-d H:i:s') . "] Loaded " . count($comments) . " comments for post $post_id\n", FILE_APPEND);
            } else {
                $response = ['success' => false, 'message' => 'Invalid post ID'];
            }
            break;

        case 'like_post':
            $post_id = filter_var($_POST['post_id'] ?? 0, FILTER_VALIDATE_INT);
            file_put_contents('debug.log', "[" . date('Y-m-d H:i:s') . "] Like post - User: $user_id, Post: $post_id\n", FILE_APPEND);
            
            if ($post_id) {
                // Check if already liked
                $checkSql = "SELECT id FROM post_likes WHERE post_id = ? AND user_id = ?";
                $checkStmt = $conn->prepare($checkSql);
                $checkStmt->bind_param("ii", $post_id, $user_id);
                $checkStmt->execute();
                
                if ($checkStmt->get_result()->num_rows > 0) {
                    // Unlike the post
                    file_put_contents('debug.log', "[" . date('Y-m-d H:i:s') . "] Removing like\n", FILE_APPEND);
                    $deleteSql = "DELETE FROM post_likes WHERE post_id = ? AND user_id = ?";
                    $deleteStmt = $conn->prepare($deleteSql);
                    $deleteStmt->bind_param("ii", $post_id, $user_id);
                    $deleteStmt->execute();
                    $response = ['success' => true, 'liked' => false];
                    $deleteStmt->close();
                } else {
                    // Like the post
                    file_put_contents('debug.log', "[" . date('Y-m-d H:i:s') . "] Adding like\n", FILE_APPEND);
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
                file_put_contents('debug.log', "[" . date('Y-m-d H:i:s') . "] Like count: $like_count\n", FILE_APPEND);
            } else {
                $response = ['success' => false, 'message' => 'Invalid post ID'];
            }
            break;
            
        case 'add_comment':
            $post_id = filter_var($_POST['post_id'] ?? 0, FILTER_VALIDATE_INT);
            $content = trim($_POST['content'] ?? '');
            
            file_put_contents('debug.log', "[" . date('Y-m-d H:i:s') . "] Add comment - User: $user_id, Post: $post_id, Content: $content\n", FILE_APPEND);
            
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
                        file_put_contents('debug.log', "[" . date('Y-m-d H:i:s') . "] Comment added successfully - ID: $comment_id\n", FILE_APPEND);
                    } else {
                        $response = ['success' => false, 'message' => 'Failed to add comment: ' . $insertStmt->error];
                        file_put_contents('debug.log', "[" . date('Y-m-d H:i:s') . "] Comment failed: " . $insertStmt->error . "\n", FILE_APPEND);
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
    
    file_put_contents('debug.log', "[" . date('Y-m-d H:i:s') . "] Response: " . json_encode($response) . "\n", FILE_APPEND);
    echo json_encode($response);
    exit;
}

// Fetch posts from user and their friends
$user_id = $_SESSION['user_id'];
$sql = "SELECT DISTINCT p.id, p.content, p.created_at, p.image_filename, p.image_caption, u.name, u.id as user_id, u.profile_picture
        FROM posts p
        JOIN users u ON p.user_id = u.id
        WHERE p.user_id = ?
           OR p.user_id IN (
               SELECT friend_id FROM friends WHERE user_id = ? AND status = 'approved'
               UNION
               SELECT user_id FROM friends WHERE friend_id = ? AND status = 'approved'
           )
        ORDER BY p.created_at DESC
        LIMIT 50";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    // helpful debug if prepare fails
    file_put_contents('debug.log', "[" . date('Y-m-d H:i:s') . "] Prepare failed: " . $conn->error . "\n", FILE_APPEND);
    die("Database error.");
}
$stmt->bind_param("iii", $user_id, $user_id, $user_id);
$stmt->execute();
$posts_result = $stmt->get_result();
$posts = [];

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
$stmt->close();

// Debug: Check what friends the user has
$debugSql = "SELECT 
    u.id as friend_id, 
    u.name as friend_name,
    CASE 
        WHEN f.user_id = ? THEN 'You sent request'
        ELSE 'They sent request'
    END as request_direction
FROM friends f
JOIN users u ON (
    (f.user_id = ? AND u.id = f.friend_id) OR 
    (f.friend_id = ? AND u.id = f.user_id)
)
WHERE (f.user_id = ? OR f.friend_id = ?) 
AND f.status = 'approved'
AND u.id != ?";

$debugStmt = $conn->prepare($debugSql);
$debugStmt->bind_param("iiiiii", $user_id, $user_id, $user_id, $user_id, $user_id, $user_id);
$debugStmt->execute();
$friends_debug = $debugStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$debugStmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>News Feed - <?php echo htmlspecialchars($user['name']); ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="dashboard-page">
    <input type="hidden" id="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="welcome-section">
                <h1>News Feed</h1>
                <p>Latest posts from you and your friends</p>
            </div>
            <div class="user-info">
            <?php 
            if (!empty($user['profile_picture'])) {
                echo '<div class="user-avatar" style="background-image: url(../../uploads/profile_pictures/' . htmlspecialchars($user['profile_picture']) . ');"></div>';
            } else {
                echo '<div class="user-avatar">' . strtoupper(substr($user['name'] ?? 'U', 0, 1)) . '</div>';
            }
            ?>
                <a href="create_post.php" class="action-btn">Create Post</a>
                <a href="dashboard.php" class="action-btn secondary">Dashboard</a>
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

        <!-- Debug Info (Enable for testing) -->
        <!--
        <div class="debug-info">
            <h4>Debug Information:</h4>
            <p><strong>Your User ID:</strong> <?php echo $user_id; ?></p>
            <p><strong>Your Friends:</strong> <?php echo count($friends_debug); ?></p>
            <?php if (!empty($friends_debug)): ?>
                <ul>
                    <?php foreach ($friends_debug as $friend): ?>
                        <li><?php echo htmlspecialchars($friend['friend_name']); ?> (ID: <?php echo $friend['friend_id']; ?>) - <?php echo $friend['request_direction']; ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p>No friends found. Make some friends to see their posts!</p>
            <?php endif; ?>
        </div>
        -->

        <div class="news-feed-container">
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

                            <!-- Image Display - Fully Visible like Facebook -->
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
    </div>


    <script src="js/news_feed.js"></script>
</body>
</html>