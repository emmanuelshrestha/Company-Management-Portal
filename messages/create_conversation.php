<?php
session_start();
require __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit(json_encode(['error' => 'Not logged in']));
}

$user_id = $_SESSION['user_id'];
$friend_id = isset($_POST['friend_id']) ? intval($_POST['friend_id']) : 0;

if (!$friend_id) {
    header('HTTP/1.1 400 Bad Request');
    exit(json_encode(['error' => 'Friend ID required']));
}

// Check if conversation already exists
$checkSql = "SELECT id FROM conversations WHERE (user1_id = ? AND user2_id = ?) OR (user1_id = ? AND user2_id = ?)";
$checkStmt = $conn->prepare($checkSql);
$user1_id = min($user_id, $friend_id);
$user2_id = max($user_id, $friend_id);
$checkStmt->bind_param("iiii", $user1_id, $user2_id, $user2_id, $user1_id);
$checkStmt->execute();
$result = $checkStmt->get_result();

if ($result->num_rows > 0) {
    $conversation = $result->fetch_assoc();
    echo json_encode(['conversation_id' => $conversation['id']]);
} else {
    // Create new conversation
    $insertSql = "INSERT INTO conversations (user1_id, user2_id) VALUES (?, ?)";
    $insertStmt = $conn->prepare($insertSql);
    $insertStmt->bind_param("ii", $user1_id, $user2_id);
    
    if ($insertStmt->execute()) {
        echo json_encode(['conversation_id' => $insertStmt->insert_id]);
    } else {
        echo json_encode(['error' => 'Failed to create conversation']);
    }
    $insertStmt->close();
}

$checkStmt->close();
?>