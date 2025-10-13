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

$message = "";
$msgClass = "";

// Handle post submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = "Invalid CSRF token.";
        $msgClass = "msg-error";
    } else {
        $content = trim($_POST['content'] ?? '');
        
        // Validate content
        if (empty($content)) {
            $message = "Post content is required.";
            $msgClass = "msg-error";
        } elseif (strlen($content) > 500) {
            $message = "Post content cannot exceed 500 characters.";
            $msgClass = "msg-error";
        } else {
            // Insert post into database
            $sql = "INSERT INTO posts (user_id, content) VALUES (?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("is", $_SESSION['user_id'], $content);
            
            if ($stmt->execute()) {
                $message = "Post created successfully!";
                $msgClass = "msg-success";
                
                // Redirect to news feed after a brief delay
                header('Refresh: 2; URL=news_feed.php');
            } else {
                $message = "Error creating post. Please try again.";
                $msgClass = "msg-error";
            }
            $stmt->close();
        }
        
        // Regenerate CSRF token
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Post - <?php echo htmlspecialchars($user['name']); ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .create-post-container {
            max-width: 600px;
            margin: 0 auto;
        }
        
        .post-form-card {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }
        
        .post-header {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #f7fafc;
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
            margin-right: 15px;
        }
        
        .user-info-small h3 {
            margin: 0;
            color: #2d3748;
            font-size: 16px;
        }
        
        .user-info-small p {
            margin: 0;
            color: #718096;
            font-size: 14px;
        }
        
        .post-textarea {
            width: 100%;
            min-height: 120px;
            padding: 15px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 16px;
            font-family: inherit;
            resize: vertical;
            transition: border-color 0.3s, box-shadow 0.3s;
        }
        
        .post-textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .char-counter {
            text-align: right;
            color: #718096;
            font-size: 14px;
            margin-top: 5px;
        }
        
        .char-counter.warning {
            color: #ed8936;
        }
        
        .char-counter.error {
            color: #e53e3e;
        }
        
        .post-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
        }
        
        .btn-post {
            background: #667eea;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .btn-post:hover {
            background: #5a67d8;
        }
        
        .btn-post:disabled {
            background: #a0aec0;
            cursor: not-allowed;
        }
        
        .preview-section {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
            display: none;
        }
        
        .preview-section h4 {
            margin: 0 0 10px 0;
            color: #2d3748;
        }
        
        .preview-content {
            color: #4a5568;
            line-height: 1.5;
        }
        
        .tips-section {
            background: #f0fff4;
            border: 1px solid #9ae6b4;
            border-radius: 8px;
            padding: 15px;
            margin-top: 20px;
        }
        
        .tips-section h4 {
            margin: 0 0 10px 0;
            color: #276749;
        }
        
        .tips-section ul {
            margin: 0;
            padding-left: 20px;
            color: #2d3748;
        }
        
        .tips-section li {
            margin-bottom: 5px;
        }
        
        @media (max-width: 768px) {
            .create-post-container {
                padding: 10px;
            }
            
            .post-form-card {
                padding: 20px;
            }
            
            .post-actions {
                flex-direction: column;
                gap: 15px;
            }
            
            .btn-post {
                width: 100%;
            }
        }
    </style>
</head>
<body class="dashboard-page">
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="welcome-section">
                <h1>Create New Post</h1>
                <p>Share your thoughts with your friends</p>
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
                <?php if ($msgClass === 'msg-success'): ?>
                    <p>Redirecting to news feed...</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="create-post-container">
            <!-- Create Post Form -->
            <div class="post-form-card">
                <div class="post-header">
                    <div class="user-avatar-small">
                        <?php echo strtoupper(substr($user['name'] ?? 'U', 0, 1)); ?>
                    </div>
                    <div class="user-info-small">
                        <h3><?php echo htmlspecialchars($user['name']); ?></h3>
                        <p>Posting to your news feed</p>
                    </div>
                </div>

                <form method="POST" action="" id="postForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    
                    <div class="form-group">
                        <textarea 
                            name="content" 
                            id="content" 
                            class="post-textarea" 
                            placeholder="What's on your mind, <?php echo htmlspecialchars(explode(' ', $user['name'])[0]); ?>?"
                            maxlength="500"
                        ></textarea>
                        <div class="char-counter" id="charCounter">
                            <span id="charCount">0</span>/500 characters
                        </div>
                    </div>

                    <!-- Preview Section -->
                    <div class="preview-section" id="previewSection">
                        <h4>Preview:</h4>
                        <div class="preview-content" id="previewContent"></div>
                    </div>

                    <div class="post-actions">
                        <div>
                            <button type="button" class="action-btn secondary" id="previewBtn">Preview</button>
                        </div>
                        <button type="submit" class="btn-post" id="submitBtn" disabled>Post</button>
                    </div>
                </form>
            </div>

            <!-- Tips Section -->
            <div class="tips-section">
                <h4>💡 Posting Tips</h4>
                <ul>
                    <li>Keep your posts respectful and positive</li>
                    <li>Share interesting thoughts or updates</li>
                    <li>Tag friends using @username (coming soon)</li>
                    <li>Posts are visible to your friends</li>
                    <li>You can edit or delete your posts later</li>
                </ul>
            </div>
        </div>
    </div>

    <script src="js/create_post.js"></script>
</body>
</html>