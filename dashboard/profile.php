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

// Fetch user details with new fields
$sql = "SELECT id, name, email, status, profile_picture, cover_photo, bio, location, website, created_at, updated_at FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $message = "User not found.";
    $msgClass = "msg-error";
    $user = null;
} else {
    $user = $result->fetch_assoc();
}
$stmt->close();

// Count friends for sidebar
$friendSql = "SELECT COUNT(*) as friend_count FROM friends WHERE (user_id = ? OR friend_id = ?) AND status = 'approved'";
$friendStmt = $conn->prepare($friendSql);
$friendStmt->bind_param("ii", $user_id, $user_id);
$friendStmt->execute();
$friend_count = $friendStmt->get_result()->fetch_assoc()['friend_count'];
$friendStmt->close();

// Fetch pending friend requests for sidebar
$pendingSql = "SELECT COUNT(*) as pending_count FROM friends WHERE friend_id = ? AND status = 'pending'";
$pendingStmt = $conn->prepare($pendingSql);
$pendingStmt->bind_param("i", $user_id);
$pendingStmt->execute();
$pending_count = $pendingStmt->get_result()->fetch_assoc()['pending_count'];
$pendingStmt->close();

// Fetch user's posts
$posts = [];
$postsSql = "SELECT p.*, 
    (SELECT COUNT(*) FROM post_likes WHERE post_id = p.id) as like_count,
    (SELECT COUNT(*) FROM post_comments WHERE post_id = p.id) as comment_count,
    (SELECT COUNT(*) FROM post_likes WHERE post_id = p.id AND user_id = ?) as user_liked
    FROM posts p 
    WHERE p.user_id = ? 
    ORDER BY p.created_at DESC 
    LIMIT 10";
$postsStmt = $conn->prepare($postsSql);
$postsStmt->bind_param("ii", $user_id, $user_id);
$postsStmt->execute();
$postsResult = $postsStmt->get_result();
while ($post = $postsResult->fetch_assoc()) {
    $posts[] = $post;
}
$postsStmt->close();

