<?php
session_start();
require __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit(json_encode(['error' => 'Not logged in']));
}

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    exit(json_encode(['error' => 'Method not allowed']));
}

$data = json_decode(file_get_contents('php://input'), true);
$conversation_id = isset($data['conversation_id']) ? intval($data['conversation_id']) : 0;
$message = trim($data['message'] ?? '');

if (!$conversation_id || empty($message)) {
    header('HTTP/1.1 400 Bad Request');
    exit(json_encode(['error' => 'Conversation ID and message required']));
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

// Insert message
$sql = "INSERT INTO messages (conversation_id, sender_id, message) VALUES (?, ?, ?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iis", $conversation_id, $user_id, $message);

if ($stmt->execute()) {
    $message_id = $stmt->insert_id;
    
    // Update conversation timestamp
    $updateSql = "UPDATE conversations SET updated_at = NOW() WHERE id = ?";
    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->bind_param("i", $conversation_id);
    $updateStmt->execute();
    $updateStmt->close();
    
    // Get the created message
    $getMessageSql = "SELECT 
        m.id,
        m.sender_id,
        m.message,
        m.created_at,
        u.name as sender_name
    FROM messages m
    JOIN users u ON m.sender_id = u.id
    WHERE m.id = ?";
    
    $getStmt = $conn->prepare($getMessageSql);
    $getStmt->bind_param("i", $message_id);
    $getStmt->execute();
    $message_data = $getStmt->get_result()->fetch_assoc();
    $getStmt->close();
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => [
            'id' => $message_data['id'],
            'sender_id' => $message_data['sender_id'],
            'sender_name' => $message_data['sender_name'],
            'message' => $message_data['message'],
            'created_at' => $message_data['created_at'],
            'is_me' => true
        ]
    ]);
} else {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Failed to send message']);
}

$stmt->close();
?>