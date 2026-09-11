<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$evaluation_id = $_GET['evaluation_id'] ?? null;
$user_id = $_SESSION['user_id'];

if (!$evaluation_id) {
    echo json_encode(['success' => false, 'message' => 'No evaluation ID provided']);
    exit();
}

try {
    // Get evaluation details - user can only see their own evaluations
    $evaluation_sql = "SELECT 
        e.*,
        u.name as employee_name,
        u.department as employee_department,
        u.teaching_status,
        evaluator.name as evaluator_name,
        sent_by.name as sent_by_name
    FROM evaluations e
    JOIN users u ON e.user_id = u.id
    JOIN users evaluator ON e.evaluator_id = evaluator.id
    LEFT JOIN users sent_by ON e.sent_by = sent_by.id
    WHERE e.id = ? AND e.user_id = ?";

    $stmt = $con->prepare($evaluation_sql);
    $stmt->bind_param("ii", $evaluation_id, $user_id);
    $stmt->execute();
    $evaluation_result = $stmt->get_result();

    if ($evaluation_result->num_rows > 0) {
        $evaluation_details = $evaluation_result->fetch_assoc();
        
        // Get evaluation ratings
        $ratings_sql = "SELECT * FROM evaluation_ratings WHERE evaluation_id = ? ORDER BY question_number";
        $ratings_stmt = $con->prepare($ratings_sql);
        $ratings_stmt->bind_param("i", $evaluation_id);
        $ratings_stmt->execute();
        $ratings_result = $ratings_stmt->get_result();

        $evaluation_ratings = [];
        while ($rating = $ratings_result->fetch_assoc()) {
            $evaluation_ratings[$rating['question_number']] = $rating;
        }

        // Generate HTML content
        ob_start();
        ?>
        <div class="space-y-6">
            <!-- Header Information -->
            <div class="flex justify-between items-center mb-6 border-b pb-4">
                <div>
                    <?php if ($evaluation_details['status'] === 'submitted'): ?>
                        <span class="status-badge status-submitted">
                            <i class="ri-send-plane-fill mr-1"></i> Submitted
                        </span>
                    <?php elseif ($evaluation_details['status'] === 'sent_to_user'): ?>
                        <span class="status-badge status-sent">
                            <i class="ri-check-double-fill mr-1"></i> Sent to You
                        </span>
                    <?php elseif ($evaluation_details['status'] === 'approved'): ?>
                        <span class="status-badge status-approved">
                            <i class="ri-checkbox-circle-fill mr-1"></i> Approved
                        </span>
                    <?php endif; ?>
                    <span class="text-sm text-gray-500 ml-4">
                        Created: <?= date('M d, Y h:i A', strtotime($evaluation_details['created_at'])) ?>
                    </span>
                </div>
            </div>

            <?php if ($evaluation_details['status'] === 'sent_to_user' && !empty($evaluation_details['sent_by_name'])): ?>
                <div class="sent-info">
                    <p><strong>Sent to you on:</strong> <?= date('M d, Y \a\t g:i A', strtotime($evaluation_details['sent_to_user_at'])) ?></p>
                    <p><strong>Sent by:</strong> <?= htmlspecialchars($evaluation_details['sent_by_name']) ?></p>
                </div>
            <?php endif; ?>

            <!-- Basic Information -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Name of Employee:</label>
                    <div class="form-field"><?= htmlspecialchars($evaluation_details['employee_name']) ?></div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Department/Unit:</label>
                    <div class="form-field"><?= htmlspecialchars($evaluation_details['employee_department']) ?></div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Teaching Status:</label>
                    <div class="form-field"><?= htmlspecialchars($evaluation_details['teaching_status']) ?></div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Title of Training/Seminar Attended:</label>
                    <div class="form-field"><?= htmlspecialchars($evaluation_details['training_title']) ?></div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date Conducted:</label>
                    <div class="form-field"><?= date('M d, Y', strtotime($evaluation_details['date_conducted'])) ?></div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Evaluated by:</label>
                    <div class="form-field"><?= htmlspecialchars($evaluation_details['evaluator_name']) ?></div>
                </div>
            </div>

            <!-- Objectives -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-1">Objective/s:</label>
                <div class="form-field min-h-[80px]"><?= nl2br(htmlspecialchars($evaluation_details['objectives'] ?? '')) ?></div>
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
                                $rating = $evaluation_ratings[$i] ?? null;
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
                <div class="form-field min-h-[80px]"><?= nl2br(htmlspecialchars($evaluation_details['comments'] ?? '')) ?></div>
            </div>

            <!-- Future Training Needs -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-1">Please list down other training programs he/she might need in the future:</label>
                <div class="form-field min-h-[100px]"><?= nl2br(htmlspecialchars($evaluation_details['future_training_needs'] ?? '')) ?></div>
            </div>

            <!-- Signature Section -->
            <div class="border-t border-gray-200 pt-6">
                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Rated by:</label>
                        <div class="form-field"><?= htmlspecialchars($evaluation_details['rated_by'] ?? '') ?></div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Signature:</label>
                        <div class="signature-preview rounded">
                            <?php if (!empty($evaluation_details['signature_date'])): ?>
                                <img src="<?= htmlspecialchars($evaluation_details['signature_date']) ?>" alt="Signature" class="signature-image">
                            <?php else: ?>
                                <span class="text-gray-400">No signature</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date:</label>
                        <div class="form-field">
                            <?= $evaluation_details['created_at'] ? date('M d, Y', strtotime($evaluation_details['created_at'])) : 'Not set' ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
        $html_content = ob_get_clean();
        
        echo json_encode(['success' => true, 'html' => $html_content]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Evaluation not found or access denied']);
    }
} catch (Exception $e) {
    error_log("Error in get_evaluation_details.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

if (isset($con) && $con) {
    $con->close();
}
?>