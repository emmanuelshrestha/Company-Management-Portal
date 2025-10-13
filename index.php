<?php
session_start();
require __DIR__ . '/config/db.php';

// Check if user is logged in to show personalized content
$logged_in = isset($_SESSION['user_id']);
$user_name = '';

if ($logged_in) {
    $userSql = "SELECT name FROM users WHERE id = ?";
    $userStmt = $conn->prepare($userSql);
    $userStmt->bind_param("i", $_SESSION['user_id']);
    $userStmt->execute();
    $userResult = $userStmt->get_result();
    if ($userResult->num_rows > 0) {
        $user = $userResult->fetch_assoc();
        $user_name = $user['name'];
    }
    $userStmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ConnectHub - Social Network</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #333;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            width: 100%;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            align-items: center;
        }

        .hero-section {
            color: white;
            text-align: left;
        }

        .hero-section h1 {
            font-size: 3.5rem;
            font-weight: 700;
            margin-bottom: 20px;
            line-height: 1.2;
        }

        .hero-section p {
            font-size: 1.2rem;
            margin-bottom: 30px;
            opacity: 0.9;
            line-height: 1.6;
        }

        .features {
            display: flex;
            gap: 20px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }

        .feature {
            background: rgba(255, 255, 255, 0.1);
            padding: 15px 20px;
            border-radius: 12px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .feature span {
            font-weight: 600;
            font-size: 0.9rem;
        }

        .auth-section {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .welcome-message {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
        }

        .welcome-message h3 {
            color: #0369a1;
            margin-bottom: 10px;
        }

        .btn-group {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-bottom: 25px;
        }

        .btn {
            display: inline-block;
            padding: 15px 30px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.3s ease;
            text-align: center;
            border: none;
            cursor: pointer;
        }

        .btn-primary {
            background: #667eea;
            color: white;
        }

        .btn-primary:hover {
            background: #5a67d8;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.3);
        }

        .btn-secondary {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #e2e8f0;
        }

        .btn-secondary:hover {
            background: #e2e8f0;
            transform: translateY(-2px);
        }

        .btn-success {
            background: #10b981;
            color: white;
        }

        .btn-success:hover {
            background: #059669;
            transform: translateY(-2px);
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-top: 30px;
        }

        .stat {
            background: #f8fafc;
            padding: 15px;
            border-radius: 12px;
            text-align: center;
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: #667eea;
            display: block;
        }

        .stat-label {
            font-size: 0.8rem;
            color: #64748b;
            margin-top: 5px;
        }

        .quick-links {
            margin-top: 25px;
            padding-top: 25px;
            border-top: 1px solid #e2e8f0;
        }

        .quick-links h4 {
            margin-bottom: 15px;
            color: #475569;
        }

        .links-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }

        .link-item {
            background: #f8fafc;
            padding: 12px;
            border-radius: 8px;
            text-decoration: none;
            color: #475569;
            font-size: 0.9rem;
            transition: all 0.2s ease;
            text-align: center;
        }

        .link-item:hover {
            background: #e2e8f0;
            color: #334155;
        }

        footer {
            margin-top: 50px;
            text-align: center;
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.9rem;
        }

        @media (max-width: 768px) {
            .container {
                grid-template-columns: 1fr;
                gap: 30px;
            }

            .hero-section h1 {
                font-size: 2.5rem;
            }

            .features {
                justify-content: center;
            }

            .stats {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 480px) {
            .hero-section h1 {
                font-size: 2rem;
            }

            .auth-section {
                padding: 30px 20px;
            }

            .stats {
                grid-template-columns: 1fr;
            }

            .links-grid {
                grid-template-columns: 1fr;
            }
        }

        .logo {
            font-size: 2rem;
            font-weight: 700;
            color: white;
            margin-bottom: 10px;
        }

        .tagline {
            font-size: 1.1rem;
            opacity: 0.9;
            margin-bottom: 30px;
        }
    </style>
</head>
<body>

    <div class="container">
        <!-- Hero Section -->
        <div class="hero-section">
            <div class="logo">ConnectHub</div>
            <h1>Connect, Share, and Grow Together</h1>
            <p class="tagline">Join our community and stay connected with friends, share your thoughts, and discover new connections.</p>
            
            <div class="features">
                <div class="feature">
                    <span>👥 Connect with Friends</span>
                </div>
                <div class="feature">
                    <span>💬 Share Posts</span>
                </div>
                <div class="feature">
                    <span>❤️ Like & Comment</span>
                </div>
                <div class="feature">
                    <span>🔒 Secure & Private</span>
                </div>
            </div>

            <p>Join thousands of users who are already sharing their stories and building meaningful connections.</p>
        </div>

        <!-- Auth Section -->
        <div class="auth-section">
            <?php if ($logged_in): ?>
                <!-- User is logged in -->
                <div class="welcome-message">
                    <h3>Welcome back, <?php echo htmlspecialchars($user_name); ?>! 👋</h3>
                    <p>Ready to see what's new in your network?</p>
                </div>

                <div class="btn-group">
                    <a href="dashboard/dashboard.php" class="btn btn-primary">Go to Dashboard</a>
                    <a href="dashboard/news_feed.php" class="btn btn-success">View News Feed</a>
                </div>

                <div class="quick-links">
                    <h4>Quick Access</h4>
                    <div class="links-grid">
                        <a href= "dashboard/profile.php" class="link-item">Profile</a>
                        <a href= "dashboard/add_friend.php" class="link-item">Add Friends</a>
                        <a href= "dashboard/list_friends.php" class="link-item">Friends List</a>
                        <a href= "dashboard/create_post.php" class="link-item">Create Post</a>
                    </div>
                </div>

            <?php else: ?>
                <!-- User is not logged in -->
                <h2 style="margin-bottom: 25px; color: #1e293b;">Join ConnectHub Today</h2>
                <p style="color: #64748b; margin-bottom: 30px;">Sign up to start connecting with friends and sharing your experiences.</p>

                <div class="btn-group">
                    <a href= "modules/user/register.php" class="btn btn-primary">Create Account</a>
                    <a href= "modules/user/login.php" class="btn btn-secondary">Sign In</a>
                </div>

                <div class="stats">
                    <div class="stat">
                        <span class="stat-number">
                            <?php
                            $userCount = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];
                            echo $userCount;
                            ?>
                        </span>
                        <span class="stat-label">Users</span>
                    </div>
                    <div class="stat">
                        <span class="stat-number">
                            <?php
                            $postCount = $conn->query("SELECT COUNT(*) as count FROM posts")->fetch_assoc()['count'];
                            echo $postCount;
                            ?>
                        </span>
                        <span class="stat-label">Posts</span>
                    </div>
                    <div class="stat">
                        <span class="stat-number">
                            <?php
                            $friendCount = $conn->query("SELECT COUNT(*) as count FROM friends WHERE status = 'approved'")->fetch_assoc()['count'];
                            echo $friendCount;
                            ?>
                        </span>
                        <span class="stat-label">Connections</span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <footer>
        <p>© <?php echo date('Y'); ?> ConnectHub - Social Network Platform</p>
        <p style="margin-top: 5px; font-size: 0.8rem;">Connect, share, and grow with your community</p>
    </footer>

</body>
</html>