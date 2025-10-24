<?php
session_start();
require __DIR__ . '/../config/db.php';

// CSRF Protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../modules/user/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch user details for header - INCLUDING STATUS FIELD
$userSql = "SELECT name, profile_picture, status, created_at FROM users WHERE id = ?";
$userStmt = $conn->prepare($userSql);
$userStmt->bind_param("i", $user_id);
$userStmt->execute();
$userResult = $userStmt->get_result();
$user = $userResult->fetch_assoc();
$userStmt->close();

// Count friends for sidebar
$friendSql = "SELECT COUNT(*) as friend_count FROM friends WHERE (user_id = ? OR friend_id = ?) AND status = 'approved'";
$friendStmt = $conn->prepare($friendSql);
$friendStmt->bind_param("ii", $user_id, $user_id);
$friendStmt->execute();
$friend_count = $friendStmt->get_result()->fetch_assoc()['friend_count'];
$friendStmt->close();

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
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        /* Dashboard-style layout */
        body {
            margin: 0;
            padding: 0;
            background: #f5f5f5;
        }

        /* Main header with logo and search */
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

        .search-bar-container input,
        .search-input {
            width: 100%;
            padding: 12px 20px 12px 45px;
            border: none;
            background: #f0f2f5;
            border-radius: 25px;
            font-size: 14px;
            margin-bottom: 0;
            transition: all 0.2s ease;
            box-sizing: border-box;
        }

        /* search variant used inside conversations area */
        .search-input {
            padding: 12px 15px 12px 40px;
            border: 1px solid #e2e8f0;
            background: #f7fafc;
        }

        .search-input:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .search-icon,
        .search-input-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #666;
        }

        /* Header right */
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
            box-sizing: border-box;
        }

        /* Left sidebar */
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
            background: #f5f5f5;
        }

        .sidebar-nav-item.active {
            border-left: 4px solid #6c5ce7;
            background: #f5f5f5;
            color: #6c5ce7;
        }

        .nav-icon {
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        /* Main content area - EXPANDED */
        .main-content {
            flex: 1;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            min-height: calc(100vh - 150px);
        }

        /* Messages-specific styles */
        .messages-container {
            display: flex;
            height: 100%;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            flex: 1;
            min-height: 0;
        }

        /* ---------- Conversations sidebar (merged & deduped) ---------- */
        /* Expanded conversations sidebar with fixed height */
        .conversations-sidebar {
            width: 400px;
            border-right: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            background: white;
            min-height: 0; /* Important for flexbox scrolling */
        }

        .conversations-header {
            padding: 25px;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
            flex-shrink: 0; /* Prevent header from shrinking */
        }

        .conversations-header h3 {
            margin: 0;
            color: #1a202c;
            font-size: 20px;
            font-weight: 600;
        }

        .conversations-search {
            padding: 15px 25px;
            border-bottom: 1px solid #e2e8f0;
            background: white;
            flex-shrink: 0;
        }

        .search-input-container {
            position: relative;
        }

        /* Conversation list: using enhanced scrollbar + fixed max height + flexbox scrolling */
        .conversations-list {
            flex: 1;
            overflow-y: auto;
            padding: 10px 0;
            min-height: 0; /* Important for flexbox scrolling */
            max-height: calc(100vh - 200px); /* Fixed height for conversation list */
        }

        /* Enhanced scrollbar for conversations list */
        .conversations-list::-webkit-scrollbar {
            width: 6px;
        }

        .conversations-list::-webkit-scrollbar-track {
            background: #f8fafc;
            border-radius: 3px;
        }

        .conversations-list::-webkit-scrollbar-thumb {
            background: #cbd5e0;
            border-radius: 3px;
        }

        .conversations-list::-webkit-scrollbar-thumb:hover {
            background: #a0aec0;
        }

        /* Conversation item */
        .conversation-item {
            display: flex;
            align-items: center;
            padding: 18px 25px;
            cursor: pointer;
            transition: all 0.2s ease;
            border-bottom: 1px solid #f7fafc;
            text-decoration: none;
            color: inherit;
            flex-shrink: 0; /* Prevent items from shrinking */
        }

        .conversation-item:hover {
            background: #f8fafc;
            transform: translateX(2px);
        }

        .conversation-item.active {
            background: #667eea;
            color: white;
            border-left: 4px solid #5a67d8;
        }

        /* Avatar */
        .conversation-avatar {
            width: 55px;
            height: 55px;
            border-radius: 50%;
            background: #667eea;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            margin-right: 18px;
            background-size: cover;
            background-position: center;
            font-size: 18px;
            flex-shrink: 0; /* Prevent avatar from shrinking */
            border: 3px solid transparent;
            transition: border-color 0.2s ease;
        }

        .conversation-item.active .conversation-avatar {
            border-color: rgba(255, 255, 255, 0.3);
        }

        .conversation-item:hover .conversation-avatar {
            border-color: #e2e8f0;
        }

        .conversation-info {
            flex: 1;
            min-width: 0; /* Allow text truncation */
        }

        .conversation-name {
            font-weight: 600;
            margin-bottom: 6px;
            font-size: 16px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .conversation-preview {
            font-size: 14px;
            color: #718096;
            opacity: 0.8;
            line-height: 1.4;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .conversation-item.active .conversation-preview {
            color: rgba(255, 255, 255, 0.8);
        }

        .conversation-time {
            font-size: 12px;
            color: #a0aec0;
            margin-top: 4px;
            font-weight: 500;
        }

        .conversation-item.active .conversation-time {
            color: rgba(255, 255, 255, 0.7);
        }

        /* Unread indicator */
        .conversation-unread {
            position: relative;
        }

        .conversation-unread::after {
            content: '';
            position: absolute;
            top: 50%;
            right: 20px;
            width: 8px;
            height: 8px;
            background: #e53e3e;
            border-radius: 50%;
            transform: translateY(-50%);
        }

        .conversation-unread-count {
            position: absolute;
            top: 50%;
            right: 20px;
            background: #e53e3e;
            color: white;
            border-radius: 12px;
            padding: 2px 8px;
            font-size: 11px;
            font-weight: 600;
            transform: translateY(-50%);
            min-width: 20px;
            text-align: center;
        }

        .conversation-item.active .conversation-unread-count {
            background: rgba(255, 255, 255, 0.9);
            color: #e53e3e;
        }

        /* Empty / loading states for conversations */
        .conversations-empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 60px 40px;
            text-align: center;
            color: #718096;
        }

        .conversations-empty-icon {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .conversations-empty h3 {
            margin: 0 0 10px 0;
            color: #4a5568;
            font-weight: 500;
        }

        .conversations-empty p {
            margin: 0 0 20px 0;
            opacity: 0.8;
            line-height: 1.5;
        }

        .conversations-loading {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px;
            color: #718096;
        }

        .conversations-loading-spinner {
            width: 24px;
            height: 24px;
            border: 2px solid #e2e8f0;
            border-top: 2px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 15px;
        }

        /* ---------- Chat area (merged & deduped) ---------- */
        .chat-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .chat-header {
            padding: 25px;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
            display: flex;
            align-items: center;
            gap: 18px;
            flex-shrink: 0;
        }

        .chat-header-avatar {
            width: 55px;
            height: 55px;
            border-radius: 50%;
            background: #667eea;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            background-size: cover;
            background-position: center;
            font-size: 18px;
        }

        .chat-header-info h3 {
            margin: 0 0 6px 0;
            color: #1a202c;
            font-size: 20px;
            font-weight: 600;
        }

        .chat-header-info p {
            margin: 0;
            color: #48bb78;
            font-size: 14px;
            font-weight: 500;
        }

        /* FIXED HEIGHT MESSAGES AREA */
        .messages-area {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            display: flex;
            flex-direction: column;
            gap: 12px;
            position: relative;
            min-height: 0;
            max-height: calc(100vh - 340px);
            height: 600px;
        }

        /* Enhanced scrollbar for messages area (kept and merged) */
        .messages-area::-webkit-scrollbar {
            width: 8px;
        }

        .messages-area::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.05);
            border-radius: 4px;
        }

        .messages-area::-webkit-scrollbar-thumb {
            background: rgba(0, 0, 0, 0.2);
            border-radius: 4px;
        }

        .messages-area::-webkit-scrollbar-thumb:hover {
            background: rgba(0, 0, 0, 0.3);
        }

        /* Enhanced Message Styling */
        .message {
            max-width: 70%;
            padding: 15px 20px;
            border-radius: 20px;
            position: relative;
            word-wrap: break-word;
            line-height: 1.5;
            font-size: 15px;
            animation: messageSlideIn 0.3s ease-out;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            flex-shrink: 0;
        }

        .message:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .message.sent {
            align-self: flex-end;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-bottom-right-radius: 8px;
            margin-left: 20%;
        }

        .message.sent::before {
            content: '';
            position: absolute;
            right: -8px;
            top: 0;
            width: 0;
            height: 0;
            border-left: 8px solid transparent;
            border-right: 8px solid transparent;
            border-top: 8px solid #764ba2;
            transform: rotate(45deg);
        }

        .message.received {
            align-self: flex-start;
            background: white;
            color: #1a202c;
            border: 1px solid #e2e8f0;
            border-bottom-left-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-right: 20%;
        }

        .message.received::before {
            content: '';
            position: absolute;
            left: -8px;
            top: 0;
            width: 0;
            height: 0;
            border-left: 8px solid transparent;
            border-right: 8px solid transparent;
            border-top: 8px solid white;
            transform: rotate(-45deg);
        }

        .message-text {
            margin-bottom: 8px;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .message-time {
            font-size: 11px;
            opacity: 0.7;
            margin-top: 8px;
            text-align: right;
            font-weight: 500;
        }

        .message.received .message-time {
            color: #718096;
        }

        .message.sent .message-time {
            color: rgba(255, 255, 255, 0.8);
        }

        /* Message status indicators */
        .message-status {
            display: inline-flex;
            align-items: center;
            margin-left: 5px;
            font-size: 10px;
        }

        .message-status.sent { color: rgba(255, 255, 255, 0.6); }
        .message-status.delivered { color: rgba(255, 255, 255, 0.8); }
        .message-status.read { color: #48bb78; }

        /* Typing indicator */
        .typing-indicator {
            align-self: flex-start;
            background: white;
            padding: 15px 20px;
            border-radius: 20px;
            border-bottom-left-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            display: flex;
            align-items: center;
            gap: 8px;
            max-width: 120px;
            margin-right: 20%;
        }

        .typing-dots {
            display: flex;
            gap: 3px;
        }

        .typing-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #718096;
            animation: typingBounce 1.4s infinite ease-in-out;
        }

        .typing-dot:nth-child(1) { animation-delay: -0.32s; }
        .typing-dot:nth-child(2) { animation-delay: -0.16s; }

        .typing-text {
            font-size: 12px;
            color: #718096;
            font-style: italic;
        }

        /* Message animations */
        @keyframes messageSlideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes typingBounce {
            0%, 80%, 100% {
                transform: scale(0.8);
                opacity: 0.5;
            }
            40% {
                transform: scale(1);
                opacity: 1;
            }
        }

        /* Message grouping for consecutive messages */
        .message-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
            margin-bottom: 10px;
        }

        .message-group .message {
            margin: 0;
            animation: none;
        }

        .message-group .message:not(:first-child) {
            margin-top: 2px;
        }

        .message-group .message:first-child {
            border-top-left-radius: 20px;
            border-top-right-radius: 20px;
        }

        .message-group .message:last-child {
            border-bottom-left-radius: 20px;
            border-bottom-right-radius: 20px;
        }

        /* Compact messages in group */
        .message-group .message:not(:first-child):not(:last-child) {
            border-radius: 15px;
        }

        /* Remove speech bubbles for grouped messages except first */
        .message-group .message:not(:first-child)::before {
            display: none;
        }

        /* Date separators */
        .date-separator {
            text-align: center;
            margin: 20px 0;
            position: relative;
        }

        .date-separator::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: #e2e8f0;
            z-index: 1;
        }

        .date-separator span {
            background: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 12px;
            color: #718096;
            font-weight: 500;
            position: relative;
            z-index: 2;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        /* Message reactions */
        .message-reactions {
            display: flex;
            gap: 5px;
            margin-top: 8px;
            flex-wrap: wrap;
        }

        .reaction {
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 4px 8px;
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 4px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .reaction:hover {
            background: #f7fafc;
            transform: scale(1.05);
        }

        .reaction.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }

        /* System messages */
        .message.system {
            align-self: center;
            background: rgba(255, 255, 255, 0.8);
            color: #718096;
            font-size: 12px;
            font-style: italic;
            padding: 8px 16px;
            border-radius: 15px;
            max-width: 300px;
            text-align: center;
            border: 1px solid #e2e8f0;
        }

        .message.system::before {
            display: none;
        }

        /* Scroll to bottom button */
        .scroll-to-bottom {
            position: absolute;
            bottom: 80px;
            right: 25px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            transition: all 0.3s ease;
            z-index: 100;
            opacity: 0;
            pointer-events: none;
            font-size: 16px;
            font-weight: bold;
        }

        .scroll-to-bottom.visible {
            opacity: 1;
            pointer-events: all;
        }

        .scroll-to-bottom:hover {
            background: #5a67d8;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
        }

        /* Message input area */
        .message-input-area {
            padding: 10px;
            border-top: 1px solid #e2e8f0;
            background: white;
            flex-shrink: 0;
        }

        .message-input-form {
            display: flex;
            gap: 15px;
            align-items: flex-end;
        }

        .message-input {
            flex: 1;
            padding: 15px 20px;
            border: 1px solid #e2e8f0;
            border-radius: 25px;
            resize: none;
            font-family: inherit;
            font-size: 15px;
            max-height: 120px;
            outline: none;
            transition: border-color 0.2s;
            line-height: 1.5;
            box-sizing: border-box;
        }

        .message-input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .send-button {
            width: 50px;
            height: 50px;
            border: none;
            background: #667eea;
            color: white;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s;
            flex-shrink: 0;
        }

        .send-button:hover {
            background: #5a67d8;
            transform: translateY(-1px);
        }

        .send-button:disabled {
            background: #a0aec0;
            cursor: not-allowed;
            transform: none;
        }

        /* Empty chat */
        .empty-chat {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #718096;
            text-align: center;
            padding: 40px;
        }

        .empty-chat-icon {
            font-size: 80px;
            margin-bottom: 25px;
            opacity: 0.5;
        }

        .empty-chat h3 {
            margin: 0 0 15px 0;
            color: #4a5568;
            font-size: 24px;
            font-weight: 600;
        }

        .empty-chat p {
            margin: 0;
            font-size: 16px;
            line-height: 1.5;
        }

        /* Loading state */
        .messages-loading {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100%;
            color: #718096;
        }

        .loading-spinner {
            width: 20px;
            height: 20px;
            border: 2px solid #e2e8f0;
            border-top: 2px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-right: 10px;
        }

        /* spin keyframes (used by multiple elements) */
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Empty state */
        .messages-empty {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            height: 100%;
            color: #718096;
            text-align: center;
            padding: 40px;
        }

        .messages-empty-icon {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .messages-empty h3 {
            margin: 0 0 10px 0;
            font-weight: 500;
            color: #4a5568;
        }

        .messages-empty p {
            margin: 0;
            opacity: 0.8;
        }

        /* Scrollbar styling for left sidebar (merged) */
        .left-sidebar::-webkit-scrollbar,
        .conversations-list::-webkit-scrollbar {
            width: 6px;
        }

        .left-sidebar::-webkit-scrollbar-track,
        .conversations-list::-webkit-scrollbar-track {
            background: #f1f1f1;
        }

        .left-sidebar::-webkit-scrollbar-thumb,
        .conversations-list::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 3px;
        }

        /* Debug info */
        #debugInfo {
            display: none;
            background: #ffebee;
            padding: 10px;
            margin: 10px;
            border-radius: 5px;
            font-size: 12px;
            color: #c53030;
        }

        /* Responsive design */
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

            .conversations-sidebar {
                width: 350px;
            }

            .conversations-list {
                max-height: calc(100vh - 500px);
            }

            .conversation-item {
                padding: 15px 20px;
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

            .conversations-sidebar {
                width: 100%;
                border-right: none;
                border-bottom: 1px solid #e2e8f0;
                max-height: 40%;
            }

            .messages-container {
                flex-direction: column;
                height: calc(100vh - 150px);
            }

            .chat-area {
                flex: 1;
            }

            .message {
                max-width: 85%;
                padding: 12px 16px;
            }

            .messages-area {
                max-height: calc(100vh - 250px);
                height: 400px;
                padding: 15px;
            }

            .scroll-to-bottom {
                bottom: 70px;
                right: 15px;
            }

            .conversation-avatar {
                width: 45px;
                height: 45px;
                margin-right: 15px;
            }

            .conversation-name {
                font-size: 15px;
            }

            .conversation-preview {
                font-size: 13px;
            }
        }
    </style>
