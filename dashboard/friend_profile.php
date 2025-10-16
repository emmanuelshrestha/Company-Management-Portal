<?php
session_start();
require __DIR__ . '/../config/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
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
    <title><?php echo htmlspecialchars($friend['name']); ?>'s Profile</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="dashboard-page">
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="welcome-section">
                <h1><?php echo htmlspecialchars($friend['name']); ?>'s Profile</h1>
                <p>View your friend's posts and information</p>
            </div>
            <div class="user-info">
                <?php 
                if (!empty($current_user['profile_picture'])) {
                    echo '<div class="user-avatar" style="background-image: url(../../uploads/profile_pictures/' . htmlspecialchars($current_user['profile_picture']) . ');"></div>';
                } else {
                    echo '<div class="user-avatar">' . strtoupper(substr($current_user['name'] ?? 'U', 0, 1)) . '</div>';
                }
                ?>
                <a href="list_friends.php" class="action-btn secondary">Back to Friends</a>
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
            <a href="../messages/messages.php">Messages</a>
        </div>

        <!-- Friend Profile Header -->
        <div class="profile-header">
            <div class="cover-photo" style="<?php echo !empty($friend['cover_photo']) ? 'background-image: url(../../uploads/cover_photos/' . htmlspecialchars($friend['cover_photo']) . ');' : ''; ?>">
            </div>
            
            <div class="profile-info">
                <div class="profile-avatar-section">
                    <div class="profile-avatar" style="<?php echo !empty($friend['profile_picture']) ? 'background-image: url(../../uploads/profile_pictures/' . htmlspecialchars($friend['profile_picture']) . ');' : ''; ?>">
                        <?php if (empty($friend['profile_picture'])) echo strtoupper(substr($friend['name'] ?? 'U', 0, 1)); ?>
                    </div>
                    
                    <div class="profile-details">
                        <h1><?php echo htmlspecialchars($friend['name']); ?> <span class="friend-indicator">Friend</span></h1>
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
                            <div class="profile-meta-item">📝 <?php echo count($posts); ?> Posts</div>
                            <div class="profile-meta-item">👥 Member since <?php echo date('M Y', strtotime($friend['created_at'])); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Posts Section -->
        <div class="form-section">
            <h3><?php echo htmlspecialchars($friend['name']); ?>'s Posts</h3>
            
            <?php if (empty($posts)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📝</div>
                    <h3>No posts yet</h3>
                    <p><?php echo htmlspecialchars($friend['name']); ?> hasn't created any posts yet.</p>
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

                            <!-- Image Display -->
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

        <!-- Image Modal -->
        <div id="imageModal" class="image-modal">
            <span class="close-modal" onclick="closeImageModal()">&times;</span>
            <img class="modal-content" id="modalImage">
            <div class="modal-caption" id="modalCaption"></div>
        </div>
    </div>

    <script>
        // Image Modal Functions (same as profile.php)
        function openImageModal(img, caption) {
            const modal = document.getElementById('imageModal');
            const modalImg = document.getElementById('modalImage');
            const captionText = document.getElementById('modalCaption');
            
            modal.style.display = 'block';
            modalImg.src = img.src;
            captionText.innerHTML = caption || '';
            document.body.style.overflow = 'hidden';
        }

        function closeImageModal() {
            const modal = document.getElementById('imageModal');
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
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
    </script>
</body>
</html>