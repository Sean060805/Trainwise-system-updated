<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Database connection
require_once 'config.php';

// Get evaluation ID from session or URL
$evaluation_id = isset($_SESSION['view_evaluation_id']) ? $_SESSION['view_evaluation_id'] : (isset($_GET['id']) ? $_GET['id'] : null);

if (!$evaluation_id) {
    header("Location: CCS_admin/CCS.php");
    exit();
}

// Clear the session variable after use
unset($_SESSION['view_evaluation_id']);

// Get evaluation data
$evaluation_sql = "SELECT 
    e.*,
    u.name as employee_name,
    u.department as employee_department,
    evaluator.name as evaluator_name
FROM evaluations e
JOIN users u ON e.user_id = u.id
JOIN users evaluator ON e.evaluator_id = evaluator.id
WHERE e.id = ?";

$stmt = $con->prepare($evaluation_sql);
$stmt->bind_param("i", $evaluation_id);
$stmt->execute();
$evaluation_result = $stmt->get_result();

if ($evaluation_result->num_rows === 0) {
    die("Evaluation not found.");
}

$evaluation = $evaluation_result->fetch_assoc();

// Get evaluation ratings
$ratings_sql = "SELECT * FROM evaluation_ratings WHERE evaluation_id = ? ORDER BY question_number";
$ratings_stmt = $con->prepare($ratings_sql);
$ratings_stmt->bind_param("i", $evaluation_id);
$ratings_stmt->execute();
$ratings_result = $ratings_stmt->get_result();

$ratings = [];
while ($rating = $ratings_result->fetch_assoc()) {
    $ratings[$rating['question_number']] = $rating;
}

// Get workflow history
$workflow_sql = "SELECT 
    wf.*,
    u.name as changed_by_name
FROM evaluation_workflow wf
JOIN users u ON wf.changed_by = u.id
WHERE wf.evaluation_id = ?
ORDER BY wf.created_at ASC";

$workflow_stmt = $con->prepare($workflow_sql);
$workflow_stmt->bind_param("i", $evaluation_id);
$workflow_stmt->execute();
$workflow_result = $workflow_stmt->get_result();

$workflow_history = [];
while ($history = $workflow_result->fetch_assoc()) {
    $workflow_history[] = $history;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Evaluation - Training Program Impact Assessment</title>
    <link rel="stylesheet" href="assets/css/tw-0.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/4.6.0/remixicon.min.css">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f8fafc;
        }
        .form-container {
            background: white;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            max-width: 1000px;
            margin: 0 auto;
        }
        .form-field {
            border: 1px solid #d1d5db;
            background-color: #f9fafb;
        }
        .form-field:focus {
            outline: none;
            border-color: #3b82f6;
        }
        .rating-cell {
            text-align: center;
            padding: 8px 4px;
        }
        .rating-selected {
            background-color: #3b82f6;
            color: white;
            border-radius: 4px;
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.35rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: capitalize;
        }
        .status-draft {
            background-color: #f3f4f6;
            color: #374151;
        }
        .status-submitted {
            background-color: #ddd6fe;
            color: #5b21b6;
        }
        .status-approved {
            background-color: #bbf7d0;
            color: #166534;
        }
        .status-rejected {
            background-color: #fecaca;
            color: #991b1b;
        }
        .workflow-timeline {
            border-left: 2px solid #e5e7eb;
            margin-left: 10px;
        }
        .workflow-step {
            position: relative;
            padding-left: 20px;
            margin-bottom: 1rem;
        }
        .workflow-step::before {
            content: '';
            position: absolute;
            left: -6px;
            top: 6px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background-color: #3b82f6;
        }
        .signature-preview {
            border: 1px solid #d1d5db;
            background-color: #f9fafb;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .signature-image {
            max-width: 100%;
            max-height: 70px;
        }
    </style>
