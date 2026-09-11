<?php
include '../config.php';
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

// Get URL parameters
$facultyName = isset($_GET['name']) ? urldecode($_GET['name']) : '';
$evaluationId = isset($_GET['evaluation_id']) ? $_GET['evaluation_id'] : '';
$userId = isset($_GET['user_id']) ? $_GET['user_id'] : '';
$autoOpen = isset($_GET['auto_open']) ? $_GET['auto_open'] : '';

// Validate that the user_id exists in users table
if (!empty($userId)) {
    $check_user_sql = "SELECT id, name, department FROM users WHERE id = ?";
    $check_user_stmt = $con->prepare($check_user_sql);
    $check_user_stmt->bind_param('i', $userId);
    $check_user_stmt->execute();
    $user_result = $check_user_stmt->get_result();
    
    if ($user_result->num_rows === 0) {
        die("Invalid user ID. User does not exist in the system.");
    }
    
    $user_data = $user_result->fetch_assoc();
    $facultyName = $user_data['name']; // Use the actual name from database
}

// Get current user info (evaluator)
$evaluator_id = $_SESSION['user_id'];
$evaluator_sql = "SELECT name, department FROM users WHERE id = ?";
$evaluator_stmt = $con->prepare($evaluator_sql);
$evaluator_stmt->bind_param('i', $evaluator_id);
$evaluator_stmt->execute();
$evaluator_result = $evaluator_stmt->get_result();
$evaluator = $evaluator_result->fetch_assoc();

