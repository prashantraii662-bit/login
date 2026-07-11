# Online Voting System - Complete Code Documentation

## Overview
This is a complete college election voting system with admin dashboard, user authentication, and real-time vote tracking.

---

## 1. index.php - Home/Landing Page
```php
<?php
session_start();
?>

<!DOCTYPE html>
<html>
<head>
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
```

---

## 2. db.php - Database Configuration
```php
<?php
/**
 * Database Connection Handler
 * ===========================
 * Handles all database connections using MySQLi prepared statements
 * Ensures secure database interaction
 */

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');          // XAMPP default user
define('DB_PASSWORD', '');          // XAMPP default password (empty)
define('DB_NAME', 'voting_system');

// Create connection using MySQLi
$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die('Database Connection Failed: ' . $conn->connect_error);
}

// Set charset to utf8
$conn->set_charset('utf8');

// Display errors in development (remove in production)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

?>
```

---

## 3. login.php - Student Login
[Full login.php code - 328 lines with form styling]

Key Features:
- Student ID and password authentication
- Session management
- Redirects based on voting status
- Password verification using PHP's password_verify()
- Responsive design with modern styling

---

## 4. register.php - Student Registration
[Full register.php code - 395 lines with comprehensive form]

Key Features:
- Collects: First Name, Middle Name, Last Name, Email, Student ID, DOB, Department, Password
- Password hashing with PASSWORD_DEFAULT
- Duplicate Student ID and Email checking
- Validation for all fields
- Department selection dropdown
- Responsive grid layout

---

