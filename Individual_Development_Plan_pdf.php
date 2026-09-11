<?php
require('fpdf.php');
require_once 'config.php';

// Get form_id from URL parameter
$form_id = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;

if (!$form_id) {
    die('Invalid form ID');
}

// Fetch form data from database
$stmt = $con->prepare("SELECT form_data FROM idp_forms WHERE id = ?");
$stmt->bind_param("i", $form_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die('Form not found');
}

$row = $result->fetch_assoc();
$form_data = json_decode($row['form_data'], true);

if (!$form_data) {
    die('Invalid form data');
}

$stmt->close();

// Create a custom PDF class that extends FPDF
class PDF extends FPDF
{
    // Header function - runs at the top of every page
    function Header(){
        // Add logo image at position X=35, Y=6 with width 24mm
        $this->Image('images/lspu-logo.png', 35, 6, 24);
        $this->Ln(1); // Small line break
        
        // Set font for regular text
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(0, 4, 'Republic of the Philippines', 0, 1, 'C'); // Centered cell
        
        // Add and set custom Old English font
        $this->AddFont('oldengl', '', 'OLDENGL.php');
        $this->SetFont('oldengl', '', 15);
        $this->Cell(0, 4, 'Laguna State Polytechnic University', 0, 1, 'C');
        
        // Back to regular font
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(0, 4, 'Province of Laguna', 0, 1, 'C');
        $this->Ln(8); // Larger line break
        
        // Document title
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 6, 'INDIVIDUAL DEVELOPMENT PLAN', 0, 1, 'C');
        $this->Ln(0); // No line break
    }

    // Footer function - runs at the bottom of every page
    function Footer(){
        $this->SetY(-10); // Position at 10mm from bottom
        $this->SetFont('Arial','',7);
        // Footer text with multiple spaces for alignment
        $this->Cell(0,4,'LSPU-HRO-SF-027                                                                                          Rev. 1                                                                                                  2 April 2018',0,0,'C');
    }

    // Function to calculate number of lines a text will occupy
    function NbLines($w,$txt){
        $cw=&$this->CurrentFont['cw'];
        if($w==0) $w=$this->w-$this->rMargin-$this->x;
        $wmax=($w-2*$this->cMargin)*1000/$this->FontSize;
        $s=str_replace("\r",'',$txt);
        $nb=strlen($s);
        if($nb>0 && $s[$nb-1]=="\n") $nb--;
        $sep=-1; $i=0; $j=0; $l=0; $nl=1;
        while($i<$nb){
            $c=$s[$i];
            if($c=="\n"){ $i++; $sep=-1; $j=$i; $l=0; $nl++; continue; }
            if($c==' ') $sep=$i;
            $l+=$cw[$c];
            if($l>$wmax){
                if($sep==-1){
                    if($i==$j) $i++;
                } else $i=$sep+1;
                $sep=-1; $j=$i; $l=0; $nl++;
            } else $i++;
        }
        return $nl;
    }
}

// ✅ Create new PDF document in Portrait mode, mm units, Letter size
$pdf = new PDF('P', 'mm', 'Letter');
$pdf->SetMargins(12, 10, 12); // Left, Top, Right margins
$pdf->SetAutoPageBreak(true, 10); // Auto page break with 10mm bottom margin
$pdf->AddPage(); // Add first page
$pdf->SetFont('Arial', '', 7); // Set default font


$smallLineHeight = 4; // Fixed height for all cells in the table
$col = 48; // Width of each column

// Personal Information Table data
$info=[
    ['1. Name:', isset($form_data['personal_info']['name']) ? $form_data['personal_info']['name'] : '', '6. Years in other office/agency if any:', isset($form_data['personal_info']['years_other']) ? $form_data['personal_info']['years_other'] : ''],
    ['2. Current Position:', isset($form_data['personal_info']['position']) ? $form_data['personal_info']['position'] : '', '7. Division:', isset($form_data['personal_info']['division']) ? $form_data['personal_info']['division'] : ''],
    ['3. Salary Grade:', isset($form_data['personal_info']['salary_grade']) ? $form_data['personal_info']['salary_grade'] : '', '8. Office:', isset($form_data['personal_info']['office']) ? $form_data['personal_info']['office'] : ''],
    ['4. Years in the Position:', isset($form_data['personal_info']['years_position']) ? $form_data['personal_info']['years_position'] : '', '9. No further development is desired or required for:', ''],
    ['5. Years in LSPU:', isset($form_data['personal_info']['years_lspu']) ? $form_data['personal_info']['years_lspu'] : '', '10. Supervisor\'s Name:', isset($form_data['personal_info']['supervisor']) ? $form_data['personal_info']['supervisor'] : '']
];

