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

// Get the friend ID from URL
$friend_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$friend_id) {
    header('Location: list_friends.php');
    exit;
}

// Check if the user is actually friends with this person
$checkFriendshipSql = "SELECT status FROM friends 
                      WHERE ((user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)) 
                      AND status = 'approved'";
$checkStmt = $conn->prepare($checkFriendshipSql);
$checkStmt->bind_param("iiii", $_SESSION['user_id'], $friend_id, $friend_id, $_SESSION['user_id']);
$checkStmt->execute();
$friendship = $checkStmt->get_result()->fetch_assoc();
$checkStmt->close();

if (!$friendship) {
    header('Location: list_friends.php?error=not_friends');
    exit;
}

// Fetch friend's details
$friendSql = "SELECT id, name, email, profile_picture, cover_photo, bio, location, website, created_at, status 
              FROM users WHERE id = ?";
$friendStmt = $conn->prepare($friendSql);
$friendStmt->bind_param("i", $friend_id);
$friendStmt->execute();
$friendResult = $friendStmt->get_result();

if ($friendResult->num_rows === 0) {
    header('Location: list_friends.php?error=user_not_found');
    exit;
}

$friend = $friendResult->fetch_assoc();
$friendStmt->close();

// Count friend's friends
$friendFriendsSql = "SELECT COUNT(*) as friend_count FROM friends 
                    WHERE (user_id = ? OR friend_id = ?) AND status = 'approved'";
$friendFriendsStmt = $conn->prepare($friendFriendsSql);
$friendFriendsStmt->bind_param("ii", $friend_id, $friend_id);
$friendFriendsStmt->execute();
$friend_friend_count = $friendFriendsStmt->get_result()->fetch_assoc()['friend_count'];
$friendFriendsStmt->close();

// Fetch friend's posts
$postsSql = "SELECT p.*, 
    (SELECT COUNT(*) FROM post_likes WHERE post_id = p.id) as like_count,
    (SELECT COUNT(*) FROM post_comments WHERE post_id = p.id) as comment_count,
    (SELECT COUNT(*) FROM post_likes WHERE post_id = p.id AND user_id = ?) as user_liked
    FROM posts p 
    WHERE p.user_id = ? 
    ORDER BY p.created_at DESC 
    LIMIT 10";
$postsStmt = $conn->prepare($postsSql);
$postsStmt->bind_param("ii", $_SESSION['user_id'], $friend_id);
$postsStmt->execute();
$postsResult = $postsStmt->get_result();
$posts = [];
while ($post = $postsResult->fetch_assoc()) {
    $posts[] = $post;
}
$postsStmt->close();

// Count friend's posts
$friendPostCountSql = "SELECT COUNT(*) as post_count FROM posts WHERE user_id = ?";
$friendPostCountStmt = $conn->prepare($friendPostCountSql);
$friendPostCountStmt->bind_param("i", $friend_id);
$friendPostCountStmt->execute();
$friend_post_count = $friendPostCountStmt->get_result()->fetch_assoc()['post_count'];
$friendPostCountStmt->close();

// Fetch current user for header and sidebar
$current_user_id = $_SESSION['user_id'];
$userSql = "SELECT id, name, profile_picture FROM users WHERE id = ?";
$userStmt = $conn->prepare($userSql);
$userStmt->bind_param("i", $current_user_id);
$userStmt->execute();
$userResult = $userStmt->get_result();
$current_user = $userResult->fetch_assoc();
$userStmt->close();

// Count current user's friends for sidebar
$friendSql = "SELECT COUNT(*) as friend_count FROM friends WHERE (user_id = ? OR friend_id = ?) AND status = 'approved'";
$friendStmt = $conn->prepare($friendSql);
$friendStmt->bind_param("ii", $current_user_id, $current_user_id);
$friendStmt->execute();
$friend_count = $friendStmt->get_result()->fetch_assoc()['friend_count'];
$friendStmt->close();

// Count current user's pending requests for sidebar
$pendingSql = "SELECT COUNT(*) as pending_count FROM friends WHERE friend_id = ? AND status = 'pending'";
$pendingStmt = $conn->prepare($pendingSql);
$pendingStmt->bind_param("i", $current_user_id);
$pendingStmt->execute();
$pending_count = $pendingStmt->get_result()->fetch_assoc()['pending_count'];
$pendingStmt->close();