// Count user posts for sidebar
$postCountSql = "SELECT COUNT(*) as post_count FROM posts WHERE user_id = ?";
$postCountStmt = $conn->prepare($postCountSql);
$postCountStmt->bind_param("i", $user_id);
$postCountStmt->execute();
$post_count = $postCountStmt->get_result()->fetch_assoc()['post_count'];
$postCountStmt->close();

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = "Invalid CSRF token.";
        $msgClass = "msg-error";
    } else {
        if (isset($_POST['update_profile'])) {
            // Handle basic profile update
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $bio = trim($_POST['bio'] ?? '');
            $location = trim($_POST['location'] ?? '');
            $website = trim($_POST['website'] ?? '');
            
            // Validate inputs
            if (empty($name) || empty($email)) {
                $message = "Please fill in all required fields.";
                $msgClass = "msg-error";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $message = "Please enter a valid email address.";
                $msgClass = "msg-error";
            } else {
                // Check if email already exists (excluding current user)
                $checkEmailSql = "SELECT id FROM users WHERE email = ? AND id != ?";
                $checkStmt = $conn->prepare($checkEmailSql);
                $checkStmt->bind_param("si", $email, $user_id);
                $checkStmt->execute();
                $emailResult = $checkStmt->get_result();
                
                if ($emailResult->num_rows > 0) {
                    $message = "This email is already registered.";
                    $msgClass = "msg-error";
                } else {
                    // Update user profile
                    $updateSql = "UPDATE users SET name = ?, email = ?, bio = ?, location = ?, website = ? WHERE id = ?";
                    $updateStmt = $conn->prepare($updateSql);
                    $updateStmt->bind_param("sssssi", $name, $email, $bio, $location, $website, $user_id);
                    
                    if ($updateStmt->execute()) {
                        $message = "Profile updated successfully!";
                        $msgClass = "msg-success";
                        
                        // Refresh user data
                        $refreshSql = "SELECT id, name, email, status, profile_picture, cover_photo, bio, location, website, created_at, updated_at FROM users WHERE id = ?";
                        $refreshStmt = $conn->prepare($refreshSql);
                        $refreshStmt->bind_param("i", $user_id);
                        $refreshStmt->execute();
                        $user = $refreshStmt->get_result()->fetch_assoc();
                        $refreshStmt->close();
                    } else {
                        $message = "Error updating profile. Please try again.";
                        $msgClass = "msg-error";
                    }
                    $updateStmt->close();
                }
                $checkStmt->close();
            }
        }
        elseif (isset($_POST['upload_profile_picture'])) {
            // Handle profile picture upload
            $uploadResult = handleImageUpload($_FILES['profile_picture'], 'profile_pictures');
            if ($uploadResult['success']) {
                // Update user profile picture
                $updateSql = "UPDATE users SET profile_picture = ? WHERE id = ?";
                $updateStmt = $conn->prepare($updateSql);
                $updateStmt->bind_param("si", $uploadResult['filename'], $user_id);
                
                if ($updateStmt->execute()) {
                    $message = "Profile picture updated successfully!";
                    $msgClass = "msg-success";
                    
                    // Refresh user data
                    $refreshSql = "SELECT id, name, email, status, profile_picture, cover_photo, bio, location, website, created_at, updated_at FROM users WHERE id = ?";
                    $refreshStmt = $conn->prepare($refreshSql);
                    $refreshStmt->bind_param("i", $user_id);
                    $refreshStmt->execute();
                    $user = $refreshStmt->get_result()->fetch_assoc();
                    $refreshStmt->close();
                } else {
                    $message = "Error updating profile picture.";
                    $msgClass = "msg-error";
                }
                $updateStmt->close();
            } else {
                $message = $uploadResult['error'];
                $msgClass = "msg-error";
            }
        }
        elseif (isset($_POST['upload_cover_photo'])) {
            // Handle cover photo upload
            $uploadResult = handleImageUpload($_FILES['cover_photo'], 'cover_photos');
            if ($uploadResult['success']) {
                // Update user cover photo
                $updateSql = "UPDATE users SET cover_photo = ? WHERE id = ?";
                $updateStmt = $conn->prepare($updateSql);
                $updateStmt->bind_param("si", $uploadResult['filename'], $user_id);
                
                if ($updateStmt->execute()) {
                    $message = "Cover photo updated successfully!";
                    $msgClass = "msg-success";
                    
                    // Refresh user data
                    $refreshSql = "SELECT id, name, email, status, profile_picture, cover_photo, bio, location, website, created_at, updated_at FROM users WHERE id = ?";
                    $refreshStmt = $conn->prepare($refreshSql);
                    $refreshStmt->bind_param("i", $user_id);
                    $refreshStmt->execute();
                    $user = $refreshStmt->get_result()->fetch_assoc();
                    $refreshStmt->close();
                } else {
                    $message = "Error updating cover photo.";
                    $msgClass = "msg-error";
                }
                $updateStmt->close();
            } else {
                $message = $uploadResult['error'];
                $msgClass = "msg-error";
            }
        }
        elseif (isset($_POST['delete_post'])) {
            // Handle post deletion
            $post_id = intval($_POST['post_id']);
            
            // Verify post belongs to user
            $verifySql = "SELECT id FROM posts WHERE id = ? AND user_id = ?";
            $verifyStmt = $conn->prepare($verifySql);
            $verifyStmt->bind_param("ii", $post_id, $user_id);
            $verifyStmt->execute();
            $verifyResult = $verifyStmt->get_result();
            
            if ($verifyResult->num_rows > 0) {
                // Delete post
                $deleteSql = "DELETE FROM posts WHERE id = ?";
                $deleteStmt = $conn->prepare($deleteSql);
                $deleteStmt->bind_param("i", $post_id);
                
                if ($deleteStmt->execute()) {
                    $message = "Post deleted successfully!";
                    $msgClass = "msg-success";
                    
                    // Refresh posts
                    $postsStmt = $conn->prepare($postsSql);
                    $postsStmt->bind_param("ii", $user_id, $user_id);
                    $postsStmt->execute();
                    $postsResult = $postsStmt->get_result();
                    $posts = [];
                    while ($post = $postsResult->fetch_assoc()) {
                        $posts[] = $post;
                    }
                    $postsStmt->close();
                } else {
                    $message = "Error deleting post.";
                    $msgClass = "msg-error";
                }
                $deleteStmt->close();
            } else {
                $message = "Post not found or you don't have permission to delete it.";
                $msgClass = "msg-error";
            }
            $verifyStmt->close();
        }
        
        // Regenerate CSRF token after successful form submission
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

// Function to handle image upload
function handleImageUpload($file, $type) {
    $uploadDir = __DIR__ . '/../../uploads/' . $type . '/';
    
    // Create directory if it doesn't exist
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    $maxFileSize = 2 * 1024 * 1024; // 2MB
    
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
        return ['success' => false, 'error' => 'Image size must be less than 2MB.'];
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
    <title>Profile - <?php echo htmlspecialchars($user['name'] ?? 'User'); ?></title>
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

        /* Profile-specific styles - subtle changes */
        .profile-header {
            background: white;
            border-radius: 12px;
            margin-bottom: 20px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }

        .cover-photo {
            height: 220px;  /* Slightly taller */
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);  /* Blue gradient variation */
            background-size: cover;
            background-position: center;
            position: relative;
        }

        .cover-photo-upload {
            position: absolute;
            bottom: 15px;
            right: 15px;
        }

        .profile-info {
            padding: 0 30px 30px;
            margin-top: -50px;
        }

        .profile-avatar-section {
            display: flex;
            align-items: flex-end;
            gap: 20px;
        }

        .profile-avatar {
            width: 130px;  /* Slightly larger */
            height: 130px;
            border-radius: 50%;
            border: 4px solid white;
            background: #3498db;  /* Blue background */
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            color: white;
            font-weight: bold;
            background-size: cover;
            background-position: center;
            position: relative;
        }

        .avatar-upload {
            position: absolute;
            bottom: 5px;
            right: 5px;
        }

        .profile-details {
            flex: 1;
            padding-bottom: 20px;
        }

        .profile-details h1 {
            margin: 0 0 10px 0;
            font-size: 30px;  /* Slightly larger name */
            color: #1a202c;
        }

        .bio {
            font-size: 16px;
            color: #4a5568;
            margin-bottom: 15px;
            line-height: 1.5;
        }

        .profile-meta {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        .profile-meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
            color: #718096;
            font-size: 14px;
        }

        /* Profile Tabs - horizontal with blue accents */
        .profile-tabs {
            display: flex;
            background: white;
            border-radius: 12px;
            padding: 0;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        .tab-btn {
            flex: 1;
            padding: 15px 20px;
            border: none;
            background: transparent;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 14px;
            font-weight: 500;
            color: #718096;
        }

        .tab-btn:hover {
            background: #ecf0f1;
            color: #4a5568;
        }

        .tab-btn.active {
            background: #3498db;
            color: white;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Form styles */
        .form-section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }

        .form-section h3 {
            margin-top: 0;
            margin-bottom: 20px;
            color: #1a202c;
            font-size: 20px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #4a5568;
        }

        .form-input, .form-textarea {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }

        .form-input:focus, .form-textarea:focus {
            outline: none;
            border-color: #3498db;  /* Blue focus */
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .form-textarea {
            resize: vertical;
            min-height: 100px;
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

        /* File upload styles */
        .file-input-wrapper {
            position: relative;
            display: inline-block;
        }

        .file-input {
            position: absolute;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }

        .upload-btn {
            background: rgba(255, 255, 255, 0.9);
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 12px;
            cursor: pointer;
            transition: background 0.3s ease;
            border: 1px solid #e2e8f0;
        }

        .upload-btn:hover {
            background: white;
        }

        /* Posts grid */
        .posts-grid {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .post-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }

        .post-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 15px;
        }

        .post-author-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #3498db;  /* Blue avatar background */
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            background-size: cover;
            background-position: center;
        }

        .post-author-info {
            flex: 1;
        }

        .post-author-name {
            font-weight: 600;
            color: #1a202c;
        }

        .post-date {
            font-size: 12px;
            color: #718096;
        }

        .post-content {
            margin-bottom: 15px;
            line-height: 1.5;
            color: #4a5568;
        }

        .post-stats {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            font-size: 14px;
            color: #718096;
        }

        .post-actions {
            border-top: 1px solid #e2e8f0;
            padding-top: 15px;
        }

        .delete-btn {
            background: #e53e3e;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            transition: background 0.3s ease;
        }

        .delete-btn:hover {
            background: #c53030;
        }

        /* Stats grid */
        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .info-grid {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            background: #f8fafc;
            border-radius: 8px;
        }

        .info-label {
            color: #718096;
            font-size: 14px;
        }

        .info-value {
            font-weight: 600;
            color: #1a202c;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-verified {
            background: #c6f6d5;
            color: #276749;
        }

        .status-not-verified {
            background: #fed7d7;
            color: #c53030;
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

            .form-grid {
                grid-template-columns: 1fr;
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

            .profile-avatar-section {
                flex-direction: column;
                align-items: center;
                text-align: center;
            }

            .profile-meta {
                justify-content: center;
            }

            .profile-tabs {
                flex-direction: column;
            }

            .profile-info {
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
            <!-- Messages -->
            <?php if (!empty($message)): ?>
                <div class="message <?php echo $msgClass; ?>" style="margin-bottom: 20px;">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <!-- Enhanced Profile Header -->
            <div class="profile-header">
                <div class="cover-photo" style="<?php echo !empty($user['cover_photo']) ? 'background-image: url(../../uploads/cover_photos/' . htmlspecialchars($user['cover_photo']) . ');' : ''; ?>">
                    <form method="POST" enctype="multipart/form-data" class="cover-photo-upload">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <div class="file-input-wrapper">
                            <input type="file" id="cover_photo" name="cover_photo" class="file-input" accept="image/*" onchange="this.form.submit()">
                            <label for="cover_photo" class="upload-btn">📷 Change Cover</label>
                        </div>
                        <input type="hidden" name="upload_cover_photo" value="1">
                    </form>
                </div>
                
                <div class="profile-info">
                    <div class="profile-avatar-section">
                        <div class="profile-avatar" style="<?php echo !empty($user['profile_picture']) ? 'background-image: url(../../uploads/profile_pictures/' . htmlspecialchars($user['profile_picture']) . ');' : ''; ?>">
                            <?php if (empty($user['profile_picture'])) echo strtoupper(substr($user['name'] ?? 'U', 0, 1)); ?>
                            <form method="POST" enctype="multipart/form-data" class="avatar-upload">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <div class="file-input-wrapper">
                                    <input type="file" id="profile_picture" name="profile_picture" class="file-input" accept="image/*" onchange="this.form.submit()">
                                    <label for="profile_picture" class="upload-btn">📷</label>
                                </div>
                                <input type="hidden" name="upload_profile_picture" value="1">
                            </form>
                        </div>
                        
                        <div class="profile-details">
                            <h1><?php echo htmlspecialchars($user['name']); ?></h1>
                            <?php if (!empty($user['bio'])): ?>
                                <div class="bio"><?php echo htmlspecialchars($user['bio']); ?></div>
                            <?php endif; ?>
                            <div class="profile-meta">
                                <?php if (!empty($user['location'])): ?>
                                    <div class="profile-meta-item">📍 <?php echo htmlspecialchars($user['location']); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($user['website'])): ?>
                                    <div class="profile-meta-item">🌐 <a href="<?php echo htmlspecialchars($user['website']); ?>" target="_blank">Website</a></div>
                                <?php endif; ?>
                                <div class="profile-meta-item">👥 <?php echo $friend_count . ' Friends'; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Profile Tabs -->
            <div class="profile-tabs">
                <button class="tab-btn active" onclick="switchTab('my-posts')">My Posts</button>
                <button class="tab-btn" onclick="switchTab('edit-profile')">Edit Profile</button>
                <button class="tab-btn" onclick="switchTab('statistics')">Statistics</button>
                <button class="tab-btn" onclick="switchTab('danger-zone')">Account</button>
            </div>

            <div id="my-posts" class="tab-content active">
                <div class="form-section">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h3>My Recent Posts</h3>
                        <a href="create_post.php" class="action-btn">Create New Post</a>
                    </div>
                    
                    <?php if (empty($posts)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">📝</div>
                            <h3>No posts yet</h3>
                            <p>You haven't created any posts. Start sharing your thoughts!</p>
                            <a href="create_post.php" class="action-btn" style="margin-top: 15px;">Create Your First Post</a>
                        </div>
                    <?php else: ?>
                        <div class="posts-grid">
                            <?php foreach ($posts as $post): ?>
                                <div class="post-card">
                                    <div class="post-header">
                                        <div class="post-author-avatar" style="<?php echo !empty($user['profile_picture']) ? 'background-image: url(../../uploads/profile_pictures/' . htmlspecialchars($user['profile_picture']) . ');' : ''; ?>">
                                            <?php if (empty($user['profile_picture'])) echo strtoupper(substr($user['name'] ?? 'U', 0, 1)); ?>
                                        </div>
                                        <div class="post-author-info">
                                            <div class="post-author-name"><?php echo htmlspecialchars($user['name']); ?></div>
                                            <div class="post-date"><?php echo date('F j, Y \a\t g:i A', strtotime($post['created_at'])); ?></div>
                                        </div>
                                    </div>
                                    
                                    <div class="post-content">
                                        <?php echo nl2br(htmlspecialchars($post['content'])); ?>
                                    </div>

                                    <!-- Image Display - Clickable for Modal -->
                                    <?php if (!empty($post['image_filename'])): ?>
                                        <div class="post-image-full">
                                            <img src="../../uploads/posts/<?php echo htmlspecialchars($post['image_filename']); ?>" 
                                                alt="<?php echo !empty($post['image_caption']) ? htmlspecialchars($post['image_caption']) : 'Post image'; ?>"
                                                class="post-image-auto"
                                                onclick="openImageModal(this, '<?php echo !empty($post['image_caption']) ? addslashes($post['image_caption']) : ''; ?>')">
                                            <?php if (!empty($post['image_caption'])): ?>
                                                <div class="image-caption-full"><?php echo htmlspecialchars($post['image_caption']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="post-stats">
                                        <span>❤️ <?php echo $post['like_count']; ?> likes</span>
                                        <span>💬 <?php echo $post['comment_count']; ?> comments</span>
                                    </div>
                                    
                                    <div class="post-actions">
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                                            <button type="submit" name="delete_post" class="delete-btn" onclick="return confirm('Are you sure you want to delete this post?')">Delete Post</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Edit Profile Tab -->
            <div id="edit-profile" class="tab-content">
                <div class="form-section">
                    <h3>Basic Information</h3>
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="update_profile" value="1">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="name" class="form-label">Full Name *</label>
                                <input type="text" id="name" name="name" class="form-input" value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="email" class="form-label">Email Address *</label>
                                <input type="email" id="email" name="email" class="form-input" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="location" class="form-label">Location</label>
                                <input type="text" id="location" name="location" class="form-input" value="<?php echo htmlspecialchars($user['location'] ?? ''); ?>" placeholder="Where do you live?">
                            </div>
                            
                            <div class="form-group">
                                <label for="website" class="form-label">Website</label>
                                <input type="url" id="website" name="website" class="form-input" value="<?php echo htmlspecialchars($user['website'] ?? ''); ?>" placeholder="https://example.com">
                            </div>
                            
                            <div class="form-group full-width">
                                <label for="bio" class="form-label">Bio</label>
                                <textarea id="bio" name="bio" class="form-textarea" placeholder="Tell us about yourself..."><?php echo htmlspecialchars($user['bio'] ?? ''); ?></textarea>
                            </div>
                        </div>

                        <div class="quick-actions" style="margin-top: 30px;">
                            <button type="submit" class="action-btn">Update Profile</button>
                            <a href="../modules/user/change_password.php" class="action-btn secondary">Change Password</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Statistics Tab -->
            <div id="statistics" class="tab-content">
                <div class="stats-grid">
                    <!-- Profile Overview Card -->
                    <div class="form-section">
                        <h3>Profile Overview</h3>
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">User ID</div>
                                <div class="info-value">#<?php echo htmlspecialchars($user['id'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Account Status</div>
                                <div class="info-value">
                                    <span class="status-badge <?php echo ($user && $user['status'] === 'Verified') ? 'status-verified' : 'status-not-verified'; ?>">
                                        <?php echo htmlspecialchars($user['status'] ?? 'Unknown'); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Member Since</div>
                                <div class="info-value"><?php echo $user ? date('F j, Y', strtotime($user['created_at'])) : 'N/A'; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Last Updated</div>
                                <div class="info-value"><?php echo $user ? date('F j, Y g:i A', strtotime($user['updated_at'])) : 'N/A'; ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Account Statistics Card -->
                    <div class="form-section">
                        <h3>Account Statistics</h3>
                        <div class="info-grid">
                            <?php
                            // Count sent requests
                            $sentSql = "SELECT COUNT(*) as sent_count FROM friends WHERE user_id = ? AND status = 'pending'";
                            $sentStmt = $conn->prepare($sentSql);
                            $sentStmt->bind_param("i", $user_id);
                            $sentStmt->execute();
                            $sent_count = $sentStmt->get_result()->fetch_assoc()['sent_count'];
                            $sentStmt->close();
                            ?>
                            <div class="info-item">
                                <div class="info-label">Total Friends</div>
                                <div class="info-value" style="font-size: 24px; color: #3498db;"><?php echo $friend_count; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Your Posts</div>
                                <div class="info-value" style="font-size: 24px; color: #3498db;"><?php echo $post_count; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Pending Received</div>
                                <div class="info-value" style="font-size: 24px; color: #3498db;"><?php echo $pending_count; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Account Age</div>
                                <div class="info-value" style="font-size: 24px; color: #3498db;">
                                    <?php 
                                    if ($user) {
                                        $accountAge = floor((time() - strtotime($user['created_at'])) / (60 * 60 * 24));
                                        echo $accountAge . ' days';
                                    } else {
                                        echo 'N/A';
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Danger Zone Tab -->
            <div id="danger-zone" class="tab-content">
                <div class="form-section" style="border-left: 4px solid #e53e3e;">
                    <h3 style="color: #e53e3e; margin-bottom: 20px;">Danger Zone</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Account Deletion</div>
                            <div class="info-value">
                                <p style="color: #718096; margin-bottom: 15px;">Once you delete your account, there is no going back. Please be certain.</p>
                                <a href="delete-account.php" class="logout-btn" onclick="return confirm('Are you sure you want to delete your account? This action cannot be undone.')">Delete Account</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <!-- Right Sidebar -->
        <aside class="right-sidebar">
            <!-- Quick Stats -->
            <div class="sidebar-card">
                <h3>Profile Stats</h3>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <div class="quick-stats-item">
                        <span style="color: #718096; font-size: 14px;">👥 Friends</span>
                        <span style="font-weight: 600; color: #2d3748;"><?php echo $friend_count; ?></span>
                    </div>
                    <div class="quick-stats-item">
                        <span style="color: #718096; font-size: 14px;">📬 Requests</span>
                        <span style="font-weight: 600; color: #2d3748;"><?php echo $pending_count; ?></span>
                    </div>
                    <div class="quick-stats-item">
                        <span style="color: #718096; font-size: 14px;">✉️ Status</span>
                        <span style="font-weight: 600; color: <?php echo ($user && $user['status'] === 'Verified') ? '#48bb78' : '#e53e3e'; ?>;">
                            <?php echo ($user && $user['status'] === 'Verified') ? 'Verified' : 'Not Verified'; ?>
                        </span>
                    </div>
                    <div class="quick-stats-item">
                        <span style="color: #718096; font-size: 14px;">📝 Posts</span>
                        <span style="font-weight: 600; color: #2d3748;"><?php echo $post_count; ?></span>
                    </div>
                </div>
            </div>

            <!-- Quick Links -->
            <div class="sidebar-card">
                <h3>Quick Actions</h3>
                <div style="display: flex; flex-direction: column; gap: 10px;">
                    <a href="create_post.php" style="display: flex; align-items: center; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 8px; text-decoration: none; color: #2d3748; transition: background 0.2s;">
                        <span>✏️</span>
                        <span>Create Post</span>
                    </a>
                    <a href="dashboard.php" style="display: flex; align-items: center; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 8px; text-decoration: none; color: #2d3748; transition: background 0.2s;">
                        <span>🏠</span>
                        <span>Dashboard</span>
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

    <script src="js/profile.js"></script>
</body>
</html>