<?php
// This file should be included, not accessed directly
// No need to start session as it's already started in user_page.php

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    die('Access denied. Please login first.');
}

// Initialize CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get current deadline ID
$currentDeadlineId = null;
try {
    if ($con && !$con->connect_error) {
        $deadline_query = $con->prepare("SELECT id FROM settings WHERE is_active = 1 ORDER BY submission_deadline DESC LIMIT 1");
        if ($deadline_query) {
            $deadline_query->execute();
            $result = $deadline_query->get_result();
            if ($row = $result->fetch_assoc()) {
                $currentDeadlineId = $row['id'] ?? null;
            }
            $deadline_query->close();
        }
    }
} catch (Exception $e) {
    error_log("Error getting deadline: " . $e->getMessage());
}

// 2026-09-03 - part of the "eto na yung nakakainip" (real TAM feedback:
// filling out training history entry-by-entry felt tedious) fix. Venues
// get reused constantly (the same LSPU gym, SEAFDEC, a partner hotel,
// etc.), so suggest whatever venues real employees have already typed
// system-wide instead of making everyone retype the same handful of
// places from scratch. Parsed from training_history's JSON blobs in PHP
// (not a native JSON column query) since this DB stores it as plain
// TEXT - cheap for this dataset's size, and fails open to an empty list
// (no autocomplete, not a crash) if anything about an old row's JSON is
// malformed.
$existing_training_venues = [];
try {
    if ($con && !$con->connect_error) {
        $venue_result = $con->query("SELECT training_history FROM assessments WHERE training_history IS NOT NULL AND training_history != '' AND training_history != '[]'");
        if ($venue_result) {
            $seen_venues = [];
            while ($vrow = $venue_result->fetch_assoc()) {
                $decoded = json_decode($vrow['training_history'], true);
                if (!is_array($decoded)) continue;
                foreach ($decoded as $entry) {
                    $v = trim($entry['venue'] ?? '');
                    if ($v !== '' && !isset($seen_venues[$v])) {
                        $seen_venues[$v] = true;
                        $existing_training_venues[] = $v;
                    }
                }
            }
            sort($existing_training_venues);
        }
    }
} catch (Exception $e) {
    error_log("Error collecting existing training venues: " . $e->getMessage());
}
?>

<form id="assessmentForm" method="POST" action="save_assessment.php">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <input type="hidden" name="deadline_id" value="<?= htmlspecialchars($currentDeadlineId ?? '') ?>">

    <!-- Hidden fields for print data -->
    <input type="hidden" id="print-training-data" name="print_training_data" value="">
    <input type="hidden" id="print-desired-skills" name="print_desired_skills" value="">
    <input type="hidden" id="print-comments" name="print_comments" value="">

    <!-- 2026-09-07 fix - "Training Needs Assessment Form_pdf.php" has
         always expected the Personal Profile section (name,
         educationalAttainment, specialization, designation, department,
         yearsInLSPU, teaching_status) to arrive as plain POST fields with
         those exact names - but this form never actually sent them, so
         every export showed the Personal Profile section blank. This
         partial is include()'d directly inside user_page.php, which
         already queries the logged-in user's full profile into $user
         (see the profileQuery near the top of that file) - just reuse it
         here rather than re-querying. Falls back to the same $_SESSION
         profile_* mirrors user_page.php also sets, in the unlikely case
         $user isn't populated for some reason (fails to blank, not a
         fatal error, consistent with how the rest of this form treats
         missing profile data). -->
    <?php $u = $user ?? []; ?>
    <input type="hidden" name="name" value="<?= htmlspecialchars($u['name'] ?? $_SESSION['profile_name'] ?? '') ?>">
    <input type="hidden" name="educationalAttainment" value="<?= htmlspecialchars($u['educationalAttainment'] ?? $_SESSION['profile_educationalAttainment'] ?? '') ?>">
    <input type="hidden" name="specialization" value="<?= htmlspecialchars($u['specialization'] ?? $_SESSION['profile_specialization'] ?? '') ?>">
    <input type="hidden" name="designation" value="<?= htmlspecialchars($u['designation'] ?? $_SESSION['profile_designation'] ?? '') ?>">
    <input type="hidden" name="department" value="<?= htmlspecialchars($u['department'] ?? $_SESSION['profile_department'] ?? '') ?>">
    <input type="hidden" name="yearsInLSPU" value="<?= htmlspecialchars($u['yearsInLSPU'] ?? $_SESSION['profile_yearsInLSPU'] ?? '') ?>">
    <input type="hidden" name="teaching_status" value="<?= htmlspecialchars($u['teaching_status'] ?? $_SESSION['profile_teaching_status'] ?? '') ?>">

    <!-- 2026-09-07 - this whole partial used to open with its own eyebrow
         + big Fraunces title + subtitle ("Training Needs Assessment") and
         wrap everything in a second cream-gradient bordered card - but the
         modal shell in user_page.php that includes this partial ALREADY
         has a proper blue header with the same title and a subtitle right
         above where this renders. Two stacked headers saying almost the
         same thing, plus a card nested inside the modal's own white body
         with its own separate padding, is exactly the "card within a
         card" look and inconsistent spacing flagged directly by a real
         user - removed the duplicate header and the extra wrapper
         entirely; this form now just IS the modal body, using the
         wrapper's own p-6 padding rather than adding a second layer on
         top of it. Section rhythm below uses the shared .tna-section/
         .tna-divider classes (see the <style> block) instead of one-off
         margin utilities, so the vertical spacing between sections is
         actually consistent rather than a mix of mb-10/mt-10/mb-5/mt-5
         guessed independently per section. -->

    <!-- Past Trainings -->
    <section class="print-section tna-section">
      <div class="tna-section-head">
        <h2 class="tna-section-title">
          <i class="ri-history-line"></i>
          Trainings you've attended in the last three years
        </h2>
        <span class="tna-count-pill">
          <span id="training-count">0</span> listed
        </span>
      </div>
      <p class="tna-section-help">Optional &mdash; only add this if you've actually attended one. Most new hires won't have anything to list here yet.</p>

      <div id="training-entries-container" class="space-y-4">
        <!-- Training entries (or the empty-state placeholder) are inserted here -->
      </div>

      <datalist id="venueSuggestions">
        <?php foreach ($existing_training_venues as $v): ?>
          <option value="<?= htmlspecialchars($v) ?>">
        <?php endforeach; ?>
      </datalist>

      <div class="mt-4 no-print">
        <button type="button" id="add-entry" class="px-5 py-2.5 text-white text-sm rounded-lg flex items-center gap-2 font-medium" style="background:linear-gradient(135deg, var(--royal,#1A4B8C) 0%, var(--royal-2,#0F3460) 100%); box-shadow:0 8px 20px -8px rgba(26,75,140,0.5);">
          <i class="ri-add-line"></i>
          Add Training Entry
        </button>
      </div>
    </section>

    <div class="tna-divider"></div>

    <!-- Additional Training and Comments -->
    <section class="print-section tna-section">
      <h2 class="tna-section-title">
        <i class="ri-target-line"></i>
        What skills or areas would you like more training in?
      </h2>
      <!-- 2026-09-03 - reworded from "training courses you'd like to
           attend" (asked the employee to already know a course name)
           to a needs/skill-gap framing that matches how the AI
           recommendation feature is actually meant to work - describe
           the gap, let the system suggest the course. Also produces
           richer free text for the SBERT matcher to work with than a
           bare course title. Purely a label/placeholder change - the
           submitted field name, storage, and matching logic are
           unchanged. -->
      <textarea id="desired_skills" name="desired_skills" rows="4" maxlength="2000" class="tna-textarea" placeholder="e.g. I want to improve my records management and document handling skills for the Registrar's Office..."></textarea>
      <p class="tna-section-help tna-help-tight">Describe your needs in your own words, or list specific trainings if you already have some in mind &mdash; this is what your recommendations are matched against.</p>
    </section>

    <section class="print-section tna-section tna-section-last">
      <h2 class="tna-section-title">
        <i class="ri-message-3-line"></i>
        Comments or suggestions
      </h2>
      <textarea id="comments" name="comments" rows="4" maxlength="2000" class="tna-textarea" placeholder="Your comments or suggestions here..."></textarea>
      <p class="tna-section-help tna-help-tight">Any additional feedback or suggestions for training programs.</p>
    </section>

    <div class="tna-divider"></div>

    <!-- Submit Buttons -->
    <div class="flex flex-col sm:flex-row gap-3 no-print">
      <button type="button" id="print-btn" class="px-5 py-2.5 text-white text-sm rounded-lg flex items-center justify-center gap-2 font-medium" style="background:linear-gradient(135deg, var(--forest,#0D6B4D) 0%, var(--forest-2,#084A34) 100%); box-shadow:0 8px 20px -8px rgba(13,107,77,0.5);">
        <i class="ri-printer-line"></i>
        Print / PDF
      </button>
      <button type="submit" id="submit-btn" class="px-5 py-2.5 text-white text-sm rounded-lg flex items-center justify-center gap-2 font-medium" style="background:linear-gradient(135deg, var(--royal,#1A4B8C) 0%, var(--royal-2,#0F3460) 100%); box-shadow:0 8px 20px -8px rgba(26,75,140,0.5);">
        <i class="ri-send-plane-line"></i>
        Submit Assessment
      </button>
      <button type="button" id="reset-btn" class="px-5 py-2.5 text-sm rounded-lg flex items-center justify-center gap-2 font-medium" style="background:#fff; color:var(--slate-strong,#37485C); border:1px solid var(--border-soft,#E8DDD0);">
        <i class="ri-refresh-line"></i>
        Reset Form
      </button>
    </div>

    <div class="mt-4 text-xs flex items-center gap-1" style="color:var(--slate,#5B7288);">
      <i class="ri-information-line"></i>
      <span>Past trainings are optional. If you add one, please fill in all of its fields.</span>
    </div>
  </form>

