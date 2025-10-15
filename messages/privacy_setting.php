<?php
session_start();
require __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$message = "";
$msgClass = "";

// Fetch current user privacy settings
$userSql = "SELECT privacy_level FROM users WHERE id = ?";
$userStmt = $conn->prepare($userSql);
$userStmt->bind_param("i", $user_id);
$userStmt->execute();
$userResult = $userStmt->get_result();
$current_settings = $userResult->fetch_assoc();
$userStmt->close();

// Handle privacy settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_privacy'])) {
    $privacy_level = $_POST['privacy_level'] ?? 'friends';
    $default_post_visibility = $_POST['default_post_visibility'] ?? 'friends';
    
    // Validate inputs
    $allowed_privacy = ['public', 'friends', 'private'];
    $allowed_visibility = ['public', 'friends', 'only_me'];
    
    if (!in_array($privacy_level, $allowed_privacy) || !in_array($default_post_visibility, $allowed_visibility)) {
        $message = "Invalid privacy settings.";
        $msgClass = "msg-error";
    } else {
        // Update user privacy settings
        $updateSql = "UPDATE users SET privacy_level = ? WHERE id = ?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->bind_param("si", $privacy_level, $user_id);
        
        if ($updateStmt->execute()) {
            $message = "Privacy settings updated successfully!";
            $msgClass = "msg-success";
            
            // Update current settings
            $current_settings['privacy_level'] = $privacy_level;
        } else {
            $message = "Error updating privacy settings.";
            $msgClass = "msg-error";
        }
        $updateStmt->close();
    }
}

// Fetch user details for header
$userHeaderSql = "SELECT name, profile_picture FROM users WHERE id = ?";
$userHeaderStmt = $conn->prepare($userHeaderSql);
$userHeaderStmt->bind_param("i", $user_id);
$userHeaderStmt->execute();
$userHeaderResult = $userHeaderStmt->get_result();
$user = $userHeaderResult->fetch_assoc();
$userHeaderStmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Settings - <?php echo htmlspecialchars($user['name']); ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .privacy-option {
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .privacy-option:hover {
            border-color: #cbd5e0;
        }
        
        .privacy-option.selected {
            border-color: #667eea;
            background: #f7fafc;
        }
        
        .privacy-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 10px;
        }
        
        .privacy-icon {
            font-size: 24px;
            width: 40px;
            text-align: center;
        }
        
        .privacy-title {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 5px;
        }
        
        .privacy-description {
            color: #718096;
            font-size: 14px;
            line-height: 1.5;
        }
        
        .privacy-radio {
            display: none;
        }
        
        .settings-section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            margin-bottom: 20px;
            border: 1px solid #e2e8f0;
        }
        
        .settings-section h3 {
            margin: 0 0 20px 0;
            color: #2d3748;
            font-size: 18px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f7fafc;
        }
    </style>
</head>
<body class="dashboard-page">
    <div class="container">
        <!-- Header & Navigation (same as other pages) -->
        
        <!-- Messages -->
        <?php if (!empty($message)): ?>
            <div class="message <?php echo $msgClass; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class="settings-section">
            <h3>🔒 Profile Privacy</h3>
            <p style="color: #718096; margin-bottom: 20px;">Control who can see your profile information</p>
            
            <form method="POST" action="">
                <input type="hidden" name="update_privacy" value="1">
                
                <label class="privacy-option <?php echo $current_settings['privacy_level'] === 'public' ? 'selected' : ''; ?>">
                    <input type="radio" name="privacy_level" value="public" class="privacy-radio" <?php echo $current_settings['privacy_level'] === 'public' ? 'checked' : ''; ?>>
                    <div class="privacy-header">
                        <div class="privacy-icon">🌎</div>
                        <div>
                            <div class="privacy-title">Public</div>
                            <div class="privacy-description">Anyone can see your profile and posts</div>
                        </div>
                    </div>
                </label>
                
                <label class="privacy-option <?php echo $current_settings['privacy_level'] === 'friends' ? 'selected' : ''; ?>">
                    <input type="radio" name="privacy_level" value="friends" class="privacy-radio" <?php echo $current_settings['privacy_level'] === 'friends' ? 'checked' : ''; ?>>
                    <div class="privacy-header">
                        <div class="privacy-icon">👥</div>
                        <div>
                            <div class="privacy-title">Friends Only</div>
                            <div class="privacy-description">Only your approved friends can see your profile and posts</div>
                        </div>
                    </div>
                </label>
                
                <label class="privacy-option <?php echo $current_settings['privacy_level'] === 'private' ? 'selected' : ''; ?>">
                    <input type="radio" name="privacy_level" value="private" class="privacy-radio" <?php echo $current_settings['privacy_level'] === 'private' ? 'checked' : ''; ?>>
                    <div class="privacy-header">
                        <div class="privacy-icon">🔐</div>
                        <div>
                            <div class="privacy-title">Private</div>
                            <div class="privacy-description">Only you can see your profile and posts</div>
                        </div>
                    </div>
                </label>
                
                <div style="margin-top: 25px;">
                    <button type="submit" class="action-btn">Save Privacy Settings</button>
                    <a href="profile.php" class="action-btn secondary">Cancel</a>
                </div>
            </form>
        </div>

        <div class="settings-section">
            <h3>📝 Post Visibility Default</h3>
            <p style="color: #718096; margin-bottom: 20px;">Set default visibility for new posts (can be changed per post)</p>
            
            <!-- Similar radio options for post visibility -->
        </div>
    </div>

    <script>
        // Auto-select privacy option when clicked
        document.querySelectorAll('.privacy-option').forEach(option => {
            option.addEventListener('click', function() {
                // Remove selected class from all options
                document.querySelectorAll('.privacy-option').forEach(opt => {
                    opt.classList.remove('selected');
                });
                // Add selected class to clicked option
                this.classList.add('selected');
                // Check the radio button
                this.querySelector('input[type="radio"]').checked = true;
            });
        });
    </script>
</body>
</html>