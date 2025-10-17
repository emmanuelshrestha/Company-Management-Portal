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

// Fetch user details for header
$userSql = "SELECT name, profile_picture FROM users WHERE id = ?";
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
                    $stmt->bind_param("isss", $_SESSION['user_id'], $content, $image_filename, $image_caption);
                } else {
                    $sql = "INSERT INTO posts (user_id, content) VALUES (?, ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("is", $_SESSION['user_id'], $content);
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
    <title>Create Post - <?php echo htmlspecialchars($user['name']); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="dashboard-page">
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="welcome-section">
                <h1>Create New Post</h1>
                <p>Share your thoughts and images with your friends</p>
            </div>
            <div class="user-info">
            <?php 
            if (!empty($user['profile_picture'])) {
                echo '<div class="user-avatar" style="background-image: url(../../uploads/profile_pictures/' . htmlspecialchars($user['profile_picture']) . ');"></div>';
            } else {
                echo '<div class="user-avatar">' . strtoupper(substr($user['name'] ?? 'U', 0, 1)) . '</div>';
            }
            ?>
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
            <a href="../messages/messages.php">Messages</a>
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

                <form method="POST" action="" id="postForm" enctype="multipart/form-data">
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
                            <button type="button" class="action-btn secondary" id="previewBtn">Preview</button>
                        </div>
                        <button type="submit" class="btn-post" id="submitBtn" disabled>Post</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        const contentTextarea = document.getElementById('content');
        const charCounter = document.getElementById('charCounter');
        const charCount = document.getElementById('charCount');
        const fileInput = document.getElementById('post_image');
        const imagePreview = document.getElementById('imagePreview');
        const previewImage = document.getElementById('previewImage');
        const removeImageBtn = document.getElementById('removeImage');
        const imageUploadSection = document.getElementById('imageUploadSection');
        const imageCaption = document.getElementById('image_caption');
        const submitBtn = document.getElementById('submitBtn');
        const postForm = document.getElementById('postForm');

        // Character counter
        contentTextarea.addEventListener('input', function() {
            const length = this.value.length;
            charCount.textContent = length;
            
            // Update counter color
            charCounter.className = 'char-counter';
            if (length > 400) {
                charCounter.classList.add('warning');
            }
            if (length > 480) {
                charCounter.classList.add('error');
            }
            
            updateSubmitButton();
        });

        // File input change
        fileInput.addEventListener('change', function(e) {
            if (this.files && this.files[0]) {
                const file = this.files[0];
                
                // Validate file type
                if (!file.type.match('image.*')) {
                    alert('Please select an image file.');
                    this.value = '';
                    return;
                }
                
                // Validate file size (5MB)
                if (file.size > 5 * 1024 * 1024) {
                    alert('Image must be smaller than 5MB.');
                    this.value = '';
                    return;
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewImage.src = e.target.result;
                    imagePreview.style.display = 'block';
                    imageCaption.style.display = 'block';
                }
                reader.readAsDataURL(file);
            }
            updateSubmitButton();
        });

        // Remove image
        removeImageBtn.addEventListener('click', function() {
            fileInput.value = '';
            imagePreview.style.display = 'none';
            imageCaption.style.display = 'none';
            imageCaption.value = '';
            updateSubmitButton();
        });

        // Drag and drop functionality
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            imageUploadSection.addEventListener(eventName, preventDefaults, false);
        });

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        ['dragenter', 'dragover'].forEach(eventName => {
            imageUploadSection.addEventListener(eventName, highlight, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            imageUploadSection.addEventListener(eventName, unhighlight, false);
        });

        function highlight() {
            imageUploadSection.classList.add('dragover');
        }

        function unhighlight() {
            imageUploadSection.classList.remove('dragover');
        }

        imageUploadSection.addEventListener('drop', handleDrop, false);

        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            fileInput.files = files;
            fileInput.dispatchEvent(new Event('change'));
        }

        // Click on upload section to trigger file input
        imageUploadSection.addEventListener('click', function(e) {
            if (e.target !== removeImageBtn && e.target !== fileInput) {
                fileInput.click();
            }
        });

        // Update submit button state
        function updateSubmitButton() {
            const hasContent = contentTextarea.value.trim().length > 0;
            const hasImage = fileInput.files.length > 0;
            submitBtn.disabled = !hasContent && !hasImage;
        }

        // Form submission handling
        postForm.addEventListener('submit', function(e) {
            const content = contentTextarea.value.trim();
            const hasImage = fileInput.files.length > 0;
            
            if (!content && !hasImage) {
                e.preventDefault();
                alert('Please enter some content or select an image to post.');
                return false;
            }
            
            if (content.length > 500) {
                e.preventDefault();
                alert('Post content cannot exceed 500 characters.');
                return false;
            }
            
            // Show loading state
            submitBtn.textContent = 'Posting...';
            submitBtn.disabled = true;
        });

        // Auto-focus textarea
        contentTextarea.focus();
    </script>
</body>
</html>