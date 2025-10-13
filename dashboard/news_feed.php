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
$userSql = "SELECT name FROM users WHERE id = ?";
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
                $commentsSql = "SELECT pc.*, u.name 
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
$sql = "SELECT DISTINCT p.id, p.content, p.created_at, p.image_filename, p.image_caption, u.name, u.id as user_id
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
    <style>
        .news-feed-container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .post-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
            border: 1px solid #e2e8f0;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .post-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        .post-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #f7fafc;
        }
        
        .post-author {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .author-avatar {
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
        }
        
        .author-info h3 {
            margin: 0;
            color: #2d3748;
            font-size: 16px;
        }
        
        .author-info p {
            margin: 0;
            color: #718096;
            font-size: 14px;
        }
        
        .post-date {
            color: #a0aec0;
            font-size: 14px;
        }
        
        .post-content {
            color: #2d3748;
            line-height: 1.6;
            font-size: 16px;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        
        .post-actions {
            display: flex;
            gap: 15px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #f7fafc;
        }
        
        .post-action {
            background: none;
            border: none;
            color: #718096;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: color 0.2s;
        }
        
        .post-action:hover {
            color: #667eea;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #718096;
        }
        
        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .create-post-prompt {
            background: #f0fff4;
            border: 1px solid #9ae6b4;
            border-radius: 12px;
            padding: 25px;
            text-align: center;
            margin-bottom: 30px;
        }
        
        .debug-info {
            background: #fffaf0;
            border: 1px solid #fbd38d;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            font-size: 14px;
            display: none; /* Hide by default, can be enabled for debugging */
        }
        
        .post-stats {
            display: flex;
            gap: 20px;
            margin-bottom: 10px;
            color: #718096;
            font-size: 14px;
        }

        .post-actions {
            display: flex;
            gap: 0;
            border-top: 1px solid #f7fafc;
            padding-top: 10px;
        }

        .post-action {
            background: none;
            border: none;
            color: #718096;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            padding: 8px 16px;
            border-radius: 6px;
            flex: 1;
            justify-content: center;
        }

        .post-action:hover {
            background: #f7fafc;
            color: #2d3748;
        }

        .post-action.liked {
            color: #e53e3e;
        }

        .like-icon, .comment-icon {
            font-size: 16px;
        }

        .comments-section {
            margin-top: 20px;
            border-top: 1px solid #f7fafc;
            padding-top: 15px;
        }

        .comment-form {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }

        .comment-input {
            flex: 1;
            padding: 10px 15px;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            font-size: 14px;
        }

        .comment-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.1);
        }

        .btn-comment {
            background: #667eea;
            color: white;
            border: none;
            border-radius: 20px;
            padding: 0 20px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.2s;
        }

        .btn-comment:hover {
            background: #5a67d8;
        }

        .comments-list {
            max-height: 300px;
            overflow-y: auto;
            margin-top: 10px;
        }

        .comment-item {
            padding: 12px 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .comment-item:last-child {
            border-bottom: none;
        }

        .comment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 5px;
        }

        .comment-author {
            font-weight: 600;
            color: #2d3748;
            font-size: 14px;
        }

        .comment-date {
            color: #a0aec0;
            font-size: 12px;
        }

        .comment-content {
            color: #4a5568;
            font-size: 14px;
            line-height: 1.4;
            word-wrap: break-word;
        }

        /* Post Images */
        .post-image {
            margin: 15px 0;
            text-align: center;
        }

        .post-image img {
            max-width: 100%;
            max-height: 500px;
            border-radius: 12px;
            cursor: pointer;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .post-image img:hover {
            transform: scale(1.02);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .image-caption {
            margin-top: 10px;
            color: #64748b;
            font-size: 14px;
            text-align: center;
            font-style: italic;
        }

        /* Image Modal */
        .image-modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.9);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            position: relative;
            margin: auto;
            display: block;
            width: auto;
            max-width: 90%;
            max-height: 90%;
            top: 50%;
            transform: translateY(-50%);
            border-radius: 12px;
            animation: zoom 0.3s;
        }

        @keyframes zoom {
            from {transform: translateY(-50%) scale(0.9); opacity: 0;}
            to {transform: translateY(-50%) scale(1); opacity: 1;}
        }

        .modal-caption {
            text-align: center;
            color: white;
            padding: 15px;
            font-size: 16px;
            position: absolute;
            bottom: 0;
            width: 100%;
            background: linear-gradient(transparent, rgba(0,0,0,0.8));
            border-bottom-left-radius: 12px;
            border-bottom-right-radius: 12px;
        }

        .close-modal {
            position: absolute;
            top: 20px;
            right: 35px;
            color: white;
            font-size: 40px;
            font-weight: bold;
            cursor: pointer;
            z-index: 1001;
            background: rgba(0,0,0,0.5);
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.3s;
        }

        .close-modal:hover {
            background: rgba(0,0,0,0.8);
        }

        .modal-controls {
            position: absolute;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 10px;
        }

        .modal-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 25px;
            cursor: pointer;
            backdrop-filter: blur(10px);
            transition: background 0.3s;
        }

        .modal-btn:hover {
            background: rgba(255,255,255,0.3);
        }

        /* Post Images - Facebook Style (Fully Visible) */
        .post-image-full {
            margin: 15px 0;
            border-radius: 12px;
            overflow: hidden;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
        }

        .post-image-auto {
            width: 100%;
            height: auto;
            display: block;
            max-height: none; /* Remove height restrictions */
        }

        .image-caption-full {
            padding: 12px 16px;
            color: #65676b;
            font-size: 14px;
            text-align: left;
            border-top: 1px solid #e2e8f0;
            background: white;
            line-height: 1.4;
        }

        /* Text + Image combination */
        .post-content:not(:empty) + .post-image-full {
            margin-top: 12px;
        }

        /* Remove any hover effects that might suggest it's clickable */
        .post-image-auto {
            cursor: default;
        }

        .post-image-auto:hover {
            transform: none;
        }

        /* Ensure proper spacing */
        .post-stats {
            margin-top: 15px;
        }

        .post-actions {
            margin-top: 10px;
        }

        /* Remove the image actions section since we don't need "View Full Size" anymore */
        .image-actions {
            display: none;
        }

        /* For very large images, we can add a subtle max-width to maintain layout */
        .post-image-auto {
            max-width: 100%;
            height: auto;
        }

        /* Keep the modal for those who might want to see details, but make it optional */
        .post-image-full {
            position: relative;
        }

        /* Optional: Add a subtle zoom on hover for better UX (like Facebook) */
        .post-image-auto {
            transition: transform 0.2s ease;
        }

        .post-image-full:hover .post-image-auto {
            transform: scale(1.01);
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .post-image-full {
                margin: 12px 0;
                border-radius: 8px;
                border: 1px solid #e2e8f0;
            }
            
            .image-caption-full {
                padding: 10px 12px;
                font-size: 13px;
            }
        }

        /* For landscape vs portrait images - maintain aspect ratio */
        .post-image-auto {
            object-fit: contain;
        }

        /* Remove any previous modal-related styles that might interfere */
        .post-image-preview,
        .image-action-btn {
            display: none;
        }

        .image-loading {
            background: #f7fafc;
            border-radius: 12px;
            min-height: 200px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #a0aec0;
        }

        /* Responsive images */
        @media (max-width: 768px) {
            .post-image img {
                max-height: 300px;
            }
            
            .modal-content {
                max-width: 95%;
                max-height: 80%;
            }
            
            .close-modal {
                top: 10px;
                right: 20px;
                font-size: 30px;
                width: 40px;
                height: 40px;
            }
        }

        @media (max-width: 768px) {
            .post-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .post-date {
                align-self: flex-end;
            }
            
            .post-actions {
                justify-content: space-around;
            }
        }
    </style>
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
                <div class="user-avatar">
                    <?php echo strtoupper(substr($user['name'] ?? 'U', 0, 1)); ?>
                </div>
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
            <a href="logout.php">Logout</a>
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
                                    <div class="author-avatar">
                                        <?php echo strtoupper(substr($post['name'], 0, 1)); ?>
                                    </div>
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