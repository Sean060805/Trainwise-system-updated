<?php
session_start();

// Database connection
require_once 'config.php';
require('fpdf.php');

class PDF extends FPDF
{
    function __construct()
    {
        parent::__construct();
        $this->SetMargins(10, 10, 10);
        $this->SetAutoPageBreak(true, 15);
    }

    function Header()
    {
        // Check if image exists before trying to include it
        if (file_exists('images/lspu-logo.png')) {
            $this->Image('images/lspu-logo.png', 30, 11, 23);
        }
        
        $this->Ln(5);
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 5, 'Republic of the Philippines', 0, 1, 'C');

        // Check if font file exists
        if (file_exists('OLDENGL.php')) {
            $this->AddFont('oldengl', '', 'OLDENGL.php');
            $this->SetFont('oldengl', '', 16);
        } else {
            $this->SetFont('Arial', 'B', 14);
        }
        $this->Cell(0, 6, 'Laguna State Polytechnic University', 0, 1, 'C');

        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 5, 'Province of Laguna', 0, 1, 'C');
        $this->Ln(8);

        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 7, 'TRAINING PROGRAM IMPACT ASSESSMENT FORM', 0, 1, 'C');
        $this->Ln(2);
    }

    function FormBody($evaluation_details, $evaluation_ratings)
    {
        $this->SetFont('Arial', '', 9);

        $labelWidth = 50;
        $fieldWidth = 70;
        $labelWidth2 = 40;
        $fieldWidth2 = 30;

        $safeMultiCellText = function($text) {
            return utf8_decode(str_replace("\r\n", "\n", $text));
        };

        // Safely get data with default values
        $name = isset($evaluation_details['employee_name']) ? trim($evaluation_details['employee_name']) : '';
        $department = isset($evaluation_details['employee_department']) ? trim($evaluation_details['employee_department']) : '';
        $training_title = isset($evaluation_details['training_title']) ? trim($evaluation_details['training_title']) : '';
        $date_conducted = isset($evaluation_details['date_conducted']) ? trim($evaluation_details['date_conducted']) : '';
        $objectives = isset($evaluation_details['objectives']) ? trim($evaluation_details['objectives']) : '';
        $comments = isset($evaluation_details['comments']) ? trim($evaluation_details['comments']) : '';
        $future_training = isset($evaluation_details['future_training_needs']) ? trim($evaluation_details['future_training_needs']) : '';
        $rated_by = isset($evaluation_details['rated_by']) ? trim($evaluation_details['rated_by']) : '';
        $assessment_date = isset($evaluation_details['created_at']) ? trim($evaluation_details['created_at']) : '';
        $signature_data = isset($evaluation_details['signature_date']) ? trim($evaluation_details['signature_date']) : '';

        // Format dates if they exist
        if (!empty($date_conducted)) {
            $date_conducted = date('m/d/Y', strtotime($date_conducted));
        }
        if (!empty($assessment_date)) {
            $assessment_date = date('m/d/Y', strtotime($assessment_date));
        }

        // ROW 1
        $this->Cell($labelWidth, 7, 'Name of Employee:', 1, 0);
        $this->Cell($fieldWidth, 7, $safeMultiCellText($name), 1, 0);
        $this->Cell($labelWidth2, 7, 'Department/Unit:', 1, 0);
        $this->Cell($fieldWidth2, 7, $safeMultiCellText($department), 1, 1);

        // ROW 2
        $this->Cell($labelWidth, 7, 'Title of Training/Seminar Attended:', 1, 0);
        $this->Cell($fieldWidth, 7, $safeMultiCellText($training_title), 1, 0);
        $this->Cell($labelWidth2, 7, 'Date Conducted:', 1, 0);
        $this->Cell($fieldWidth2, 7, $safeMultiCellText($date_conducted), 1, 1);

        // Objectives
        $this->Cell(0, 7, 'Objective/s:', 1, 1);
        $this->MultiCell(0, 16, $safeMultiCellText($objectives), 1);
        $this->Ln(1);

        // Instruction
        $this->SetFont('Arial', '', 8);
        $instruction = "INSTRUCTION: Please check (/) in the appropriate column the impact/benefits gained by the employee in attending the training program in a scale of 1-5 (where 5 - Strongly Agree; 4 - Agree; 3 - Neither agree nor disagree; 2 - Disagree; 1 - Strongly Disagree)";
        $this->MultiCell(0, 5, $safeMultiCellText($instruction), 0);
        $this->Ln(1);

        // Ratings table
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(100, 8, 'IMPACT/BENEFITS GAINED', 1, 0, 'C');
        for ($i = 1; $i <= 5; $i++) {
            $this->Cell(10, 8, $i, 1, 0, 'C');
        }
        $this->Cell(40, 8, 'REMARKS', 1, 1, 'C');

        $this->SetFont('Arial', '', 7.5);
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

        foreach ($questions as $index => $q) {
            $question_num = $index + 1;
            $x = $this->GetX();
            $y = $this->GetY();

            $this->MultiCell(100, 5, $safeMultiCellText($q), 1);
            $rowHeight = $this->GetY() - $y;

            $this->SetXY($x + 100, $y);

            // Get rating for this question
            $rating_value = isset($evaluation_ratings[$question_num]) ? $evaluation_ratings[$question_num]['rating'] : 0;
            $remark_text = isset($evaluation_ratings[$question_num]) ? $evaluation_ratings[$question_num]['remark'] : '';

            for ($i = 1; $i <= 5; $i++) {
                if (intval($rating_value) === $i) {
                    $this->SetFont('Arial', 'B', 10);
                    $this->Cell(10, $rowHeight, '/', 1, 0, 'C');
                    $this->SetFont('Arial', '', 7.5);
                } else {
                    $this->Cell(10, $rowHeight, '', 1, 0);
                }
            }

            $this->Cell(40, $rowHeight, $safeMultiCellText($remark_text), 1, 1);
        }

        // Comments
        $this->Ln(1);
        $this->SetFont('Arial', '', 9);
        $this->Cell(0, 6, 'Comments:', 0, 1);
        $this->MultiCell(0, 12, $safeMultiCellText($comments), 1);

        // Future Training
        $this->Ln(1);
        $this->Cell(0, 6, 'Please list down other training programs he/she might need in the future:', 0, 1);
        $this->MultiCell(0, 12, $safeMultiCellText($future_training), 1);

        // Signature Section
        $this->Ln(4);
        $this->Cell(25, 6, 'Rated by:', 0, 0);
        $this->Cell(70, 6, $safeMultiCellText($rated_by ?: '__________________________'), 0, 0);
        $this->Cell(20, 6, 'Signature:', 0, 0);

        // Show signature image if available (from URL or base64)
        if (!empty($signature_data)) {
            // Check if it's a URL
            if (filter_var($signature_data, FILTER_VALIDATE_URL)) {
                $this->Image($signature_data, $this->GetX(), $this->GetY() - 5, 40, 12);
            } 
            // Check if it's base64 encoded
            elseif (strpos($signature_data, 'data:image') === 0) {
                try {
                    $signature_image = preg_replace('#^data:image/\w+;base64,#i', '', $signature_data);
                    $signature_binary = base64_decode($signature_image);
                    
                    if ($signature_binary !== false) {
                        $signature_path = tempnam(sys_get_temp_dir(), 'sig_') . '.png';
                        if (file_put_contents($signature_path, $signature_binary) !== false) {
                            $this->Image($signature_path, $this->GetX(), $this->GetY() - 5, 40, 12);
                            unlink($signature_path);
                        }
                    }
                } catch (Exception $e) {
                    // Fallback if signature processing fails
                    $this->Cell(40, 6, '__________________', 0, 0);
                }
            } else {
                $this->Cell(40, 6, '__________________', 0, 0);
            }
        } else {
            $this->Cell(40, 6, '__________________', 0, 0);
        }

        $this->Cell(0, 6, 'Date: ' . $safeMultiCellText($assessment_date ?: '___________'), 0, 1);
        $this->Cell(70, 6, "(Immediate Supervisor's Name)", 0, 1);
    }

    function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('Arial', '', 7);
        $this->Cell(0, 5, 'LSPU-HRO-SF-021', 0, 0, 'L');
        $this->SetX(0);
        $this->Cell($this->GetPageWidth(), 5, 'Rev. 1', 0, 0, 'C');
        $this->SetX(-50);
        $this->Cell(40, 5, '01 August 2019', 0, 0, 'R');
    }
}