</head>
<body class="min-h-screen p-4 md:p-8 bg-gray-50">
    <div class="form-container rounded-lg p-6 md:p-8">
        <!-- Header -->
        <div class="flex justify-between items-center mb-6 border-b pb-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-800">TRAINING PROGRAM IMPACT ASSESSMENT FORM</h1>
                <div class="flex items-center space-x-4 mt-2">
                    <span class="status-badge status-<?= $evaluation['status'] ?>">
                        <?= ucfirst($evaluation['status']) ?>
                    </span>
                    <span class="text-sm text-gray-500">
                        Created: <?= date('M d, Y h:i A', strtotime($evaluation['created_at'])) ?>
                    </span>
                    <?php if ($evaluation['updated_at'] != $evaluation['created_at']): ?>
                    <span class="text-sm text-gray-500">
                        Updated: <?= date('M d, Y h:i A', strtotime($evaluation['updated_at'])) ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="flex space-x-2">
                <button onclick="window.print()" class="px-4 py-2 bg-green-500 text-white rounded hover:bg-green-600 transition-colors">
                    <i class="ri-printer-line mr-2"></i>Print
                </button>
                <a href="CCS_admin/CCS.php" class="px-4 py-2 bg-gray-500 text-white rounded hover:bg-gray-600 transition-colors">
                    <i class="ri-arrow-left-line mr-2"></i>Back to Dashboard
                </a>
            </div>
        </div>

        <!-- Basic Information -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Name of Employee:</label>
                <div class="form-field w-full px-4 py-2 rounded"><?= htmlspecialchars($evaluation['employee_name']) ?></div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Department/Unit:</label>
                <div class="form-field w-full px-4 py-2 rounded"><?= htmlspecialchars($evaluation['employee_department']) ?></div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Title of Training/Seminar Attended:</label>
                <div class="form-field w-full px-4 py-2 rounded"><?= htmlspecialchars($evaluation['training_title']) ?></div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Date Conducted:</label>
                <div class="form-field w-full px-4 py-2 rounded"><?= date('M d, Y', strtotime($evaluation['date_conducted'])) ?></div>
            </div>
        </div>

        <!-- Objectives -->
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-700 mb-1">Objective/s:</label>
            <div class="form-field w-full px-4 py-2 rounded min-h-[80px]"><?= nl2br(htmlspecialchars($evaluation['objectives'])) ?></div>
        </div>

        <!-- Ratings Table -->
        <div class="mb-6">
            <div class="bg-gray-100 p-3 rounded mb-4">
                <p class="text-sm text-gray-700"><span class="font-medium">INSTRUCTION:</span> Please check (✓) in the appropriate column the impact/benefits gained by the employee in attending the training program in a scale of 1-5 (where 5 – Strongly Agree; 4 – Agree; 3 – Neither agree nor disagree; 2 – Disagree; 1 – Strongly Disagree)</p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full border-collapse border border-gray-300">
                    <thead>
                        <tr class="bg-gray-50">
                            <th class="text-left py-3 px-4 font-medium text-gray-700 border border-gray-300 w-1/2">IMPACT/BENEFITS GAINED</th>
                            <th class="text-center py-3 px-2 font-medium text-gray-700 border border-gray-300 w-8">1</th>
                            <th class="text-center py-3 px-2 font-medium text-gray-700 border border-gray-300 w-8">2</th>
                            <th class="text-center py-3 px-2 font-medium text-gray-700 border border-gray-300 w-8">3</th>
                            <th class="text-center py-3 px-2 font-medium text-gray-700 border border-gray-300 w-8">4</th>
                            <th class="text-center py-3 px-2 font-medium text-gray-700 border border-gray-300 w-8">5</th>
                            <th class="text-left py-3 px-4 font-medium text-gray-700 border border-gray-300">REMARKS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $questions = [
                            "1. The employee's performance became more efficient as shown with no/less commitment of mistakes on work.",
                            "2. The employee enhanced his/her ability to generate ideas and recommendations.",
                            "3. He/She has developed new system or improved the present system through contributing new ideas.",
                            "4. The employee's morale has been upgraded.",
                            "5. The employee has applied new skills in the performance of his/her work.",
                            "6. The employee became more proud and confident in his/her tasks.",
                            "7. The employee can now be entrusted higher/greater responsibility.",
                            "8. He/She transferred the knowledge and skills gained through conduct of workshop or demonstration to co-employee."
                        ];
                        
                        for ($i = 1; $i <= 8; $i++): 
                            $rating = $ratings[$i] ?? null;
                        ?>
                        <tr class="<?= $i % 2 === 0 ? 'bg-gray-50' : 'bg-white' ?>">
                            <td class="py-3 px-4 border border-gray-300 text-gray-700 text-sm">
                                <?= $questions[$i-1] ?>
                            </td>
                            <?php for ($j = 1; $j <= 5; $j++): ?>
                            <td class="rating-cell border border-gray-300">
                                <?php if ($rating && $rating['rating'] == $j): ?>
                                <div class="rating-selected w-8 h-8 flex items-center justify-center mx-auto">
                                    <i class="ri-check-line"></i>
                                </div>
                                <?php else: ?>
                                <div class="w-8 h-8 flex items-center justify-center mx-auto text-gray-400">
                                    <?= $j ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <?php endfor; ?>
                            <td class="py-2 px-4 border border-gray-300">
                                <div class="text-sm text-gray-700"><?= htmlspecialchars($rating['remark'] ?? '') ?></div>
                            </td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Comments -->
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-700 mb-1">Comments:</label>
            <div class="form-field w-full px-4 py-2 rounded min-h-[80px]"><?= nl2br(htmlspecialchars($evaluation['comments'])) ?></div>
        </div>

        <!-- Future Training Needs -->
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-700 mb-1">Please list down other training programs he/she might need in the future:</label>
            <div class="form-field w-full px-4 py-2 rounded min-h-[100px]"><?= nl2br(htmlspecialchars($evaluation['future_training_needs'])) ?></div>
        </div>

        <!-- Signature Section -->
        <div class="border-t border-gray-200 pt-6">
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Rated by:</label>
                    <div class="form-field w-full px-4 py-2 rounded"><?= htmlspecialchars($evaluation['rated_by']) ?></div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Signature:</label>
                    <div class="signature-preview rounded">
                        <?php if (!empty($evaluation['signature_date'])): ?>
                            <img src="<?= htmlspecialchars($evaluation['signature_date']) ?>" alt="Signature" class="signature-image">
                        <?php else: ?>
                            <span class="text-gray-400">No signature</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date:</label>
                    <div class="form-field w-full px-4 py-2 rounded">
                        <?= $evaluation['created_at'] ? date('M d, Y', strtotime($evaluation['created_at'])) : 'Not set' ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Workflow History -->
        <?php if (!empty($workflow_history)): ?>
        <div class="mt-8 border-t pt-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Evaluation History</h3>
            <div class="workflow-timeline">
                <?php foreach ($workflow_history as $history): ?>
                <div class="workflow-step">
                    <div class="bg-white p-3 rounded-lg shadow-sm">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="font-medium text-gray-800">
                                    Status changed from 
                                    <span class="text-blue-600"><?= $history['from_status'] ? ucfirst($history['from_status']) : 'None' ?></span> 
                                    to 
                                    <span class="text-green-600"><?= ucfirst($history['to_status']) ?></span>
                                </p>
                                <p class="text-sm text-gray-500 mt-1">
                                    By: <?= htmlspecialchars($history['changed_by_name']) ?>
                                </p>
                                <?php if (!empty($history['comments'])): ?>
                                <p class="text-sm text-gray-600 mt-2">
                                    <strong>Comment:</strong> <?= htmlspecialchars($history['comments']) ?>
                                </p>
                                <?php endif; ?>
                            </div>
                            <span class="text-xs text-gray-400">
                                <?= date('M d, Y h:i A', strtotime($history['created_at'])) ?>
                            </span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Action Buttons -->
        <div class="mt-8 flex justify-end space-x-4 pt-6 border-t">
            <?php if ($evaluation['status'] === 'draft'): ?>
            <form method="POST" action="CCS_admin/CCS.php" class="inline">
                <input type="hidden" name="evaluation_id" value="<?= $evaluation_id ?>">
                <button type="submit" name="send_evaluation" class="px-6 py-2 bg-green-600 text-white rounded hover:bg-green-700 transition-colors" onclick="return confirm('Submit this evaluation to HR?')">
                    <i class="ri-send-plane-line mr-2"></i>Submit to HR
                </button>
            </form>
            <?php endif; ?>
            
            <a href="CCS_admin/ccs_eval.php?evaluation_id=<?= $evaluation_id ?>&user_id=<?= $evaluation['user_id'] ?>" class="px-6 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 transition-colors">
                <i class="ri-edit-line mr-2"></i>Edit Evaluation
            </a>
        </div>
    </div>

    <script>
        // Print functionality
        function printForm() {
            window.print();
        }

        // Auto-print if specified in URL
        <?php if (isset($_GET['print']) && $_GET['print'] == '1'): ?>
        window.addEventListener('load', function() {
            window.print();
        });
        <?php endif; ?>
    </script>
</body>
</html>