<?php
require_once 'auth.php';
startAppSession();
require_once 'db.php';

// If already logged in, send the user to the dashboard for their role.
if (!empty($_SESSION['user_id']) && in_array($_SESSION['role'] ?? '', ['student', 'admin'], true)) {
    header('Location: ' . (($_SESSION['role'] ?? '') === 'admin' ? 'admin.php' : 'dashboard.php'));
    exit();
}

$errors = [];
$success_message = '';

// Check for success message from registration
if (isset($_GET['success'])) {
    $success_message = htmlspecialchars(requestString($_GET, 'success'), ENT_QUOTES, 'UTF-8');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    }
    $student_id = trim(requestString($_POST, 'student_id'));
    $password = requestString($_POST, 'password');

    // Validation
    if (empty($student_id)) {
        $errors[] = 'Student ID is required';
    }
    if (empty($password)) {
        $errors[] = 'Password is required';
    }

    // Check credentials
    if (empty($errors)) {
        $stmt = $conn->prepare('SELECT id, first_name, last_name, student_id, password, has_voted, role, is_verified, is_active FROM users WHERE student_id = ?');
        $stmt->bind_param('s', $student_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $user = $result->fetch_assoc();
        if (!$user || !password_verify($password, $user['password'])) {
            $errors[] = 'Invalid username/email or password.';
        } elseif (!(int) $user['is_active']) {
            $errors[] = 'Your account is currently inactive. Please contact the administrator.';
        } elseif (!(int) $user['is_verified']) {
            $errors[] = 'Your account is awaiting administrator verification before you can log in.';
        } elseif (!in_array($user['role'], ['student', 'admin'], true)) {
            $errors[] = 'This account cannot sign in.';
        } else {
            establishUserSession($user, $user['role']);
            if ($user['role'] === 'admin') {
                $actorId = (int) $user['id'];
                $auditStmt = $conn->prepare(
                    "INSERT INTO admin_audit_log (actor_user_id, action, entity_type, entity_id)
                     VALUES (?, 'admin_login', 'user', ?)"
                );
                $auditStmt->bind_param('ii', $actorId, $actorId);
                $auditStmt->execute();
                $auditStmt->close();
                header('Location: admin.php');
            } else {
                header('Location: dashboard.php');
            }
            exit();
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

        .password-control { position: relative; }
        .password-control input { padding-right: 62px; }
        .password-toggle { position: absolute; top: 50%; right: 10px; transform: translateY(-50%); border: 0; background: transparent; color: #586de0; font: inherit; font-size: 13px; font-weight: 700; cursor: pointer; }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.html" class="back-link">← Back to Home</a>

        <h1>Online Voting System</h1>
        <p class="form-subtitle">Sign in with your Student ID to continue</p>

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
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
            <div class="form-group">
                <label for="student_id">Student ID *</label>
                <input type="text" id="student_id" name="student_id" value="<?php echo htmlspecialchars(requestString($_POST, 'student_id'), ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g., STU-2026-001" required autofocus>
            </div>

            <div class="form-group">
                <label for="password">Password *</label>
                <div class="password-control">
                    <input type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
                    <button type="button" class="password-toggle" aria-controls="password" aria-label="Show password" data-password-toggle>Show</button>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="btn-group">
                <button type="submit" class="btn btn-login">Login</button>
                <a href="index.html" class="btn btn-cancel" style="text-decoration: none; text-align: center;">Back to Home</a>
            </div>
        </form>

        <!-- Register Link -->
        <p class="register-link">
            Not registered? <a href="register.php">Register here</a>
        </p>

        <!-- Info Box -->
        <div class="info-box">
            <strong>💡 Note:</strong> New accounts need administrator verification
            before they can sign in and participate in elections.
        </div>
    </div>
    <script>
        document.querySelector('[data-password-toggle]').addEventListener('click', function () {
            const password = document.getElementById('password');
            const showPassword = password.type === 'password';
            password.type = showPassword ? 'text' : 'password';
            this.textContent = showPassword ? 'Hide' : 'Show';
            this.setAttribute('aria-label', showPassword ? 'Hide password' : 'Show password');
        });
    </script>
</body>
</html>
