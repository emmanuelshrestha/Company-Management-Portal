<?php
session_start();
require __DIR__ . '/../config/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.0 403 Forbidden');
    exit('Access denied');
}

if (isset($_GET['image'])) {
    $image_filename = basename($_GET['image']);
    $image_path = __DIR__ . '/../../uploads/posts/' . $image_filename;
    
    // Check if image exists and user has permission to view it
    if (file_exists($image_path)) {
        // Verify user has permission to view this image
        $sql = "SELECT p.id FROM posts p 
                LEFT JOIN friends f ON (p.user_id = f.friend_id AND f.user_id = ? AND f.status = 'approved')
                LEFT JOIN friends f2 ON (p.user_id = f2.user_id AND f2.friend_id = ? AND f2.status = 'approved')
                WHERE p.image_filename = ? AND (p.user_id = ? OR f.user_id IS NOT NULL OR f2.friend_id IS NOT NULL)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("issi", $_SESSION['user_id'], $_SESSION['user_id'], $image_filename, $_SESSION['user_id']);
        $stmt->execute();
        
        if ($stmt->get_result()->num_rows > 0) {
            // Serve the image
            $mime_type = mime_content_type($image_path);
            header('Content-Type: ' . $mime_type);
            header('Content-Length: ' . filesize($image_path));
            readfile($image_path);
        } else {
            header('HTTP/1.0 403 Forbidden');
            exit('Access denied');
        }
        $stmt->close();
    } else {
        header('HTTP/1.0 404 Not Found');
        exit('Image not found');
    }
} else {
    header('HTTP/1.0 400 Bad Request');
    exit('No image specified');
}
?>