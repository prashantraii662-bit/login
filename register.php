<?php
require_once 'auth.php';
startAppSession();
require_once 'db.php';

// Initialize variables
$errors = [];
$success = false;
$departments = [
    'Computer Engineering',
    'Civil Engineering',
    'Electrical Engineering',
    'Mechanical Engineering'
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    }
    // Collect and sanitize input
    $first_name = trim(requestString($_POST, 'first_name'));
    $middle_name = trim(requestString($_POST, 'middle_name'));
    $last_name = trim(requestString($_POST, 'last_name'));
    $email = trim(requestString($_POST, 'email'));
    $student_id = trim(requestString($_POST, 'student_id'));
    $department = trim(requestString($_POST, 'department'));
    $password = requestString($_POST, 'password');
    $confirm_password = requestString($_POST, 'confirm_password');

    // Validation
    if (empty($first_name)) {
        $errors[] = 'First Name is required';
    } elseif (strlen($first_name) > 50) {
        $errors[] = 'First Name must be 50 characters or fewer';
    }
    if (empty($last_name)) {
        $errors[] = 'Last Name is required';
    } elseif (strlen($last_name) > 50) {
        $errors[] = 'Last Name must be 50 characters or fewer';
    }
    if (strlen($middle_name) > 50) {
        $errors[] = 'Middle Name must be 50 characters or fewer';
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Valid Email ID is required';
    } elseif (strlen($email) > 100) {
        $errors[] = 'Email address must be 100 characters or fewer';
    }
    if (empty($student_id) || strlen($student_id) < 3 || strlen($student_id) > 20) {
        $errors[] = 'Valid Student ID is required (3 to 20 characters)';
    }
    if (!in_array($department, $departments, true)) {
        $errors[] = 'Department must be selected';
    }
    if (empty($password) || strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters long';
    }
    if ($password !== $confirm_password) {
        $errors[] = 'Passwords do not match';
    }

    // Check for duplicate Student ID
    if (empty($errors)) {
        $stmt = $conn->prepare('SELECT id FROM users WHERE student_id = ?');
        $stmt->bind_param('s', $student_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $errors[] = 'Student ID already registered. Please login or use a different ID.';
        }
        $stmt->close();
    }

    // Check for duplicate Email
    if (empty($errors)) {
        $stmt = $conn->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $errors[] = 'Email already registered. Please login or use a different email.';
        }
        $stmt->close();
    }

    // If no errors, insert into database
    if (empty($errors)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $conn->prepare(
            'INSERT INTO users (first_name, middle_name, last_name, email, student_id, department, password, has_voted, role, is_verified) 
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, \'student\', 0)'
        );
        
        $stmt->bind_param(
            'sssssss',
            $first_name,
            $middle_name,
            $last_name,
            $email,
            $student_id,
            $department,
            $hashed_password
        );
        
        try {
            $stmt->execute();
            $success = true;
            // Redirect to login after successful registration
            header('Location: login.php?success=Registration successful! Please login.');
            exit();
        } catch (mysqli_sql_exception $exception) {
            if ($exception->getCode() !== 1062) {
                throw $exception;
            }
            $errors[] = 'Student ID or Email already registered. Please login or use different details.';
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
    <title>Register - College Voting System</title>
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
            max-width: 600px;
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

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .form-row.full {
            grid-template-columns: 1fr;
        }

        label {
            display: block;
            color: #333;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 14px;
        }

        input, select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }

        input:focus, select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-actions {
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

        .btn-register {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-register:hover {
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

        .login-link {
            text-align: center;
            margin-top: 20px;
            color: #777;
            font-size: 14px;
        }

        .login-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }

        .login-link a:hover {
            text-decoration: underline;
        }

        @media (max-width: 600px) {
            .form-row {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.html" class="back-link">← Back to Home</a>

        <h1>Student Registration</h1>
        <p class="form-subtitle">Create your account to participate in college elections</p>

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

        <!-- Registration Form -->
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
            <!-- Name Fields -->
            <div class="form-row">
                <div class="form-group">
                    <label for="first_name">First Name *</label>
                    <input type="text" id="first_name" name="first_name" maxlength="50" value="<?php echo htmlspecialchars(requestString($_POST, 'first_name'), ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="form-group">
                    <label for="middle_name">Middle Name</label>
                    <input type="text" id="middle_name" name="middle_name" maxlength="50" value="<?php echo htmlspecialchars(requestString($_POST, 'middle_name'), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="last_name">Last Name *</label>
                <input type="text" id="last_name" name="last_name" maxlength="50" value="<?php echo htmlspecialchars(requestString($_POST, 'last_name'), ENT_QUOTES, 'UTF-8'); ?>" required>
            </div>

            <!-- Email and Student ID -->
            <div class="form-row">
                <div class="form-group">
                    <label for="email">Email ID *</label>
                    <input type="email" id="email" name="email" maxlength="100" value="<?php echo htmlspecialchars(requestString($_POST, 'email'), ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="form-group">
                    <label for="student_id">Student ID *</label>
                    <input type="text" id="student_id" name="student_id" maxlength="20" value="<?php echo htmlspecialchars(requestString($_POST, 'student_id'), ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g., STU-2026-001" required>
                </div>
            </div>

            <!-- Department -->
            <div class="form-row">
                <div class="form-group">
                    <label for="department">Department *</label>
                    <select id="department" name="department" required>
                        <option value="">-- Select Department --</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo htmlspecialchars($dept); ?>" 
                                <?php echo ($_POST['department'] ?? '') === $dept ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Password Fields -->
            <div class="form-row">
                <div class="form-group">
                    <label for="password">Password *</label>
                    <input type="password" id="password" name="password" required minlength="6" placeholder="Minimum 6 characters">
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm Password *</label>
                    <input type="password" id="confirm_password" name="confirm_password" required minlength="6" placeholder="Re-enter password">
                </div>
            </div>

            <!-- Form Actions -->
            <div class="form-actions">
                <button type="submit" class="btn btn-register">Register</button>
                <a href="index.html" class="btn btn-cancel" style="text-decoration: none; text-align: center;">Cancel</a>
            </div>
        </form>

        <!-- Login Link -->
        <p class="login-link">
            Already registered? <a href="login.php">Login here</a>
        </p>
    </div>
</body>
</html>
