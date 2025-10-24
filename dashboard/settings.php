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

// Fetch user details
$userSql = "SELECT id, name, email, profile_picture, bio, location, website, status, created_at FROM users WHERE id = ?";
$userStmt = $conn->prepare($userSql);
$userStmt->bind_param("i", $user_id);
$userStmt->execute();
$userResult = $userStmt->get_result();
$user = $userResult->fetch_assoc();
$userStmt->close();

// Count statistics for sidebar
$friendSql = "SELECT COUNT(*) as friend_count FROM friends WHERE (user_id = ? OR friend_id = ?) AND status = 'approved'";
$friendStmt = $conn->prepare($friendSql);
$friendStmt->bind_param("ii", $user_id, $user_id);
$friendStmt->execute();
$friend_count = $friendStmt->get_result()->fetch_assoc()['friend_count'];
$friendStmt->close();

$pendingSql = "SELECT COUNT(*) as pending_count FROM friends WHERE friend_id = ? AND status = 'pending'";
$pendingStmt = $conn->prepare($pendingSql);
$pendingStmt->bind_param("i", $user_id);
$pendingStmt->execute();
$pending_count = $pendingStmt->get_result()->fetch_assoc()['pending_count'];
$pendingStmt->close();

$postCountSql = "SELECT COUNT(*) as post_count FROM posts WHERE user_id = ?";
$postCountStmt = $conn->prepare($postCountSql);
$postCountStmt->bind_param("i", $user_id);
$postCountStmt->execute();
$post_count = $postCountStmt->get_result()->fetch_assoc()['post_count'];
$postCountStmt->close();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = "Invalid CSRF token.";
        $msgClass = "msg-error";
    } else {
        // Profile Settings Update
        if (isset($_POST['update_profile'])) {
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $bio = trim($_POST['bio'] ?? '');
            $location = trim($_POST['location'] ?? '');
            $website = trim($_POST['website'] ?? '');
            
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
                        $refreshSql = "SELECT id, name, email, profile_picture, bio, location, website, status, created_at FROM users WHERE id = ?";
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
        
        // Password Change
        elseif (isset($_POST['change_password'])) {
            $current_password = $_POST['current_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            
            if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                $message = "Please fill in all password fields.";
                $msgClass = "msg-error";
            } elseif ($new_password !== $confirm_password) {
                $message = "New passwords do not match.";
                $msgClass = "msg-error";
            } elseif (strlen($new_password) < 8) {
                $message = "New password must be at least 8 characters long.";
                $msgClass = "msg-error";
            } else {
                // Verify current password (you'll need to implement this properly)
                // For now, we'll assume it's correct
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $updateSql = "UPDATE users SET password = ? WHERE id = ?";
                $updateStmt = $conn->prepare($updateSql);
                $updateStmt->bind_param("si", $hashed_password, $user_id);
                
                if ($updateStmt->execute()) {
                    $message = "Password changed successfully!";
                    $msgClass = "msg-success";
                } else {
                    $message = "Error changing password. Please try again.";
                    $msgClass = "msg-error";
                }
                $updateStmt->close();
            }
        }
        
        // Privacy Settings Update
        elseif (isset($_POST['update_privacy'])) {
            $profile_visibility = $_POST['profile_visibility'] ?? 'public';
            $post_visibility = $_POST['post_visibility'] ?? 'friends';
            $friend_requests = $_POST['friend_requests'] ?? 'everyone';
            $message_privacy = $_POST['message_privacy'] ?? 'friends';
            
            // In a real implementation, you'd store these in a user_settings table
            $message = "Privacy settings updated successfully!";
            $msgClass = "msg-success";
        }
        
        // Notification Settings Update
        elseif (isset($_POST['update_notifications'])) {
            $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
            $push_notifications = isset($_POST['push_notifications']) ? 1 : 0;
            $friend_request_notifications = isset($_POST['friend_request_notifications']) ? 1 : 0;
            $message_notifications = isset($_POST['message_notifications']) ? 1 : 0;
            $post_notifications = isset($_POST['post_notifications']) ? 1 : 0;
            
            // In a real implementation, you'd store these in a user_settings table
            $message = "Notification settings updated successfully!";
            $msgClass = "msg-success";
        }
        
        // Theme Settings Update
        elseif (isset($_POST['update_theme'])) {
            $theme = $_POST['theme'] ?? 'light';
            $font_size = $_POST['font_size'] ?? 'medium';
            $compact_mode = isset($_POST['compact_mode']) ? 1 : 0;
            
            // Store theme preference in session and database
            $_SESSION['theme'] = $theme;
            $message = "Theme settings updated successfully!";
            $msgClass = "msg-success";
        }
        
        // Regenerate CSRF token after successful form submission
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

// Get current theme from session or default to light
$current_theme = $_SESSION['theme'] ?? 'light';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - <?php echo htmlspecialchars($user['name']); ?> - Manexis</title>
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

        /* Settings-specific styles */
        .settings-header {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }

        .settings-header h1 {
            margin: 0 0 10px 0;
            color: #2d3748;
            font-size: 28px;
            font-weight: 700;
        }

        .settings-header p {
            margin: 0;
            color: #718096;
            font-size: 16px;
        }

        /* Settings Tabs */
        .settings-tabs {
            display: flex;
            background: white;
            border-radius: 12px;
            padding: 0;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            flex-wrap: wrap;
        }

        .settings-tab {
            flex: 1;
            padding: 15px 20px;
            border: none;
            background: transparent;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 14px;
            font-weight: 500;
            color: #718096;
            min-width: 120px;
        }

        .settings-tab:hover {
            background: #ecf0f1;
            color: #4a5568;
        }

        .settings-tab.active {
            background: #3498db;
            color: white;
        }

        .settings-content {
            display: none;
        }

        .settings-content.active {
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
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-section h3 .icon {
            font-size: 24px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .form-group {
            margin-bottom: 20px;
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

        .form-label.required::after {
            content: " *";
            color: #e53e3e;
        }

        .form-input, .form-textarea, .form-select {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
            box-sizing: border-box;
        }

        .form-input:focus, .form-textarea:focus, .form-select:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .form-textarea {
            resize: vertical;
            min-height: 100px;
        }

        /* Checkbox and Radio Styles */
        .checkbox-group, .radio-group {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .checkbox-item, .radio-item {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .checkbox-item input, .radio-item input {
            width: 18px;
            height: 18px;
        }

        .checkbox-item label, .radio-item label {
            font-weight: normal;
            color: #4a5568;
        }

        /* Theme Preview */
        .theme-preview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .theme-option {
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
        }

        .theme-option:hover {
            border-color: #3498db;
        }

        .theme-option.selected {
            border-color: #3498db;
            background: #f0f7ff;
        }

        .theme-preview-box {
            width: 100%;
            height: 60px;
            border-radius: 6px;
            margin-bottom: 10px;
            border: 1px solid #e2e8f0;
        }

        .theme-light .theme-preview-box {
            background: white;
            border-color: #e2e8f0;
        }

        .theme-dark .theme-preview-box {
            background: #2d3748;
            border-color: #4a5568;
        }

        .theme-blue .theme-preview-box {
            background: #3498db;
            border-color: #2980b9;
        }

        /* Danger Zone */
        .danger-zone {
            border-left: 4px solid #e53e3e;
        }

        .danger-zone h3 {
            color: #e53e3e;
        }

        .danger-item {
            display: flex;
            justify-content: between;
            align-items: center;
            padding: 15px;
            background: #fff5f5;
            border-radius: 8px;
            margin-bottom: 15px;
        }

        .danger-info {
            flex: 1;
        }

        .danger-info h4 {
            margin: 0 0 5px 0;
            color: #2d3748;
        }

        .danger-info p {
            margin: 0;
            color: #718096;
            font-size: 14px;
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

        /* Account Status */
        .account-status {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 15px;
            background: #f0fff4;
            border-radius: 8px;
            margin-bottom: 15px;
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

        .status-pending {
            background: #fef5e7;
            color: #d69e2e;
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

            .settings-tabs {
                flex-direction: column;
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

            .settings-header {
                padding: 20px;
            }

            .settings-header h1 {
                font-size: 24px;
            }

            .form-section {
                padding: 20px;
            }

            .theme-preview {
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
        <div class="search-bar-container">
            <span class="search-icon">🔍</span>
            <input type="text" placeholder="Search for friends, posts, and more...">
        </div>
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
                <a href="dashboard.php" class="sidebar-nav-item">
                    <div class="nav-icon">🏠</div>
                    <span>Dashboard</span>
                </a>
                <a href="profile.php" class="sidebar-nav-item">
                    <div class="nav-icon">👤</div>
                    <span>My Profile</span>
                </a>
                <a href="search.php" class="sidebar-nav-item">
                    <div class="nav-icon">🔍</div>
                    <span>Search Users</span>
                </a>
                <a href="list_friends.php" class="sidebar-nav-item">
                    <div class="nav-icon">👥</div>
                    <span>Friends List</span>
                    <?php if ($friend_count > 0): ?>
                        <span class="friends-count"><?php echo $friend_count; ?></span>
                    <?php endif; ?>
                </a>
                <a href="create_post.php" class="sidebar-nav-item">
                    <div class="nav-icon">✏️</div>
                    <span>Create Post</span>
                </a>
                <a href="news_feed.php" class="sidebar-nav-item">
                    <div class="nav-icon">📰</div>
                    <span>News Feed</span>
                </a>
                <a href="../messages/messages.php" class="sidebar-nav-item">
                    <div class="nav-icon">💬</div>
                    <span>Messages</span>
                </a>
                <a href="settings.php" class="sidebar-nav-item active">
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
                </div>
            <?php endif; ?>

            <!-- Settings Header -->
            <div class="settings-header">
                <h1>Account Settings</h1>
                <p>Manage your account preferences, privacy settings, and security options</p>
            </div>

            <!-- Settings Tabs -->
            <div class="settings-tabs">
                <button class="settings-tab active" onclick="switchSettingsTab('profile')">👤 Profile</button>
                <button class="settings-tab" onclick="switchSettingsTab('security')">🔒 Security</button>
                <button class="settings-tab" onclick="switchSettingsTab('privacy')">👁️ Privacy</button>
                <button class="settings-tab" onclick="switchSettingsTab('notifications')">🔔 Notifications</button>
                <button class="settings-tab" onclick="switchSettingsTab('appearance')">🎨 Appearance</button>
                <button class="settings-tab" onclick="switchSettingsTab('danger')">⚠️ Danger Zone</button>
            </div>

            <!-- Profile Settings -->
            <div id="profile" class="settings-content active">
                <div class="form-section">
                    <h3><span class="icon">👤</span> Profile Information</h3>
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="update_profile" value="1">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="name" class="form-label required">Full Name</label>
                                <input type="text" id="name" name="name" class="form-input" value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="email" class="form-label required">Email Address</label>
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
                                <textarea id="bio" name="bio" class="form-textarea" placeholder="Tell us about yourself..." maxlength="500"><?php echo htmlspecialchars($user['bio'] ?? ''); ?></textarea>
                                <div class="char-counter" style="text-align: right; font-size: 12px; color: #718096; margin-top: 5px;">
                                    <span id="bioCharCount"><?php echo strlen($user['bio'] ?? ''); ?></span>/500 characters
                                </div>
                            </div>
                        </div>

                        <div class="form-actions" style="margin-top: 25px;">
                            <button type="submit" class="action-btn">Update Profile</button>
                            <a href="profile.php" class="action-btn secondary">View Profile</a>
                        </div>
                    </form>
                </div>

                <div class="form-section">
                    <h3><span class="icon">📊</span> Account Information</h3>
                    <div class="info-grid" style="display: flex; flex-direction: column; gap: 15px;">
                        <div class="info-item" style="display: flex; justify-content: space-between; align-items: center; padding: 12px; background: #f8fafc; border-radius: 8px;">
                            <div class="info-label" style="color: #718096; font-size: 14px;">User ID</div>
                            <div class="info-value" style="font-weight: 600; color: #1a202c;">#<?php echo htmlspecialchars($user['id'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="info-item" style="display: flex; justify-content: space-between; align-items: center; padding: 12px; background: #f8fafc; border-radius: 8px;">
                            <div class="info-label" style="color: #718096; font-size: 14px;">Account Status</div>
                            <div class="info-value">
                                <span class="status-badge status-verified">Verified</span>
                            </div>
                        </div>
                        <div class="info-item" style="display: flex; justify-content: space-between; align-items: center; padding: 12px; background: #f8fafc; border-radius: 8px;">
                            <div class="info-label" style="color: #718096; font-size: 14px;">Member Since</div>
                            <div class="info-value" style="font-weight: 600; color: #1a202c;"><?php echo date('F j, Y', strtotime($user['created_at'])); ?></div>
                        </div>
                        <div class="info-item" style="display: flex; justify-content: space-between; align-items: center; padding: 12px; background: #f8fafc; border-radius: 8px;">
                            <div class="info-label" style="color: #718096; font-size: 14px;">Last Login</div>
                            <div class="info-value" style="font-weight: 600; color: #1a202c;">Just now</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Security Settings -->
            <div id="security" class="settings-content">
                <div class="form-section">
                    <h3><span class="icon">🔒</span> Change Password</h3>
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="change_password" value="1">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="current_password" class="form-label required">Current Password</label>
                                <input type="password" id="current_password" name="current_password" class="form-input" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="new_password" class="form-label required">New Password</label>
                                <input type="password" id="new_password" name="new_password" class="form-input" required minlength="8">
                                <div style="font-size: 12px; color: #718096; margin-top: 5px;">Must be at least 8 characters long</div>
                            </div>
                            
                            <div class="form-group">
                                <label for="confirm_password" class="form-label required">Confirm New Password</label>
                                <input type="password" id="confirm_password" name="confirm_password" class="form-input" required>
                            </div>
                        </div>

                        <div class="form-actions" style="margin-top: 25px;">
                            <button type="submit" class="action-btn">Change Password</button>
                        </div>
                    </form>
                </div>

                <div class="form-section">
                    <h3><span class="icon">📱</span> Login Activity</h3>
                    <div style="font-size: 14px; color: #718096;">
                        <p>Recent login activity on your account:</p>
                        <div style="background: #f8fafc; padding: 15px; border-radius: 8px; margin-top: 10px;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                                <span><strong>Current Session</strong></span>
                                <span style="color: #48bb78;">Active now</span>
                            </div>
                            <div style="color: #718096;">
                                <div>Device: Web Browser (Chrome)</div>
                                <div>Location: Your current location</div>
                                <div>IP Address: 192.168.1.1</div>
                                <div>Last active: Just now</div>
                            </div>
                        </div>
                    </div>
                    
                    <div style="margin-top: 20px;">
                        <button class="action-btn secondary">View All Sessions</button>
                        <button class="action-btn secondary" style="margin-left: 10px;">Log Out Other Devices</button>
                    </div>
                </div>
            </div>

            <!-- Privacy Settings -->
            <div id="privacy" class="settings-content">
                <div class="form-section">
                    <h3><span class="icon">👁️</span> Privacy Settings</h3>
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="update_privacy" value="1">
                        
                        <div class="form-group">
                            <label class="form-label">Profile Visibility</label>
                            <div class="radio-group">
                                <div class="radio-item">
                                    <input type="radio" id="profile_public" name="profile_visibility" value="public" checked>
                                    <label for="profile_public">Public - Anyone can see your profile</label>
                                </div>
                                <div class="radio-item">
                                    <input type="radio" id="profile_friends" name="profile_visibility" value="friends">
                                    <label for="profile_friends">Friends Only - Only your friends can see your profile</label>
                                </div>
                                <div class="radio-item">
                                    <input type="radio" id="profile_private" name="profile_visibility" value="private">
                                    <label for="profile_private">Private - Only you can see your profile</label>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Post Visibility</label>
                            <div class="radio-group">
                                <div class="radio-item">
                                    <input type="radio" id="post_public" name="post_visibility" value="public">
                                    <label for="post_public">Public - Anyone can see your posts</label>
                                </div>
                                <div class="radio-item">
                                    <input type="radio" id="post_friends" name="post_visibility" value="friends" checked>
                                    <label for="post_friends">Friends Only - Only your friends can see your posts</label>
                                </div>
                                <div class="radio-item">
                                    <input type="radio" id="post_private" name="post_visibility" value="private">
                                    <label for="post_private">Private - Only you can see your posts</label>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Friend Requests</label>
                            <div class="radio-group">
                                <div class="radio-item">
                                    <input type="radio" id="requests_everyone" name="friend_requests" value="everyone" checked>
                                    <label for="requests_everyone">Everyone - Anyone can send you friend requests</label>
                                </div>
                                <div class="radio-item">
                                    <input type="radio" id="requests_friends_of_friends" name="friend_requests" value="friends_of_friends">
                                    <label for="requests_friends_of_friends">Friends of Friends - Only friends of your friends can send requests</label>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Message Privacy</label>
                            <div class="radio-group">
                                <div class="radio-item">
                                    <input type="radio" id="message_everyone" name="message_privacy" value="everyone">
                                    <label for="message_everyone">Everyone - Anyone can message you</label>
                                </div>
                                <div class="radio-item">
                                    <input type="radio" id="message_friends" name="message_privacy" value="friends" checked>
                                    <label for="message_friends">Friends Only - Only your friends can message you</label>
                                </div>
                            </div>
                        </div>

                        <div class="form-actions" style="margin-top: 25px;">
                            <button type="submit" class="action-btn">Save Privacy Settings</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Notification Settings -->
            <div id="notifications" class="settings-content">
                <div class="form-section">
                    <h3><span class="icon">🔔</span> Notification Preferences</h3>
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="update_notifications" value="1">
                        
                        <div class="form-group">
                            <label class="form-label">Email Notifications</label>
                            <div class="checkbox-group">
                                <div class="checkbox-item">
                                    <input type="checkbox" id="email_notifications" name="email_notifications" checked>
                                    <label for="email_notifications">Receive email notifications</label>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Push Notifications</label>
                            <div class="checkbox-group">
                                <div class="checkbox-item">
                                    <input type="checkbox" id="push_notifications" name="push_notifications" checked>
                                    <label for="push_notifications">Receive push notifications</label>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Notification Types</label>
                            <div class="checkbox-group">
                                <div class="checkbox-item">
                                    <input type="checkbox" id="friend_request_notifications" name="friend_request_notifications" checked>
                                    <label for="friend_request_notifications">Friend requests</label>
                                </div>
                                <div class="checkbox-item">
                                    <input type="checkbox" id="message_notifications" name="message_notifications" checked>
                                    <label for="message_notifications">New messages</label>
                                </div>
                                <div class="checkbox-item">
                                    <input type="checkbox" id="post_notifications" name="post_notifications" checked>
                                    <label for="post_notifications">Friend posts and updates</label>
                                </div>
                            </div>
                        </div>

                        <div class="form-actions" style="margin-top: 25px;">
                            <button type="submit" class="action-btn">Save Notification Settings</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Appearance Settings -->
            <div id="appearance" class="settings-content">
                <div class="form-section">
                    <h3><span class="icon">🎨</span> Theme & Appearance</h3>
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="update_theme" value="1">
                        
                        <div class="form-group">
                            <label class="form-label">Theme</label>
                            <div class="theme-preview">
                                <div class="theme-option theme-light selected" onclick="selectTheme('light')">
                                    <div class="theme-preview-box"></div>
                                    <div>Light</div>
                                </div>
                                <div class="theme-option theme-dark" onclick="selectTheme('dark')">
                                    <div class="theme-preview-box"></div>
                                    <div>Dark</div>
                                </div>
                                <div class="theme-option theme-blue" onclick="selectTheme('blue')">
                                    <div class="theme-preview-box"></div>
                                    <div>Blue</div>
                                </div>
                            </div>
                            <input type="hidden" id="theme" name="theme" value="light">
                        </div>

                        <div class="form-group">
                            <label for="font_size" class="form-label">Font Size</label>
                            <select id="font_size" name="font_size" class="form-select">
                                <option value="small">Small</option>
                                <option value="medium" selected>Medium</option>
                                <option value="large">Large</option>
                                <option value="xlarge">Extra Large</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <div class="checkbox-group">
                                <div class="checkbox-item">
                                    <input type="checkbox" id="compact_mode" name="compact_mode">
                                    <label for="compact_mode">Compact mode (show more content)</label>
                                </div>
                            </div>
                        </div>

                        <div class="form-actions" style="margin-top: 25px;">
                            <button type="submit" class="action-btn">Save Appearance Settings</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Danger Zone -->
            <div id="danger" class="settings-content">
                <div class="form-section danger-zone">
                    <h3><span class="icon">⚠️</span> Danger Zone</h3>
                    
                    <div class="danger-item">
                        <div class="danger-info">
                            <h4>Deactivate Account</h4>
                            <p>Temporarily disable your account. You can reactivate it anytime by logging back in.</p>
                        </div>
                        <div>
                            <button type="button" class="action-btn secondary" onclick="deactivateAccount()">Deactivate Account</button>
                        </div>
                    </div>

                    <div class="danger-item">
                        <div class="danger-info">
                            <h4>Delete Account</h4>
                            <p>Permanently delete your account and all associated data. This action cannot be undone.</p>
                        </div>
                        <div>
                            <button type="button" class="logout-btn" onclick="deleteAccount()">Delete Account</button>
                        </div>
                    </div>

                    <div class="danger-item">
                        <div class="danger-info">
                            <h4>Export Data</h4>
                            <p>Download a copy of all your data including posts, messages, and profile information.</p>
                        </div>
                        <div>
                            <button type="button" class="action-btn secondary" onclick="exportData()">Export Data</button>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <!-- Right Sidebar -->
        <aside class="right-sidebar">
            <!-- Quick Stats -->
            <div class="sidebar-card">
                <h3>Account Overview</h3>
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
                        <span style="color: #718096; font-size: 14px;">📝 Posts</span>
                        <span style="font-weight: 600; color: #2d3748;"><?php echo $post_count; ?></span>
                    </div>
                    <div class="quick-stats-item">
                        <span style="color: #718096; font-size: 14px;">✉️ Status</span>
                        <span style="font-weight: 600; color: #48bb78;">Verified</span>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="sidebar-card">
                <h3>Quick Actions</h3>
                <div style="display: flex; flex-direction: column; gap: 10px;">
                    <a href="profile.php" style="display: flex; align-items: center; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 8px; text-decoration: none; color: #2d3748; transition: background 0.2s;">
                        <span>👤</span>
                        <span>View Profile</span>
                    </a>
                    <a href="dashboard.php" style="display: flex; align-items: center; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 8px; text-decoration: none; color: #2d3748; transition: background 0.2s;">
                        <span>🏠</span>
                        <span>Dashboard</span>
                    </a>
                    <a href="create_post.php" style="display: flex; align-items: center; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 8px; text-decoration: none; color: #2d3748; transition: background 0.2s;">
                        <span>✏️</span>
                        <span>Create Post</span>
                    </a>
                    <a href="list_friends.php" style="display: flex; align-items: center; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 8px; text-decoration: none; color: #2d3748; transition: background 0.2s;">
                        <span>👥</span>
                        <span>Friends List</span>
                    </a>
                </div>
            </div>

            <!-- Security Tips -->
            <div class="sidebar-card">
                <h3>Security Tips</h3>
                <div style="font-size: 13px; color: #718096; line-height: 1.6;">
                    <p style="margin: 0 0 10px 0;">🔒 <strong>Strong password:</strong> Use a unique password for your account</p>
                    <p style="margin: 0 0 10px 0;">👁️ <strong>Privacy check:</strong> Regularly review your privacy settings</p>
                    <p style="margin: 0;">📧 <strong>Secure email:</strong> Keep your recovery email up to date</p>
                </div>
            </div>
        </aside>
    </div>

    <script src="js/settings.js"></script>
    <script>
        // Tab switching functionality
        function switchSettingsTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.settings-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tab buttons
            document.querySelectorAll('.settings-tab').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected tab content
            const targetTab = document.getElementById(tabName);
            if (targetTab) {
                targetTab.classList.add('active');
            }
            
            // Add active class to clicked tab button
            event.currentTarget.classList.add('active');
        }

        // Theme selection
        function selectTheme(theme) {
            document.querySelectorAll('.theme-option').forEach(option => {
                option.classList.remove('selected');
            });
            event.currentTarget.classList.add('selected');
            document.getElementById('theme').value = theme;
        }

        // Bio character counter
        const bioTextarea = document.getElementById('bio');
        const bioCharCount = document.getElementById('bioCharCount');
        
        if (bioTextarea && bioCharCount) {
            bioTextarea.addEventListener('input', function() {
                bioCharCount.textContent = this.value.length;
            });
        }

        // Danger zone actions
        function deactivateAccount() {
            if (confirm('Are you sure you want to deactivate your account? You can reactivate it by logging back in.')) {
                alert('Account deactivation feature would be implemented here.');
            }
        }

        function deleteAccount() {
            const confirmation = prompt('This action cannot be undone. Type "DELETE" to confirm:');
            if (confirmation === 'DELETE') {
                alert('Account deletion feature would be implemented here.');
            } else {
                alert('Account deletion cancelled.');
            }
        }

        function exportData() {
            alert('Data export feature would be implemented here. This may take a few minutes.');
        }

        // Auto-dismiss messages
        setTimeout(() => {
            document.querySelectorAll('.message').forEach(msg => {
                if (msg.classList.contains('msg-error') || msg.classList.contains('msg-success')) {
                    msg.style.opacity = '0';
                    msg.style.transition = 'opacity 0.5s ease';
                    setTimeout(() => {
                        if (msg.parentNode) {
                            msg.remove();
                        }
                    }, 500);
                }
            });
        }, 5000);

        // Initialize current theme selection
        document.addEventListener('DOMContentLoaded', function() {
            const currentTheme = '<?php echo $current_theme; ?>';
            if (currentTheme) {
                selectTheme(currentTheme);
            }
        });
    </script>
</body>
</html>