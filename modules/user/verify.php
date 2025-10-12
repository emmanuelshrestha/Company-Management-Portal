<?php
require __DIR__ .'/../../config/db.php';

$message = "";
$redirect = false;

if (isset($_GET['token'])) {
    $token = filter_var($_GET['token'], FILTER_SANITIZE_STRING);

    // Check if token exists in the database
    $stmt = $conn->prepare("SELECT id, status FROM users WHERE verification_token = ?");
    if ($stmt === false) {
        $message = "<p style='color:red;'>Database error: " . htmlspecialchars($conn->error) . "</p>";
    } else {
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();

            // Check if already verified
            if ($user['status'] == 'Verified') {
                $message = "<p style='color:orange;'>Your email is already verified!</p>";
            } else {
                // Update status to Verified
                $update = $conn->prepare("UPDATE users SET status = 'Verified', verification_token = NULL WHERE id = ?");
                $update->bind_param("i", $user['id']);
                if ($update->execute()) {
                    $message = "<p style='color:green;'>✅ Email verified successfully! You will be redirected to the login page in 3 seconds.</p>";
                    $redirect = true;
                } else {
                    $message = "<p style='color:red;'>Error updating status: " . htmlspecialchars($update->error) . "</p>";
                }
                $update->close();
            }
        } else {
            $message = "<p style='color:red;'>Invalid verification token!</p>";
        }
        $stmt->close();
    }
} else {
    $message = "<p style='color:red;'>No token provided!</p>";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .error { color: red; font-size: 14px; }
        body { font-family: Arial, sans-serif; margin: 20px; }
    </style>
    <script>
        <?php if ($redirect) { ?>
            setTimeout(() => {
                window.location.href = 'login.php'; // Adjust to your login page URL
            }, 3000);
        <?php } ?>
    </script>
</head>
<body>
    <div class="container">
        <h1>Email Verification</h1>
        <?php echo $message; ?>
        <p><a href="../../index.php">Return to Home</a></p>
    </div>
</body>
</html>