// Draw the table
foreach($info as $row){
    // First column - Label
    $pdf->Cell(30,$smallLineHeight,$row[0],1,0,'L');
    // Second column - Value
    $pdf->Cell(54,$smallLineHeight,$row[1],1,0,'L');
    // Third column - Label
    $pdf->Cell(58,$smallLineHeight,$row[2],1,0,'L');
    // Fourth column - Value, move to next line after
    $pdf->Cell(50,$smallLineHeight,$row[3],1,1,'L');
}

$pdf->Ln(0.5); // Add 3mm vertical space after the table

// Purpose Section
$pur = [
    isset($form_data['purpose']['purpose1']) && $form_data['purpose']['purpose1'] ? '(/) To meet the competencies in the current positions' : '( ) To meet the competencies in the current positions',
    isset($form_data['purpose']['purpose2']) && $form_data['purpose']['purpose2'] ? '(/) To increase the level of competencies of current positions' : '( ) To increase the level of competencies of current positions',
    isset($form_data['purpose']['purpose3']) && $form_data['purpose']['purpose3'] ? '(/) To meet the competencies in the next higher position' : '( ) To meet the competencies in the next higher position',
    isset($form_data['purpose']['purpose4']) && $form_data['purpose']['purpose4'] ? '(/) To acquire new competencies across different functions/position' : '( ) To acquire new competencies across different functions/position',
    isset($form_data['purpose']['purpose5']) && $form_data['purpose']['purpose5'] ? '(/) Others, please specify: ' . (isset($form_data['purpose']['purpose_other']) ? $form_data['purpose']['purpose_other'] : '') : '( ) Others, please specify: ______'
];

// Calculate total width of the cell area
$cellWidth = 130;

// Center the whole block by moving the X position
$pdf->SetX(($pdf->GetPageWidth() - $cellWidth) / 2);

foreach ($pur as $p) {
    // Move to the centered position for each line
    $pdf->SetX(($pdf->GetPageWidth() - $cellWidth) / 2);
    // Align text to the left inside the centered cell
    $pdf->Cell($cellWidth, $smallLineHeight, $p, 0, 1, 'L');
}

$pdf->Ln(0);

// Career Development - Improved table with equal line heights
$pdf->SetFont('Arial','B',7);
$pdf->Cell(0,$smallLineHeight,'CAREER DEVELOPMENT:',0,1);
$pdf->Ln(0);

$w = [55, 70, 30, 37]; // Column widths
$h_row = $smallLineHeight; // Fixed row height

$pdf->SetFont('Arial','',7);
// Title cell spanning all columns
$pdf->Cell(array_sum($w), $smallLineHeight + 1, 'Training/Development Interventions for Long Term Goals (Next Five Years)', 1, 1, 'C');

// Header row
$pdf->Cell($w[0], $h_row, 'Area of Development', 1, 0, 'C');
$pdf->Cell($w[1], $h_row, 'Development Activity', 1, 0, 'C');
$pdf->Cell($w[2], $h_row, 'Target Completion Date', 1, 0, 'C');
$pdf->Cell($w[3], $h_row, 'Completion Stage', 1, 1, 'C');

// Get long term goals data
$long_term_goals = isset($form_data['long_term_goals']) ? $form_data['long_term_goals'] : [];

// If no long term goals data, use default values
if (empty($long_term_goals)) {
    $long_term_goals = [[
        'area' => 'Academic (if applicable), Attendance to seminar on Supervisory Development Program & Management/Executive & Leadership Development',
        'activity' => 'Pursuance of Academic Degrees for advancement, conduct of trainings/seminars',
        'target_date' => '',
        'stage' => ''
    ]];
}

