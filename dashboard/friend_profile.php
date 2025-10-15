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
    <link rel="stylesheet" href="style.css">
    <style>
        /* Reuse the same CSS from profile.php */
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
            background: #48bb78;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        /* Rest of the CSS from profile.php for posts grid, etc. */
        .posts-grid {
            display: grid;
            gap: 20px;
            margin-top: 20px;
        }

        .post-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            border: 1px solid #e2e8f0;
        }

        .post-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }

        .post-author-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 16px;
            background-size: cover;
            background-position: center;
        }

        .post-content {
            color: #4a5568;
            line-height: 1.6;
            margin-bottom: 15px;
            white-space: pre-wrap;
        }

        .post-stats {
            display: flex;
            gap: 20px;
            color: #718096;
            font-size: 14px;
            border-top: 1px solid #e2e8f0;
            padding-top: 15px;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #718096;
        }

        /* Post Images & Modal - same as profile.php */
        .post-image-full {
            margin: 15px 0;
            border-radius: 12px;
            overflow: hidden;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
        }

        .post-image-auto {
            width: 100%;
            height: auto;
            display: block;
            object-fit: contain;
            cursor: zoom-in;
            transition: transform 0.2s ease;
        }

        .image-caption-full {
            padding: 12px 16px;
            color: #65676b;
            font-size: 14px;
            text-align: left;
            border-top: 1px solid #e2e8f0;
            background: white;
            line-height: 1.4;
        }

        /* Image Modal - same as profile.php */
        .image-modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.9);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            position: relative;
            margin: auto;
            display: block;
            width: auto;
            max-width: 90%;
            max-height: 90%;
            top: 50%;
            transform: translateY(-50%);
            border-radius: 12px;
            animation: zoom 0.3s;
        }

        @keyframes zoom {
            from { transform: translateY(-50%) scale(0.9); opacity: 0; }
            to { transform: translateY(-50%) scale(1); opacity: 1; }
        }

        .modal-caption {
            text-align: center;
            color: white;
            padding: 15px;
            font-size: 16px;
            position: absolute;
            bottom: 0;
            width: 100%;
            background: linear-gradient(transparent, rgba(0,0,0,0.8));
            border-bottom-left-radius: 12px;
            border-bottom-right-radius: 12px;
        }

        .close-modal {
            position: absolute;
            top: 20px;
            right: 35px;
            color: white;
            font-size: 40px;
            font-weight: bold;
            cursor: pointer;
            z-index: 1001;
            background: rgba(0,0,0,0.5);
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.3s;
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