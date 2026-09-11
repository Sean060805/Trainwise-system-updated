<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

if (!isset($_GET['id'])) {
    header("Location: admin_page.php?tab=validation");
    exit();
}

$assessment_id = intval($_GET['id']);

try {
    $stmt = $con->prepare("SELECT u.name, u.department, a.dean_evaluation 
                          FROM assessments a
                          JOIN users u ON a.user_id = u.id
                          WHERE a.id = ?");
    $stmt->bind_param("i", $assessment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception("Evaluation not found");
    }
    
    $evaluation = $result->fetch_assoc();
    $evaluation_data = json_decode($evaluation['dean_evaluation'], true);
    
} catch (Exception $e) {
    error_log("Error viewing evaluation: " . $e->getMessage());
    $_SESSION['error'] = "Error loading evaluation";
    header("Location: admin_page.php?tab=validation");
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>View Evaluation</title>
    <!-- Include your CSS files here -->
</head>
<body>
    <div class="container">
        <h2>Evaluation for <?= htmlspecialchars($evaluation['name']) ?></h2>
        <p>Department: <?= htmlspecialchars($evaluation['department']) ?></p>
        
        <div class="evaluation-details">
            <?php foreach ($evaluation_data['ratings'] as $rating): ?>
                <div class="rating-item">
                    <h4><?= htmlspecialchars($rating['question']) ?></h4>
                    <p>Rating: <?= $rating['score'] ?>/5</p>
                    <p>Remarks: <?= htmlspecialchars($rating['remark']) ?></p>
                </div>
            <?php endforeach; ?>
            
            <div class="comments">
                <h3>Overall Comments</h3>
                <p><?= htmlspecialchars($evaluation_data['comments']) ?></p>
            </div>
            
            <div class="signature">
                <h3>Dean's Signature</h3>
                <img src="<?= htmlspecialchars($evaluation_data['signature']) ?>" alt="Signature">
            </div>
        </div>
        
        <div class="action-buttons">
            <a href="admin_page.php?tab=validation" class="btn">Back to Validation</a>
        </div>
    </div>
</body>
</html>