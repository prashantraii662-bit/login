<?php
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
        // User has already voted, redirect to already_voted page
        header('Location: already_voted.php');
        exit();
    }
}
$stmt->close();

// Define candidates for each position
$candidates = [
    'president' => [
        'Balen Shah                       ✌',
        'Ranju Darshana                   🙌',
        'Gagan Thapa                       🐐'
    ],
    'vice_president' => [
        'Ravi Lamichhane                    🦁',
        'Sobita Goutam                       ✍',
        'Kulman Ghishing                     💡'
    ],
    'secretary' => [
        'Harka Sampang                      🥌',
        'Renu Dahal                         🎁',
        'Swornim Wagle                      🧭'
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

