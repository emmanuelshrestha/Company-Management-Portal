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

// Fetch user details for header
$userSql = "SELECT name FROM users WHERE id = ?";
$userStmt = $conn->prepare($userSql);
$userStmt->bind_param("i", $_SESSION['user_id']);
$userStmt->execute();
$userResult = $userStmt->get_result();
$user = $userResult->fetch_assoc();
$userStmt->close();

// Fetch posts from user and their friends (both directions)
$user_id = $_SESSION['user_id'];
$sql = "SELECT DISTINCT p.id, p.content, p.created_at, u.name, u.id as user_id
        FROM posts p 
        JOIN users u ON p.user_id = u.id 
        WHERE p.user_id = ? 
           OR p.user_id IN (
               -- Friends where current user sent the request
               SELECT friend_id FROM friends WHERE user_id = ? AND status = 'approved'
               UNION
               -- Friends where current user received the request  
               SELECT user_id FROM friends WHERE friend_id = ? AND status = 'approved'
           )
        ORDER BY p.created_at DESC 
        LIMIT 50";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iii", $user_id, $user_id, $user_id);
$stmt->execute();
$posts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
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
            <a href="../index.php">Home</a> | 
            <a href="dashboard.php">Dashboard</a> | 
            <a href="profile.php">Profile</a> | 
            <a href="add_friend.php">Add Friends</a> | 
            <a href="list_friends.php">Friends List</a> |
            <a href="news_feed.php">News Feed</a>
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
                            
                            <div class="post-actions">
                                <button class="post-action">👍 Like</button>
                                <button class="post-action">💬 Comment</button>
                                <button class="post-action">🔄 Share</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="js/news_feed.php"></script>
</body>
</html>