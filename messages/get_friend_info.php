<?php
session_start();
require __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit(json_encode(['error' => 'Not logged in']));
}

$friend_id = isset($_GET['friend_id']) ? intval($_GET['friend_id']) : 0;

$sql = "SELECT name, profile_picture FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $friend_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $friend = $result->fetch_assoc();
    echo json_encode($friend);
} else {
    echo json_encode(['error' => 'Friend not found']);
}

$stmt->close();
?>