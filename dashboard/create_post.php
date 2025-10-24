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

// Fetch user details for header and sidebar
$userSql = "SELECT id, name, profile_picture FROM users WHERE id = ?";
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

// Count pending friend requests for sidebar
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

// Handle post submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = "Invalid CSRF token.";
        $msgClass = "msg-error";
    } else {
        $content = trim($_POST['content'] ?? '');
        $image_caption = trim($_POST['image_caption'] ?? '');
        $image_filename = null;
        
        // Validate content
        if (empty($content) && empty($_FILES['post_image']['name'])) {
            $message = "Please enter some content or select an image to post.";
            $msgClass = "msg-error";
        } elseif (strlen($content) > 500) {
            $message = "Post content cannot exceed 500 characters.";
            $msgClass = "msg-error";
        } else {
            // Handle image upload
            if (!empty($_FILES['post_image']['name'])) {
                $uploadResult = handleImageUpload($_FILES['post_image']);
                if ($uploadResult['success']) {
                    $image_filename = $uploadResult['filename'];
                } else {
                    $message = $uploadResult['error'];
                    $msgClass = "msg-error";
                }
            }
            
            // If no errors, insert post
            if (empty($message)) {
                if ($image_filename) {
                    $sql = "INSERT INTO posts (user_id, content, image_filename, image_caption) VALUES (?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("isss", $user_id, $content, $image_filename, $image_caption);
                } else {
                    $sql = "INSERT INTO posts (user_id, content) VALUES (?, ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("is", $user_id, $content);
                }
                
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
        }
        
        // Regenerate CSRF token
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

// Function to handle image upload
function handleImageUpload($file) {
    $uploadDir = __DIR__ . '/../../uploads/posts/';
    
    // Create directory if it doesn't exist
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    $maxFileSize = 5 * 1024 * 1024; // 5MB
    
    // Check for errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'File upload error.'];
    }
    
    // Check file type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $allowedTypes)) {
        return ['success' => false, 'error' => 'Only JPG, PNG, GIF, and WebP images are allowed.'];
    }
    
    // Check file size
    if ($file['size'] > $maxFileSize) {
        return ['success' => false, 'error' => 'Image size must be less than 5MB.'];
    }
    
    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid() . '_' . time() . '.' . $extension;
    $filepath = $uploadDir . $filename;
    
    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => true, 'filename' => $filename];
    } else {
        return ['success' => false, 'error' => 'Failed to save image.'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Post - <?php echo htmlspecialchars($user['name']); ?> - Manexis</title>
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

        /* Create Post Specific Styles */
        .create-post-header {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            text-align: center;
        }

        .create-post-header h1 {
            margin: 0 0 10px 0;
            color: #2d3748;
            font-size: 28px;
            font-weight: 700;
        }

        .create-post-header p {
            margin: 0;
            color: #718096;
            font-size: 16px;
        }

        /* Post Form Card */
        .post-form-card {
            background: white;
            border-radius: 12px;
            padding: 0;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            margin-bottom: 20px;
            overflow: hidden;
        }

        .post-header {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 25px 25px 0;
            margin-bottom: 20px;
        }

        .user-avatar-small {
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
            border: 2px solid #f7fafc;
        }

        .user-info-small h3 {
            margin: 0 0 5px 0;
            color: #2d3748;
            font-size: 16px;
            font-weight: 600;
        }

        .user-info-small p {
            margin: 0;
            color: #718096;
            font-size: 14px;
        }

        .post-form {
            padding: 0 25px 25px;
        }

        /* Textarea Styles */
        .post-textarea {
            width: 100%;
            min-height: 120px;
            padding: 15px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 16px;
            font-family: inherit;
            resize: vertical;
            transition: border-color 0.3s ease;
            margin-bottom: 10px;
        }

        .post-textarea:focus {
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
            outline: none;
        }

        .post-textarea::placeholder {
            color: #a0aec0;
        }

        /* Character Counter */
        .char-counter {
            text-align: right;
            font-size: 12px;
            color: #718096;
            margin-bottom: 15px;
        }

        .char-counter.warning {
            color: #ed8936;
        }

        .char-counter.error {
            color: #e53e3e;
            font-weight: 600;
        }

        /* Image Upload Section */
        .image-upload-section {
            border: 2px dashed #e2e8f0;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            transition: border-color 0.3s ease;
        }

        .image-upload-section.dragover {
            border-color: #3498db;
            background: #f0f7ff;
        }

        .upload-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .upload-header h4 {
            margin: 0;
            color: #2d3748;
            font-size: 16px;
        }

        .upload-actions {
            display: flex;
            gap: 10px;
        }

        .file-input-label {
            background: #3498db;
            color: white;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.3s ease;
        }

        .file-input-label:hover {
            background: #2980b9;
        }

        .file-input {
            display: none;
        }

        /* Upload Area */
        .upload-area {
            position: relative;
            min-height: 150px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            background: #f8fafc;
            transition: background 0.3s ease;
        }

        .upload-area.has-image {
            background: transparent;
            min-height: auto;
        }

        .upload-placeholder {
            text-align: center;
            color: #718096;
        }

        .upload-icon {
            font-size: 40px;
            margin-bottom: 10px;
        }

        .upload-placeholder p {
            margin: 5px 0;
        }

        .file-info {
            font-size: 12px;
            color: #a0aec0;
            margin-top: 10px;
        }

        /* Image Preview */
        .image-preview {
            display: none;
            width: 100%;
            text-align: center;
        }

        .image-preview img {
            max-width: 100%;
            max-height: 300px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .preview-actions {
            margin-top: 10px;
        }

        .remove-image {
            background: #e53e3e;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            transition: background 0.3s ease;
        }

        .remove-image:hover {
            background: #c53030;
        }

        /* Caption Section */
        .caption-section {
            display: none;
            margin-top: 15px;
        }

        .caption-section.visible {
            display: block;
        }

        .image-caption {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }

        .image-caption:focus {
            border-color: #3498db;
            outline: none;
        }

        .image-caption::placeholder {
            color: #a0aec0;
        }

        /* Post Actions */
        .post-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
        }

        .btn-post {
            background: #3498db;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-post:hover:not(:disabled) {
            background: #2980b9;
            transform: translateY(-1px);
        }

        .btn-post:disabled {
            background: #a0aec0;
            cursor: not-allowed;
            transform: none;
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

            .post-actions {
                flex-direction: column;
                gap: 15px;
                align-items: stretch;
            }

            .upload-header {
                flex-direction: column;
                gap: 10px;
                align-items: flex-start;
            }

            .upload-actions {
                width: 100%;
                justify-content: space-between;
            }

            .create-post-header {
                padding: 20px;
            }

            .create-post-header h1 {
                font-size: 24px;
            }

            .post-header {
                padding: 20px 20px 0;
            }

            .post-form {
                padding: 0 20px 20px;
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
            <span class="search-icon">🔍</span>
            <input type="text" name="search_query" placeholder="Search for friends, posts, and more...">
        </form>
        <div class="header-right">
            <a href="news_feed.php" class="action-btn">News Feed</a>
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
                <a href="dashboard.php" class="sidebar-nav-item">
                    <div class="nav-icon">🏠</div>
                    <span>Dashboard</span>
                </a>
                <a href="profile.php" class="sidebar-nav-item">
                    <div class="nav-icon">👤</div>
                    <span>My Profile</span>
                </a>
                <a href="list_friends.php" class="sidebar-nav-item">
                    <div class="nav-icon">👥</div>
                    <span>Friends List</span>
                    <?php if ($friend_count > 0): ?>
                        <span class="friends-count"><?php echo $friend_count; ?></span>
                    <?php endif; ?>
                </a>
                <a href="create_post.php" class="sidebar-nav-item active">
                    <div class="nav-icon">✏️</div>
                    <span>Create Post</span>
                </a>
                <a href="../messages/messages.php" class="sidebar-nav-item">
                    <div class="nav-icon">💬</div>
                    <span>Messages</span>
                </a>
                <a href="edit-profile.php" class="sidebar-nav-item">
                    <div class="nav-icon">⚙️</div>
                    <span>Settings</span>
                </a>
                <a href="logout.php" class="sidebar-nav-item" style="color: #e53e3e;">
                    <div class="nav-icon">🚪</div>
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
                    <?php if ($msgClass === 'msg-success'): ?>
                        <p style="margin-top: 10px; font-size: 14px; color: #718096;">Redirecting to news feed...</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Create Post Header -->
            <div class="create-post-header">
                <h1>Create New Post</h1>
                <p>Share your thoughts and images with your friends</p>
            </div>

            <!-- Create Post Form -->
            <div class="post-form-card">
                <div class="post-header">
                    <?php 
                    if (!empty($user['profile_picture'])) {
                        echo '<div class="user-avatar-small" style="background-image: url(../../uploads/profile_pictures/' . htmlspecialchars($user['profile_picture']) . ');"></div>';
                    } else {
                        echo '<div class="user-avatar-small">' . strtoupper(substr($user['name'] ?? 'U', 0, 1)) . '</div>';
                    }
                    ?>
                    <div class="user-info-small">
                        <h3><?php echo htmlspecialchars($user['name']); ?></h3>
                        <p>Posting to your news feed</p>
                    </div>
                </div>

                <form method="POST" action="" id="postForm" enctype="multipart/form-data" class="post-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    
                    <!-- Text Content -->
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

                    <!-- Image Upload -->
                    <div class="image-upload-section" id="imageUploadSection">
                        <div class="upload-header">
                            <h4>📷 Add a Photo (Optional)</h4>
                            <div class="upload-actions">
                                <label for="post_image" class="file-input-label">Choose Image</label>
                                <button type="button" class="action-btn secondary" id="toggleUpload" onclick="toggleUploadSection()">Hide</button>
                            </div>
                        </div>
                        
                        <div class="upload-area" id="uploadArea">
                            <div class="upload-placeholder" id="uploadPlaceholder">
                                <div class="upload-icon">🖼️</div>
                                <p><strong>Drag & drop an image here</strong></p>
                                <p>or click "Choose Image" above</p>
                                <div class="file-info">Supports JPG, PNG, GIF, WebP • Max 5MB</div>
                            </div>
                            
                            <!-- Image Preview -->
                            <div class="image-preview" id="imagePreview">
                                <img id="previewImage" src="#" alt="Preview">
                                <div class="preview-actions">
                                    <button type="button" class="remove-image" id="removeImage">Remove Image</button>
                                </div>
                            </div>
                        </div>
                        
                        <input type="file" id="post_image" name="post_image" class="file-input" accept="image/*">
                        
                        <!-- Image Caption -->
                        <div class="caption-section" id="captionSection">
                            <input type="text" name="image_caption" id="image_caption" class="image-caption" placeholder="Add a caption for your image...">
                        </div>
                    </div>

                    <div class="post-actions">
                        <div>
                            <a href="news_feed.php" class="action-btn secondary">← Back to Feed</a>
                        </div>
                        <button type="submit" class="btn-post" id="submitBtn" disabled>Post</button>
                    </div>
                </form>
            </div>
        </main>

        <!-- Right Sidebar -->
        <aside class="right-sidebar">
            <!-- Quick Stats -->
            <div class="sidebar-card">
                <h3>Your Activity</h3>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <div class="quick-stats-item">
                        <span style="color: #718096; font-size: 14px;">📝 Your Posts</span>
                        <span style="font-weight: 600; color: #2d3748;"><?php echo $post_count; ?></span>
                    </div>
                    <div class="quick-stats-item">
                        <span style="color: #718096; font-size: 14px;">👥 Friends</span>
                        <span style="font-weight: 600; color: #2d3748;"><?php echo $friend_count; ?></span>
                    </div>
                    <div class="quick-stats-item">
                        <span style="color: #718096; font-size: 14px;">📬 Requests</span>
                        <span style="font-weight: 600; color: #2d3748;"><?php echo $pending_count; ?></span>
                    </div>
                </div>
            </div>

            <!-- Quick Links -->
            <div class="sidebar-card">
                <h3>Quick Actions</h3>
                <div style="display: flex; flex-direction: column; gap: 10px;">
                    <a href="news_feed.php" style="display: flex; align-items: center; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 8px; text-decoration: none; color: #2d3748; transition: background 0.2s;">
                        <span>📰</span>
                        <span>News Feed</span>
                    </a>
                    <a href="profile.php" style="display: flex; align-items: center; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 8px; text-decoration: none; color: #2d3748; transition: background 0.2s;">
                        <span>👤</span>
                        <span>My Profile</span>
                    </a>
                    <a href="dashboard.php" style="display: flex; align-items: center; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 8px; text-decoration: none; color: #2d3748; transition: background 0.2s;">
                        <span>🏠</span>
                        <span>Dashboard</span>
                    </a>
                    <a href="list_friends.php" style="display: flex; align-items: center; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 8px; text-decoration: none; color: #2d3748; transition: background 0.2s;">
                        <span>👥</span>
                        <span>Friends List</span>
                    </a>
                </div>
            </div>

            <!-- Posting Tips -->
            <div class="sidebar-card">
                <h3>Posting Tips</h3>
                <div style="font-size: 13px; color: #718096; line-height: 1.6;">
                    <p style="margin: 0 0 10px 0;">💬 <strong>Share thoughts:</strong> Keep posts engaging and positive</p>
                    <p style="margin: 0 0 10px 0;">🖼️ <strong>Add images:</strong> Visual content gets more engagement</p>
                    <p style="margin: 0;">🔒 <strong>Privacy:</strong> Only your friends can see your posts</p>
                </div>
            </div>
        </aside>
    </div>

    <script src="js/create_post.js"></script>
</body>
</html>