// Process each long term goal
foreach ($long_term_goals as $goal) {
    $area = isset($goal['area']) ? $goal['area'] : '';
    $activity = isset($goal['activity']) ? $goal['activity'] : '';
    $date = isset($goal['target_date']) ? $goal['target_date'] : '';
    $stage = isset($goal['stage']) ? $goal['stage'] : '';
    
    // Calculate required height for this row
    $nb_ad = $pdf->NbLines($w[0], $area);
    $nb_da = $pdf->NbLines($w[1], $activity);
    $nb = max($nb_ad, $nb_da);
    $h = $h_row * $nb;
    
    // Print multi-cells with equal height
    $x = $pdf->GetX();
    $y = $pdf->GetY();
    
    $pdf->MultiCell($w[0], $h_row, $area, 1, 'L');
    $pdf->SetXY($x + $w[0], $y);
    $pdf->MultiCell($w[1], $h_row, $activity, 1, 'L');
    $pdf->SetXY($x + $w[0] + $w[1], $y);
    $pdf->MultiCell($w[2], $h, $date, 1, 'L');
    $pdf->SetXY($x + $w[0] + $w[1] + $w[2], $y);
    $pdf->MultiCell($w[3], $h, $stage, 1, 'L');
    
    $pdf->SetXY($x, $y + $h);
}

$pdf->Ln(1);

// Short Term Development Goals - Centered title
$pdf->SetFont('Arial','B',7);
$pdf->Cell(0,$smallLineHeight,'Short Term Development Goals Next Year',0,1,'C');
$pdf->Ln(0);

// Original column widths (6 columns)
$w = [45, 30, 42, 30, 22, 23]; 
$h_row = $smallLineHeight * 4; // Header row height

// Header texts with MANUAL line breaks to match Image 1
$head = [
    'Area of Development', 
    "Priority for\nLearning and\nDevelopment\nProgram (LDP)",
    'Development Activity', 
    'Target Completion Date', 
    "Who is\nResponsible", 
    "Completion\nStage"
];

// Draw header row with MANUAL line breaks
$pdf->SetFont('Arial','',7);
$x = $pdf->GetX();
$y = $pdf->GetY();

foreach($w as $i => $width) {
    // Split text by manual line breaks
    $lines = explode("\n", $head[$i]);
    $lineHeight = $smallLineHeight;
    $totalHeight = count($lines) * $lineHeight;
    $startY = $y + ($h_row - $totalHeight)/2;
    
    // Draw each line of the header
    foreach($lines as $line) {
        $pdf->SetXY($x, $startY);
        $pdf->Cell($width, $lineHeight, trim($line), 0, 0, 'C');
        $startY += $lineHeight;
    }
    
    // Draw border
    $pdf->Rect($x, $y, $width, $h_row);
    $x += $width;
}

$pdf->SetXY(12, $y + $h_row); // Reset position after headers
$pdf->SetFont('Arial','',7);

// Get short term goals data
$short_term_goals = isset($form_data['short_term_goals']) ? $form_data['short_term_goals'] : [];

// If no short term goals data, use default values
if (empty($short_term_goals)) {
    $short_term_goals = [
        [
            'area' => '1. Behavioral Training such as: Value Re-orientation, Team Building, Oral Communication, Written Communication, Customer Relations, People Development, Improving Planning & Delivery, Solving Problems and making decisions, Basic Communication Training Programme',
            'priority' => '',
            'activity' => 'Conduct of training/seminar',
            'target_date' => '',
            'responsible' => '',
            'stage' => ''
        ],
        [
            'area' => '2. Technical Skills Training such as: Basic Occupational Safety & health, University Safety procedures, Preventive Maintenance Activities, etc.',
            'priority' => '',
            'activity' => '',
            'target_date' => '',
            'responsible' => '',
            'stage' => ''
        ],
        [
            'area' => '3. Quality Management Training such as: Customer Requirements, Time Management, Continous Improvement for Quality & Productivity,etc',
            'priority' => '',
            'activity' => '',
            'target_date' => '',
            'responsible' => '',
            'stage' => ''
        ],
        [
            'area' => '4. others: Formal Classroom Training, on-the job training, Self-development, developmental activities/interventions,etc.',
            'priority' => '',
            'activity' => 'Coaching on the Job-knowledge sharing and learning session',
            'target_date' => '',
            'responsible' => '',
            'stage' => ''
        ]
    ];
}

