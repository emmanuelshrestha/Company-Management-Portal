<?php
session_start();
require __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit(json_encode(['error' => 'Not logged in']));
}

$user_id = $_SESSION['user_id'];

// First, get all friends
$friendsSql = "SELECT 
    u.id as friend_id,
    u.name as friend_name,
    u.profile_picture
FROM friends f
JOIN users u ON (
    (f.user_id = ? AND u.id = f.friend_id) OR 
    (f.friend_id = ? AND u.id = f.user_id)
)
WHERE (f.user_id = ? OR f.friend_id = ?) 
AND f.status = 'approved'
AND u.id != ?
ORDER BY u.name ASC";

$friendsStmt = $conn->prepare($friendsSql);
$friendsStmt->bind_param("iiiii", $user_id, $user_id, $user_id, $user_id, $user_id);
$friendsStmt->execute();
$friendsResult = $friendsStmt->get_result();

$conversations = [];

while ($friend = $friendsResult->fetch_assoc()) {
    // For each friend, get the latest message info
    $messageSql = "SELECT 
        m.message,
        m.created_at,
        m.sender_id,
        c.id as conversation_id,
        (SELECT COUNT(*) FROM messages m2 
         WHERE m2.conversation_id = c.id 
         AND m2.sender_id != ? 
         AND m2.is_read = FALSE) as unread_count
    FROM conversations c
    LEFT JOIN messages m ON m.conversation_id = c.id
    WHERE ((c.user1_id = ? AND c.user2_id = ?) OR (c.user1_id = ? AND c.user2_id = ?))
    ORDER BY m.created_at DESC 
    LIMIT 1";
    
    $messageStmt = $conn->prepare($messageSql);
    $messageStmt->bind_param("iiiii", $user_id, $user_id, $friend['friend_id'], $friend['friend_id'], $user_id);
    $messageStmt->execute();
    $messageResult = $messageStmt->get_result();
    $messageData = $messageResult->fetch_assoc();
    
    $conversations[] = [
        'conversation_id' => $messageData['conversation_id'] ?? null,
        'friend_id' => $friend['friend_id'],
        'friend_name' => $friend['friend_name'],
        'profile_picture' => $friend['profile_picture'],
        'last_message' => $messageData['message'] ?? null,
        'last_message_time' => $messageData['created_at'] ?? null,
        'is_read' => ($messageData['unread_count'] ?? 0) == 0,
        'is_sent_by_me' => ($messageData['sender_id'] ?? null) == $user_id,
        'unread' => ($messageData['unread_count'] ?? 0) > 0
    ];
    
    $messageStmt->close();
}

$friendsStmt->close();

// Sort conversations by last_message_time (most recent first)
usort($conversations, function($a, $b) {
    $timeA = $a['last_message_time'] ? strtotime($a['last_message_time']) : 0;
    $timeB = $b['last_message_time'] ? strtotime($b['last_message_time']) : 0;
    return $timeB - $timeA; // Descending order
});

header('Content-Type: application/json');
echo json_encode(['conversations' => $conversations]);
?>