<?php
/**
 * ADMIN LOGIN PAGE
 * ================
 * Admin authentication page
 */

session_start();

// If already logged in as admin, redirect to dashboard
if (isset($_SESSION['admin_logged_in'])) {
    header('Location: admin.php');
    exit();
}

$errors = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin_username = trim($_POST['username'] ?? '');
    $admin_password = $_POST['password'] ?? '';

    // Simple admin credentials (in production, use secure methods)
    $valid_username = 'admin';
    $valid_password = 'admin123'; // Change this in production

    if ($admin_username === $valid_username && $admin_password === $valid_password) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $admin_username;
        header('Location: admin.php');
        exit();
    } else {
        $errors[] = 'Invalid username or password';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Online Voting System</title>
    <style>
        body { font-family: Arial; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); height: 100vh; display: flex; align-items: center; justify-content: center; margin: 0; }
        .container { background: white; padding: 50px; border-radius: 10px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); width: 100%; max-width: 400px; }
        h1 { color: #333; text-align: center; margin-bottom: 30px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; color: #333; margin-bottom: 8px; font-weight: bold; }
        input { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px; box-sizing: border-box; }
        input:focus { outline: none; border-color: #667eea; box-shadow: 0 0 5px rgba(102, 126, 234, 0.2); }
        button { width: 100%; padding: 12px; background: #667eea; color: white; border: none; border-radius: 5px; font-size: 16px; font-weight: bold; cursor: pointer; margin-top: 10px; }
        button:hover { background: #764ba2; }
        .errors { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin-bottom: 20px; border: 1px solid #f5c6cb; }
        .errors p { margin: 5px 0; }
        .back-link { text-align: center; margin-top: 20px; }
        .back-link a { color: #667eea; text-decoration: none; font-weight: bold; }
        .back-link a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔐 Admin Login</h1>
        
        <?php if (!empty($errors)): ?>
            <div class="errors">
                <?php foreach ($errors as $error): ?>
                    <p><?php echo htmlspecialchars($error); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label for="username">Admin Username:</label>
                <input type="text" id="username" name="username" required>
            </div>

            <div class="form-group">
                <label for="password">Admin Password:</label>
                <input type="password" id="password" name="password" required>
            </div>

            <button type="submit">Login as Admin</button>
        </form>

        <div class="back-link">
            <a href="index.php">← Back to Home</a>
        </div>
    </div>
</body>
</html>
