<?php
session_start();
require __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit(json_encode(['error' => 'Not logged in']));
}

$user_id = $_SESSION['user_id'];

// Get conversations with latest messages
// Get all friends who can be messaged
$sql = "SELECT 
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

$stmt = $conn->prepare($sql);
$stmt->bind_param("iiiii", $user_id, $user_id, $user_id, $user_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

$conversations = [];
while ($row = $result->fetch_assoc()) {
    $conversations[] = [
        'conversation_id' => null, // Will be created when first message is sent
        'friend_id' => $row['friend_id'],
        'friend_name' => $row['friend_name'],
        'profile_picture' => $row['profile_picture'],
        'last_message' => null,
        'last_message_time' => null,
        'is_read' => true,
        'is_sent_by_me' => false,
        'unread' => false
    ];
}

$stmt->close();

header('Content-Type: application/json');
echo json_encode(['conversations' => $conversations]);
?>