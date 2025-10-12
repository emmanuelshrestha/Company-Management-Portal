<?php
require __DIR__ .'/../../config/db.php';

if(isset($_GET['token'])){
    $token = $_GET['token'];

    // Step 3.2: Check if token exists in the database
    $stmt = $conn->prepare("SELECT id, status FROM users WHERE verification_token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if($result->num_rows == 1){
        $user = $result->fetch_assoc();

        // Step 3.3: Check if already verified
        if($user['status'] == 'Verified'){
            echo "Your email is already verified!";
        } else {
            // Step 3.4: Update status to Verified
            $update = $conn->prepare("UPDATE users SET status = 'Verified' WHERE id = ?");
            $update->bind_param("i", $user['id']);
            if($update->execute()){
                echo "✅ Email verified successfully!";
            } else {
                echo "Error updating status: " . $update->error;
            }
        }
    } else {
        echo "Invalid verification token!";
    }
} else {
    echo "No token provided!";
}
?>