## 5. vote.php - Voting Page
```php
<?php
/**
 * VOTING PAGE
 * ===========
 * Handles the voting process for multiple positions
 * Positions: President, Vice President, Secretary
 * Uses radio buttons to select one candidate per position
 */

session_start();
require_once 'db.php';

// Check if user is logged in
if (!isset($_SESSION['student_id'])) {
    header('Location: login.php');
    exit();
}

$student_id = $_SESSION['student_id'];
$errors = [];
$success = false;

// Check if user has already voted
$stmt = $conn->prepare('SELECT has_voted FROM users WHERE student_id = ?');
$stmt->bind_param('s', $student_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    if ($user['has_voted'] == 1) {
        header('Location: already_voted.php');
        exit();
    }
}
$stmt->close();

// Define candidates for each position
$candidates = [
    'president' => [
        'Balen Shah',
        'Ranju Darshana',
        'Gagan Thapa'
    ],
    'vice_president' => [
        'Ravi Lamichhane',
        'Sobita Goutam',
        'Kulman Ghishing'
    ],
    'secretary' => [
        'Harka Sampang',
        'Renu Dahal',
        'Swornim Wagle'
    ]
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $president = $_POST['president'] ?? '';
    $vice_president = $_POST['vice_president'] ?? '';
    $secretary = $_POST['secretary'] ?? '';

    // Validate all positions are selected
    if (empty($president)) {
        $errors[] = 'Please select a President candidate';
    }
    if (empty($vice_president)) {
        $errors[] = 'Please select a Vice President candidate';
    }
    if (empty($secretary)) {
        $errors[] = 'Please select a Secretary candidate';
    }

    // If validation passes, store the vote
    if (empty($errors)) {
        // Double-check user hasn't already voted
        $stmt = $conn->prepare('SELECT has_voted FROM users WHERE student_id = ?');
        $stmt->bind_param('s', $student_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if ($user['has_voted'] == 0) {
            // Insert vote into votes table
            $stmt = $conn->prepare(
                'INSERT INTO votes (student_id, president, vice_president, secretary) 
                 VALUES (?, ?, ?, ?)'
            );
            $stmt->bind_param(
                'ssss',
                $student_id,
                $president,
                $vice_president,
                $secretary
            );

            if ($stmt->execute()) {
                // Update user's has_voted flag
                $update_stmt = $conn->prepare('UPDATE users SET has_voted = 1 WHERE student_id = ?');
                $update_stmt->bind_param('s', $student_id);
                $update_stmt->execute();
                $update_stmt->close();

                // Redirect to vote success (thank you) page
                header('Location: vote_success.php?voted=true');
                exit();
            } else {
                $errors[] = 'Error recording your vote. Please try again.';
            }
            $stmt->close();
        } else {
            header('Location: already_voted.php');
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voting - College Voting System</title>
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
            padding: 20px;
        }

        .container {
            max-width: 700px;
            margin: 0 auto;
        }

        .header {
            background: white;
            border-radius: 15px 15px 0 0;
            padding: 30px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            color: #333;
            font-size: 28px;
            margin: 0;
        }

        .user-info {
            text-align: right;
            color: #777;
            font-size: 14px;
        }

        .user-name {
            font-weight: 600;
            color: #667eea;
        }

        .logout-btn {
            display: inline-block;
            background: #e74c3c;
            color: white;
            padding: 8px 15px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 12px;
            margin-top: 5px;
            cursor: pointer;
            border: none;
            transition: background 0.3s;
        }

        .logout-btn:hover {
            background: #c0392b;
        }

        .form-container {
            background: white;
            border-radius: 0 0 15px 15px;
            padding: 30px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
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

        .voting-section {
            margin-bottom: 40px;
        }

        .section-title {
            color: #333;
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #667eea;
        }

        .candidate-group {
            background: #f9f9f9;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 12px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .candidate-group:hover {
            border-color: #667eea;
            background: #f5f5ff;
        }

        .candidate-group input[type="radio"] {
            margin-right: 12px;
            cursor: pointer;
            width: 18px;
            height: 18px;
        }

        .candidate-group label {
            cursor: pointer;
            display: flex;
            align-items: center;
            font-weight: 600;
            color: #333;
        }

        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 30px;
        }

        .btn {
            flex: 1;
            padding: 14px;
            font-size: 16px;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-submit {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-submit:hover {
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

        .info-box {
            background: #e3f2fd;
            border-left: 4px solid #667eea;
            padding: 15px;
            border-radius: 8px;
            color: #1565c0;
            font-size: 13px;
            line-height: 1.6;
            margin-bottom: 25px;
        }

        @media (max-width: 600px) {
            .header {
                flex-direction: column;
                text-align: center;
            }

            .user-info {
                text-align: center;
                margin-top: 15px;
            }

            .form-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>🗳️ Cast Your Vote</h1>
            <div class="user-info">
                <div>Welcome, <span class="user-name"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Student'); ?></span></div>
                <form method="GET" action="logout.php" style="display: inline;">
                    <button type="submit" class="logout-btn">Logout</button>
                </form>
            </div>
        </div>

        <!-- Voting Form -->
        <form method="POST" action="" class="form-container">
            <!-- Information Box -->
            <div class="info-box">
                <strong>ℹ️ Important:</strong> Select exactly ONE candidate for each position. 
                Once you submit your vote, it cannot be changed. Please review your selections carefully before submitting.
            </div>

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

            <!-- President Section -->
            <div class="voting-section">
                <h2 class="section-title">👑 Election for President</h2>
                <?php foreach ($candidates['president'] as $candidate): ?>
                    <div class="candidate-group">
                        <label>
                            <input type="radio" name="president" value="<?php echo htmlspecialchars($candidate); ?>" required>
                            <?php echo htmlspecialchars($candidate); ?>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Vice President Section -->
            <div class="voting-section">
                <h2 class="section-title">📌 Election for Vice President</h2>
                <?php foreach ($candidates['vice_president'] as $candidate): ?>
                    <div class="candidate-group">
                        <label>
                            <input type="radio" name="vice_president" value="<?php echo htmlspecialchars($candidate); ?>" required>
                            <?php echo htmlspecialchars($candidate); ?>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Secretary Section -->
            <div class="voting-section">
                <h2 class="section-title">📋 Election for Secretary</h2>
                <?php foreach ($candidates['secretary'] as $candidate): ?>
                    <div class="candidate-group">
                        <label>
                            <input type="radio" name="secretary" value="<?php echo htmlspecialchars($candidate); ?>" required>
                            <?php echo htmlspecialchars($candidate); ?>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Form Actions -->
            <div class="form-actions">
                <button type="submit" class="btn btn-submit">Submit Vote</button>
                <a href="logout.php" class="btn btn-cancel" style="text-decoration: none; text-align: center;">Cancel & Logout</a>
            </div>
        </form>
    </div>
</body>
</html>
```

---