<!-- Submit Confirmation Modal -->
<!-- 2026-09-07 fix - this modal is position:fixed and lives deep inside
     #assessmentFormWrapper (a .modal-overlay, z-index:9999), and since
     #assessmentFormWrapper has no transform on it, this modal escapes to
     the document root for stacking purposes - meaning its z-index is
     compared directly against #assessmentFormWrapper's 9999, not treated
     as "inside" it. Tailwind's z-50 (z-index:50) lost that comparison, so
     clicking Submit WAS correctly opening this confirmation dialog, it
     was just rendering completely hidden behind the assessment modal -
     looked exactly like "the button does nothing." Bumped above 9999. -->
<!-- 2026-09-07 - the REAL bug, found after the z-index fix alone still
     didn't work: this page loads a precompiled per-page Tailwind bundle
     (assets/css/tw-46.css), generated once from whatever classes existed
     in the scanned source at build time - it never scanned this
     dynamically include()'d partial, so `inset-0`, `top-6`, `right-6`,
     and every `z-*` class used below have ZERO matching CSS rules in
     that file (confirmed directly: grepped the compiled CSS, none of
     them exist, while `fixed`/`flex`/`items-center`/`justify-center`
     happen to already be used elsewhere on the page and do exist). That
     means this modal was never actually covering the viewport at all -
     no top/right/bottom/left set, "fixed" with no coordinates just sits
     at its normal-flow position - regardless of any z-index. Fixed by
     setting position/inset/z-index explicitly inline, which works
     regardless of what the CSS build did or didn't scan. -->
<div id="submit-confirmation-modal" class="fixed flex items-center justify-center hidden no-print" style="top:0;right:0;bottom:0;left:0;z-index:10000;background:rgba(22,35,58,0.55);">
  <div class="p-6 rounded-2xl max-w-sm w-full mx-4" style="background:#fff; box-shadow:0 24px 48px -12px rgba(15,24,48,0.35);">
    <div class="flex items-center gap-3 mb-4">
      <div class="w-12 h-12 rounded-full flex items-center justify-center flex-shrink-0" style="background:var(--gold-soft,#F5E6C8);">
        <i class="ri-question-line text-xl" style="color:#8C6423;"></i>
      </div>
      <div>
        <h2 class="text-lg font-semibold" style="font-family:'Fraunces',serif; color:var(--ink,#16233A);">Ready to submit?</h2>
        <p class="text-sm" style="color:var(--slate,#5B7288);">Please review your entries before submitting.</p>
      </div>
    </div>
    <div id="modal-content" class="mb-4">
      <p class="text-sm" style="color:var(--ink-soft,#52627B);">Your assessment will be submitted and cannot be edited.</p>
    </div>
    <div class="flex justify-end gap-2">
      <button id="cancel-submit" type="button" class="px-4 py-2 rounded-lg text-sm font-medium transition" style="background:#fff; color:var(--slate-strong,#37485C); border:1px solid var(--border-soft,#E8DDD0);">Cancel</button>
      <button id="confirm-submit" type="button" class="px-4 py-2 text-white rounded-lg text-sm font-medium transition" style="background:linear-gradient(135deg, var(--royal,#1A4B8C) 0%, var(--royal-2,#0F3460) 100%);">Confirm Submit</button>
    </div>
  </div>