// Check if evaluation ID is provided
$evaluation_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($evaluation_id <= 0) {
    die("Invalid evaluation ID");
}

try {
    // Get evaluation data from database
    $evaluation_sql = "SELECT 
        e.*,
        u.name as employee_name,
        u.department as employee_department,
        evaluator.name as evaluator_name,
        sent_by.name as sent_by_name
    FROM evaluations e
    JOIN users u ON e.user_id = u.id
    JOIN users evaluator ON e.evaluator_id = evaluator.id
    LEFT JOIN users sent_by ON e.sent_by = sent_by.id
    WHERE e.id = ?";

    $stmt = $con->prepare($evaluation_sql);
    $stmt->bind_param("i", $evaluation_id);
    $stmt->execute();
    $evaluation_result = $stmt->get_result();

    if ($evaluation_result->num_rows === 0) {
        die("Evaluation not found");
    }

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

    // Create and output PDF
    $pdf = new PDF();
    $pdf->AddPage();
    $pdf->FormBody($evaluation_details, $evaluation_ratings);
    
    // Output PDF in browser (no download)
    $pdf->Output('I', 'Evaluation_Form_' . $evaluation_id . '.pdf');
    
} catch (Exception $e) {
    // Handle errors gracefully
    echo "<html><body>";
    echo "<h2>Error generating PDF</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><a href='javascript:history.back()'>Go Back</a></p>";
    echo "</body></html>";
    exit;
}
?>