// Load existing evaluation data if editing
$existing_evaluation = null;
$existing_ratings = [];
if (!empty($evaluationId)) {
    $eval_sql = "SELECT * FROM evaluations WHERE id = ?";
    $eval_stmt = $con->prepare($eval_sql);
    $eval_stmt->bind_param('i', $evaluationId);
    $eval_stmt->execute();
    $existing_evaluation = $eval_stmt->get_result()->fetch_assoc();
    
    // Load existing ratings
    if ($existing_evaluation) {
        $ratings_sql = "SELECT * FROM evaluation_ratings WHERE evaluation_id = ? ORDER BY question_number";
        $ratings_stmt = $con->prepare($ratings_sql);
        $ratings_stmt->bind_param('i', $evaluationId);
        $ratings_stmt->execute();
        $ratings_result = $ratings_stmt->get_result();
        
        while ($rating = $ratings_result->fetch_assoc()) {
            $existing_ratings[$rating['question_number']] = $rating;
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Process form data
        $name = $_POST['name'];
        $department = $_POST['department'];
        $training_title = $_POST['training_title'];
        $date_conducted = $_POST['date_conducted'];
        $objectives = $_POST['objectives'];
        $comments = $_POST['comments'];
        $future_training = $_POST['future_training'];
        $rated_by = $_POST['rated_by'];
        $signature_data = $_POST['signature_data'];
        $assessment_date = $_POST['assessment_date'];
        
        // Get user_id from form or URL parameter
        $form_user_id = $_POST['user_id'] ?? $userId;
        
        // Validate user_id exists before proceeding
        $validate_user_sql = "SELECT id FROM users WHERE id = ?";
        $validate_user_stmt = $con->prepare($validate_user_sql);
        $validate_user_stmt->bind_param('i', $form_user_id);
        $validate_user_stmt->execute();
        $validate_result = $validate_user_stmt->get_result();
        
        if ($validate_result->num_rows === 0) {
            throw new Exception("Invalid user ID. User does not exist.");
        }
        
        // Process ratings
        $ratings = [];
        $remarks = [];
        for ($i = 1; $i <= 8; $i++) {
            $ratings[$i] = isset($_POST['rating'][$i]) ? (int)$_POST['rating'][$i] : 0;
            $remarks[$i] = isset($_POST['remark'][$i]) ? $_POST['remark'][$i] : '';
        }
        
        // Begin transaction
        $con->begin_transaction();
        
        if (!empty($evaluationId) && $existing_evaluation) {
            // Update existing evaluation
            $update_sql = "UPDATE evaluations SET
                training_title = ?, date_conducted = ?, objectives = ?, 
                comments = ?, future_training_needs = ?, rated_by = ?, 
                signature_date = ?, status = 'draft', 
                updated_at = NOW()
                WHERE id = ?";
            
            $update_stmt = $con->prepare($update_sql);
            $update_stmt->bind_param(
                'sssssssi',
                $training_title,
                $date_conducted,
                $objectives,
                $comments,
                $future_training,
                $rated_by,
                $signature_data,
                $evaluationId
            );
            
            if ($update_stmt->execute()) {
                // Update ratings
                for ($i = 1; $i <= 8; $i++) {
                    // Check if rating exists
                    $check_rating_sql = "SELECT id FROM evaluation_ratings WHERE evaluation_id = ? AND question_number = ?";
                    $check_rating_stmt = $con->prepare($check_rating_sql);
                    $check_rating_stmt->bind_param('ii', $evaluationId, $i);
                    $check_rating_stmt->execute();
                    $rating_exists = $check_rating_stmt->get_result()->num_rows > 0;
                    
                    if ($rating_exists) {
                        // Update existing rating
                        $rating_sql = "UPDATE evaluation_ratings SET 
                                      rating = ?, remark = ?, created_at = NOW()
                                      WHERE evaluation_id = ? AND question_number = ?";
                        $rating_stmt = $con->prepare($rating_sql);
                        $rating_stmt->bind_param('isii', $ratings[$i], $remarks[$i], $evaluationId, $i);
                    } else {
                        // Insert new rating
                        $rating_sql = "INSERT INTO evaluation_ratings (evaluation_id, question_number, rating, remark) 
                                      VALUES (?, ?, ?, ?)";
                        $rating_stmt = $con->prepare($rating_sql);
                        $rating_stmt->bind_param('iiis', $evaluationId, $i, $ratings[$i], $remarks[$i]);
                    }
                    $rating_stmt->execute();
                }
                
                // Add to workflow history if status changed
                if ($existing_evaluation['status'] != 'draft') {
                    $workflow_sql = "INSERT INTO evaluation_workflow (evaluation_id, from_status, to_status, changed_by) 
                                     VALUES (?, ?, 'draft', ?)";
                    $workflow_stmt = $con->prepare($workflow_sql);
                    $workflow_stmt->bind_param('isi', $evaluationId, $existing_evaluation['status'], $evaluator_id);
                    $workflow_stmt->execute();
                }
            }
        } else {
            // Insert new evaluation - make sure user_id is valid
            $insert_sql = "INSERT INTO evaluations (
                user_id, evaluator_id, training_title, date_conducted, 
                objectives, comments, future_training_needs, rated_by, signature_date, 
                status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')";
            
            $insert_stmt = $con->prepare($insert_sql);
            $insert_stmt->bind_param(
                'iisssssss',
                $form_user_id,  // Use the validated user_id
                $evaluator_id,
                $training_title,
                $date_conducted,
                $objectives,
                $comments,
                $future_training,
                $rated_by,
                $signature_data
            );
            
            if ($insert_stmt->execute()) {
                $new_evaluation_id = $con->insert_id;
                
                // Insert ratings
                for ($i = 1; $i <= 8; $i++) {
                    $rating_sql = "INSERT INTO evaluation_ratings (evaluation_id, question_number, rating, remark) 
                                  VALUES (?, ?, ?, ?)";
                    $rating_stmt = $con->prepare($rating_sql);
                    $rating_stmt->bind_param('iiis', $new_evaluation_id, $i, $ratings[$i], $remarks[$i]);
                    $rating_stmt->execute();
                }
                
                // Add to workflow history
                $workflow_sql = "INSERT INTO evaluation_workflow (evaluation_id, to_status, changed_by) 
                                 VALUES (?, 'draft', ?)";
                $workflow_stmt = $con->prepare($workflow_sql);
                $workflow_stmt->bind_param('ii', $new_evaluation_id, $evaluator_id);
                $workflow_stmt->execute();
                
                $evaluationId = $new_evaluation_id;
            }
        }
        
        // Commit transaction
        $con->commit();
        
        // Return success response
        if (isset($_POST['action']) && $_POST['action'] === 'submit') {
            echo json_encode(['success' => true, 'message' => 'Evaluation saved as draft successfully!']);
            exit();
        }
        
        // For print action, just return success
        if (isset($_POST['action']) && $_POST['action'] === 'print') {
            echo json_encode(['success' => true, 'message' => 'Form ready for printing!']);
            exit();
        }
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $con->rollback();
        error_log("Evaluation submission error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to save evaluation: ' . $e->getMessage()]);
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Training Program Impact Assessment Form Evaluation</title>
    <link rel="stylesheet" href="../assets/css/tw-50.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/4.6.0/remixicon.min.css">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
        }
        .form-container {
            background: white;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        .form-field {
            border: 1px solid #d1d5db;
            transition: border-color 0.2s;
        }
        .form-field:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        .rounded-button {
            border-radius: 8px;
        }
        .radio-container input[type="radio"] {
            display: none;
        }
        .radio-container input[type="radio"]:checked + label {
            background-color: #3b82f6;
            color: white;
        }
        .radio-container label {
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #d1d5db;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .radio-container label:hover {
            border-color: #3b82f6;
        }
        .signature-pad {
            border: 1px solid #d1d5db;
            background-color: #f9fafb;
        }
        .signature-btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            border-radius: 4px;
            margin-right: 0.5rem;
        }
        .upload-btn {
            background-color: #3b82f6;
            color: white;
        }
        .clear-btn {
            background-color: #6b7280;
            color: white;
        }
    </style>
</head>
<body class="min-h-screen p-4 md:p-8 bg-gray-50">
    <div class="form-container max-w-5xl mx-auto rounded-lg p-6 md:p-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-gray-800">TRAINING PROGRAM IMPACT ASSESSMENT FORM</h1>
        </div>

        <form action="training_program_impact_assessment_form.php" method="POST" id="assessment-form">
            <!-- Hidden fields -->
            <input type="hidden" name="evaluation_id" value="<?= htmlspecialchars($evaluationId) ?>">
            <input type="hidden" name="user_id" value="<?= htmlspecialchars($userId) ?>">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Name of Employee:</label>
                    <input id="name" type="text" name="name" class="form-field w-full px-4 py-2 rounded" 
                           value="<?= htmlspecialchars($facultyName) ?>" required readonly>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Department/Unit:</label>
                    <input type="text" name="department" class="form-field w-full px-4 py-2 rounded" value="CCS" readonly>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Title of Training/Seminar Attended:</label>
                    <input type="text" name="training_title" class="form-field w-full px-4 py-2 rounded" 
                           placeholder="Enter training title" required
                           value="<?= htmlspecialchars($existing_evaluation['training_title'] ?? '') ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date Conducted:</label>
                    <input type="date" name="date_conducted" class="form-field w-full px-4 py-2 rounded" 
                           required value="<?= htmlspecialchars($existing_evaluation['date_conducted'] ?? '') ?>">
                </div>
            </div>

            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-1">Objective/s:</label>
                <textarea name="objectives" class="form-field w-full px-4 py-2 rounded min-h-[80px]" 
                          placeholder="Enter training objectives" required><?= htmlspecialchars($existing_evaluation['objectives'] ?? '') ?></textarea>
            </div>

            <div class="mb-6">
                <div class="bg-gray-100 p-3 rounded mb-4">
                    <p class="text-sm text-gray-700"><span class="font-medium">INSTRUCTION:</span> Please check (✓) in the appropriate column the impact/benefits gained by the employee in attending the training program in a scale of 1-5 (where 5 – Strongly Agree; 4 – Agree; 3 – Neither agree nor disagree; 2 – Disagree; 1 – Strongly Disagree)</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full border-collapse">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="text-left py-3 px-4 font-medium text-gray-700 border border-gray-200 w-1/2">IMPACT/BENEFITS GAINED</th>
                                <th class="text-center py-3 px-2 font-medium text-gray-700 border border-gray-200 w-8">1</th>
                                <th class="text-center py-3 px-2 font-medium text-gray-700 border border-gray-200 w-8">2</th>
                                <th class="text-center py-3 px-2 font-medium text-gray-700 border border-gray-200 w-8">3</th>
                                <th class="text-center py-3 px-2 font-medium text-gray-700 border border-gray-200 w-8">4</th>
                                <th class="text-center py-3 px-2 font-medium text-gray-700 border border-gray-200 w-8">5</th>
                                <th class="text-left py-3 px-4 font-medium text-gray-700 border border-gray-200">REMARKS</th>
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
                                $existing_rating = $existing_ratings[$i] ?? null;
                            ?>
                            <tr class="<?= $i % 2 === 0 ? 'bg-gray-50' : 'bg-white' ?>">
                              <td class="py-3 px-4 border border-gray-200 text-gray-700 text-sm">
                                <?= $questions[$i-1] ?>
                              </td>
                              <?php for ($j = 1; $j <= 5; $j++): ?>
                              <td class="py-2 px-0 border border-gray-200">
                                <div class="radio-container h-8 flex items-center justify-center">
                                  <input type="radio" id="q<?= $i ?>-<?= $j ?>" 
                                         name="rating[<?= $i ?>]" 
                                         value="<?= $j ?>" 
                                         <?= ($existing_rating && $existing_rating['rating'] == $j) ? 'checked' : '' ?>
                                         required>
                                  <label for="q<?= $i ?>-<?= $j ?>" class="rounded-button w-8 h-8"><?= $j ?></label>
                                </div>
                              </td>
                              <?php endfor; ?>
                              <td class="py-2 px-2 border border-gray-200">
                                <input type="text" 
                                       name="remark[<?= $i ?>]" 
                                       class="w-full px-2 py-1 border-none bg-transparent text-sm" 
                                       placeholder="Add remarks"
                                       value="<?= htmlspecialchars($existing_rating['remark'] ?? '') ?>">
                              </td>
                            </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-1">Comments:</label>
                <textarea name="comments" class="form-field w-full px-4 py-2 rounded min-h-[80px]" 
                          placeholder="Enter additional comments"><?= htmlspecialchars($existing_evaluation['comments'] ?? '') ?></textarea>
            </div>

            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-1">Please list down other training programs he/she might need in the future:</label>
                <textarea name="future_training" class="form-field w-full px-4 py-2 rounded min-h-[100px]" 
                          placeholder="Enter future training needs"><?= htmlspecialchars($existing_evaluation['future_training_needs'] ?? '') ?></textarea>
            </div>

            <div class="border-t border-gray-200 pt-6">
                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Rated by:</label>
                        <input type="text" name="rated_by" class="form-field w-full px-4 py-2 rounded" 
                               value="<?= htmlspecialchars($evaluator['name'] ?? '') ?>" required readonly>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Signature:</label>
                        <div id="signature-pad" class="signature-pad w-full h-20 rounded flex items-center justify-center cursor-pointer">
                            <span class="text-gray-400">Click to sign or upload image</span>
                        </div>
                        <input type="file" id="signature-upload" accept="image/*" class="hidden" />
                        <input type="hidden" name="signature_data" id="signature-data" 
                               value="<?= htmlspecialchars($existing_evaluation['signature_date'] ?? '') ?>" required />
                        <div class="signature-actions mt-2">
                            <button type="button" id="upload-signature" class="signature-btn upload-btn">Upload Image</button>
                            <button type="button" id="clear-signature" class="signature-btn clear-btn">Clear</button>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date:</label>
                        <input type="date" name="assessment_date" class="form-field w-full px-4 py-2 rounded" 
                               required value="<?= htmlspecialchars($existing_evaluation['assessment_date'] ?? date('Y-m-d')) ?>">
                    </div>
                </div>
            </div>

            <div class="mt-8 flex justify-end space-x-4">
                <button type="button" id="clear-form" class="px-6 py-2 bg-gray-200 text-gray-700 rounded-button hover:bg-gray-300 transition-colors whitespace-nowrap">Clear Form</button>
                <button type="submit" name="action" value="print" class="px-6 py-2 bg-green-500 text-white rounded-button hover:bg-green-600 transition-colors whitespace-nowrap">Print</button>
                <button type="submit" id="submit-form" name="action" value="submit" class="px-6 py-2 bg-primary text-white rounded-button hover:bg-blue-600 transition-colors whitespace-nowrap">Save as Draft</button>
            </div>
        </form>
    </div> 

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Close button functionality
        document.getElementById('close-form').addEventListener('click', function() {
            window.parent.postMessage('closeModal', '*');
        });

        // Signature pad functionality
        const signaturePad = document.getElementById('signature-pad');
        const signatureDataInput = document.getElementById('signature-data');
        const signatureUpload = document.getElementById('signature-upload');
        const uploadSignatureBtn = document.getElementById('upload-signature');
        const clearSignatureBtn = document.getElementById('clear-signature');
        let isDrawing = false;
        let ctx;
        let canvas;

        function initializeCanvas() {
            canvas = document.createElement('canvas');
            canvas.width = signaturePad.clientWidth;
            canvas.height = signaturePad.clientHeight;
            canvas.style.width = '100%';
            canvas.style.height = '100%';
            canvas.style.cursor = 'crosshair';
            
            ctx = canvas.getContext('2d');
            ctx.lineWidth = 2;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            ctx.strokeStyle = '#3b82f6';
            
            signaturePad.innerHTML = '';
            signaturePad.appendChild(canvas);
            
            // Load existing signature if available
            if (signatureDataInput.value) {
                const img = new Image();
                img.onload = function() {
                    ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                };
                img.src = signatureDataInput.value;
            }
            
            canvas.addEventListener('mousedown', startDrawing);
            canvas.addEventListener('mousemove', draw);
            canvas.addEventListener('mouseup', stopDrawing);
            canvas.addEventListener('mouseout', stopDrawing);
            
            canvas.addEventListener('touchstart', startDrawingTouch);
            canvas.addEventListener('touchmove', drawTouch);
            canvas.addEventListener('touchend', stopDrawing);
        }

        function startDrawing(e) {
            isDrawing = true;
            ctx.beginPath();
            ctx.moveTo(e.offsetX, e.offsetY);
        }

        function startDrawingTouch(e) {
            e.preventDefault();
            if (e.touches.length === 1) {
                const touch = e.touches[0];
                const rect = canvas.getBoundingClientRect();
                const offsetX = touch.clientX - rect.left;
                const offsetY = touch.clientY - rect.top;

                isDrawing = true;
                ctx.beginPath();
                ctx.moveTo(offsetX, offsetY);
            }
        }

        function draw(e) {
            if (!isDrawing) return;
            ctx.lineTo(e.offsetX, e.offsetY);
            ctx.stroke();
            signatureDataInput.value = canvas.toDataURL();
        }

        function drawTouch(e) {
            e.preventDefault();
            if (!isDrawing || e.touches.length !== 1) return;

            const touch = e.touches[0];
            const rect = canvas.getBoundingClientRect();
            const offsetX = touch.clientX - rect.left;
            const offsetY = touch.clientY - rect.top;

            ctx.lineTo(offsetX, offsetY);
            ctx.stroke();
            signatureDataInput.value = canvas.toDataURL();
        }

        function stopDrawing() {
            isDrawing = false;
        }

        function clearSignature() {
            if (canvas) {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                signatureDataInput.value = '';
            }
        }

        // Handle image upload
        uploadSignatureBtn.addEventListener('click', function() {
            signatureUpload.click();
        });

        signatureUpload.addEventListener('change', function(e) {
            if (e.target.files.length > 0) {
                const file = e.target.files[0];
                const reader = new FileReader();
                
                reader.onload = function(event) {
                    const img = new Image();
                    img.onload = function() {
                        if (!canvas) {
                            initializeCanvas();
                        }
                        clearSignature();
                        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                        signatureDataInput.value = canvas.toDataURL();
                    };
                    img.src = event.target.result;
                };
                reader.readAsDataURL(file);
            }
        });

        // Clear signature button
        clearSignatureBtn.addEventListener('click', clearSignature);

        // Initialize canvas on load
        initializeCanvas();
        window.addEventListener('resize', initializeCanvas);

        // Form submission handling
        const form = document.getElementById('assessment-form');
        if (form) {
            form.addEventListener('submit', function(e) {
                // Always capture signature data
                if (canvas && !signatureDataInput.value) {
                    const signatureDataURL = canvas.toDataURL();
                    signatureDataInput.value = signatureDataURL;
                }

                // For submit action, handle with AJAX
                if (e.submitter && e.submitter.value === 'submit') {
                    e.preventDefault();
                    
                    // Validate required fields
                    let isValid = true;
                    const requiredFields = document.querySelectorAll('[required]');
                    const ratingInputs = document.querySelectorAll('input[type="radio"]:checked');
                    
                    requiredFields.forEach(field => {
                        if (!field.value.trim()) {
                            field.classList.add('border-red-500');
                            isValid = false;
                        } else {
                            field.classList.remove('border-red-500');
                        }
                    });
                    
                    // Check if all ratings are provided
                    if (ratingInputs.length < 8) {
                        alert('Please provide ratings for all 8 questions.');
                        isValid = false;
                    }
                    
                    if (!isValid) {
                        alert('Please fill in all required fields.');
                        return;
                    }

                    // Show loading state
                    const submitBtn = document.getElementById('submit-form');
                    const originalText = submitBtn.innerHTML;
                    submitBtn.innerHTML = 'Saving...';
                    submitBtn.disabled = true;

                    // Submit form via AJAX
                    const formData = new FormData(form);
                    
                    fetch('training_program_impact_assessment_form.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Evaluation saved as draft successfully!');
                            window.parent.postMessage('closeModal', '*');
                        } else {
                            alert(data.message || 'Failed to save evaluation.');
                        }
                    })
                    .catch(error => {
                        alert('An error occurred while saving the evaluation.');
                        console.error('Error:', error);
                    })
                    .finally(() => {
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    });
                }
            });
        }

        // Clear form button
        document.getElementById('clear-form').addEventListener('click', function() {
            if (confirm('Are you sure you want to clear all form data?')) {
                document.querySelectorAll('input[type="text"]:not(#name):not([name="department"]):not([name="rated_by"]), input[type="date"], textarea').forEach(input => {
                    input.value = '';
                });
                
                document.querySelectorAll('input[type="radio"]').forEach(radio => {
                    radio.checked = false;
                });
                
                clearSignature();
            }
        });
    });
    </script>
</body>
</html>