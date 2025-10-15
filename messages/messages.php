<?php
session_start();
require __DIR__ . '/../config/db.php';

// CSRF Protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch user details for header
$userSql = "SELECT name, profile_picture FROM users WHERE id = ?";
$userStmt = $conn->prepare($userSql);
$userStmt->bind_param("i", $user_id);
$userStmt->execute();
$userResult = $userStmt->get_result();
$user = $userResult->fetch_assoc();
$userStmt->close();

// Get or create conversation with selected friend
$selected_conversation = null;
$selected_friend = null;
$conversation_id = null;

if (isset($_GET['friend_id'])) {
    $friend_id = intval($_GET['friend_id']);
    
    // Verify friendship
    $friendCheckSql = "SELECT u.id, u.name, u.profile_picture 
                      FROM friends f 
                      JOIN users u ON (f.user_id = ? AND u.id = f.friend_id) OR (f.friend_id = ? AND u.id = f.user_id)
                      WHERE ((f.user_id = ? AND f.friend_id = ?) OR (f.user_id = ? AND f.friend_id = ?)) 
                      AND f.status = 'approved' AND u.id = ?";
    $friendStmt = $conn->prepare($friendCheckSql);
    $friendStmt->bind_param("iiiiiii", $user_id, $user_id, $user_id, $friend_id, $friend_id, $user_id, $friend_id);
    $friendStmt->execute();
    $friendResult = $friendStmt->get_result();
    
    if ($friendResult->num_rows > 0) {
        $selected_friend = $friendResult->fetch_assoc();
        
        // Get or create conversation
        $conversationSql = "SELECT id FROM conversations 
                           WHERE (user1_id = ? AND user2_id = ?) OR (user1_id = ? AND user2_id = ?)";
        $conversationStmt = $conn->prepare($conversationSql);
        $conversationStmt->bind_param("iiii", $user_id, $friend_id, $friend_id, $user_id);
        $conversationStmt->execute();
        $conversationResult = $conversationStmt->get_result();
        
        if ($conversationResult->num_rows > 0) {
            $conversation = $conversationResult->fetch_assoc();
            $conversation_id = $conversation['id'];
        } else {
            // Create new conversation
            $createConversationSql = "INSERT INTO conversations (user1_id, user2_id) VALUES (?, ?)";
            $createStmt = $conn->prepare($createConversationSql);
            $user1_id = min($user_id, $friend_id);
            $user2_id = max($user_id, $friend_id);
            $createStmt->bind_param("ii", $user1_id, $user2_id);
            $createStmt->execute();
            $conversation_id = $createStmt->insert_id;
            $createStmt->close();
        }
        $conversationStmt->close();
    }
    $friendStmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - <?php echo htmlspecialchars($user['name']); ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .messages-container {
            display: flex;
            height: calc(100vh - 200px);
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            border: 1px solid #e2e8f0;
        }

        .conversations-sidebar {
            width: 350px;
            border-right: 1px solid #e2e8f0;
            background: #f8fafc;
            display: flex;
            flex-direction: column;
        }

        .conversations-header {
            padding: 20px;
            border-bottom: 1px solid #e2e8f0;
            background: white;
        }

        .conversations-header h3 {
            margin: 0;
            color: #2d3748;
            font-size: 18px;
        }

        .conversations-list {
            flex: 1;
            overflow-y: auto;
        }

        .conversation-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px 20px;
            border-bottom: 1px solid #e2e8f0;
            cursor: pointer;
            transition: background 0.2s;
            background: white;
        }

        .conversation-item:hover {
            background: #f7fafc;
        }

        .conversation-item.active {
            background: #667eea;
            color: white;
        }

        .conversation-item.active .conversation-name,
        .conversation-item.active .conversation-preview {
            color: white;
        }

        .conversation-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 18px;
            flex-shrink: 0;
        }

        .conversation-info {
            flex: 1;
            min-width: 0;
        }

        .conversation-name {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 4px;
            font-size: 14px;
        }

        .conversation-preview {
            color: #718096;
            font-size: 13px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .conversation-time {
            color: #a0aec0;
            font-size: 12px;
            margin-top: 2px;
        }

        .chat-area {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .chat-header {
            padding: 20px;
            border-bottom: 1px solid #e2e8f0;
            background: white;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .chat-header-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 16px;
        }

        .chat-header-info h3 {
            margin: 0;
            color: #2d3748;
            font-size: 16px;
        }

        .chat-header-info p {
            margin: 0;
            color: #48bb78;
            font-size: 12px;
            font-weight: 500;
        }

        .messages-area {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
            background: #f7fafc;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .message {
            max-width: 70%;
            padding: 12px 16px;
            border-radius: 18px;
            position: relative;
            animation: fadeIn 0.3s ease-in;
        }

        .message.sent {
            align-self: flex-end;
            background: #667eea;
            color: white;
            border-bottom-right-radius: 4px;
        }

        .message.received {
            align-self: flex-start;
            background: white;
            color: #2d3748;
            border: 1px solid #e2e8f0;
            border-bottom-left-radius: 4px;
        }

        .message-time {
            font-size: 11px;
            opacity: 0.7;
            margin-top: 5px;
            text-align: right;
        }

        .message-input-area {
            padding: 20px;
            border-top: 1px solid #e2e8f0;
            background: white;
        }

        .message-input-form {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }

        .message-input {
            flex: 1;
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 24px;
            resize: none;
            font-family: inherit;
            font-size: 14px;
            max-height: 120px;
            min-height: 44px;
        }

        .message-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.1);
        }

        .send-button {
            background: #667eea;
            color: white;
            border: none;
            border-radius: 50%;
            width: 44px;
            height: 44px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
            flex-shrink: 0;
        }

        .send-button:hover {
            background: #5a67d8;
            transform: scale(1.05);
        }

        .send-button:disabled {
            background: #a0aec0;
            cursor: not-allowed;
            transform: none;
        }

        .empty-chat {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            color: #718096;
            text-align: center;
        }

        .empty-chat-icon {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Avatar images */
        .conversation-avatar[style*="background-image"],
        .chat-header-avatar[style*="background-image"] {
            color: transparent !important;
            background-size: cover !important;
            background-position: center !important;
        }

        @media (max-width: 768px) {
            .messages-container {
                height: calc(100vh - 150px);
            }
            
            .conversations-sidebar {
                width: 100%;
                display: <?php echo $selected_friend ? 'none' : 'flex'; ?>;
            }
            
            .chat-area {
                display: flex; /* Always show if friend is selected */
            }
        }
    </style>
</head>
<body class="dashboard-page">
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="welcome-section">
                <h1>Messages</h1>
                <p>Chat with your friends</p>
            </div>
            <div class="user-info">
                <?php 
                if (!empty($user['profile_picture'])) {
                    echo '<div class="user-avatar" style="background-image: url(../../uploads/profile_pictures/' . htmlspecialchars($user['profile_picture']) . ');"></div>';
                } else {
                    echo '<div class="user-avatar">' . strtoupper(substr($user['name'] ?? 'U', 0, 1)) . '</div>';
                }
                ?>
                <a href="../dashboard/dashboard.php" class="action-btn secondary">Dashboard</a>
                <a href="../dashboard/logout.php" class="logout-btn">Logout</a>
            </div>
        </div>

        <!-- Navigation -->
        <div class="nav-links">
            <a href="../dashboard/dashboard.php">Dashboard</a> | 
            <a href="../dashboard/profile.php">Profile</a> | 
            <a href="../dashboard/add_friend.php">Add Friends</a> | 
            <a href="../dashboard/list_friends.php">Friends List</a> | 
            <a href="../dashboard/create_post.php">Create Post</a> | 
            <a href="../dashboard/news_feed.php">News Feed</a> | 
            <a href="messages.php">Messages</a>
        </div>

        <div class="messages-container">
            <!-- Conversations Sidebar -->
            <div class="conversations-sidebar">
                <div class="conversations-header">
                    <h3>Conversations</h3>
                </div>
                <div class="conversations-list" id="conversationsList">
                    <!-- Conversations will be loaded via JavaScript -->
                </div>
            </div>

            <!-- Chat Area -->
            <div class="chat-area">
                <?php if ($selected_friend): ?>
                    <div class="chat-header">
                        <div class="chat-header-avatar" style="<?php echo !empty($selected_friend['profile_picture']) ? 'background-image: url(../../uploads/profile_pictures/' . htmlspecialchars($selected_friend['profile_picture']) . ');' : ''; ?>">
                            <?php if (empty($selected_friend['profile_picture'])) echo strtoupper(substr($selected_friend['name'], 0, 1)); ?>
                        </div>
                        <div class="chat-header-info">
                            <h3><?php echo htmlspecialchars($selected_friend['name']); ?></h3>
                            <p>● Online</p>
                        </div>
                    </div>

                    <div class="messages-area" id="messagesArea">
                        <!-- Debug info -->
                        <div id="debugInfo" style="display: none; background: #ffebee; padding: 10px; margin: 10px; border-radius: 5px;">
                            Debug: Conversation ID: <span id="debugConvId"></span><br>
                            Status: <span id="debugStatus">Loading...</span>
                        </div>
                        <!-- Messages will be loaded via JavaScript -->
                    </div>

                    <div class="message-input-area">
                    <form class="message-input-form" id="messageForm">
                        <input type="hidden" id="conversationId" value="<?php echo $conversation_id; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <textarea 
                                class="message-input" 
                                id="messageInput" 
                                placeholder="Type a message..." 
                                rows="1"
                            ></textarea>
                            <button type="submit" class="send-button" id="sendButton">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <line x1="22" y1="2" x2="11" y2="13"></line>
                                    <polygon points="22,2 15,22 11,13 2,9"></polygon>
                                </svg>
                            </button>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="empty-chat">
                        <div class="empty-chat-icon">💬</div>
                        <h3>Select a conversation</h3>
                        <p>Choose a friend from the list to start chatting</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="js/messages.js"></script>
</body>
</html>