<?php
require_once 'auth.php';
startAppSession();
require_once 'db.php';

// If already logged in, send the user to the dashboard for their role.
if (!empty($_SESSION['user_id']) && in_array($_SESSION['role'] ?? '', ['student', 'admin'], true)) {
    if (userRoleIsCurrent($_SESSION['role'])) {
        header('Location: ' . (($_SESSION['role'] ?? '') === 'admin' ? 'admin.php' : 'dashboard.php'));
        exit();
    }
    destroyAppSession();
}

$errors = [];
$success_message = '';
$selectedLoginType = requestString($_GET, 'type') === 'admin' ? 'admin' : 'student';

// Check for success message from registration
if (isset($_GET['success'])) {
    $success_message = htmlspecialchars(requestString($_GET, 'success'), ENT_QUOTES, 'UTF-8');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedLoginType = requestString($_POST, 'account_type');
    if (in_array($submittedLoginType, ['student', 'admin'], true)) {
        $selectedLoginType = $submittedLoginType;
    } else {
        $errors[] = 'Choose a valid login type.';
    }
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    }
    $identifierField = $selectedLoginType === 'admin' ? 'admin_identifier' : 'student_identifier';
    $loginIdentifier = trim(requestString($_POST, $identifierField));
    $password = requestString($_POST, 'password');

    if ($loginIdentifier === '') {
        $errors[] = $selectedLoginType === 'admin'
            ? 'Enter your administrator email address or Student ID.'
            : 'Enter your Student ID or email address.';
    }
    if ($password === '') {
        $errors[] = 'Enter your password.';
    }

    if (empty($errors)) {
        try {
            $expectedRole = $selectedLoginType;
            $stmt = $conn->prepare(
                'SELECT id, first_name, last_name, student_id, password, has_voted, role, is_verified, is_active
                 FROM users
                 WHERE role = ? AND (student_id = ? OR email = ?)
                 LIMIT 1'
            );
            $stmt->bind_param('sss', $expectedRole, $loginIdentifier, $loginIdentifier);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$user || !password_verify($password, $user['password'])) {
                $errors[] = $selectedLoginType === 'admin'
                    ? 'Invalid admin username/email or password.'
                    : 'Invalid student ID/email or password.';
            } elseif (!(int) $user['is_active']) {
                $errors[] = 'Your account is inactive. Please contact the administrator.';
            } elseif (!(int) $user['is_verified']) {
                $errors[] = 'Your account is awaiting administrator verification before you can log in.';
            } elseif ($user['role'] !== $expectedRole) {
                $errors[] = $selectedLoginType === 'admin'
                    ? 'Invalid admin username/email or password.'
                    : 'Invalid student ID/email or password.';
            } else {
                establishUserSession($user, $user['role']);
                if ($user['role'] === 'admin') {
                    try {
                        $actorId = (int) $user['id'];
                        $auditStmt = $conn->prepare(
                            "INSERT INTO admin_audit_log (actor_user_id, action, entity_type, entity_id)
                             VALUES (?, 'admin_login', 'user', ?)"
                        );
                        $auditStmt->bind_param('ii', $actorId, $actorId);
                        $auditStmt->execute();
                        $auditStmt->close();
                    } catch (mysqli_sql_exception $exception) {
                        error_log('Admin login audit recording failed: ' . $exception->getMessage());
                    }
                    header('Location: admin.php');
                } else {
                    header('Location: dashboard.php');
                }
                exit();
            }
        } catch (mysqli_sql_exception $exception) {
            error_log('Login database operation failed: ' . $exception->getMessage());
            $errors[] = 'Login is temporarily unavailable. Please try again.';
        }
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
        .login-tabs { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin: 0 0 24px; padding: 5px; border-radius: 10px; background: #f2f3f9; }
        .login-tab { min-height: 42px; border: 0; border-radius: 7px; background: transparent; color: #667085; font: inherit; font-size: 13px; font-weight: 700; cursor: pointer; }
        .login-tab[aria-selected="true"] { background: white; color: #586de0; box-shadow: 0 2px 7px rgba(35,45,75,.1); }
        .login-panel[hidden] { display: none; }
        .login-panel h2 { margin: 0 0 7px; color: #333; font-size: 19px; }
        .login-panel .panel-description { margin: 0 0 22px; color: #777; font-size: 13px; line-height: 1.5; }
        .account-note { margin-top: 18px; color: #7a8190; font-size: 12px; line-height: 1.5; text-align: center; }
        @media (max-width: 420px) { .container { padding: 28px 22px; } .login-tab { font-size: 12px; } }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.html" class="back-link">← Back to Home</a>

        <h1>Online Voting System</h1>
        <p class="form-subtitle">Choose your account type to sign in</p>

        <div class="login-tabs" role="tablist" aria-label="Choose login type">
            <button type="button" class="login-tab" id="student-tab" role="tab" aria-controls="student-panel" aria-selected="<?php echo $selectedLoginType === 'student' ? 'true' : 'false'; ?>" data-login-tab="student">Login as Student</button>
            <button type="button" class="login-tab" id="admin-tab" role="tab" aria-controls="admin-panel" aria-selected="<?php echo $selectedLoginType === 'admin' ? 'true' : 'false'; ?>" data-login-tab="admin">Login as Admin</button>
        </div>

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

        <section class="login-panel" id="student-panel" role="tabpanel" aria-labelledby="student-tab" <?php echo $selectedLoginType === 'student' ? '' : 'hidden'; ?>>
            <h2>Student Login</h2>
            <p class="panel-description">Sign in with your registered Student ID or email.</p>
            <form method="POST" action="login.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="account_type" value="student">
                <div class="form-group">
                    <label for="student_identifier">Student ID or email *</label>
                    <input type="text" id="student_identifier" name="student_identifier" value="<?php echo htmlspecialchars(requestString($_POST, 'student_identifier'), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Enter your Student ID or email" autocomplete="username" required <?php echo $selectedLoginType === 'student' ? 'autofocus' : 'disabled'; ?>>
                </div>
                <div class="form-group">
                    <label for="student_password">Password *</label>
                    <div class="password-control">
                        <input type="password" id="student_password" name="password" placeholder="Enter your password" autocomplete="current-password" required <?php echo $selectedLoginType === 'student' ? '' : 'disabled'; ?>>
                        <button type="button" class="password-toggle" aria-controls="student_password" aria-label="Show password" data-password-toggle <?php echo $selectedLoginType === 'student' ? '' : 'disabled'; ?>>Show</button>
                    </div>
                </div>
                <div class="btn-group">
                    <button type="submit" class="btn btn-login" <?php echo $selectedLoginType === 'student' ? '' : 'disabled'; ?>>Login as Student</button>
                    <a href="index.html" class="btn btn-cancel" style="text-decoration: none; text-align: center;">Back to Home</a>
                </div>
            </form>
            <p class="register-link">Not registered? <a href="register.php">Register here</a></p>
        </section>

        <section class="login-panel" id="admin-panel" role="tabpanel" aria-labelledby="admin-tab" <?php echo $selectedLoginType === 'admin' ? '' : 'hidden'; ?>>
            <h2>Admin Login</h2>
            <p class="panel-description">Use the email address or Student ID linked to your administrator account.</p>
            <form method="POST" action="login.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="account_type" value="admin">
                <div class="form-group">
                    <label for="admin_identifier">Admin username or email *</label>
                    <input type="text" id="admin_identifier" name="admin_identifier" value="<?php echo htmlspecialchars(requestString($_POST, 'admin_identifier'), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Enter your admin email or Student ID" autocomplete="username" required <?php echo $selectedLoginType === 'admin' ? 'autofocus' : 'disabled'; ?>>
                </div>
                <div class="form-group">
                    <label for="admin_password">Password *</label>
                    <div class="password-control">
                        <input type="password" id="admin_password" name="password" placeholder="Enter your password" autocomplete="current-password" required <?php echo $selectedLoginType === 'admin' ? '' : 'disabled'; ?>>
                        <button type="button" class="password-toggle" aria-controls="admin_password" aria-label="Show password" data-password-toggle <?php echo $selectedLoginType === 'admin' ? '' : 'disabled'; ?>>Show</button>
                    </div>
                </div>
                <div class="btn-group">
                    <button type="submit" class="btn btn-login" <?php echo $selectedLoginType === 'admin' ? '' : 'disabled'; ?>>Login as Admin</button>
                    <a href="index.html" class="btn btn-cancel" style="text-decoration: none; text-align: center;">Back to Home</a>
                </div>
            </form>
            <p class="account-note">Administrator access is determined by your account's database role.</p>
        </section>

        <div class="info-box student-note" <?php echo $selectedLoginType === 'student' ? '' : 'hidden'; ?>>
            <strong>💡 Note:</strong> New accounts need administrator verification
            before they can sign in and participate in elections.
        </div>
    </div>
    <script>
        const loginTabs = document.querySelectorAll('[data-login-tab]');
        const loginPanels = {
            student: document.getElementById('student-panel'),
            admin: document.getElementById('admin-panel')
        };

        const setLoginType = (selectedType, focusForm) => {
            loginTabs.forEach((tab) => {
                const selected = tab.dataset.loginTab === selectedType;
                tab.setAttribute('aria-selected', String(selected));
            });
            Object.keys(loginPanels).forEach((type) => {
                loginPanels[type].hidden = type !== selectedType;
                loginPanels[type].querySelectorAll('input:not([type="hidden"]), button[type="submit"], .password-toggle').forEach((control) => {
                    control.disabled = type !== selectedType;
                });
            });
            document.querySelector('.student-note').hidden = selectedType !== 'student';
            if (focusForm) {
                loginPanels[selectedType].querySelector('input[autocomplete="username"]').focus();
            }
        };

        loginTabs.forEach((tab) => {
            tab.addEventListener('click', () => {
                const selectedType = tab.dataset.loginTab;
                setLoginType(selectedType, true);
            });
        });
        setLoginType(document.querySelector('[data-login-tab][aria-selected="true"]').dataset.loginTab, false);

        document.querySelectorAll('[data-password-toggle]').forEach((toggle) => {
            toggle.addEventListener('click', () => {
                const password = document.getElementById(toggle.getAttribute('aria-controls'));
                const showPassword = password.type === 'password';
                password.type = showPassword ? 'text' : 'password';
                toggle.textContent = showPassword ? 'Hide' : 'Show';
                toggle.setAttribute('aria-label', showPassword ? 'Hide password' : 'Show password');
            });
        });
    </script>
</body>
</html>