## 6. vote_success.php - Thank You Page After Voting
```php
<?php
// Include database configuration
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vote Success - Online Voting System</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header>
        <div class="container">
            <h1 style="margin: 0; color: white;">Online Voting System</h1>
            <nav>
                <a href="logout.php">Logout</a>
            </nav>
        </div>
    </header>

    <main>
        <div class="content-wrapper" style="text-align: center;">
            <h1>✓ Vote Successfully Submitted!</h1>

            <div class="alert alert-success" style="margin-top: 30px;">
                <h2 style="color: #155724; margin: 0;">Thank You for Voting!</h2>
                <p style="margin-top: 15px; font-size: 1.1em;">
                    Your vote has been securely recorded. You have successfully completed your voting process.
                </p>
            </div>

            <div style="margin-top: 40px; padding: 20px; background: var(--light-bg); border-radius: var(--border-radius);">
                <p><strong>Important Note:</strong></p>
                <p>You cannot vote again as per the system's one-vote-per-student policy for maintaining election integrity.</p>
            </div>

            <div style="margin-top: 30px;">
                <a href="logout.php" class="btn btn-secondary btn-large">Logout</a>
            </div>
        </div>
    </main>

    <footer>
        <p>&copy; 2026 College Online Voting System. All rights reserved.</p>
    </footer>
</body>
</html>
```

---

## 7. admin_login.php - Admin Authentication
```php
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
```

---

## 8. admin.php - Admin Dashboard
```php
<?php
/**
 * ADMIN PAGE
 * ==========
 * Admin dashboard to view voting results and manage the voting system
 */

session_start();
require_once 'db.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit();
}

// Get voting statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_votes,
        (SELECT COUNT(*) FROM users WHERE has_voted = 1) as voted_users,
        (SELECT COUNT(*) FROM users) as total_users
    FROM votes
";
$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();

// Get results for each position
$president_query = "SELECT president as candidate, COUNT(*) as votes FROM votes GROUP BY president ORDER BY votes DESC";
$vice_president_query = "SELECT vice_president as candidate, COUNT(*) as votes FROM votes GROUP BY vice_president ORDER BY votes DESC";
$secretary_query = "SELECT secretary as candidate, COUNT(*) as votes FROM votes GROUP BY secretary ORDER BY votes DESC";

$president_results = $conn->query($president_query)->fetch_all(MYSQLI_ASSOC);
$vice_president_results = $conn->query($vice_president_query)->fetch_all(MYSQLI_ASSOC);
$secretary_results = $conn->query($secretary_query)->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Online Voting System</title>
    <style>
        body { font-family: Arial; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); margin: 0; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; border-bottom: 2px solid #667eea; padding-bottom: 20px; }
        h1 { color: #333; margin: 0; }
        .logout-btn { padding: 10px 20px; background: #dc3545; color: white; text-decoration: none; border-radius: 5px; font-weight: bold; }
        .logout-btn:hover { background: #c82333; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-box { background: #f8f9fa; padding: 20px; border-radius: 8px; border-left: 4px solid #667eea; }
        .stat-number { font-size: 28px; font-weight: bold; color: #667eea; }
        .stat-label { color: #666; font-size: 14px; margin-top: 5px; }
        .results { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 30px; margin-top: 30px; }
        .position-result { background: #f8f9fa; padding: 20px; border-radius: 8px; border-top: 3px solid #667eea; }
        .position-result h3 { color: #333; margin-top: 0; }
        .candidate-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd; }
        .candidate-row:last-child { border-bottom: none; }
        .candidate-name { font-weight: bold; }
        .candidate-votes { color: #667eea; font-weight: bold; }
        .progress-bar { width: 100%; height: 8px; background: #ddd; border-radius: 4px; margin-top: 5px; overflow: hidden; }
        .progress-fill { height: 100%; background: #667eea; }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>📊 Admin Dashboard</h1>
            <a href="admin_logout.php" class="logout-btn">Logout</a>
        </header>

        <div class="stats">
            <div class="stat-box">
                <div class="stat-number"><?php echo $stats['total_votes'] ?? 0; ?></div>
                <div class="stat-label">Total Votes Cast</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo $stats['voted_users'] ?? 0; ?></div>
                <div class="stat-label">Users Who Voted</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo $stats['total_users'] ?? 0; ?></div>
                <div class="stat-label">Total Registered Users</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo round(($stats['voted_users'] / max($stats['total_users'], 1)) * 100, 1); ?>%</div>
                <div class="stat-label">Voter Turnout</div>
            </div>
        </div>

        <h2 style="color: #333; margin-top: 40px;">Voting Results</h2>
        <div class="results">
            <!-- President Results -->
            <div class="position-result">
                <h3>🎯 President</h3>
                <?php if (!empty($president_results)): ?>
                    <?php 
                    $max_votes = $president_results[0]['votes'] ?? 1;
                    foreach ($president_results as $result): 
                    ?>
                        <div class="candidate-row">
                            <div class="candidate-name"><?php echo htmlspecialchars($result['candidate']); ?></div>
                            <div class="candidate-votes"><?php echo $result['votes']; ?></div>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo ($result['votes'] / max($max_votes, 1)) * 100; ?>%"></div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="color: #999;">No votes yet</p>
                <?php endif; ?>
            </div>

            <!-- Vice President Results -->
            <div class="position-result">
                <h3>📜 Vice President</h3>
                <?php if (!empty($vice_president_results)): ?>
                    <?php 
                    $max_votes = $vice_president_results[0]['votes'] ?? 1;
                    foreach ($vice_president_results as $result): 
                    ?>
                        <div class="candidate-row">
                            <div class="candidate-name"><?php echo htmlspecialchars($result['candidate']); ?></div>
                            <div class="candidate-votes"><?php echo $result['votes']; ?></div>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo ($result['votes'] / max($max_votes, 1)) * 100; ?>%"></div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="color: #999;">No votes yet</p>
                <?php endif; ?>
            </div>

            <!-- Secretary Results -->
            <div class="position-result">
                <h3>✍️ Secretary</h3>
                <?php if (!empty($secretary_results)): ?>
                    <?php 
                    $max_votes = $secretary_results[0]['votes'] ?? 1;
                    foreach ($secretary_results as $result): 
                    ?>
                        <div class="candidate-row">
                            <div class="candidate-name"><?php echo htmlspecialchars($result['candidate']); ?></div>
                            <div class="candidate-votes"><?php echo $result['votes']; ?></div>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo ($result['votes'] / max($max_votes, 1)) * 100; ?>%"></div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="color: #999;">No votes yet</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
```

