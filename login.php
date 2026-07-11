<?php
session_start();
require_once 'db.php';

// If already logged in, redirect to appropriate page
if (isset($_SESSION['student_id'])) {
    // Check if already voted
    $stmt = $conn->prepare('SELECT has_voted FROM users WHERE student_id = ?');
    $stmt->bind_param('s', $_SESSION['student_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        if ($user['has_voted'] == 1) {
            header('Location: already_voted.php');
        } else {
            header('Location: vote.php');
        }
        exit();
    }
    $stmt->close();
}

$errors = [];
$success_message = '';

// Check for success message from registration
if (isset($_GET['success'])) {
    $success_message = htmlspecialchars($_GET['success']);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = trim($_POST['student_id'] ?? '');
    $password = $_POST['password'] ?? '';

    // Validation
    if (empty($student_id)) {
        $errors[] = 'Student ID is required';
    }
    if (empty($password)) {
        $errors[] = 'Password is required';
    }

    // Check credentials
    if (empty($errors)) {
        $stmt = $conn->prepare('SELECT id, first_name, last_name, password, has_voted FROM users WHERE student_id = ?');
        $stmt->bind_param('s', $student_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            
            // Verify password
            if (password_verify($password, $user['password'])) {
                // Set session variables
                $_SESSION['student_id'] = $student_id;
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
                
                // Redirect based on voting status
                if ($user['has_voted'] == 1) {
                    header('Location: already_voted.php');
                } else {
                    header('Location: vote.php');
                }
                exit();
            } else {
                $errors[] = 'Invalid student ID or password';
            }
        } else {
            $errors[] = 'Student ID not found. Please register first.';
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - College Voting System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            padding: 40px;
            max-width: 450px;
            width: 100%;
        }

        .back-link {
            display: inline-block;
            color: #667eea;
            text-decoration: none;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 28px;
        }

        .form-subtitle {
            color: #777;
            margin-bottom: 30px;
            font-size: 14px;
        }

        .success-box {
            background: #e8f5e9;
            border: 1px solid #a5d6a7;
            color: #2e7d32;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: none;
        }

        .success-box.show {
            display: block;
        }

        .error-box {
            background: #fee;
            border: 1px solid #fcc;
            color: #c33;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: none;
        }

        .error-box.show {
            display: block;
        }

        .error-box ul {
            margin: 0;
            padding-left: 20px;
        }

        .error-box li {
            margin: 5px 0;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            color: #333;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 14px;
        }

        input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }

        input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .btn-group {
            display: flex;
            gap: 10px;
            margin-top: 30px;
        }

        .btn {
            flex: 1;
            padding: 12px;
            font-size: 16px;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }

        .btn-cancel {
            background: #f0f0f0;
            color: #333;
        }

        .btn-cancel:hover {
            background: #e0e0e0;
        }

        .register-link {
            text-align: center;
            margin-top: 20px;
            color: #777;
            font-size: 14px;
        }

        .register-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }

        .register-link a:hover {
            text-decoration: underline;
        }

        .info-box {
            background: #f0f4ff;
            padding: 12px;
            border-radius: 8px;
            color: #667eea;
            font-size: 13px;
            margin-top: 20px;
            line-height: 1.5;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.html" class="back-link">← Back to Home</a>

        <h1>Student Login</h1>
        <p class="form-subtitle">Enter your credentials to vote</p>

        <!-- Success Message -->
        <?php if (!empty($success_message)): ?>
            <div class="success-box show">
                ✓ <?php echo $success_message; ?>
            </div>
        <?php endif; ?>

        <!-- Error Messages -->
        <?php if (!empty($errors)): ?>
            <div class="error-box show">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Login Form -->
        <form method="POST" action="">
            <div class="form-group">
                <label for="student_id">Student ID *</label>
                <input type="text" id="student_id" name="student_id" value="<?php echo htmlspecialchars($_POST['student_id'] ?? ''); ?>" placeholder="e.g., STU-2026-001" required autofocus>
            </div>

            <div class="form-group">
                <label for="password">Password *</label>
                <input type="password" id="password" name="password" placeholder="Enter your password" required>
            </div>

            <!-- Form Actions -->
            <div class="btn-group">
                <button type="submit" class="btn btn-login">Login</button>
                <a href="index.html" class="btn btn-cancel" style="text-decoration: none; text-align: center;">Cancel</a>
            </div>
        </form>

        <!-- Register Link -->
        <p class="register-link">
            Not registered? <a href="register.php">Register here</a>
        </p>

        <!-- Info Box -->
        <div class="info-box">
            <strong>💡 Note:</strong> Only registered students can login. 
            After successful registration, you'll be able to vote in the college elections.
        </div>
    </div>
</body>
</html>

