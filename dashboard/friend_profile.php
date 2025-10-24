<?php
session_start();
require __DIR__ . '/../config/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../modules/user/login.php');
    exit;
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
$friendSql = "SELECT id, name, email, profile_picture, cover_photo, bio, location, website, created_at 
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

// Fetch friend's posts (only show to friends)
$postsSql = "SELECT p.*, 
    (SELECT COUNT(*) FROM post_likes WHERE post_id = p.id) as like_count,
    (SELECT COUNT(*) FROM post_comments WHERE post_id = p.id) as comment_count
    FROM posts p 
    WHERE p.user_id = ? 
    ORDER BY p.created_at DESC 
    LIMIT 20";
$postsStmt = $conn->prepare($postsSql);
$postsStmt->bind_param("i", $friend_id);
$postsStmt->execute();
$postsResult = $postsStmt->get_result();
$posts = [];
while ($post = $postsResult->fetch_assoc()) {
    $posts[] = $post;
}
$postsStmt->close();

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
    <title><?php echo htmlspecialchars($friend['name']); ?>'s Profile - Manexis</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        /* Friend Profile Specific Styles */
        .friend-profile-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .friend-profile-header {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
            overflow: hidden;
        }

        .friend-cover-section {
            position: relative;
            height: 300px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            background-size: cover;
            background-position: center;
        }

        .friend-profile-info {
            padding: 0 40px 30px;
            position: relative;
        }

        .friend-avatar-section {
            display: flex;
            align-items: flex-end;
            gap: 30px;
            margin-top: -75px;
            margin-bottom: 20px;
        }

        .friend-avatar-large {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            border: 5px solid white;
            background: #667eea;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 48px;
            font-weight: bold;
            background-size: cover;
            background-position: center;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .friend-details h1 {
            margin: 0 0 10px 0;
            color: #2d3748;
            font-size: 32px;
            font-weight: 700;
        }

        .friend-bio {
            color: #718096;
            font-size: 16px;
            line-height: 1.5;
            margin-bottom: 15px;
            max-width: 600px;
        }

        .friend-meta {
            display: flex;
            gap: 25px;
            flex-wrap: wrap;
        }

        .friend-meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #718096;
            font-size: 14px;
        }

        .friend-meta-item a {
            color: #667eea;
            text-decoration: none;
        }

        .friend-meta-item a:hover {
            text-decoration: underline;
        }

        .friend-indicator-badge {
            background: #48bb78;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 10px;
            vertical-align: middle;
        }

        /* Friend Profile Content */
        .friend-profile-content {
            display: grid;
            grid-template-columns: 1fr 350px;
            gap: 30px;
            align-items: start;
        }

        .friend-posts-section {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }

        .friend-sidebar {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .friend-sidebar-widget {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }

        .friend-sidebar-widget h3 {
            margin: 0 0 20px 0;
            color: #2d3748;
            font-size: 18px;
            font-weight: 600;
            border-bottom: 2px solid #f7fafc;
            padding-bottom: 10px;
        }

        /* Friend Stats */
        .friend-stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .friend-stat-item {
            text-align: center;
            padding: 15px;
            background: #f8fafc;
            border-radius: 8px;
        }

        .friend-stat-number {
            font-size: 24px;
            font-weight: 700;
            color: #667eea;
            display: block;
        }

        .friend-stat-label {
            font-size: 12px;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Friend Actions */
        .friend-actions-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .friend-action-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            background: #f8fafc;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            color: #4a5568;
            font-size: 14px;
            width: 100%;
            text-align: left;
        }

        .friend-action-btn:hover {
            background: #edf2f7;
            transform: translateX(5px);
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .friend-profile-content {
                grid-template-columns: 1fr;
            }
            
            .friend-sidebar {
                order: -1;
            }
        }

        @media (max-width: 768px) {
            .friend-profile-container {
                padding: 15px;
            }
            
            .friend-avatar-section {
                flex-direction: column;
                align-items: center;
                text-align: center;
                gap: 20px;
            }
            
            .friend-details {
                text-align: center;
            }
            
            .friend-meta {
                justify-content: center;
            }
            
            .friend-cover-section {
                height: 200px;
            }
            
            .friend-avatar-large {
                width: 120px;
                height: 120px;
                margin-top: 0;
            }
            
            .friend-profile-info {
                padding: 0 20px 20px;
            }
            
            .friend-posts-section {
                padding: 20px;
            }
        }

        @media (max-width: 480px) {
            .friend-stats-grid {
                grid-template-columns: 1fr;
            }
            
            .friend-meta {
                flex-direction: column;
                gap: 10px;
                align-items: center;
            }
        }

        /* Top Navigation Styles (same as dashboard) */
        .top-header-bar {
            background: #4a4a4a;
            height: 40px;
            width: 100%;
        }

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
            color: #333;
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
            background: #f0f2f5;
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
        }
    </style>
