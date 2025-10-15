<?php
session_start();
require __DIR__ . '/../config/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Get the profile ID from URL
$profile_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$profile_id) {
    header('Location: add_friend.php');
    exit;
}

// Fetch profile details
$profileSql = "SELECT id, name, profile_picture, cover_photo, bio, location, website, created_at, status 
              FROM users WHERE id = ? AND status = 'Verified'";
$profileStmt = $conn->prepare($profileSql);
$profileStmt->bind_param("i", $profile_id);
$profileStmt->execute();
$profileResult = $profileStmt->get_result();

if ($profileResult->num_rows === 0) {
    header('Location: add_friend.php?error=user_not_found');
    exit;
}

$profile_user = $profileResult->fetch_assoc();
$profileStmt->close();

// Check if current user is viewing their own profile
$is_own_profile = ($_SESSION['user_id'] == $profile_id);

// Check friendship status
$friendship_status = 'none';
if (!$is_own_profile) {
    $checkFriendshipSql = "SELECT status FROM friends 
                          WHERE ((user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?))";
    $checkStmt = $conn->prepare($checkFriendshipSql);
    $checkStmt->bind_param("iiii", $_SESSION['user_id'], $profile_id, $profile_id, $_SESSION['user_id']);
    $checkStmt->execute();
    $friendshipResult = $checkStmt->get_result();
    
    if ($friendshipResult->num_rows > 0) {
        $friendship = $friendshipResult->fetch_assoc();
        $friendship_status = $friendship['status']; // 'approved' or 'pending'
    }
    $checkStmt->close();
}

// Fetch current user for header
$userSql = "SELECT name, profile_picture FROM users WHERE id = ?";
$userStmt = $conn->prepare($userSql);
$userStmt->bind_param("i", $_SESSION['user_id']);
$userStmt->execute();
$userResult = $userStmt->get_result();
$current_user = $userResult->fetch_assoc();
$userStmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($profile_user['name']); ?>'s Profile</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Reuse the same CSS from friend_profile.php */
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
        
        .friend-indicator {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 10px;
        }
        
        .friend-status-approved {
            background: #48bb78;
            color: white;
        }
        
        .friend-status-pending {
            background: #ed8936;
            color: white;
        }
        
        .friend-status-none {
            background: #a0aec0;
            color: white;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #718096;
        }
        
        .privacy-notice {
            background: #fffaf0;
            border: 1px solid #feebc8;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
            text-align: center;
        }
    </style>
</head>
<body class="dashboard-page">
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="welcome-section">
                <h1><?php echo htmlspecialchars($profile_user['name']); ?>'s Profile</h1>
                <p>
                    <?php if ($is_own_profile): ?>
                        Your public profile
                    <?php else: ?>
                        View user profile
                    <?php endif; ?>
                </p>
            </div>
            <div class="user-info">
                <?php 
                if (!empty($current_user['profile_picture'])) {
                    echo '<div class="user-avatar" style="background-image: url(../../uploads/profile_pictures/' . htmlspecialchars($current_user['profile_picture']) . ');"></div>';
                } else {
                    echo '<div class="user-avatar">' . strtoupper(substr($current_user['name'] ?? 'U', 0, 1)) . '</div>';
                }
                ?>
                <a href="add_friend.php" class="action-btn secondary">Back to Search</a>
                <a href="dashboard.php" class="action-btn secondary">Dashboard</a>
                <a href="logout.php" class="logout-btn">Logout</a>
            </div>
        </div>

        <!-- Profile Header -->
        <div class="profile-header">
            <div class="cover-photo" style="<?php echo !empty($profile_user['cover_photo']) ? 'background-image: url(../../uploads/cover_photos/' . htmlspecialchars($profile_user['cover_photo']) . ');' : ''; ?>">
            </div>
            
            <div class="profile-info">
                <div class="profile-avatar-section">
                    <div class="profile-avatar" style="<?php echo !empty($profile_user['profile_picture']) ? 'background-image: url(../../uploads/profile_pictures/' . htmlspecialchars($profile_user['profile_picture']) . ');' : ''; ?>">
                        <?php if (empty($profile_user['profile_picture'])) echo strtoupper(substr($profile_user['name'] ?? 'U', 0, 1)); ?>
                    </div>
                    
                    <div class="profile-details">
                        <h1>
                            <?php echo htmlspecialchars($profile_user['name']); ?>
                            <?php if ($is_own_profile): ?>
                                <span class="friend-indicator friend-status-approved">You</span>
                            <?php elseif ($friendship_status === 'approved'): ?>
                                <span class="friend-indicator friend-status-approved">Friend</span>
                            <?php elseif ($friendship_status === 'pending'): ?>
                                <span class="friend-indicator friend-status-pending">Request Sent</span>
                            <?php else: ?>
                                <span class="friend-indicator friend-status-none">Not Friends</span>
                            <?php endif; ?>
                        </h1>
                        
                        <?php if (!empty($profile_user['bio'])): ?>
                            <div class="bio"><?php echo htmlspecialchars($profile_user['bio']); ?></div>
                        <?php endif; ?>
                        
                        <div class="profile-meta">
                            <?php if (!empty($profile_user['location'])): ?>
                                <div class="profile-meta-item">📍 <?php echo htmlspecialchars($profile_user['location']); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($profile_user['website'])): ?>
                                <div class="profile-meta-item">🌐 <a href="<?php echo htmlspecialchars($profile_user['website']); ?>" target="_blank">Website</a></div>
                            <?php endif; ?>
                            <div class="profile-meta-item">👥 Member since <?php echo date('M Y', strtotime($profile_user['created_at'])); ?></div>
                        </div>
                        
                        <?php if (!$is_own_profile && $friendship_status === 'none'): ?>
                            <div style="margin-top: 15px;">
                                <form method="POST" action="add_friend.php" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="send_request" value="1">
                                    <input type="hidden" name="friend_id" value="<?php echo $profile_user['id']; ?>">
                                    <button type="submit" class="action-btn">Send Friend Request</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Privacy Notice for Non-Friends -->
        <?php if (!$is_own_profile && $friendship_status !== 'approved'): ?>
            <div class="privacy-notice">
                <h3>🔒 Limited Profile View</h3>
                <p>You're viewing a limited version of this profile. Become friends to see their posts and full activity.</p>
                <?php if ($friendship_status === 'none'): ?>
                    <p>Send a friend request to connect!</p>
                <?php elseif ($friendship_status === 'pending'): ?>
                    <p>Your friend request is pending approval.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Posts Section (Only show to friends or self) -->
        <?php if ($is_own_profile || $friendship_status === 'approved'): ?>
            <!-- Include posts section from friend_profile.php here -->
            <div class="form-section">
                <h3><?php echo htmlspecialchars($profile_user['name']); ?>'s Posts</h3>
                <div class="empty-state">
                    <div class="empty-state-icon">📝</div>
                    <h3>Posts are private</h3>
                    <p>You need to be friends with <?php echo htmlspecialchars($profile_user['name']); ?> to see their posts.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>