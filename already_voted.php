<?php
/**
 * ALREADY VOTED CONFIRMATION PAGE
 * ===============================
 * Displays confirmation message when user has already voted
 * Prevents double voting by redirecting here
 */

session_start();
require_once 'db.php';

// Check if user is logged in
if (!isset($_SESSION['student_id'])) {
    header('Location: login.php');
    exit();
}

$user_name = htmlspecialchars($_SESSION['user_name'] ?? 'Student');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vote Submitted - College Voting System</title>
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
            padding: 50px;
            max-width: 500px;
            width: 100%;
            text-align: center;
        }

        .success-icon {
            width: 120px;
            height: 120px;
            background: linear-gradient(135deg, #4caf50 0%, #45a049 100%);
            border-radius: 50%;
            margin: 0 auto 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 60px;
            animation: scaleIn 0.5s ease;
        }

        @keyframes scaleIn {
            from {
                transform: scale(0);
            }
            to {
                transform: scale(1);
            }
        }

        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 28px;
        }

        .message {
            color: #666;
            font-size: 18px;
            margin-bottom: 30px;
            line-height: 1.6;
        }

        .info-box {
            background: #e8f5e9;
            border: 2px solid #4caf50;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
            color: #2e7d32;
        }

        .info-box p {
            margin: 10px 0;
            font-size: 14px;
            line-height: 1.6;
        }

        .status-line {
            color: #4caf50;
            font-weight: 600;
            font-size: 16px;
            margin-bottom: 10px;
        }

        .user-info {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 30px;
            font-size: 14px;
        }

        .user-name {
            color: #667eea;
            font-weight: 600;
        }

        .btn-group {
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
            text-decoration: none;
            display: inline-block;
        }

        .btn-logout {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-logout:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }

        .btn-home {
            background: #f0f0f0;
            color: #333;
        }

        .btn-home:hover {
            background: #e0e0e0;
        }

        .note {
            background: #fff3cd;
            border: 1px solid #ffc107;
            color: #856404;
            padding: 12px;
            border-radius: 8px;
            font-size: 13px;
            margin-top: 20px;
            line-height: 1.5;
        }

        @media (max-width: 600px) {
            .container {
                padding: 30px;
            }

            .btn-group {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Success Icon -->
        <div class="success-icon">✓</div>

        <!-- Main Message -->
        <h1>Vote Submitted Successfully!</h1>
        <p class="message">
            Thank you for participating in the college elections.
        </p>

        <!-- Info Box -->
        <div class="info-box">
            <p class="status-line">✓ Your Vote Status: SUBMITTED</p>
            <p>
                Your vote has been securely recorded in our database. According to voting regulations, 
                each student is allowed to vote only once.
            </p>
        </div>

        <!-- User Info -->
        <div class="user-info">
            <p>Voting as: <span class="user-name"><?php echo $user_name; ?></span></p>
        </div>

        <!-- Important Note -->
        <div class="note">
            <strong>💡 Important:</strong> Your vote is permanent and cannot be changed. 
            Thank you for exercising your democratic right!
        </div>

        <!-- Action Buttons -->
        <div class="btn-group">
            <a href="logout.php" class="btn btn-logout">Logout</a>
            <a href="index.html" class="btn btn-home">Go to Home</a>
        </div>
    </div>
</body>
</html>