</head>
<body class="dashboard-page">
    <!-- Top Navigation -->
    <div class="top-header-bar"></div>

    <div class="main-header">
        <h1 class="logo-text">Manexis</h1>
        <div class="search-bar-container">
            <span class="search-icon">🔍</span>
            <input type="text" placeholder="Search for friends, posts, and more...">
        </div>
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

    <!-- Main Friend Profile Content -->
    <div class="friend-profile-container">
        <!-- Friend Profile Header -->
        <div class="friend-profile-header">
            <div class="friend-cover-section" style="<?php echo !empty($friend['cover_photo']) ? 'background-image: url(../../uploads/cover_photos/' . htmlspecialchars($friend['cover_photo']) . ');' : ''; ?>">
            </div>
            
            <div class="friend-profile-info">
                <div class="friend-avatar-section">
                    <div class="friend-avatar-large" style="<?php echo !empty($friend['profile_picture']) ? 'background-image: url(../../uploads/profile_pictures/' . htmlspecialchars($friend['profile_picture']) . ');' : ''; ?>">
                        <?php if (empty($friend['profile_picture'])) echo strtoupper(substr($friend['name'] ?? 'U', 0, 1)); ?>
                    </div>
                    
                    <div class="friend-details">
                        <h1>
                            <?php echo htmlspecialchars($friend['name']); ?>
                            <span class="friend-indicator-badge">Friend</span>
                        </h1>
                        <?php if (!empty($friend['bio'])): ?>
                            <div class="friend-bio"><?php echo htmlspecialchars($friend['bio']); ?></div>
                        <?php endif; ?>
                        <div class="friend-meta">
                            <?php if (!empty($friend['location'])): ?>
                                <div class="friend-meta-item">📍 <?php echo htmlspecialchars($friend['location']); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($friend['website'])): ?>
                                <div class="friend-meta-item">🌐 <a href="<?php echo htmlspecialchars($friend['website']); ?>" target="_blank">Website</a></div>
                            <?php endif; ?>
                            <div class="friend-meta-item">👥 <?php echo $friend_friend_count; ?> Friends</div>
                            <div class="friend-meta-item">📝 <?php echo count($posts); ?> Posts</div>
                            <div class="friend-meta-item">📅 Member since <?php echo date('M Y', strtotime($friend['created_at'])); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Friend Profile Content -->
        <div class="friend-profile-content">
            <!-- Main Posts Section -->
            <div class="friend-posts-section">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
                    <h2 style="margin: 0; color: #2d3748; font-size: 24px;">
                        <?php echo htmlspecialchars($friend['name']); ?>'s Posts
                    </h2>
                    <div style="display: flex; gap: 10px;">
                        <a href="list_friends.php" class="action-btn secondary btn-small">← Back to Friends</a>
                        <a href="dashboard.php" class="action-btn secondary btn-small">Dashboard</a>
                    </div>
                </div>
                
                <?php if (empty($posts)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📝</div>
                        <h3>No posts yet</h3>
                        <p><?php echo htmlspecialchars($friend['name']); ?> hasn't created any posts yet.</p>
                        <p style="color: #718096; margin-top: 10px;">When they share something, you'll see it here!</p>
                    </div>
                <?php else: ?>
                    <div class="posts-feed">
                        <?php foreach ($posts as $post): ?>
                            <div class="post-card">
                                <div class="post-header">
                                    <div class="post-author">
                                        <?php 
                                        if (!empty($friend['profile_picture'])) {
                                            echo '<div class="author-avatar" style="background-image: url(../../uploads/profile_pictures/' . htmlspecialchars($friend['profile_picture']) . '); background-size: cover; background-position: center;"></div>';
                                        } else {
                                            echo '<div class="author-avatar">' . strtoupper(substr($friend['name'], 0, 1)) . '</div>';
                                        }
                                        ?>
                                        <div class="author-info">
                                            <h3><?php echo htmlspecialchars($friend['name']); ?></h3>
                                            <p>Friend</p>
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

                                <?php if (!empty($post['image_filename'])): ?>
                                    <div class="post-image-full">
                                        <img src="../../uploads/posts/<?php echo htmlspecialchars($post['image_filename']); ?>" 
                                            alt="<?php echo !empty($post['image_caption']) ? htmlspecialchars($post['image_caption']) : 'Post image'; ?>"
                                            class="post-image-auto"
                                            onclick="openImageModal(this, '<?php echo !empty($post['image_caption']) ? addslashes(htmlspecialchars($post['image_caption'])) : ''; ?>')">
                                        <?php if (!empty($post['image_caption'])): ?>
                                            <div class="image-caption-full"><?php echo htmlspecialchars($post['image_caption']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="post-stats">
                                    <span class="like-count"><?php echo $post['like_count']; ?> likes</span>
                                    <span class="comment-count"><?php echo $post['comment_count']; ?> comments</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Friend Sidebar -->
            <div class="friend-sidebar">
                <!-- Friend Stats -->
                <div class="friend-sidebar-widget">
                    <h3>Friend Stats</h3>
                    <div class="friend-stats-grid">
                        <div class="friend-stat-item">
                            <span class="friend-stat-number"><?php echo $friend_friend_count; ?></span>
                            <span class="friend-stat-label">Friends</span>
                        </div>
                        <div class="friend-stat-item">
                            <span class="friend-stat-number"><?php echo count($posts); ?></span>
                            <span class="friend-stat-label">Posts</span>
                        </div>
                        <div class="friend-stat-item">
                            <span class="friend-stat-number">
                                <?php 
                                $accountAge = floor((time() - strtotime($friend['created_at'])) / (60 * 60 * 24));
                                echo $accountAge;
                                ?>
                            </span>
                            <span class="friend-stat-label">Days</span>
                        </div>
                        <div class="friend-stat-item">
                            <span class="friend-stat-number">👥</span>
                            <span class="friend-stat-label">Active</span>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="friend-sidebar-widget">
                    <h3>Quick Actions</h3>
                    <div class="friend-actions-list">
                        <a href="../messages/messages.php?user_id=<?php echo $friend_id; ?>" class="friend-action-btn">
                            <span>💬</span>
                            <span>Send Message</span>
                        </a>
                        <a href="list_friends.php" class="friend-action-btn">
                            <span>👥</span>
                            <span>All Friends</span>
                        </a>
                        <a href="dashboard.php" class="friend-action-btn">
                            <span>🏠</span>
                            <span>Dashboard</span>
                        </a>
                        <a href="create_post.php" class="friend-action-btn">
                            <span>✏️</span>
                            <span>Create Post</span>
                        </a>
                    </div>
                </div>

                <!-- Friendship Info -->
                <div class="friend-sidebar-widget">
                    <h3>Friendship</h3>
                    <div style="font-size: 14px; color: #718096; line-height: 1.6;">
                        <p style="margin: 0 0 10px 0;">
                            <strong>Connected since:</strong><br>
                            <?php 
                            // This would need to be stored in your friends table
                            echo 'Recently'; // Placeholder - you'd need to store friendship date
                            ?>
                        </p>
                        <p style="margin: 0;">
                            <strong>Status:</strong><br>
                            <span style="color: #48bb78;">✓ Friends</span>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Image Modal -->
    <div id="imageModal" class="image-modal">
        <span class="close-modal" onclick="closeImageModal()">&times;</span>
        <img class="modal-content" id="modalImage">
        <div class="modal-caption" id="modalCaption"></div>
    </div>

    <script>
        // Image Modal Functions
        function openImageModal(imgElement, caption) {
            const modal = document.getElementById('imageModal');
            const modalImg = document.getElementById('modalImage');
            const captionText = document.getElementById('modalCaption');
            
            if (!modal || !modalImg) return;
            
            modal.style.display = 'block';
            modalImg.src = imgElement.src;
            captionText.innerHTML = caption || '';
            document.body.style.overflow = 'hidden';
        }

        function closeImageModal() {
            const modal = document.getElementById('imageModal');
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        }

        // Close modal when clicking outside the image
        document.getElementById('imageModal')?.addEventListener('click', function(e) {
            if (e.target === this) {
                closeImageModal();
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeImageModal();
            }
        });

        // Make all post images clickable
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.post-image-auto').forEach(img => {
                img.style.cursor = 'pointer';
            });
        });
    </script>
</body>
</html>