---

## 9. admin_logout.php - Admin Logout
```php
<?php
/**
 * ADMIN LOGOUT
 * ============
 * Logs out the admin user
 */

session_start();
session_destroy();
header('Location: index.php');
exit();
?>
```

---

## 10. logout.php - User Logout
```php
<?php
/**
 * LOGOUT PAGE
 * ===========
 * Handles user session destruction and redirects to login page
 */

session_start();
session_destroy();
header('Location: index.html');
exit();

?>
```

---

## Database Setup

### SQL Database Structure
Create a database named `voting_system` with the following tables:

```sql
-- Users Table
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100),
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    student_id VARCHAR(50) UNIQUE NOT NULL,
    department VARCHAR(100),
    password VARCHAR(255) NOT NULL,
    has_voted INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Votes Table
CREATE TABLE votes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id VARCHAR(50) UNIQUE NOT NULL,
    president VARCHAR(100) NOT NULL,
    vice_president VARCHAR(100) NOT NULL,
    secretary VARCHAR(100) NOT NULL,
    voted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(student_id)
);
```

---

## Admin Credentials
- **Username:** admin
- **Password:** admin123

⚠️ **Important:** Change these credentials in production!

---

## File Structure
```
Voting/
├── index.php (Home Page)
├── db.php (Database Connection)
├── login.php (Student Login)
├── register.php (Student Registration)
├── vote.php (Voting Page)
├── vote_success.php (Thank You Page)
├── logout.php (User Logout)
├── admin_login.php (Admin Login)
├── admin.php (Admin Dashboard)
├── admin_logout.php (Admin Logout)
└── ALL_CODE_SUMMARY.md (This File)
```

---

## Features
✅ Student Registration with Email & Student ID Validation  
✅ Secure Login with Password Hashing  
✅ One-Vote-Per-Student Policy  
✅ Admin Dashboard with Real-Time Results  
✅ Vote Progress Bars & Statistics  
✅ Responsive Design  
✅ Session Management  
✅ Thank You Page After Voting  
✅ Voter Turnout Percentage  

---

## Security Notes
- Passwords are hashed using PHP's PASSWORD_DEFAULT algorithm
- SQL Prepared Statements prevent SQL injection
- User inputs are sanitized and validated
- Session-based authentication
- XSS protection via htmlspecialchars()

---

End of Documentation
```