</head>
<body class="dashboard-page">
    <input type="hidden" id="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

    <!-- Main header -->
    <div class="main-header">
        <h1 class="logo-text">Manexis</h1>
        <div class="search-bar-container">
            <span class="search-icon">🔍</span>
            <input type="text" placeholder="Search for friends, posts, and more...">
        </div>
        <div class="header-right">
            <a href="../dashboard/create_post.php" class="action-btn">Create Post</a>
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
                <a href="../dashboard/dashboard.php" class="sidebar-nav-item">
                    <div class="nav-icon">🏠</div>
                    <span>Dashboard</span>
                </a>
                <a href="../dashboard/profile.php" class="sidebar-nav-item">
                    <div class="nav-icon">👤</div>
                    <span>Profile</span>
                </a>
                <a href="../dashboard/add_friend.php" class="sidebar-nav-item">
                    <div class="nav-icon">➕</div>
                    <span>Add Friends</span>
                </a>
                <a href="../dashboard/list_friends.php" class="sidebar-nav-item">
                    <div class="nav-icon">👥</div>
                    <span>Friends List</span>
                    <?php if ($friend_count > 0): ?>
                        <span class="friends-count"><?php echo $friend_count; ?></span>
                    <?php endif; ?>
                </a>
                <a href="messages.php" class="sidebar-nav-item active">
                    <div class="nav-icon">💬</div>
                    <span>Messages</span>
                </a>
                <a href="../dashboard/edit-profile.php" class="sidebar-nav-item">
                    <div class="nav-icon">⚙️</div>
                    <span>Settings</span>
                </a>
                <a href="../dashboard/logout.php" class="sidebar-nav-item" style="color: #e53e3e;">
                    <div class="nav-icon">🚪</div>
                    <span>Logout</span>
                </a>
            </nav>
        </aside>

        <!-- Main Content - EXPANDED -->
        <main class="main-content">
            <!-- Messages Container -->
            <div class="messages-container">
                <!-- Expanded Conversations Sidebar -->
                <div class="conversations-sidebar">
                    <div class="conversations-header">
                        <h3>Conversations</h3>
                    </div>
                    <div class="conversations-list" id="conversationsList">
                        <!-- Conversations will be loaded via JavaScript -->
                    </div>
                </div>

                <!-- Expanded Chat Area -->
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
        </main>
    </div>

    <script src="js/messages.js"></script>
    
    <!-- DEBUG SCRIPT - Keep this separate from messages.js -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        console.log('🏠 DOM Ready - Checking elements:');
        console.log('messagesArea:', document.getElementById('messagesArea'));
        console.log('conversationId input:', document.getElementById('conversationId'));
        console.log('URL friend_id:', new URLSearchParams(window.location.search).get('friend_id'));
        
        // Check if we should have a chat area based on PHP
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('friend_id')) {
            console.log('🔍 Friend ID in URL - chat area should be visible');
        }
    });
    </script>
</body>
</html>