// Draw data rows
foreach($short_term_goals as $goal) {
    $area = isset($goal['area']) ? $goal['area'] : '';
    $priority = isset($goal['priority']) ? $goal['priority'] : '';
    $activity = isset($goal['activity']) ? $goal['activity'] : '';
    $date = isset($goal['target_date']) ? $goal['target_date'] : '';
    $responsible = isset($goal['responsible']) ? $goal['responsible'] : '';
    $stage = isset($goal['stage']) ? $goal['stage'] : '';
    
    $row_data = [$area, $priority, $activity, $date, $responsible, $stage];
    
    $max_lines = 1;
    foreach($row_data as $i => $t) {
        $lines = $pdf->NbLines($w[$i], $t);
        $max_lines = max($max_lines, $lines);
    }
    $row_height = $smallLineHeight * $max_lines;
    
    foreach($row_data as $i => $t) {
        $x = $pdf->GetX();
        $y = $pdf->GetY();
        $pdf->Rect($x, $y, $w[$i], $row_height);
        $align = ($t == '') ? 'C' : 'L';
        $pdf->MultiCell($w[$i], $smallLineHeight, $t, 0, $align);
        $pdf->SetXY($x + $w[$i], $y);
    }
    
    $pdf->SetXY(12, $pdf->GetY() + $row_height);
}
$pdf->Ln(0);

// Header (not in a cell)
$pdf->SetFont('Arial','B',7);
$pdf->Cell(0, 6, 'CERTIFICATION AND COMMITMENT', 0, 1, 'L');
$pdf->SetFont('Arial','',7);

// Table setup
$col1_width = 160;  // Left column width
$col2_width = 33;   // Right column width

// Calculate positions
$startY = $pdf->GetY();

// Get certification data from form
// Kung wala sa certification section, gamitin ang nasa personal info
$employee_name = isset($form_data['certification']['employee_name']) ? $form_data['certification']['employee_name'] : 
                (isset($form_data['personal_info']['name']) ? $form_data['personal_info']['name'] : '');
$employee_date = isset($form_data['certification']['employee_date']) ? $form_data['certification']['employee_date'] : '';
$supervisor_name = isset($form_data['certification']['supervisor_name']) ? $form_data['certification']['supervisor_name'] : 
                  (isset($form_data['personal_info']['supervisor']) ? $form_data['personal_info']['supervisor'] : '');
$supervisor_date = isset($form_data['certification']['supervisor_date']) ? $form_data['certification']['supervisor_date'] : '';
$director_name = isset($form_data['certification']['director_name']) ? $form_data['certification']['director_name'] : 
                (isset($form_data['personal_info']['director']) ? $form_data['personal_info']['director'] : '');
$director_date = isset($form_data['certification']['director_date']) ? $form_data['certification']['director_date'] : '';

// Row 1 - Certification text and signature (left cell)
$certification_text = 'This is to certify that this Individual Development Plan has been discussed with me by immediate superior. I further commit that I will exert time and effort to ensure that this will be achieved according to agreed time frames.' . "\n";
$certification_text .= 'Signature of Employee:';$certification_text .= $employee_name; 

$pdf->MultiCell($col1_width, 4, $certification_text, 1, 'L');

// Immediate Supervisor (right cell)
$supervisor_cell_content = 'Immediate Supervisor' . "\n\n" . $supervisor_name;
$pdf->SetXY($col1_width+12, $startY);
$pdf->MultiCell($col2_width, 4, $supervisor_cell_content, 1, 'C');

// Row 2 - Commitment text (left cell)
$pdf->SetY($startY + 12); // Move to next row
$pdf->Cell($col1_width, 9, 'I commit to support and ensure that this agreed Individual Development Plan is achieved to the agreed time frames', 1, 0, 'L');

// Campus Director (right cell)
$director_cell_content = 'Campus Director' . "\n\n" . $director_name;
$pdf->Cell($col2_width, 9, $director_cell_content, 1, 1, 'C');

$pdf->Output('I','Individual_Development_Plan.pdf');
?>