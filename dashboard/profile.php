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
    <link rel="stylesheet" href="style.css">
    <style>
        .profile-header {
            position: relative;
            margin-bottom: 30px;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }
        
        .cover-photo {
            width: 100%;
            height: 300px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            position: relative;
            background-size: cover;
            background-position: center;
        }
        
        .cover-photo-upload {
            position: absolute;
            top: 20px;
            right: 20px;
        }
        
        .profile-info {
            position: relative;
            padding: 0 30px 30px;
            background: white;
        }
        
        .profile-avatar-section {
            position: relative;
            margin-top: -75px;
            display: flex;
            align-items: flex-end;
            gap: 30px;
            margin-bottom: 20px;
        }
        
        .profile-avatar {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            border: 5px solid white;
            background: #667eea;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 48px;
            background-size: cover;
            background-position: center;
            position: relative;
        }
        
        .avatar-upload {
            position: absolute;
            bottom: 10px;
            right: 10px;
        }
        
        .profile-details h1 {
            margin: 0 0 10px 0;
            color: #2d3748;
            font-size: 28px;
        }
        
        .profile-details .bio {
            color: #718096;
            font-size: 16px;
            line-height: 1.5;
            margin-bottom: 10px;
        }
        
        .profile-meta {
            display: flex;
            gap: 20px;
            color: #64748b;
            font-size: 14px;
        }
        
        .profile-meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .upload-btn {
            background: rgba(255, 255, 255, 0.9);
            color: #2d3748;
            border: 1px solid #e2e8f0;
            padding: 8px 16px;
            border-radius: 20px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.3s;
            backdrop-filter: blur(10px);
        }
        
        .upload-btn:hover {
            background: white;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .profile-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .tab-btn {
            background: none;
            border: none;
            padding: 12px 24px;
            color: #64748b;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            transition: all 0.3s;
        }
        
        .tab-btn.active {
            color: #667eea;
            border-bottom-color: #667eea;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .form-section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }
        
        .form-section h3 {
            margin: 0 0 20px 0;
            color: #2d3748;
            font-size: 18px;
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
            color: #2d3748;
            font-weight: 500;
        }
        
        .form-input, .form-textarea {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s, box-shadow 0.3s;
        }
        
        .form-textarea {
            min-height: 100px;
            resize: vertical;
            font-family: inherit;
        }
        
        .form-input:focus, .form-textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .file-input-wrapper {
            position: relative;
            display: inline-block;
        }
        
        .file-input {
            position: absolute;
            left: -9999px;
        }
        
        @media (max-width: 768px) {
            .profile-avatar-section {
                flex-direction: column;
                align-items: center;
                text-align: center;
                gap: 20px;
            }
            
            .profile-avatar {
                width: 120px;
                height: 120px;
                margin-top: 0;
            }
            
            .profile-details {
                text-align: center;
            }
            
            .profile-meta {
                justify-content: center;
                flex-wrap: wrap;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .cover-photo {
                height: 200px;
            }
        }
    </style>
</head>
<body class="dashboard-page">
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="welcome-section">
                <h1>Profile Settings</h1>
                <p>Manage your account information and preferences</p>
            </div>
            <div class="user-info">
                <div class="user-avatar" style="<?php echo !empty($user['profile_picture']) ? 'background-image: url(../../uploads/profile_pictures/' . htmlspecialchars($user['profile_picture']) . '); background-size: cover;' : ''; ?>">
                    <?php if (empty($user['profile_picture'])) echo strtoupper(substr($user['name'] ?? 'U', 0, 1)); ?>
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
                            <div class="profile-meta-item">👥 <?php 
                                $friendSql = "SELECT COUNT(*) as friend_count FROM friends WHERE (user_id = ? OR friend_id = ?) AND status = 'approved'";
                                $friendStmt = $conn->prepare($friendSql);
                                $friendStmt->bind_param("ii", $user_id, $user_id);
                                $friendStmt->execute();
                                $friend_count = $friendStmt->get_result()->fetch_assoc()['friend_count'];
                                $friendStmt->close();
                                echo $friend_count . ' Friends';
                            ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Profile Tabs -->
        <div class="profile-tabs">
            <button class="tab-btn active" onclick="switchTab('edit-profile')">Edit Profile</button>
            <button class="tab-btn" onclick="switchTab('statistics')">Statistics</button>
            <button class="tab-btn" onclick="switchTab('danger-zone')">Danger Zone</button>
        </div>

        <!-- Edit Profile Tab -->
        <div id="edit-profile" class="tab-content active">
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
                        <a href="change-password.php" class="action-btn secondary">Change Password</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Statistics Tab -->
        <div id="statistics" class="tab-content">
            <div class="stats-grid">
                <!-- Profile Overview Card -->
                <div class="card">
                    <h2>Profile Overview</h2>
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
                <div class="card">
                    <h2>Account Statistics</h2>
                    <div class="info-grid">
                        <?php
                        // Count pending requests
                        $pendingSql = "SELECT COUNT(*) as pending_count FROM friends WHERE friend_id = ? AND status = 'pending'";
                        $pendingStmt = $conn->prepare($pendingSql);
                        $pendingStmt->bind_param("i", $user_id);
                        $pendingStmt->execute();
                        $pending_count = $pendingStmt->get_result()->fetch_assoc()['pending_count'];
                        $pendingStmt->close();

                        // Count sent requests
                        $sentSql = "SELECT COUNT(*) as sent_count FROM friends WHERE user_id = ? AND status = 'pending'";
                        $sentStmt = $conn->prepare($sentSql);
                        $sentStmt->bind_param("i", $user_id);
                        $sentStmt->execute();
                        $sent_count = $sentStmt->get_result()->fetch_assoc()['sent_count'];
                        $sentStmt->close();

                        // Count user posts
                        $postSql = "SELECT COUNT(*) as post_count FROM posts WHERE user_id = ?";
                        $postStmt = $conn->prepare($postSql);
                        $postStmt->bind_param("i", $user_id);
                        $postStmt->execute();
                        $post_count = $postStmt->get_result()->fetch_assoc()['post_count'];
                        $postStmt->close();
                        ?>
                        <div class="info-item">
                            <div class="info-label">Total Friends</div>
                            <div class="info-value" style="font-size: 24px; color: #667eea;"><?php echo $friend_count; ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Your Posts</div>
                            <div class="info-value" style="font-size: 24px; color: #9f7aea;"><?php echo $post_count; ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Pending Received</div>
                            <div class="info-value" style="font-size: 24px; color: #ed8936;"><?php echo $pending_count; ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Account Age</div>
                            <div class="info-value" style="font-size: 24px; color: #38a169;">
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
            <div class="card" style="border-left: 4px solid #e53e3e;">
                <h2 style="color: #e53e3e;">Danger Zone</h2>
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
    </div>

    <script src="js/profile.js"></script>
</body>
</html>