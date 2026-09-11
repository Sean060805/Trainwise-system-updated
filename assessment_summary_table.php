<?php
// Use the existing database connection from config.php instead of creating a new one
require_once 'config.php';

$user_id = $_SESSION['user_id'] ?? null;

// Redirect if not logged in
if (!$user_id) {
    header("Location: index.php");
    exit();
}

date_default_timezone_set('Asia/Manila');

// Get submission deadline from active settings
$deadline = null;
$formatted_deadline = "Not set";
$deadline_query = $con->prepare("SELECT submission_deadline FROM settings WHERE is_active = 1 ORDER BY submission_deadline DESC LIMIT 1");
if ($deadline_query) {
    $deadline_query->execute();
    $result = $deadline_query->get_result();
    if ($row = $result->fetch_assoc()) {
        $deadline = strtotime($row['submission_deadline']);
        $formatted_deadline = date("F j, Y g:i A", $deadline);
    }
    $deadline_query->close();
}

// Pagination setup
$per_page = 3;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $per_page;

// Get total number of submissions for pagination
$total_assessments = 0;
$count_stmt = $con->prepare("SELECT COUNT(*) as total FROM assessments WHERE user_id = ?");
if ($count_stmt) {
    $count_stmt->bind_param("i", $user_id);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    if ($row = $count_result->fetch_assoc()) {
        $total_assessments = $row['total'];
    }
    $count_stmt->close();
}

$total_pages = ceil($total_assessments / $per_page);

