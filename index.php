<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Online Voting System</title>
    <style>
        body { font-family: Arial; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); height: 100vh; display: flex; align-items: center; justify-content: center; margin: 0; }
        .container { background: white; padding: 50px; border-radius: 10px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); text-align: center; }
        h1 { color: #333; }
        p { color: #666; font-size: 16px; }
        .buttons { margin-top: 30px; }
        a { display: inline-block; padding: 12px 30px; margin: 10px; background: #4CAF50; color: white; text-decoration: none; border-radius: 5px; font-weight: bold; }
        a:hover { background: #45a049; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🗳️ Online Voting System</h1>
        <p>Secure and transparent election voting platform</p>
        <div class="buttons">
            <?php if (isset($_SESSION['voter_id'])): ?>
                <a href="vote.php">Vote Now</a>
                <a href="results.php">View Results</a>
                <a href="logout.php">Logout</a>
            <?php else: ?>
                <a href="login.php">Login</a>
                <a href="register.php">Register</a>
                <a href="results.php">View Results</a>
            <?php endif; ?>
            <a href="admin_login.php" style="background: #ff9800;">Admin Login</a>
        </div>
    </div>
</body>
</html>