</div>

<!-- Success Notification -->
<!-- 2026-09-07 fix - same root cause as the confirmation modal above:
     top-6/right-6/z-* have no matching rules in the precompiled CSS this
     page loads (assets/css/tw-46.css never scanned this partial), so
     this toast had no real position at all, not just a z-index problem.
     Set explicitly inline instead. -->
<div id="success-notification" class="fixed text-white px-5 py-3.5 rounded-xl hidden no-print transform transition-transform duration-300 translate-x-full" style="top:1.5rem;right:1.5rem;z-index:10000;background:linear-gradient(135deg, var(--forest,#0D6B4D) 0%, var(--forest-2,#084A34) 100%); box-shadow:0 16px 32px -12px rgba(13,107,77,0.5);">
  <div class="flex items-center gap-3">
    <div class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0" style="background:rgba(255,255,255,0.2);">
      <i class="ri-check-line text-white"></i>
    </div>
    <div>
      <p class="font-medium">Assessment submitted successfully!</p>
      <p class="text-xs" style="color:rgba(255,255,255,0.8);">Redirecting to dashboard...</p>
    </div>
  </div>
</div>

<!-- Error Notification -->
<!-- 2026-09-07 fix - same root cause as the confirmation modal above. -->
<div id="error-notification" class="fixed text-white px-5 py-3.5 rounded-xl hidden no-print transform transition-transform duration-300 translate-x-full" style="top:1.5rem;right:1.5rem;z-index:10000;background:#DC3545; box-shadow:0 16px 32px -12px rgba(220,53,69,0.5);">
  <div class="flex items-center gap-3">
    <div class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0" style="background:rgba(255,255,255,0.2);">
      <i class="ri-close-line text-white"></i>
    </div>
    <div>
      <p class="font-medium">Error!</p>
      <p id="error-message" class="text-sm">Something went wrong.</p>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
 try {
  const container = document.getElementById('training-entries-container');
  const addEntryBtn = document.getElementById('add-entry');
  const trainingCount = document.getElementById('training-count');
  const submitBtn = document.getElementById('submit-btn');
  const printBtn = document.getElementById('print-btn');
  const resetBtn = document.getElementById('reset-btn');
  const form = document.getElementById('assessmentForm');
  const modal = document.getElementById('submit-confirmation-modal');
  const confirmBtn = document.getElementById('confirm-submit');
  const cancelBtn = document.getElementById('cancel-submit');
  const desiredSkillsInput = document.getElementById('desired_skills');
  const commentsInput = document.getElementById('comments');
  const printTrainingData = document.getElementById('print-training-data');
  const printDesiredSkills = document.getElementById('print-desired-skills');
  const printComments = document.getElementById('print-comments');
  const errorNotification = document.getElementById('error-notification');
  const errorMessage = document.getElementById('error-message');
  const successNotification = document.getElementById('success-notification');

  let entryCounter = 0;

  function formatDuration(minutes) {
    if (isNaN(minutes) || minutes <= 0) return '0 minutes';
    
    const hrs = Math.floor(minutes / 60);
    const mins = minutes % 60;
    
    if (hrs > 0 && mins > 0) {
      return `${hrs} hour${hrs > 1 ? 's' : ''} ${mins} minute${mins > 1 ? 's' : ''}`;
    } else if (hrs > 0) {
      return `${hrs} hour${hrs > 1 ? 's' : ''}`;
    } else {
      return `${mins} minute${mins > 1 ? 's' : ''}`;
    }
  }

  function calculateDuration(entry) {
    const startDateInput = entry.querySelector('.date-input');
    const endDateInput = entry.querySelector('.end-date-input');
    const startInput = entry.querySelector('.start-time');
    const endInput = entry.querySelector('.end-time');
    const durationField = entry.querySelector('.duration');

    if (!startInput || !endInput || !durationField) return;

    const start = startInput.value;
    const end = endInput.value;
    const startDate = startDateInput?.value || '';
    const endDate = endDateInput?.value || '';

    if (!start || !end) {
      durationField.value = '';
      durationField.classList.remove('text-red-500');
      return;
    }

    const startTime = new Date(`1970-01-01T${start}:00`);
    const endTime = new Date(`1970-01-01T${end}:00`);

    if (endTime <= startTime) {
      durationField.value = 'Invalid time';
      durationField.classList.add('text-red-500');
      return;
    }

    // 2026-09-21 - tester feedback: a standard 8:00 AM - 5:00 PM day was being
    // reported as 9 hours, but nobody counts the lunch hour as training time
    // - it's 8 hours. When a session spans the whole 12:00-1:00 PM lunch
    // window AND has time outside it, that hour is excluded. Only a full span
    // is trimmed, so a session that merely touches noon (12:30-5:00 PM) is
    // left alone and a session that IS the lunch hour is never reduced to 0.
    const LUNCH_START = 12 * 60;
    const LUNCH_END = 13 * 60;
    const startMin = startTime.getHours() * 60 + startTime.getMinutes();
    const endMin = endTime.getHours() * 60 + endTime.getMinutes();
    let minutes = endMin - startMin;
    if (startMin <= LUNCH_START && endMin >= LUNCH_END && minutes > (LUNCH_END - LUNCH_START)) {
      minutes -= (LUNCH_END - LUNCH_START);
    }
    const perDay = formatDuration(minutes);

    // 2026-09-03 - a multi-day training reports its per-day hours plus
    // the day count, rather than pretending one start/end time pair
    // covers the whole span - real TAM feedback: trainings can run 2-3
    // days or a full week, not just one day.
    if (endDate && startDate && endDate !== startDate) {
      const days = Math.round((new Date(endDate) - new Date(startDate)) / 86400000) + 1;
      if (days < 1) {
        durationField.value = 'Invalid dates';
        durationField.classList.add('text-red-500');
        return;
      }
      durationField.value = `${days} days (${perDay}/day)`;
    } else {
      durationField.value = perDay;
    }
    durationField.classList.remove('text-red-500');
  }

  function updateCount() {
    const total = container.querySelectorAll('.training-entry').length;
    trainingCount.textContent = total;
    updateEmptyState();
  }

  function updateEmptyState() {
    const total = container.querySelectorAll('.training-entry').length;
    let placeholder = document.getElementById('training-empty-state');
    if (total === 0) {
      if (!placeholder) {
        placeholder = document.createElement('div');
        placeholder.id = 'training-empty-state';
        placeholder.className = 'training-empty-state';
        placeholder.innerHTML = `
          <i class="ri-calendar-check-line"></i>
          <p>No past trainings added yet.</p>
          <span>That's fine if you haven't attended one in the last three years. Click "Add Training Entry" below only if you have one to report.</span>
        `;
        container.appendChild(placeholder);
      }
    } else if (placeholder) {
      placeholder.remove();
    }
  }

  function updatePrintData() {
    const trainingEntries = container.querySelectorAll('.training-entry');
    const trainingData = [];

    // 2026-09-03 - switched from name*="..." substring selectors to the
    // explicit classes each field already carries. Two real reasons:
    // "date" as a substring also matches the new end_date field (order-
    // dependent, easy to get wrong later), and Venue is now a single-line
    // input (for the datalist autocomplete), not a textarea, so the old
    // textarea[name*="venue"] selector would have silently stopped
    // matching it at all.
    trainingEntries.forEach(entry => {
      const date = entry.querySelector('.date-input')?.value;
      const endDate = entry.querySelector('.end-date-input')?.value;
      const start = entry.querySelector('.start-time')?.value;
      const end = entry.querySelector('.end-time')?.value;
      const duration = entry.querySelector('.duration')?.value;
      const training = entry.querySelector('.training-input')?.value;
      const venue = entry.querySelector('.venue-input')?.value;
      const trainingType = entry.querySelector('.training-type-input')?.value;
      const trainingTypeOther = entry.querySelector('.training-type-other-input')?.value;
      const modality = entry.querySelector('.modality-input')?.value;

      if (date || training) {
        trainingData.push({
          date: date || '',
          end_date: endDate || '',
          start_time: start || '',
          end_time: end || '',
          duration: duration || '',
          training: training || '',
          venue: venue || '',
          training_type: trainingType || '',
          training_type_other: trainingTypeOther || '',
          modality: modality || 'Face-to-Face'
        });
      }
    });

    printTrainingData.value = JSON.stringify(trainingData);
    printDesiredSkills.value = desiredSkillsInput.value;
    printComments.value = commentsInput.value;
  }

  // 2026-09-03 - two real TAM feedback fixes bundled into this function:
  // (1) a date RANGE instead of one date - trainings aren't always
  // one-day (the "Report Training Found" dean modal already established
  // this exact start/end-date-with-a-"to"-and-a-blank-means-single-day
  // pattern, mirrored here for consistency), and (2) an optional
  // `values` object so this same function can either add a blank entry
  // (with sensible 8am-5pm defaults - LSPU's typical training day,
  // matching the real example already on file) or DUPLICATE an existing
  // entry's values via the new Duplicate button - "eto na yung
  // nakakainip" (the retyping felt tedious) was the direct complaint;
  // someone with several similar trainings on file now only has to
  // adjust what's actually different instead of retyping everything.
  const todayStr = new Date().toISOString().split('T')[0];

  function addTrainingEntry(values = {}) {
    entryCounter++;
    const entryId = `entry-${entryCounter}`;
    const v = {
      date: '', end_date: '', start_time: '08:00', end_time: '17:00',
      training: '', venue: '', training_type: '', training_type_other: '',
      modality: 'Face-to-Face', ...values,
    };

    const entry = document.createElement('div');
    entry.classList.add('training-entry', 'p-5', 'rounded-xl', 'bg-white');
    entry.setAttribute('data-id', entryId);

    entry.innerHTML = `
      <div class="flex justify-between items-center mb-4">
        <h3 class="flex items-center gap-2 text-sm font-semibold" style="color:var(--ink,#16233A);">
          <span class="tna-entry-num">${entryCounter}</span>
          Training Entry
        </h3>
        <div class="flex items-center gap-1">
          <button type="button"
                  class="duplicate-btn tna-delete-btn"
                  title="Duplicate this entry">
            <i class="ri-file-copy-line text-lg"></i>
          </button>
          <button type="button"
                  class="delete-btn tna-delete-btn"
                  title="Delete this entry">
            <i class="ri-delete-bin-line text-lg"></i>
          </button>
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
        <div class="md:col-span-2">
          <label class="block text-xs font-medium mb-1" style="color:var(--slate-strong,#37485C);">Date(s)</label>
          <div class="flex items-center gap-2">
            <input type="date"
                   name="training_history[${entryId}][date]"
                   max="${todayStr}"
                   value="${v.date}"
                   class="date-input tna-input w-full rounded-md px-3 py-2 text-sm"
                   required />
            <span class="text-xs flex-shrink-0" style="color:var(--slate,#5B7288);">to</span>
            <input type="date"
                   name="training_history[${entryId}][end_date]"
                   max="${todayStr}"
                   value="${v.end_date}"
                   class="end-date-input tna-input w-full rounded-md px-3 py-2 text-sm" />
          </div>
          <!-- 2026-09-17 - this hint used to sit below the whole Date/Start
               Time/End Time row (all three share one grid row at desktop
               width), reading as disconnected from the specific field it's
               actually about. Moved directly under the date inputs it
               describes. -->
          <p class="text-xs mt-1" style="color:var(--slate,#5B7288);">Leave the second date blank for a single-day training.</p>
        </div>

        <div>
          <label class="block text-xs font-medium mb-1" style="color:var(--slate-strong,#37485C);">Start Time</label>
          <input type="time"
                 name="training_history[${entryId}][start_time]"
                 value="${v.start_time}"
                 class="start-time tna-input w-full rounded-md px-3 py-2 text-sm"
                 required />
        </div>

        <div>
          <label class="block text-xs font-medium mb-1" style="color:var(--slate-strong,#37485C);">End Time</label>
          <input type="time"
                 name="training_history[${entryId}][end_time]"
                 value="${v.end_time}"
                 class="end-time tna-input w-full rounded-md px-3 py-2 text-sm"
                 required />
        </div>
      </div>

      <!-- 2026-09-17 addition, per real feedback from the first ~20
           respondents: there used to be no training type here at all, and
           Venue/Location was always required even for a training that was
           genuinely online - people were typing "Online" or "N/A" into a
           field labeled Venue as a workaround. Training Type now matches
           the same vocabulary already used in the training-demand pipeline
           (Workshop/Seminar/Webinar/Conference), plus Other for anything
           that doesn't fit; Format determines whether Venue is even shown. -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
        <div>
          <label class="block text-xs font-medium mb-1" style="color:var(--slate-strong,#37485C);">Training Type</label>
          <select name="training_history[${entryId}][training_type]"
                  class="training-type-input tna-input w-full rounded-md px-3 py-2 text-sm"
                  required>
            <option value="" ${v.training_type ? '' : 'selected'} disabled>Select type</option>
            <option value="Workshop" ${v.training_type === 'Workshop' ? 'selected' : ''}>Workshop</option>
            <option value="Seminar" ${v.training_type === 'Seminar' ? 'selected' : ''}>Seminar</option>
            <option value="Webinar" ${v.training_type === 'Webinar' ? 'selected' : ''}>Webinar</option>
            <option value="Conference" ${v.training_type === 'Conference' ? 'selected' : ''}>Conference</option>
            <option value="Other" ${v.training_type === 'Other' ? 'selected' : ''}>Other</option>
          </select>
        </div>

        <div>
          <label class="block text-xs font-medium mb-1" style="color:var(--slate-strong,#37485C);">Format</label>
          <select name="training_history[${entryId}][modality]"
                  class="modality-input tna-input w-full rounded-md px-3 py-2 text-sm"
                  required>
            <option value="Face-to-Face" ${v.modality === 'Face-to-Face' ? 'selected' : ''}>Face-to-Face</option>
            <option value="Online" ${v.modality === 'Online' ? 'selected' : ''}>Online</option>
          </select>
        </div>
      </div>

      <div class="training-type-other-wrap mb-4" style="display:${v.training_type === 'Other' ? 'block' : 'none'};">
        <label class="block text-xs font-medium mb-1" style="color:var(--slate-strong,#37485C);">Specify Training Type</label>
        <input type="text"
               name="training_history[${entryId}][training_type_other]"
               value="${v.training_type_other}"
               class="training-type-other-input tna-input w-full rounded-md px-3 py-2 text-sm"
               placeholder="e.g. Certification Exam" />
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
        <div>
          <label class="block text-xs font-medium mb-1" style="color:var(--slate-strong,#37485C);">Duration</label>
          <input type="text"
                 name="training_history[${entryId}][duration]"
                 readonly
                 class="duration tna-input tna-input-readonly w-full rounded-md px-3 py-2 text-sm"
                 placeholder="Auto-calculated" />
          <p class="text-xs mt-1" style="color:var(--slate,#5B7288);">Excludes the 12:00 - 1:00 PM lunch break.</p>
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-medium mb-1" style="color:var(--slate-strong,#37485C);">Training Title/Name</label>
          <textarea name="training_history[${entryId}][training]"
                    rows="3"
                    class="training-input tna-input w-full rounded-md px-3 py-2 text-sm resize-y"
                    placeholder="Enter the training title or name"
                    required>${v.training}</textarea>
        </div>

        <div class="venue-wrap" style="display:${v.modality === 'Online' ? 'none' : 'block'};">
          <label class="block text-xs font-medium mb-1" style="color:var(--slate-strong,#37485C);">Venue/Location</label>
          <input type="text"
                 name="training_history[${entryId}][venue]"
                 list="venueSuggestions"
                 value="${v.venue}"
                 class="venue-input tna-input w-full rounded-md px-3 py-2 text-sm"
                 placeholder="Enter the training venue or location"
                 ${v.modality === 'Online' ? '' : 'required'} />
        </div>
      </div>
    `;

    container.appendChild(entry);

    // Add event listeners for date/time inputs - duration now accounts
    // for a multi-day range too, see calculateDuration().
    const startDateInput = entry.querySelector('.date-input');
    const endDateInput = entry.querySelector('.end-date-input');
    const startInput = entry.querySelector('.start-time');
    const endInput = entry.querySelector('.end-time');

    [startDateInput, endDateInput, startInput, endInput].forEach(el => {
      if (el) el.addEventListener('change', () => calculateDuration(entry));
    });
    // Keep the end date's own min in sync with whatever start date is
    // picked - same UX as the Report Training Found modal's date range.
    if (startDateInput && endDateInput) {
      startDateInput.addEventListener('change', () => { endDateInput.min = startDateInput.value; });
      if (startDateInput.value) endDateInput.min = startDateInput.value;
    }

    // Training Type "Other" reveals a free-text field to specify what it
    // actually was; Format (Face-to-Face/Online) shows or hides Venue,
    // since an online training genuinely has no physical venue to enter.
    const typeSelect = entry.querySelector('.training-type-input');
    const typeOtherWrap = entry.querySelector('.training-type-other-wrap');
    const typeOtherInput = entry.querySelector('.training-type-other-input');
    const modalitySelect = entry.querySelector('.modality-input');
    const venueWrap = entry.querySelector('.venue-wrap');
    const venueInput = entry.querySelector('.venue-input');

    if (typeSelect && typeOtherWrap) {
      typeSelect.addEventListener('change', () => {
        const isOther = typeSelect.value === 'Other';
        typeOtherWrap.style.display = isOther ? 'block' : 'none';
        if (!isOther && typeOtherInput) typeOtherInput.value = '';
      });
    }

    if (modalitySelect && venueWrap && venueInput) {
      modalitySelect.addEventListener('change', () => {
        const isOnline = modalitySelect.value === 'Online';
        venueWrap.style.display = isOnline ? 'none' : 'block';
        if (isOnline) {
          venueInput.removeAttribute('required');
          venueInput.value = '';
        } else {
          venueInput.setAttribute('required', 'required');
        }
      });
    }

    // Add event listeners for all inputs
    entry.querySelectorAll('input, textarea').forEach(input => {
      input.addEventListener('input', updatePrintData);
      input.addEventListener('change', updatePrintData);
    });

    calculateDuration(entry);
    updateCount();
    updatePrintData();
    return entry;
  }

  function readEntryValues(entry) {
    return {
      date: entry.querySelector('.date-input')?.value || '',
      end_date: entry.querySelector('.end-date-input')?.value || '',
      start_time: entry.querySelector('.start-time')?.value || '',
      end_time: entry.querySelector('.end-time')?.value || '',
      training: entry.querySelector('.training-input')?.value || '',
      venue: entry.querySelector('.venue-input')?.value || '',
      training_type: entry.querySelector('.training-type-input')?.value || '',
      training_type_other: entry.querySelector('.training-type-other-input')?.value || '',
      modality: entry.querySelector('.modality-input')?.value || 'Face-to-Face',
    };
  }

  // No entry is auto-added on load (2026-08-29 fix) - this section is
  // optional, so an employee with nothing to report can leave it empty
  // and go straight to the fields below. Show a placeholder instead.
  updateEmptyState();

  // Event Listeners
  addEntryBtn.addEventListener('click', () => { addTrainingEntry(); updateEmptyState(); });

  container.addEventListener('click', (e) => {
    if (e.target.closest('.duplicate-btn')) {
      const entry = e.target.closest('.training-entry');
      if (entry) {
        const values = readEntryValues(entry);
        const newEntry = addTrainingEntry(values);
        // Insert right after the source entry, not at the end of the
        // list, so the duplicate stays visually next to what it came
        // from rather than jumping to the bottom.
        entry.after(newEntry);
        updateEmptyState();
      }
      return;
    }
    if (e.target.closest('.delete-btn')) {
      const entry = e.target.closest('.training-entry');
      if (entry) {
        // Not every employee has attended a training in the last three
        // years - this section is optional, so there is no minimum
        // entry count to enforce here (2026-08-29 fix; this used to
        // block deleting the last remaining entry, which combined with
        // the auto-added first entry meant nobody could ever submit
        // with zero past trainings).
        if (confirm('Are you sure you want to delete this training entry?')) {
          entry.remove();

          // Re-number the remaining entries
          const entries = container.querySelectorAll('.training-entry');
          entries.forEach((entry, index) => {
            const title = entry.querySelector('h3');
            if (title) {
              title.textContent = `Training Entry #${index + 1}`;
            }
          });

          updateCount();
          updatePrintData();
        }
      }
    }
  });

  desiredSkillsInput.addEventListener('input', updatePrintData);
  commentsInput.addEventListener('input', updatePrintData);

  printBtn.addEventListener('click', (e) => {
    e.preventDefault();
    if (validateForm(true)) {
      updatePrintData();
      form.action = "Training Needs Assessment Form_pdf.php";
      form.target = "_blank";
      form.submit();
      // Reset form action and target
      setTimeout(() => {
        form.action = "save_assessment.php";
        form.target = "_self";
      }, 100);
    }
  });

  resetBtn.addEventListener('click', () => {
    if (confirm('Are you sure you want to reset the form? All data will be lost.')) {
      container.innerHTML = '';
      desiredSkillsInput.value = '';
      commentsInput.value = '';
      entryCounter = 0;
      updateCount();
      updatePrintData();
    }
  });

  function validateForm(forPrint = false) {
    let isValid = true;
    let errorMsg = '';
    
    // Validate training entries. This section is optional (2026-08-29
    // fix) - not every employee has attended a training in the last
    // three years, so zero entries is a valid submission. Any entry
    // that IS added must still be fully filled in, though - a half-
    // filled entry is genuinely bad data, not an empty section.
    const trainingEntries = container.querySelectorAll('.training-entry');

    if (trainingEntries.length > 0) {
      trainingEntries.forEach((entry, index) => {
        // 2026-09-03 - switched from name*="..." substring selectors to
        // explicit classes, same reason as updatePrintData(): "date"
        // also matches the new end_date field, and venue is now a
        // single-line input, not a textarea, so the old
        // textarea[name*="venue"] selector returned undefined here -
        // calling .trim() on that would have thrown and silently broken
        // submission validation entirely.
        const date = entry.querySelector('.date-input')?.value;
        const endDate = entry.querySelector('.end-date-input')?.value;
        const startTime = entry.querySelector('.start-time')?.value;
        const endTime = entry.querySelector('.end-time')?.value;
        const training = entry.querySelector('.training-input')?.value.trim();
        const venue = entry.querySelector('.venue-input')?.value.trim();
        const duration = entry.querySelector('.duration')?.value;
        const trainingType = entry.querySelector('.training-type-input')?.value;
        const trainingTypeOther = entry.querySelector('.training-type-other-input')?.value.trim();
        const modality = entry.querySelector('.modality-input')?.value;

        if (!forPrint) {
          // For submission, all fields are required - EXCEPT Venue, which
          // only applies when Format is Face-to-Face (an online training
          // genuinely has no venue to enter), and Specify Type, which only
          // applies when Training Type is "Other". End date is the other
          // deliberate exception - blank means single-day, see the
          // helper text under the date range fields.
          const venueOk = modality === 'Online' || !!venue;
          const typeOk = trainingType && (trainingType !== 'Other' || !!trainingTypeOther);
          if (!date || !startTime || !endTime || !training || !venueOk || !typeOk) {
            errorMsg = `Please complete all fields for training entry #${index + 1}`;
            isValid = false;
            return;
          }

          // Check if duration is invalid (covers both a same-day
          // end-before-start time and an end date before the start date)
          if (duration === 'Invalid time' || duration === 'Invalid dates') {
            errorMsg = `Please check the dates/times for training entry #${index + 1}`;
            isValid = false;
            return;
          }

          // Validate date(s) are not in the future
          const today = new Date();
          today.setHours(0, 0, 0, 0);
          const trainingDate = new Date(date);
          if (trainingDate > today) {
            errorMsg = `Training date cannot be in the future for entry #${index + 1}`;
            isValid = false;
            return;
          }
          if (endDate && new Date(endDate) > today) {
            errorMsg = `Training end date cannot be in the future for entry #${index + 1}`;
            isValid = false;
            return;
          }
        } else {
          // For print, at least training title is required
          if (!training) {
            errorMsg = `Please enter training title for entry #${index + 1}`;
            isValid = false;
            return;
          }
        }
      });
    }
    
    // 2026-09-17 fix - this "at least one field filled" check used to
    // only run for the Print/PDF path (`forPrint`). The real Submit
    // button called validateForm() too but this check never applied to
    // it, so an entirely empty form (0 training entries, no desired
    // skills, no comments) passed client-side validation, opened the
    // "Ready to submit?" confirmation modal, and only got caught by
    // save_assessment.php's own server-side check after Confirm Submit -
    // surfacing as a raw "HTTP error! status: 400" instead of being
    // flagged immediately on the first click, before the modal ever
    // appeared. Now runs for both paths.
    if (isValid) {
      const hasTraining = trainingEntries.length > 0;
      const hasDesiredSkills = desiredSkillsInput.value.trim().length > 0;
      const hasComments = commentsInput.value.trim().length > 0;

      if (!hasTraining && !hasDesiredSkills && !hasComments) {
        errorMsg = 'Please add at least one training entry, desired skill, or comment before submitting.';
        isValid = false;
      }
    }
    
    if (!isValid) {
      showError(errorMsg);
      // Scroll to the error
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    
    return isValid;
  }

  function showError(message) {
    errorMessage.textContent = message;
    errorNotification.classList.remove('hidden', 'translate-x-full');
    errorNotification.classList.add('translate-x-0');
    
    setTimeout(() => {
      errorNotification.classList.add('translate-x-full');
      setTimeout(() => {
        errorNotification.classList.add('hidden');
        errorNotification.classList.remove('translate-x-0');
      }, 300);
    }, 5000);
  }

  function showSuccess() {
    successNotification.classList.remove('hidden', 'translate-x-full');
    successNotification.classList.add('translate-x-0');
    
    setTimeout(() => {
      successNotification.classList.add('translate-x-full');
      setTimeout(() => {
        successNotification.classList.add('hidden');
        successNotification.classList.remove('translate-x-0');
      }, 300);
    }, 3000);
  }

  function submitAssessment() {
    // 2026-09-07 - same reasoning as the Submit button's own click handler:
    // anything that throws synchronously here (before the fetch even
    // starts) would previously fail silently - the fetch's own .catch()
    // below only covers network/response failures, not a JS error in the
    // setup before it. Wrapped so "Confirm Submit doing nothing" becomes
    // an unmissable alert() instead of silence.
    try {
      if (!validateForm()) return;

      updatePrintData();

      const formData = new FormData(form);

      // Show loading state
      submitBtn.disabled = true;
      submitBtn.innerHTML = '<i class="ri-loader-4-line animate-spin"></i> Submitting...';

      fetch('save_assessment.php', {
      method: 'POST',
      body: formData
    })
    // 2026-09-17 fix - this used to throw a generic "HTTP error! status:
    // 400" the instant response.ok was false, WITHOUT ever reading the
    // response body - so save_assessment.php's own specific, helpful
    // {success:false, message:"..."} (it always sends one, even on a 400)
    // was silently discarded every single time the server rejected a
    // submission. Now parses the JSON body first regardless of status
    // code, and only falls back to a generic message if the body truly
    // isn't valid JSON at all (a real unexpected failure, not a normal
    // validation rejection).
    .then(async response => {
      let data;
      try {
        data = await response.json();
      } catch (parseErr) {
        throw new Error(`Server error (status ${response.status}). Please try again.`);
      }
      if (!data.success) {
        throw new Error(data.message || data.error || 'Submission failed. Please try again.');
      }
      return data;
    })
    .then(data => {
      modal.classList.add('hidden');
      showSuccess();

      if (data.redirect) {
        setTimeout(() => {
          window.location.href = data.redirect;
        }, 2000);
      } else {
        // Reload page after 2 seconds
        setTimeout(() => {
          window.location.reload();
        }, 2000);
      }
    })
    .catch(error => {
      console.error('Error:', error);
      showError(error.message || 'Error submitting assessment. Please try again.');

      // Reset button state
      submitBtn.disabled = false;
      submitBtn.innerHTML = '<i class="ri-send-plane-line"></i> Submit Assessment';
    });
    } catch (submitSyncErr) {
      console.error('submitAssessment() failed before the request was even sent:', submitSyncErr);
      alert('Confirm Submit error - please screenshot this exact message and send it back:\n\n' + submitSyncErr.message + '\n\n' + (submitSyncErr.stack || ''));
      submitBtn.disabled = false;
      submitBtn.innerHTML = '<i class="ri-send-plane-line"></i> Submit Assessment';
    }
  }

  // Show modal on Submit button click
  // 2026-09-07 - wrapped in try/catch + a hard alert() on failure. Every
  // CSS-level cause of "clicking Submit does nothing" (the z-index bug
  // above, an invisible error toast, etc.) has already been checked and
  // fixed - if it's STILL silent after that, the remaining possibility is
  // a runtime JS error somewhere in this handler that a syntax checker
  // can't catch (only actual execution can). alert() is a native browser
  // dialog - it cannot be hidden by any CSS z-index/stacking issue, so
  // this guarantees whatever is actually wrong becomes visible on the
  // very next click, instead of continuing to guess blind.
  submitBtn.addEventListener('click', (e) => {
    try {
      e.preventDefault();
      if (validateForm()) {
        // Update modal content with summary
        const entryCount = container.querySelectorAll('.training-entry').length;
        const desiredSkills = desiredSkillsInput.value.trim();
        const comments = commentsInput.value.trim();

        let summary = `<div class="text-sm text-gray-700 space-y-2">
          <p><span class="font-medium">Training Entries:</span> ${entryCount}</p>`;

        if (desiredSkills) {
          summary += `<p><span class="font-medium">Desired Courses:</span> ${desiredSkills.split('\n').length} entered</p>`;
        }

        if (comments) {
          summary += `<p><span class="font-medium">Comments:</span> Provided</p>`;
        }

        summary += '</div>';

        document.getElementById('modal-content').innerHTML = summary;
        modal.classList.remove('hidden');
      }
    } catch (submitClickErr) {
      console.error('Submit button click failed:', submitClickErr);
      alert('Submit button error - please screenshot this exact message and send it back:\n\n' + submitClickErr.message + '\n\n' + (submitClickErr.stack || ''));
    }
  });

  // Confirm modal submit
  confirmBtn.addEventListener('click', submitAssessment);

  // Cancel modal
  cancelBtn.addEventListener('click', () => {
    modal.classList.add('hidden');
    submitBtn.disabled = false;
    submitBtn.innerHTML = '<i class="ri-send-plane-line"></i> Submit Assessment';
  });

  // Close modal on outside click
  modal.addEventListener('click', (e) => {
    if (e.target === modal) {
      modal.classList.add('hidden');
      submitBtn.disabled = false;
      submitBtn.innerHTML = '<i class="ri-send-plane-line"></i> Submit Assessment';
    }
  });

  // Handle Enter key in form (prevent accidental submission)
  form.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
      e.preventDefault();
    }
  });

  // Initial update of print data
  updatePrintData();
 } catch (tnaInitErr) {
   console.error('Training Needs Assessment form failed to set up:', tnaInitErr);
   alert('Assessment form setup error - please screenshot this exact message and send it back:\n\n' + tnaInitErr.message + '\n\n' + (tnaInitErr.stack || ''));
 }
});
</script>