// Fetch paginated submissions
$assessments = [];
if ($total_assessments > 0) {
    $stmt = $con->prepare("SELECT * FROM assessments WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?");
    if ($stmt) {
        $stmt->bind_param("iii", $user_id, $per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
        $assessments = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

// Determine overall status
$status = "No Submission";
$status_color = "text-red-600";

if (!empty($assessments)) {
    $latest_submission = strtotime($assessments[0]['created_at']);
    if ($deadline) {
        $status = $latest_submission <= $deadline ? "On Time" : "Late";
        $status_color = $latest_submission <= $deadline ? "text-green-600" : "text-yellow-600";
    } else {
        $status = "Submitted";
        $status_color = "text-blue-600";
    }
}
?>

<div class="max-w-full">
  <div class="mb-6">
    <h2 class="mb-2" style="font-family:'Fraunces',serif; font-weight:600; font-size:1.25rem; color:var(--ink,#16233A);">My Assessment Submissions</h2>
    <div class="flex flex-wrap items-center gap-3 mt-2">
      <div class="text-sm px-3 py-1.5 rounded-lg" style="background:var(--cream-dim,#F5EDDF); color:var(--slate-strong,#37485C);">
        <span class="font-medium">Status:</span>
        <span class="<?= htmlspecialchars($status_color) ?> font-semibold ml-1"><?= htmlspecialchars($status) ?></span>
      </div>
      <?php if ($deadline): ?>
      <div class="text-sm px-3 py-1.5 rounded-lg" style="background:var(--cream-dim,#F5EDDF); color:var(--slate-strong,#37485C);">
        <span class="font-medium">Deadline:</span>
        <span class="font-semibold ml-1" style="color:var(--ink,#16233A);"><?= htmlspecialchars($formatted_deadline) ?></span>
      </div>
      <?php endif; ?>
      <div class="text-sm px-3 py-1.5 rounded-lg" style="background:var(--cream-dim,#F5EDDF); color:var(--slate-strong,#37485C);">
        <span class="font-medium">Total Submissions:</span>
        <span class="font-semibold ml-1" style="color:var(--ink,#16233A);"><?= $total_assessments ?></span>
      </div>
    </div>
  </div>

  <?php if (!empty($assessments)): ?>
    <div class="space-y-4">
      <?php foreach ($assessments as $index => $entry): ?>
        <?php
        // Safely decode JSON data
        $training = [];
        $skills = [];
        
        if (!empty($entry['training_history'])) {
            $decoded_training = json_decode($entry['training_history'], true);
            $training = is_array($decoded_training) ? $decoded_training : [];
        }
        
        if (!empty($entry['desired_skills'])) {
            $decoded_skills = json_decode($entry['desired_skills'], true);
            $skills = is_array($decoded_skills) ? $decoded_skills : [];
        }
        
        $created_at = strtotime($entry['created_at']);
        $formatted_date = date("F j, Y g:i A", $created_at);
        
        $submission_status = "Submitted";
        $status_class = "bg-blue-100 text-blue-800";
        
        if ($deadline) {
            $submission_status = $created_at <= $deadline ? "On Time" : "Late";
            $status_class = $created_at <= $deadline ? "bg-green-100 text-green-800" : "bg-yellow-100 text-yellow-800";
        }
        
        // Calculate assessment number
        $assessment_number = $total_assessments - (($page - 1) * $per_page + $index);
        ?>
        
        <div class="rounded-xl p-6 transition-shadow duration-200" style="background:linear-gradient(145deg, #ffffff 0%, var(--cream,#FDF8F0) 100%); border:1px solid var(--border-soft,#E8DDD0); box-shadow:0 1px 3px rgba(15,24,48,0.05);">
          <div class="flex flex-col md:flex-row md:items-center justify-between mb-4 gap-3">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-lg flex items-center justify-center" style="background:var(--gold-soft,#F5E6C8);">
                <span class="font-bold" style="color:#8C6423;">#<?= $assessment_number ?></span>
              </div>
              <div>
                <h3 class="font-semibold" style="color:var(--ink,#16233A);">Assessment Submission</h3>
                <span class="inline-block px-3 py-1 rounded-full text-xs font-medium <?= htmlspecialchars($status_class) ?> mt-1">
                  <?= htmlspecialchars($submission_status) ?>
                </span>
              </div>
            </div>
            <div class="text-sm flex items-center gap-2" style="color:var(--slate,#5B7288);">
              <i class="ri-calendar-line"></i>
              <?= htmlspecialchars($formatted_date) ?>
            </div>
          </div>

          <div class="grid md:grid-cols-2 gap-6 mt-4">
            <!-- Training History -->
            <div>
              <h4 class="font-bold mb-3 flex items-center text-sm uppercase tracking-wider" style="color:var(--slate-strong,#37485C);">
                <i class="ri-history-line mr-2" style="color:var(--gold,#D4A843);"></i>
                Training History
              </h4>
              <?php if (!empty($training)): ?>
                <div class="space-y-3">
                  <?php foreach ($training as $t): ?>
                    <?php
                    $start = $t['start_time'] ?? '';
                    $end = $t['end_time'] ?? '';
                    $duration = $t['duration'] ?? '';
                    $title = $t['training'] ?? 'No title';
                    $venue = $t['venue'] ?? 'N/A';

                    // 2026-09-03 - moved to the shared
                    // format_training_date_range() in config.php so this
                    // logic has one source of truth instead of being
                    // copy-pasted into every dean/PDF view that renders
                    // the same training_history shape.
                    $formattedDate = format_training_date_range($t) ?: 'N/A';
                    $formattedStart = $start ? date("g:i A", strtotime($start)) : 'N/A';
                    $formattedEnd = $end ? date("g:i A", strtotime($end)) : 'N/A';
                    ?>
                    <div class="pl-4 py-2 rounded-r" style="border-left:3px solid var(--gold,#D4A843); background:var(--cream-dim,#F5EDDF);">
                      <div class="font-medium text-sm" style="color:var(--ink,#16233A);"><?= htmlspecialchars($title) ?></div>
                      <div class="text-xs mt-1 space-y-1" style="color:var(--slate,#5B7288);">
                        <div class="flex items-center gap-2">
                          <i class="ri-map-pin-line text-xs"></i>
                          <span><?= htmlspecialchars($venue) ?></span>
                        </div>
                        <div class="flex items-center gap-2">
                          <i class="ri-calendar-event-line text-xs"></i>
                          <span><?= htmlspecialchars($formattedDate) ?></span>
                        </div>
                        <div class="flex items-center gap-2">
                          <i class="ri-time-line text-xs"></i>
                          <span><?= htmlspecialchars($formattedStart) ?> - <?= htmlspecialchars($formattedEnd) ?></span>
                          <?php if ($duration): ?>
                            <span style="color:#A8A29E;">•</span>
                            <span><?= htmlspecialchars($duration) ?></span>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <p class="text-sm italic" style="color:var(--slate,#5B7288);">No training history recorded</p>
              <?php endif; ?>
            </div>

            <!-- Desired Skills & Comments -->
            <div class="space-y-4">
              <div>
                <h4 class="font-bold mb-3 flex items-center text-sm uppercase tracking-wider" style="color:var(--slate-strong,#37485C);">
                  <i class="ri-target-line mr-2" style="color:var(--forest,#0D6B4D);"></i>
                  Desired Training Courses
                </h4>
                <?php if (!empty($skills)): ?>
                  <ul class="space-y-2">
                    <?php foreach ($skills as $skill): ?>
                      <li class="flex items-start gap-2">
                        <i class="ri-checkbox-circle-line mt-0.5 text-sm" style="color:var(--forest,#0D6B4D);"></i>
                        <span class="text-sm" style="color:var(--ink-soft,#52627B);"><?= htmlspecialchars($skill) ?></span>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php else: ?>
                  <p class="text-sm" style="color:var(--ink-soft,#52627B);"><?= nl2br(htmlspecialchars($entry['desired_skills'] ?? 'No desired courses specified')) ?></p>
                <?php endif; ?>
              </div>

              <div>
                <h4 class="font-bold mb-3 flex items-center text-sm uppercase tracking-wider" style="color:var(--slate-strong,#37485C);">
                  <i class="ri-message-3-line mr-2" style="color:var(--royal,#1A4B8C);"></i>
                  Comments &amp; Suggestions
                </h4>
                <div class="rounded-lg p-4" style="background:var(--cream-dim,#F5EDDF);">
                  <p class="text-sm" style="color:var(--ink-soft,#52627B);"><?= nl2br(htmlspecialchars($entry['comments'] ?? 'No comments provided')) ?></p>
                </div>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="flex items-center justify-center gap-2 mt-8">
      <?php if ($page > 1): ?>
        <a href="?page=<?= $page - 1 ?>"
           class="px-4 py-2 rounded-lg transition-colors flex items-center gap-2" style="background:#fff; border:1px solid var(--border-soft,#E8DDD0); color:var(--slate-strong,#37485C);">
          <i class="ri-arrow-left-s-line"></i>
          Previous
        </a>
      <?php else: ?>
        <span class="px-4 py-2 rounded-lg flex items-center gap-2" style="background:var(--cream-dim,#F5EDDF); border:1px solid var(--border-soft,#E8DDD0); color:#A8A29E;">
          <i class="ri-arrow-left-s-line"></i>
          Previous
        </span>
      <?php endif; ?>

      <div class="flex items-center gap-1">
        <?php
        $start_page = max(1, $page - 2);
        $end_page = min($total_pages, $page + 2);

        if ($start_page > 1): ?>
          <a href="?page=1" class="px-3 py-2 rounded-lg" style="color:var(--slate-strong,#37485C);">1</a>
          <?php if ($start_page > 2): ?>
            <span class="px-2" style="color:#A8A29E;">...</span>
          <?php endif; ?>
        <?php endif; ?>

        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
          <a href="?page=<?= $i ?>"
             class="px-3 py-2 rounded-lg font-medium"
             style="<?= $i == $page ? 'background:linear-gradient(135deg, var(--royal,#1A4B8C) 0%, var(--royal-2,#0F3460) 100%); color:#fff;' : 'color:var(--slate-strong,#37485C);' ?>">
            <?= $i ?>
          </a>
        <?php endfor; ?>

        <?php if ($end_page < $total_pages): ?>
          <?php if ($end_page < $total_pages - 1): ?>
            <span class="px-2" style="color:#A8A29E;">...</span>
          <?php endif; ?>
          <a href="?page=<?= $total_pages ?>" class="px-3 py-2 rounded-lg" style="color:var(--slate-strong,#37485C);"><?= $total_pages ?></a>
        <?php endif; ?>
      </div>

      <?php if ($page < $total_pages): ?>
        <a href="?page=<?= $page + 1 ?>"
           class="px-4 py-2 rounded-lg transition-colors flex items-center gap-2" style="background:#fff; border:1px solid var(--border-soft,#E8DDD0); color:var(--slate-strong,#37485C);">
          Next
          <i class="ri-arrow-right-s-line"></i>
        </a>
      <?php else: ?>
        <span class="px-4 py-2 rounded-lg flex items-center gap-2" style="background:var(--cream-dim,#F5EDDF); border:1px solid var(--border-soft,#E8DDD0); color:#A8A29E;">
          Next
          <i class="ri-arrow-right-s-line"></i>
        </span>
      <?php endif; ?>
    </div>
    <?php endif; ?>

  <?php else: ?>
    <!-- No redundant CTA here (2026-08-29) - the page already has one
         prominent "Fill Out Training Needs Assessment" button below,
         which this empty state used to duplicate with its own
         differently-styled "Take Assessment Now" button doing the exact
         same thing. One clear action beats two that do the same job. -->
    <div class="rounded-xl p-8 text-center" style="background:linear-gradient(145deg, #ffffff 0%, var(--cream,#FDF8F0) 100%); border:1px solid var(--border-soft,#E8DDD0);">
      <div class="w-20 h-20 rounded-full flex items-center justify-center mx-auto mb-4" style="background:var(--gold-soft,#F5E6C8);">
        <i class="ri-file-list-3-line text-3xl" style="color:var(--gold,#D4A843);"></i>
      </div>
      <h3 class="text-lg font-medium mb-2" style="font-family:'Fraunces',serif; color:var(--ink,#16233A);">No assessment submissions yet</h3>
      <p class="text-sm" style="color:var(--slate,#5B7288);">Complete your training needs assessment below to see your submissions here.</p>
    </div>
  <?php endif; ?>
</div>

<style>
  .assessment-summary-container {
    scrollbar-width: thin;
    scrollbar-color: #D4A843 #F5EDDF;
  }

  .assessment-summary-container::-webkit-scrollbar {
    height: 6px;
  }

  .assessment-summary-container::-webkit-scrollbar-track {
    background: #F5EDDF;
    border-radius: 3px;
  }

  .assessment-summary-container::-webkit-scrollbar-thumb {
    background: #D4A843;
    border-radius: 3px;
  }

  .assessment-summary-container::-webkit-scrollbar-thumb:hover {
    background: #B8922A;
  }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
  // Handle pagination links
  const paginationLinks = document.querySelectorAll('.pagination a');
  paginationLinks.forEach(link => {
    link.addEventListener('click', function(e) {
      e.preventDefault();
      const page = this.getAttribute('href').split('page=')[1];
      loadAssessmentPage(page);
    });
  });

  function loadAssessmentPage(page) {
    // You can implement AJAX pagination here if needed
    // For now, just follow the link
    window.location.href = '?page=' + page;
  }
});
</script>