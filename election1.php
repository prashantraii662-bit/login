<?php
session_start();

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "voting_system";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Fetch candidates
$candidates_query = "SELECT * FROM candidates WHERE position IN ('President', 'Vice President', 'Secretary')";
$candidates_result = $conn->query($candidates_query);

// Check if user already voted
$user_id = $_SESSION['user_id'];
$vote_check = $conn->query("SELECT has_voted FROM users WHERE id = $user_id");
$already_voted = $vote_check->fetch_assoc()['has_voted'] == 1;

// Handle vote submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !$already_voted) {
    $candidate_id = intval($_POST['candidate_id']);
    
    $stmt = $conn->prepare("INSERT INTO votes (user_id, candidate_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $user_id, $candidate_id);
    
    if ($stmt->execute()) {
        $success_message = "Vote submitted successfully!";
        // Update has_voted
        $conn->query("UPDATE users SET has_voted = 1 WHERE id = $user_id");
    } else {
        $error_message = "Error submitting vote.";
    }
    $stmt->close();
}

// Fetch vote results
$results_query = "SELECT c.name, c.position, COUNT(v.id) as vote_count 
                  FROM candidates c 
                  LEFT JOIN votes v ON c.id = v.candidate_id 
                  GROUP BY c.id 
                  ORDER BY c.position, vote_count DESC";
$results_result = $conn->query($results_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voting - Election System</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f4f4f4; }
        .container { max-width: 900px; margin: 50px auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        h1 { text-align: center; color: #333; margin-bottom: 30px; }
        .logout-btn { float: right; background: #dc3545; color: white; padding: 10px 15px; border: none; border-radius: 5px; cursor: pointer; text-decoration: none; }
        .logout-btn:hover { background: #c82333; }
        .message { padding: 15px; margin-bottom: 20px; border-radius: 5px; text-align: center; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .candidates { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; }
        .candidate-card { border: 1px solid #ddd; padding: 20px; border-radius: 8px; text-align: center; transition: box-shadow 0.3s; }
        .candidate-card:hover { box-shadow: 0 0 15px rgba(0,0,0,0.2); }
        .candidate-card h3 { color: #007bff; margin-top: 10px; }
        .candidate-card p { color: #666; font-size: 14px; margin: 10px 0; }
        .vote-btn { background: #28a745; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; margin-top: 10px; }
        .vote-btn:hover { background: #218838; }
        .vote-btn:disabled { background: #6c757d; cursor: not-allowed; }
        .voted-message { color: #dc3545; font-weight: bold; text-align: center; margin-top: 20px; }
        .results { margin-top: 30px; }
        .results h3 { color: #007bff; margin-top: 20px; }
        .position-results { display: flex; flex-direction: column; gap: 10px; }
        .result-item { background: #f8f9fa; padding: 10px; border-radius: 5px; border-left: 4px solid #007bff; }
    </style>
</head>
<body>
    <div class="container">
        <a href="logout.php" class="logout-btn">Logout</a>
        <h1>🗳️ Cast Your Vote</h1>
        
        <?php if (isset($success_message)): ?>
            <div class="message success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>
        
        <?php if (isset($error_message)): ?>
            <div class="message error"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>
        
        <?php if ($already_voted): ?>
            <div class="voted-message">✓ You have already voted. Thank you for your participation!</div>
        <?php endif; ?>
        
        <?php if (!$already_voted && !isset($success_message)): ?>
            <form method="POST">
                <div class="candidates">
                    <?php while ($candidate = $candidates_result->fetch_assoc()): ?>
                        <div class="candidate-card">
                            <h3><?php echo htmlspecialchars($candidate['name']); ?></h3>
                            <p><strong><?php echo htmlspecialchars($candidate['position']); ?></strong></p>
                            <p><?php echo htmlspecialchars($candidate['description']); ?></p>
                            <button type="submit" name="candidate_id" value="<?php echo $candidate['id']; ?>" class="vote-btn">Vote</button>
                        </div>
                    <?php endwhile; ?>
                </div>
            </form>
        <?php endif; ?>
        
        <?php if ($already_voted || isset($success_message)): ?>
            <h2>Election Results</h2>
            <div class="results">
                <?php 
                $current_position = '';
                mysqli_data_seek($results_result, 0); // Reset result pointer
                while ($result = $results_result->fetch_assoc()): 
                    if ($current_position != $result['position']):
                        if ($current_position != '') echo '</div>';
                        $current_position = $result['position'];
                        echo "<h3>$current_position</h3><div class='position-results'>";
                    ?>
                    <div class="result-item">
                        <strong><?php echo htmlspecialchars($result['name']); ?></strong>: <?php echo $result['vote_count']; ?> votes
                    </div>
                <?php endwhile; ?>
                <?php if ($current_position != '') echo '</div>'; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>

<?php $conn->close(); ?>