<style>
.print-section {
  page-break-inside: avoid;
}

@media print {
  .no-print {
    display: none !important;
  }
  
  .training-entry {
    break-inside: avoid;
    border: 1px solid #ddd !important;
    margin-bottom: 10px !important;
  }
  
  body {
    font-size: 12pt;
    line-height: 1.4;
  }
}

.date-input:invalid {
  border-color: #ef4444;
}

.training-input:invalid,
.venue-input:invalid {
  border-color: #ef4444;
}

#error-notification,
#success-notification {
  transition: transform 0.3s ease-in-out;
}

.training-entry textarea {
  min-height: 80px;
  max-height: 200px;
}

.training-entry .grid div {
  min-width: 0;
}

/* Training entry card - matches the app's card-gradient look */
.training-entry {
  border: 1px solid var(--border-soft, #E8DDD0);
  box-shadow: 0 1px 3px rgba(15, 24, 48, 0.05);
  transition: box-shadow 0.2s ease, border-color 0.2s ease;
}
.training-entry:hover {
  border-color: var(--gold, #D4A843);
  box-shadow: 0 4px 14px -6px rgba(15, 24, 48, 0.14);
}
.tna-entry-num {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 22px;
  height: 22px;
  border-radius: 50%;
  background: var(--gold-soft, #F5E6C8);
  color: #8C6423;
  font-size: 0.72rem;
  font-weight: 700;
  flex-shrink: 0;
}
.tna-delete-btn {
  color: #9CA3AF;
  padding: 0.35rem;
  border-radius: 999px;
  transition: color 0.15s ease, background 0.15s ease;
  line-height: 1;
}
.tna-delete-btn:hover {
  color: #DC3545;
  background: #FEF2F2;
}

/* Shared input treatment for the training-entry fields */
.tna-input {
  border: 1px solid var(--border-soft, #E8DDD0);
  background: #fff;
  color: var(--ink, #16233A);
  transition: border-color 0.18s ease, box-shadow 0.18s ease;
}
.tna-input::placeholder { color: #A8A29E; }
.tna-input:focus {
  outline: none;
  border-color: var(--royal, #1A4B8C);
  box-shadow: 0 0 0 3px rgba(26, 75, 140, 0.14);
}
.tna-input-readonly {
  background: var(--cream-dim, #F5EDDF);
  color: var(--slate, #5B7288);
}

/* 2026-09-07 - shared section rhythm for this form, replacing a mix of
   one-off mb-10/mt-10/mb-5/mt-5 utilities that didn't add up to a
   consistent vertical spacing between sections. One place to tune the
   spacing/typography instead of five. */
.tna-section {
  margin-bottom: 1.75rem;
}
.tna-section-last {
  margin-bottom: 0;
}
.tna-section-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 0.6rem;
  margin-bottom: 0.4rem;
}
.tna-section-title {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  margin: 0 0 0.4rem;
  font-family: 'Fraunces', serif;
  font-weight: 600;
  font-size: 1.05rem;
  line-height: 1.3;
  color: var(--ink, #16233A);
}
.tna-section-head .tna-section-title {
  margin-bottom: 0;
}
.tna-section-title i {
  color: var(--gold, #D4A843);
  font-size: 1.05em;
  flex-shrink: 0;
}
.tna-count-pill {
  flex-shrink: 0;
  font-size: 0.72rem;
  font-weight: 700;
  padding: 0.32rem 0.75rem;
  border-radius: 999px;
  background: var(--gold-soft, #F5E6C8);
  color: #8C6423;
  white-space: nowrap;
}
.tna-section-help {
  margin: 0 0 1rem;
  font-size: 0.82rem;
  line-height: 1.55;
  color: var(--ink-soft, #52627B);
}
.tna-help-tight {
  margin: 0.5rem 0 0;
}
.tna-textarea {
  width: 100%;
  border-radius: 10px;
  padding: 0.75rem 0.9rem;
  font-size: 0.85rem;
  line-height: 1.5;
  border: 1px solid var(--border-soft, #E8DDD0);
  background: #fff;
  color: var(--ink, #16233A);
  transition: border-color 0.18s ease, box-shadow 0.18s ease;
}
.tna-textarea::placeholder { color: #A8A29E; }
.tna-textarea:focus {
  outline: none;
  border-color: var(--royal, #1A4B8C);
  box-shadow: 0 0 0 3px rgba(26, 75, 140, 0.14);
}
.tna-divider {
  height: 1px;
  background: var(--border-soft, #E8DDD0);
  margin: 1.75rem 0;
}

/* Empty-state placeholder shown when no training entries exist */
.training-empty-state {
  text-align: center;
  padding: 2rem 1rem;
  border: 1.5px dashed var(--border-soft, #E8DDD0);
  border-radius: 12px;
  background: var(--cream-dim, #F5EDDF);
}
.training-empty-state i {
  font-size: 1.8rem;
  color: var(--gold, #D4A843);
}
.training-empty-state p {
  margin: 0.5rem 0 0.15rem;
  font-weight: 600;
  font-size: 0.9rem;
  color: var(--ink, #16233A);
}
.training-empty-state span {
  font-size: 0.8rem;
  color: var(--slate, #5B7288);
  max-width: 42ch;
  display: inline-block;
}
</style>