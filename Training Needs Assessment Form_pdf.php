<?php
require('fpdf.php');
require('config.php');
// config.php already starts the session (guarded) - calling session_start()
// again unconditionally here printed a visible Notice, and that leaked
// output is exactly what made FPDF's own "some data has already been
// output" check fail on every PDF export (2026-09-02 fix, found during
// ISO testing).
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2026-09-07 fix - base FPDF's core fonts (Arial, etc.) only understand
// Windows-1252/Latin-1, not UTF-8 - but every value flowing into this
// script (from $_POST, ultimately from utf8mb4 MySQL columns) is UTF-8.
// A dropdown value like "Master's Degree - With Units Earned" typed with
// a real en-dash character (or a curly apostrophe, or any accented
// letter) is 2-3 raw UTF-8 bytes; FPDF renders each of those bytes as if
// it were one separate cp1252 character, producing exactly the "â€"
// mojibake reported live. Converts to cp1252 with //TRANSLIT (approximate
// characters that don't exist in cp1252, e.g. emoji, into the closest
// plain-ASCII equivalent instead of dropping them) and //IGNORE as a
// second-pass fallback (drop only what truly can't be approximated,
// rather than letting the whole PDF generation fail over one character).
function fpdfText($text) {
    $text = (string)($text ?? '');
    if ($text === '') return $text;
    $converted = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $text);
    return $converted !== false ? $converted : $text;
}

class PDF extends FPDF
{
    function Header()
    {
        $this->Image('images/lspu-logo.png', 30, 11, 23);
        $this->Ln(5);
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 5, 'Republic of the Philippines', 0, 1, 'C');

        $this->AddFont('oldengl', '', 'OLDENGL.php');
        $this->SetFont('oldengl', '', 16);
        $this->Cell(0, 6, 'Laguna State Polytechnic University', 0, 1, 'C');

        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 5, 'Province of Laguna', 0, 1, 'C');
        $this->Ln(8);

        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 10, 'Training Needs Assessment Form', 0, 1, 'C');
        $this->Ln(5);
    }

    function Footer(){
        $this->SetY(-10); // Position at 10mm from bottom
        $this->SetFont('Arial','',7);
        // Footer text with multiple spaces for alignment
        $this->Cell(0,4,'LSPU-HRO-SF-025                                                                                          Rev. 1                                                                                                  15 October 2018',0,0,'C');
    }

    function PersonalProfile($data)
    {
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 6, 'Personal Profile', 0, 1);

        $this->SetFont('Arial', '', 12);
        $this->Cell(80, 8, 'Name:', 0, 0);
        $this->Cell(0, 8, fpdfText($data['name'] ?? 'Not specified'), 0, 1);
        $this->Cell(80, 8, 'Highest Educational Attainment:', 0, 0);
        $this->Cell(0, 8, fpdfText($data['educationalAttainment'] ?? 'Not specified'), 0, 1);
        $this->Cell(80, 8, 'Specialization:', 0, 0);
        $this->Cell(0, 8, fpdfText($data['specialization'] ?? 'Not specified'), 0, 1);
        $this->Cell(80, 8, 'Present Designation:', 0, 0);
        $this->Cell(0, 8, fpdfText($data['designation'] ?? 'Not specified'), 0, 1);
        $this->Cell(80, 8, 'Department:', 0, 0);
        $this->Cell(0, 8, fpdfText($data['department'] ?? 'Not specified'), 0, 1);
        $this->Cell(80, 8, 'Years in LSPU:', 0, 0);
        $this->Cell(0, 8, fpdfText($data['yearsInLSPU'] ?? 'Not specified'), 0, 1);
        $this->Cell(80, 8, 'Type of Employment:', 0, 0);
        $this->Cell(0, 8, fpdfText($data['teaching_status'] ?? 'Not specified'), 0, 1);
    }

    function TrainingHistory($trainings)
    {
        $this->Ln(3);
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, 'Training Needs:', 0, 1);

        $this->Ln(3);
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(0, 5, '1. Please list all trainings attended for the last three years.', 0, 1);

        $this->SetFont('Arial', '', 11);
        if (!empty($trainings)) {
            foreach ($trainings as $training) {
                $date = format_training_date_range($training);
                $start = $training['start_time'] ?? '';
                $end = $training['end_time'] ?? '';
                $duration = $training['duration'] ?? '';
                $title = $training['training'] ?? '';
                $venue = $training['venue'] ?? '';

                if ($date || $title || $venue) {
                    $this->SetFont('Arial', 'B', 11);
                    $this->MultiCell(0, 7, fpdfText("- $date ($start - $end)"), 0, 1);
                    $this->SetFont('Arial', '', 11);
                    $this->MultiCell(0, 7, fpdfText("Duration: $duration"), 0, 1);
                    $this->MultiCell(0, 7, fpdfText("$title at $venue"), 0, 1);
                    $this->Ln(2);
                }
            }
        } else {
            $this->MultiCell(0, 7, 'No training data provided.', 0, 1);
        }
    }

    function DesiredSkills($desiredSkills, $comments)
    {
        $this->Ln(8);
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(0, 10, '2. Other training courses relevant and important to your present job that you may want to attend.', 0, 1);

        $this->SetFont('Arial', '', 11);
        if (!empty($desiredSkills)) {
            $this->MultiCell(0, 7, fpdfText($desiredSkills), 0, 1);
        } else {
            $this->MultiCell(0, 7, 'No desired skills specified.', 0, 1);
        }

        $this->Ln(5);
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(0, 10, '3. Comments / Suggestions:', 0, 1);
        $this->SetFont('Arial', '', 11);
        $this->MultiCell(0, 7, fpdfText($comments ?: 'No comments provided.'), 0, 1);
    }
}

// Collect data from POST (not session)
$data = [
    'name' => $_POST['name'] ?? '',
    'educationalAttainment' => $_POST['educationalAttainment'] ?? '',
    'specialization' => $_POST['specialization'] ?? '',
    'designation' => $_POST['designation'] ?? '',
    'department' => $_POST['department'] ?? '',
    'yearsInLSPU' => $_POST['yearsInLSPU'] ?? '',
    'teaching_status' => $_POST['teaching_status'] ?? ''
];

// Handle training history data
// 2026-09-07 fix - this read $_POST['training_history'], a field this
// form never actually sends (the real per-entry inputs are named
// training_history[<id>][date] etc., which PHP parses into a nested
// array, not a JSON string). The form DOES already build a proper JSON
// string of the current entries into a dedicated hidden field
// (print_training_data, kept in sync by updatePrintData() in
// assessment_form_partial.php) specifically for this export - read that
// instead, same field save_assessment.php already reads for the same
// reason.
$trainingData = [];
if (!empty($_POST['print_training_data'])) {
    $trainingData = json_decode($_POST['print_training_data'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        // Fallback if JSON decode fails
        $trainingData = [];
    }
}

// Get desired skills and comments
$desiredSkills = $_POST['desired_skills'] ?? '';
$comments = $_POST['comments'] ?? '';

// Create PDF
$pdf = new PDF();
$pdf->AddPage();

$pdf->PersonalProfile($data);
$pdf->TrainingHistory($trainingData);
$pdf->DesiredSkills($desiredSkills, $comments);

$pdf->Output('I', 'Training_Needs_Assessment_'.date('Y-m-d').'.pdf');
?>