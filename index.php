<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User CRUD App</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f4f6f9;
            color: #333;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        header {
            text-align: center;
            margin-bottom: 40px;
        }

        header h1 {
            font-size: 2.8rem;
            font-weight: 600;
            color: #1e293b;
        }

        header p {
            font-size: 1rem;
            color: #64748b;
            margin-top: 8px;
        }

        .nav-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            width: 90%;
            max-width: 700px;
        }

        .nav-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.05);
            text-align: center;
            padding: 30px 20px;
            transition: all 0.3s ease;
            border: 1px solid #e2e8f0;
        }

        .nav-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 24px rgba(0, 0, 0, 0.08);
            border-color: #3b82f6;
        }

        .nav-card a {
            text-decoration: none;
            color: #1e3a8a;
            font-weight: 600;
            font-size: 1.1rem;
            display: block;
        }

        footer {
            margin-top: 50px;
            text-align: center;
            font-size: 0.9rem;
            color: #94a3b8;
        }

        @media (max-width: 600px) {
            header h1 {
                font-size: 2rem;
            }

            .nav-card {
                padding: 25px 15px;
            }
        }
    </style>
</head>
<body>

    <header>
        <h1>User CRUD Dashboard</h1>
        <p>Manage, view, and verify users effortlessly</p>
    </header>

    <div class="nav-container">
        <div class="nav-card"><a href="modules/user/register.php">➕ Register User</a></div>
        <!-- <div class="nav-card"><a href="read.php">📋 View Users</a></div>
        <div class="nav-card"><a href="update.php">✏️ Update User</a></div>
        <div class="nav-card"><a href="delete.php">🗑️ Delete User</a></div> -->
    </div>

    <footer>
        <p>© <?php echo date('Y'); ?> User CRUD System</p>
    </footer>

</body>
</html>