// Count current user's posts for sidebar
$postCountSql = "SELECT COUNT(*) as post_count FROM posts WHERE user_id = ?";
$postCountStmt = $conn->prepare($postCountSql);
$postCountStmt->bind_param("i", $current_user_id);
$postCountStmt->execute();
$post_count = $postCountStmt->get_result()->fetch_assoc()['post_count'];
$postCountStmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($friend['name']); ?>'s Profile - Manexis</title>
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

        .profile-details {
            flex: 1;
            padding-bottom: 20px;
        }

        .profile-details h1 {
            margin: 0 0 10px 0;
            font-size: 30px;  /* Slightly larger name */
            color: #1a202c;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .friend-indicator {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
        }

        .friend-status-approved {
            background: #c6f6d5;
            color: #276749;
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

        /* Friend actions */
        .friend-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            flex-wrap: wrap;
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

            .friend-actions {
                justify-content: center;
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
            <a href="create_post.php" class="action-btn">Create Post</a>
            <?php 
            if (!empty($current_user['profile_picture'])) {
                echo '<div class="user-avatar" style="background-image: url(../../uploads/profile_pictures/' . htmlspecialchars($current_user['profile_picture']) . ');"></div>';
            } else {
                echo '<div class="user-avatar">' . strtoupper(substr($current_user['name'] ?? 'U', 0, 1)) . '</div>';
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
                if (!empty($current_user['profile_picture'])) {
                    echo '<div class="user-avatar" style="background-image: url(../../uploads/profile_pictures/' . htmlspecialchars($current_user['profile_picture']) . ');"></div>';
                } else {
                    echo '<div class="user-avatar">' . strtoupper(substr($current_user['name'] ?? 'U', 0, 1)) . '</div>';
                }
                ?>
                <div>
                    <div style="font-weight: 600; font-size: 16px; color: #2d3748;"><?php echo htmlspecialchars($current_user['name'] ?? 'User'); ?></div>
                    <div style="font-size: 14px; color: #718096;">@<?php echo htmlspecialchars(strtolower(str_replace(' ', '', $current_user['name'] ?? 'user'))); ?></div>
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
                <a href="list_friends.php" class="sidebar-nav-item active">
                    <div class="nav-icon">👥</div>
                    <span>Friends List</span>
                    <?php if ($friend_count > 0): ?>
                        <span class="friends-count"><?php echo $friend_count; ?></span>
                    <?php endif; ?>
                </a>
                <a href="../messages/messages.php" class="sidebar-nav-item">
                    <div class="nav-icon">💬</div>
                    <span>Messages</span>
                </a>
                <a href="settings.php" class="sidebar-nav-item">
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
            <!-- Enhanced Profile Header -->
            <div class="profile-header">
                <div class="cover-photo" style="<?php echo !empty($friend['cover_photo']) ? 'background-image: url(../../uploads/cover_photos/' . htmlspecialchars($friend['cover_photo']) . ');' : ''; ?>">
                </div>
                
                <div class="profile-info">
                    <div class="profile-avatar-section">
                        <div class="profile-avatar" style="<?php echo !empty($friend['profile_picture']) ? 'background-image: url(../../uploads/profile_pictures/' . htmlspecialchars($friend['profile_picture']) . ');' : ''; ?>">
                            <?php if (empty($friend['profile_picture'])) echo strtoupper(substr($friend['name'] ?? 'U', 0, 1)); ?>
                        </div>
                        
                        <div class="profile-details">
                            <h1>
                                <?php echo htmlspecialchars($friend['name']); ?>
                                <span class="friend-indicator friend-status-approved">Friend</span>
                            </h1>
                            
                            <?php if (!empty($friend['bio'])): ?>
                                <div class="bio"><?php echo htmlspecialchars($friend['bio']); ?></div>
                            <?php endif; ?>
                            
                            <div class="profile-meta">
                                <?php if (!empty($friend['location'])): ?>
                                    <div class="profile-meta-item">📍 <?php echo htmlspecialchars($friend['location']); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($friend['website'])): ?>
                                    <div class="profile-meta-item">🌐 <a href="<?php echo htmlspecialchars($friend['website']); ?>" target="_blank">Website</a></div>
                                <?php endif; ?>
                                <div class="profile-meta-item">👥 <?php echo $friend_friend_count . ' Friends'; ?></div>
                                <div class="profile-meta-item">📝 <?php echo $friend_post_count . ' Posts'; ?></div>
                            </div>
                            
                            <div class="friend-actions">
                                <a href="../messages/messages.php?user_id=<?php echo $friend_id; ?>" class="action-btn">💬 Message</a>
                                <a href="list_friends.php" class="action-btn secondary">👥 All Friends</a>
                                <a href="dashboard.php" class="action-btn secondary">🏠 Dashboard</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Profile Tabs -->
            <div class="profile-tabs">
                <button class="tab-btn active" onclick="switchTab('posts')">Posts</button>
                <button class="tab-btn" onclick="switchTab('about')">About</button>
                <button class="tab-btn" onclick="switchTab('statistics')">Statistics</button>
            </div>

            <!-- Posts Tab -->
            <div id="posts" class="tab-content active">
                <div class="form-section">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h3><?php echo htmlspecialchars($friend['name']); ?>'s Posts</h3>
                        <a href="list_friends.php" class="action-btn secondary">← Back to Friends</a>
                    </div>
                    
                    <?php if (empty($posts)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">📝</div>
                            <h3>No posts yet</h3>
                            <p><?php echo htmlspecialchars($friend['name']); ?> hasn't created any posts yet.</p>
                            <p style="color: #718096; margin-top: 10px;">When they share something, you'll see it here!</p>
                        </div>
                    <?php else: ?>
                        <div class="posts-grid">
                            <?php foreach ($posts as $post): ?>
                                <div class="post-card">
                                    <div class="post-header">
                                        <div class="post-author-avatar" style="<?php echo !empty($friend['profile_picture']) ? 'background-image: url(../../uploads/profile_pictures/' . htmlspecialchars($friend['profile_picture']) . ');' : ''; ?>">
                                            <?php if (empty($friend['profile_picture'])) echo strtoupper(substr($friend['name'] ?? 'U', 0, 1)); ?>
                                        </div>
                                        <div class="post-author-info">
                                            <div class="post-author-name"><?php echo htmlspecialchars($friend['name']); ?></div>
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
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- About Tab -->
            <div id="about" class="tab-content">
                <div class="form-section">
                    <h3>About <?php echo htmlspecialchars($friend['name']); ?></h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Full Name</div>
                            <div class="info-value"><?php echo htmlspecialchars($friend['name']); ?></div>
                        </div>
                        <?php if (!empty($friend['location'])): ?>
                        <div class="info-item">
                            <div class="info-label">Location</div>
                            <div class="info-value"><?php echo htmlspecialchars($friend['location']); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($friend['website'])): ?>
                        <div class="info-item">
                            <div class="info-label">Website</div>
                            <div class="info-value"><a href="<?php echo htmlspecialchars($friend['website']); ?>" target="_blank"><?php echo htmlspecialchars($friend['website']); ?></a></div>
                        </div>
                        <?php endif; ?>
                        <div class="info-item">
                            <div class="info-label">Member Since</div>
                            <div class="info-value"><?php echo date('F j, Y', strtotime($friend['created_at'])); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Account Status</div>
                            <div class="info-value">
                                <span class="status-badge status-verified">Verified</span>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!empty($friend['bio'])): ?>
                    <div style="margin-top: 20px;">
                        <h4 style="margin-bottom: 10px; color: #4a5568;">Bio</h4>
                        <div style="background: #f8fafc; padding: 15px; border-radius: 8px; line-height: 1.6;">
                            <?php echo nl2br(htmlspecialchars($friend['bio'])); ?>
                        </div>
                    </div>
                    <?php endif; ?>
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
                                <div class="info-value">#<?php echo htmlspecialchars($friend['id'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Account Status</div>
                                <div class="info-value">
                                    <span class="status-badge status-verified">Verified</span>
                                </div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Member Since</div>
                                <div class="info-value"><?php echo date('F j, Y', strtotime($friend['created_at'])); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Friendship Status</div>
                                <div class="info-value">
                                    <span style="color: #276749;">✓ Friends</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Account Statistics Card -->
                    <div class="form-section">
                        <h3>Account Statistics</h3>
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">Total Friends</div>
                                <div class="info-value" style="font-size: 24px; color: #3498db;"><?php echo $friend_friend_count; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Total Posts</div>
                                <div class="info-value" style="font-size: 24px; color: #3498db;"><?php echo $friend_post_count; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Account Age</div>
                                <div class="info-value" style="font-size: 24px; color: #3498db;">
                                    <?php 
                                    $accountAge = floor((time() - strtotime($friend['created_at'])) / (60 * 60 * 24));
                                    echo $accountAge . ' days';
                                    ?>
                                </div>
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
                <h3>Friend Stats</h3>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <div class="quick-stats-item">
                        <span style="color: #718096; font-size: 14px;">👥 Friends</span>
                        <span style="font-weight: 600; color: #2d3748;"><?php echo $friend_friend_count; ?></span>
                    </div>
                    <div class="quick-stats-item">
                        <span style="color: #718096; font-size: 14px;">📝 Posts</span>
                        <span style="font-weight: 600; color: #2d3748;"><?php echo $friend_post_count; ?></span>
                    </div>
                    <div class="quick-stats-item">
                        <span style="color: #718096; font-size: 14px;">✉️ Status</span>
                        <span style="font-weight: 600; color: #48bb78;">Verified</span>
                    </div>
                    <div class="quick-stats-item">
                        <span style="color: #718096; font-size: 14px;">📅 Member Since</span>
                        <span style="font-weight: 600; color: #2d3748;"><?php echo date('M Y', strtotime($friend['created_at'])); ?></span>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="sidebar-card">
                <h3>Quick Actions</h3>
                <div style="display: flex; flex-direction: column; gap: 10px;">
                    <a href="../messages/messages.php?user_id=<?php echo $friend_id; ?>" style="display: flex; align-items: center; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 8px; text-decoration: none; color: #2d3748; transition: background 0.2s;">
                        <span>💬</span>
                        <span>Send Message</span>
                    </a>
                    <a href="list_friends.php" style="display: flex; align-items: center; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 8px; text-decoration: none; color: #2d3748; transition: background 0.2s;">
                        <span>👥</span>
                        <span>All Friends</span>
                    </a>
                    <a href="dashboard.php" style="display: flex; align-items: center; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 8px; text-decoration: none; color: #2d3748; transition: background 0.2s;">
                        <span>🏠</span>
                        <span>Dashboard</span>
                    </a>
                    <a href="create_post.php" style="display: flex; align-items: center; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 8px; text-decoration: none; color: #2d3748; transition: background 0.2s;">
                        <span>✏️</span>
                        <span>Create Post</span>
                    </a>
                </div>
            </div>

            <!-- Friendship Info -->
            <div class="sidebar-card">
                <h3>Friendship Info</h3>
                <div style="font-size: 13px; color: #718096; line-height: 1.6;">
                    <p style="margin: 0 0 10px 0; color: #276749;">✅ You are friends</p>
                    <p style="margin: 0;">You can see each other's posts and activities.</p>
                    <div style="margin-top: 15px; padding: 10px; background: #f0fff4; border-radius: 6px;">
                        <p style="margin: 0; font-size: 12px; color: #276749;">
                            <strong>Connected:</strong> Recently
                        </p>
                    </div>
                </div>
            </div>
        </aside>
    </div>

    <!-- Image Modal -->
    <div id="imageModal" class="image-modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.9);">
        <span class="close-modal" onclick="closeImageModal()" style="position: absolute; top: 15px; right: 35px; color: #f1f1f1; font-size: 40px; font-weight: bold; cursor: pointer;">&times;</span>
        <img class="modal-content" id="modalImage" style="margin: auto; display: block; width: 80%; max-width: 700px; margin-top: 40px;">
        <div class="modal-caption" id="modalCaption" style="text-align: center; color: #ccc; padding: 10px 20px; height: 150px;"></div>
    </div>

    <script src="js/friend_profile.js"></script>
</body>
</html>