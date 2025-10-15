<?php
session_start();
require __DIR__ . '/../config/db.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$user_id = $_SESSION['user_id'];
$conversation_id = isset($_GET['conversation_id']) ? intval($_GET['conversation_id']) : 0;

error_log("get_messages called - User: $user_id, Conversation: $conversation_id");

if (!$conversation_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Conversation ID required']);
    exit;
}

// Rest of the code remains the same...

if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit(json_encode(['error' => 'Not logged in']));
}

$user_id = $_SESSION['user_id'];
$conversation_id = isset($_GET['conversation_id']) ? intval($_GET['conversation_id']) : 0;

if (!$conversation_id) {
    header('HTTP/1.1 400 Bad Request');
    exit(json_encode(['error' => 'Conversation ID required']));
}

// Verify user has access to this conversation
$verifySql = "SELECT id FROM conversations WHERE id = ? AND (user1_id = ? OR user2_id = ?)";
$verifyStmt = $conn->prepare($verifySql);
$verifyStmt->bind_param("iii", $conversation_id, $user_id, $user_id);
$verifyStmt->execute();

if ($verifyStmt->get_result()->num_rows === 0) {
    header('HTTP/1.1 403 Forbidden');
    exit(json_encode(['error' => 'Access denied']));
}
$verifyStmt->close();

// Get messages
$sql = "SELECT 
    m.id,
    m.sender_id,
    m.message,
    m.is_read,
    m.created_at,
    u.name as sender_name,
    u.profile_picture
FROM messages m
JOIN users u ON m.sender_id = u.id
WHERE m.conversation_id = ?
ORDER BY m.created_at ASC
LIMIT 100";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $conversation_id);
$stmt->execute();
$result = $stmt->get_result();

$messages = [];
while ($row = $result->fetch_assoc()) {
    $messages[] = [
        'id' => $row['id'],
        'sender_id' => $row['sender_id'],
        'sender_name' => $row['sender_name'],
        'profile_picture' => $row['profile_picture'],
        'message' => $row['message'],
        'is_read' => $row['is_read'],
        'created_at' => $row['created_at'],
        'is_me' => $row['sender_id'] == $user_id
    ];
}

$stmt->close();

// Mark messages as read
$updateSql = "UPDATE messages SET is_read = TRUE WHERE conversation_id = ? AND sender_id != ? AND is_read = FALSE";
$updateStmt = $conn->prepare($updateSql);
$updateStmt->bind_param("ii", $conversation_id, $user_id);
$updateStmt->execute();
$updateStmt->close();

header('Content-Type: application/json');
echo json_encode(['messages' => $messages]);
?>