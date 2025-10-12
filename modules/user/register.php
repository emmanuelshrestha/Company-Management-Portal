<?php
require 'db.php';
require 'src/Exception.php';
require 'src/PHPMailer.php';
require 'src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Development helpers (enable in dev only)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
error_reporting(E_ALL);
ini_set('display_errors', 1);

$message = "";

// Only handle when form submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Trim + basic sanitization
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    // prevent CR/LF in name/email (avoid injection)
    $name  = str_replace(["\r", "\n"], ['', ''], $name);
    $email = str_replace(["\r", "\n"], ['', ''], $email);

    // Basic validation
    if ($name === '' || $email === '' || $password === '') {
        $message = "<p style='color:red;'>All fields are required.</p>";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "<p style='color:red;'>Invalid email format.</p>";
    } elseif (!PHPMailer::validateAddress($email)) {
        // PHPMailer has its own validator — extra safety
        $message = "<p style='color:red;'>Email address not valid (PHPMailer check).</p>";
    } else {
        // All good - proceed
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $token = bin2hex(random_bytes(16));

        // Check if email already exists (optional but recommended)
        $checkSql = "SELECT id FROM users WHERE email = ? LIMIT 1";
        if ($checkStmt = $conn->prepare($checkSql)) {
            $checkStmt->bind_param("s", $email);
            $checkStmt->execute();
            $checkStmt->store_result();
            if ($checkStmt->num_rows > 0) {
                $message = "<p style='color:red;'>Email already registered.</p>";
                $checkStmt->close();
            } else {
                $checkStmt->close();

                // Insert new user
                $sql = "INSERT INTO users (name, email, password, status, verification_token) VALUES (?, ?, ?, 'Not Verified', ?)";
                $stmt = $conn->prepare($sql);

                if ($stmt === false) {
                    $message = "<p style='color:red;'>Prepare failed: " . htmlspecialchars($conn->error) . "</p>";
                } else {
                    $stmt->bind_param("ssss", $name, $email, $hashed_password, $token);

                    if ($stmt->execute()) {
                        // Build verification link (use your real domain / https in production)
                        $verificationLink = "http://localhost/Email_verification/verify.php?token=" . $token;

                        // Send email via SMTP (Mailtrap used for dev)
                        $mail = new PHPMailer(true);
                        try {
                            $mail->isSMTP();
                            $mail->Host       = 'live.smtp.mailtrap.io';
                            $mail->SMTPAuth   = true;
                            $mail->Username   = 'api'; // replace
                            $mail->Password   = '158e6db39565c5b9cf36205dc319bbc7'; // replace
                            $mail->Port       =  587;
                            $mail->SMTPSecure = 'tls';

                            $mail->setFrom('hello@demomailtrap.co', 'Your App'); // set a valid from
                            $mail->addAddress($email, $name);

                            $mail->isHTML(true);
                            $mail->Subject = 'Confirm your email';
                            $mail->Body    = '
                                <html>
                                <body style="font-family: Poppins, sans-serif; background-color:#f7f7f7; margin:0; padding:0;">
                                    <table width="100%" cellpadding="0" cellspacing="0">
                                        <tr>
                                            <td align="center" style="padding:30px 0;">
                                                <table width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.1);">
                                                    <tr>
                                                        <td style="padding:30px; text-align:center;">
                                                            <h2 style="color:#333;">Confirm your email</h2>
                                                            <p>Hi '.htmlspecialchars($name).',</p>
                                                            <p>Before you get started, please confirm your email address to improve account security.</p>
                                                            <a href="'.$verificationLink.'" style="display:inline-block; padding:12px 25px; background-color:#FFFC00; color:#000; text-decoration:none; font-weight:bold; border-radius:5px; margin-top:20px;">Confirm Email</a>
                                                            <p style="margin-top:30px; font-size:12px; color:#888;">If this is not your account or you did not sign up, please ignore this email.</p>
                                                            <hr style="border:none; border-top:1px solid #eee; margin:30px 0;">
                                                            <p style="font-size:12px; color:#888;">SXC, Maitighar, Kathmandu, Nepal</p>
                                                            <p style="font-size:12px; color:#888;">
                                                                <a href="#" style="color:#888; text-decoration:none;">Privacy Policy</a> | 
                                                                <a href="#" style="color:#888; text-decoration:none;">Terms of Service</a>
                                                            </p>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                    </table>
                                </body>
                                </html>
                                ';

                            $mail->send();
                            $message = "<p style='color:green;'>User registered successfully! Please check your email to verify your account.</p>";
                        } catch (Exception $e) {
                            // Show PHPMailer-specific error (safe-escaped)
                            $message = "<p style='color:red;'>Email could not be sent. Mailer Error: " . htmlspecialchars($mail->ErrorInfo) . "</p>";
                        }
                    } else {
                        $message = "<p style='color:red;'>Execute failed: " . htmlspecialchars($stmt->error) . "</p>";
                    }

                    $stmt->close();
                }
            } // end email-exists else
        } else {
            $message = "<p style='color:red;'>Prepare failed: " . htmlspecialchars($conn->error) . "</p>";
        }
    }
} // end POST
?>

<!DOCTYPE html>
<html>
<head>
    <title>Register User</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .error { color: red; font-size: 14px; }
        body { font-family: Arial, sans-serif; margin: 20px; }
    </style>
    <script>
        function validateForm() {
            let name     = document.forms["userForm"]["name"].value.trim();
            let email    = document.forms["userForm"]["email"].value.trim();
            let password = document.forms["userForm"]["password"].value.trim();
            let valid = true;

            document.getElementById("nameError").innerText = "";
            document.getElementById("emailError").innerText = "";
            document.getElementById("passwordError").innerText = "";

            if (name === "") {
                document.getElementById("nameError").innerText = "Name is required!";
                valid = false;
            }

            let emailPattern = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
            if (!emailPattern.test(email)) {
                document.getElementById("emailError").innerText = "Enter a valid email!";
                valid = false;
            }

            if (password.length < 6) {
                document.getElementById("passwordError").innerText = "Password must be at least 6 characters!";
                valid = false;
            }

            return valid;
        }
    </script>
</head>
<body>
    <h1>Register User</h1>
    <a href="index.php">Home</a> | <a href="read.php">View Users</a>
    <br><br>

    <?php if (!empty($message)) echo $message; ?>

    <form method="POST">
        <label>
            Name:
            <input type="text" name="name" required>
        </label>
        <br><br>

        <label>
            Email:
            <input type="email" name="email" required>
        </label>
        <br><br>

        <label>
            Password:
            <input type="password" name="password" required>
        </label>
        <br><br>

        <button type="submit">Register</button>
    </form>
</body>
</html>