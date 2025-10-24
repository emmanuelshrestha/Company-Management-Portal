<?php
session_start();
require __DIR__ . '/../config/db.php';

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
$after = isset($_GET['after']) ? intval($_GET['after']) : 0;
$before = isset($_GET['before']) ? intval($_GET['before']) : 0;
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;

if (!$conversation_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Conversation ID required']);
    exit;
}

// Verify access
$verifySql = "SELECT id FROM conversations WHERE id = ? AND (user1_id = ? OR user2_id = ?)";
$verifyStmt = $conn->prepare($verifySql);
$verifyStmt->bind_param("iii", $conversation_id, $user_id, $user_id);
$verifyStmt->execute();
if ($verifyStmt->get_result()->num_rows === 0) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}
$verifyStmt->close();

// Build SQL
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
WHERE m.conversation_id = ?";

$types = "i";
$params = [$conversation_id];

if ($after > 0) {
    $sql .= " AND m.id > ?";
    $types .= "i";
    $params[] = $after;
    $sql .= " ORDER BY m.created_at ASC";
} elseif ($before > 0) {
    $sql .= " AND m.id < ?";
    $types .= "i";
    $params[] = $before;
    $sql .= " ORDER BY m.created_at DESC";
} else {
    $sql .= " ORDER BY m.created_at DESC";
}

$sql .= " LIMIT ?";
$types .= "i";
$params[] = $limit;

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
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

// Mark as read
$updateSql = "UPDATE messages SET is_read = TRUE WHERE conversation_id = ? AND sender_id != ? AND is_read = FALSE";
$updateStmt = $conn->prepare($updateSql);
$updateStmt->bind_param("ii", $conversation_id, $user_id);
$updateStmt->execute();
$updateStmt->close();

$has_more = ($after == 0) && (count($messages) == $limit);

echo json_encode(['messages' => $messages, 'has_more' => $has_more]);
?>