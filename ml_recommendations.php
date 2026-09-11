<?php
/**
 * Integration layer between the PHP app and the trainwise-ml FastAPI service.
 * Used by training_recommendations.php and user_page.php.
 */

/**
 * Create the training_demand table if it doesn't exist yet. This is the
 * HR demand-aggregation object: one row per unique recommended training
 * title with at least one employee Accept, pooled across ALL colleges.
 * Keyed on `title` (exact match against the shared, closed catalog of
 * ~22 titles from trainwise-ml), NOT a program_id - the catalog table is
 * TRUNCATE-and-reseeded on every ML sync, so a program_id FK would break
 * silently on the next reseed.
 */
function ensureTrainingDemandTable($con) {
    $checkTableQuery = $con->query("SHOW TABLES LIKE 'training_demand'");
    if ($checkTableQuery && $checkTableQuery->num_rows > 0) {
        ensureTrainingDemandBudgetHintColumn($con);
        ensureTrainingDemandStructuredFoundColumns($con);
        ensureTrainingDemandCompletedStatus($con);
        ensureTrainingDemandConfirmedStatus($con);
        ensureTrainingDemandFoundTimeColumns($con);
        ensureTrainingDemandActualModalityColumn($con);
        ensureTrainingDemandFoundTitleColumn($con);
        ensureTrainingDemandFreeColumn($con);
        ensureTrainingDemandSourcedCollegeColumn($con);
        catchUpStaleConfirmedTrainingDemands($con);
        catchUpStaleTrainingDemands($con);
        return;
    }

    $createTableSQL = "
        CREATE TABLE IF NOT EXISTS training_demand (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL UNIQUE,
            description TEXT,
            training_type VARCHAR(100),
            pipeline_status ENUM('Pending HR Review', 'Forwarded to Dean', 'Training Found', 'HR Approved', 'Confirmed', 'Training Completed', 'Closed') DEFAULT 'Pending HR Review',
            budget_hint VARCHAR(255) NULL,
            found_training_details TEXT NULL,
            found_provider VARCHAR(255) NULL,
            found_cost VARCHAR(100) NULL,
            found_dates VARCHAR(255) NULL,
            found_start_time TIME NULL,
            found_end_time TIME NULL,
            found_capacity INT NULL,
            found_venue VARCHAR(255) NULL,
            actual_modality VARCHAR(30) NULL,
            found_training_title VARCHAR(255) NULL,
            is_free TINYINT(1) DEFAULT 0,
            found_notes TEXT NULL,
            found_updated_at TIMESTAMP NULL,
            found_by_user_id INT UNSIGNED NULL,
            hr_approved_by_user_id INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    if (!$con->query($createTableSQL)) {
        error_log("Error creating training_demand table: " . $con->error);
    }
}

/**
 * Self-heal: optional guidance HR can leave for the dean when forwarding
 * (e.g. a rough budget ceiling) - not required, since a firm number
 * often isn't known until the dean actually talks to a provider (see
 * 2026-08-28 design discussion). Surfaced in the dean's notification
 * message, not a separate UI section, to avoid touching all 13 college
 * dashboards for an optional field.
 */
function ensureTrainingDemandBudgetHintColumn($con) {
    $checkColumn = $con->query("SHOW COLUMNS FROM training_demand LIKE 'budget_hint'");
    if ($checkColumn && $checkColumn->num_rows > 0) {
        return;
    }
    if (!$con->query("ALTER TABLE training_demand ADD COLUMN budget_hint VARCHAR(255) NULL")) {
        error_log("Error adding budget_hint column to training_demand: " . $con->error);
    }
}

/**
 * Self-heal: replaces the single free-text `found_training_details` box
 * with structured fields (2026-08-28) - a dean reporting back can no
 * longer bury the price/date/capacity in a paragraph HR has to parse by
 * eye. `found_training_details` itself is kept, unused going forward, so
 * demand rows reported before this change still display correctly (see
 * get_demand_detail.php's fallback logic).
 */
function ensureTrainingDemandStructuredFoundColumns($con) {
    $checkColumn = $con->query("SHOW COLUMNS FROM training_demand LIKE 'found_provider'");
    if ($checkColumn && $checkColumn->num_rows > 0) {
        return;
    }
    if (!$con->query("ALTER TABLE training_demand
        ADD COLUMN found_provider VARCHAR(255) NULL,
        ADD COLUMN found_cost VARCHAR(100) NULL,
        ADD COLUMN found_dates VARCHAR(255) NULL,
        ADD COLUMN found_capacity INT NULL,
        ADD COLUMN found_venue VARCHAR(255) NULL,
        ADD COLUMN found_notes TEXT NULL")) {
        error_log("Error adding structured found-training columns to training_demand: " . $con->error);
    }
}

/**
 * Self-heal: adds a time-of-day component to the found training (2026-08-31)
 * - `found_dates` stays free-text (it's often a range like "Dec 5-7,
 * 2026", not one calendar date, so it can't become a single <input
 * type=date>), but the date(s) given had no time component at all, unlike
 * the granularity already used for past trainings in the employee's own
 * assessment form (start_time/end_time). Also adds found_updated_at,
 * stamped whenever HR edits an already-reported training (see
 * edit_training_demand_found.php) so the employee's card and the HR
 * detail modal can both show "updated on <date>" instead of only ever
 * showing the original report date.
 */
function ensureTrainingDemandFoundTimeColumns($con) {
    $checkColumn = $con->query("SHOW COLUMNS FROM training_demand LIKE 'found_start_time'");
    if ($checkColumn && $checkColumn->num_rows > 0) {
        return;
    }
    if (!$con->query("ALTER TABLE training_demand
        ADD COLUMN found_start_time TIME NULL,
        ADD COLUMN found_end_time TIME NULL,
        ADD COLUMN found_updated_at TIMESTAMP NULL")) {
        error_log("Error adding found-training time columns to training_demand: " . $con->error);
    }
}

/**
 * Self-heal (2026-09-01): the training's ACTUAL delivery modality, as
 * reported by whoever found it - distinct from `preferred_modality` on
 * training_recommendations, which only ever records what an employee
 * *asked for* at Accept time. Before this column existed, there was no
 * way to know what modality the sourced training actually runs in, or to
 * catch a mismatch (e.g. 3 requesters asked for Online, the dean found
 * something Face-to-Face-only) - a real gap the adviser caught while
 * reviewing the modality picker. One value per demand (not per
 * requester), since HR/the dean source one concrete training.
 * Deliberately only the same two values employees choose from at Accept
 * time (Face-to-Face/Online) - no third "Hybrid" option, so every
 * mismatch is a genuine either/or conflict worth flagging, not something
 * that needs a special case.
 */
function ensureTrainingDemandActualModalityColumn($con) {
    $checkColumn = $con->query("SHOW COLUMNS FROM training_demand LIKE 'actual_modality'");
    if ($checkColumn && $checkColumn->num_rows > 0) {
        return;
    }
    if (!$con->query("ALTER TABLE training_demand ADD COLUMN actual_modality VARCHAR(30) NULL AFTER found_venue")) {
        error_log("Error adding actual_modality column to training_demand: " . $con->error);
    }
}

/**
 * Self-heal (2026-09-06): `title` was always the AI/employee-worded IDEA
 * ("Forensic Science and Criminal Investigation Techniques") - useful for
 * matching/pooling, but not necessarily what the real, eventually-found
 * training is actually called. found_training_title is the REAL name, set
 * optionally when a dean/HR reports what they found (see
 * report_training_demand.php) - never overwrites `title` itself, since
 * `title` still has to stay stable for findOrCreateTrainingDemand()'s
 * exact-match pooling logic. Wherever a demand is shown to a human once a
 * training's been found, prefer this over `title` - see demandDisplayTitle().
 */
function ensureTrainingDemandFoundTitleColumn($con) {
    $checkColumn = $con->query("SHOW COLUMNS FROM training_demand LIKE 'found_training_title'");
    if ($checkColumn && $checkColumn->num_rows > 0) {
        return;
    }
    if (!$con->query("ALTER TABLE training_demand ADD COLUMN found_training_title VARCHAR(255) NULL")) {
        error_log("Error adding found_training_title column to training_demand: " . $con->error);
    }
}

/**
 * Self-heal (2026-09-06): this system also covers free seminars/workshops,
 * not just paid ones - found_cost was a free-text field with no way to
 * distinguish "genuinely free" from "cost not entered yet". A real boolean
 * lets the UI hide/disable the cost field when checked and lets HR filter
 * or report on free vs. paid trainings later without parsing found_cost
 * text for the word "free".
 */
function ensureTrainingDemandFreeColumn($con) {
    $checkColumn = $con->query("SHOW COLUMNS FROM training_demand LIKE 'is_free'");
    if ($checkColumn && $checkColumn->num_rows > 0) {
        return;
    }
    if (!$con->query("ALTER TABLE training_demand ADD COLUMN is_free TINYINT(1) DEFAULT 0")) {
        error_log("Error adding is_free column to training_demand: " . $con->error);
    }
}

/**
 * Self-heal (2026-09-06): supports "Post a Training Opportunity" - a dean
 * who already found a real training on their own (the "hey, I found a
 * training, who wants to join?" scenario an ISO tester flagged happening
 * over Messenger, completely outside the system) can now post it directly
 * instead of that ever happening. This column has ONE job: let the
 * employee-facing "opportunities" list (training_recommendations.php)
 * know which college a freshly-posted demand belongs to, since a brand
 * new dean-sourced demand has zero linked training_recommendations rows
 * yet to derive a department from (every other place in this app derives
 * "which college" from the requesters, which doesn't exist yet here -
 * see forward_training_demand.php for that normal path). NULL for every
 * ordinary employee/HR-driven demand - only ever set by
 * create_dean_sourced_training.php.
 */
function ensureTrainingDemandSourcedCollegeColumn($con) {
    $checkColumn = $con->query("SHOW COLUMNS FROM training_demand LIKE 'sourced_college'");
    if ($checkColumn && $checkColumn->num_rows > 0) {
        return;
    }
    if (!$con->query("ALTER TABLE training_demand ADD COLUMN sourced_college VARCHAR(20) NULL")) {
        error_log("Error adding sourced_college column to training_demand: " . $con->error);
    }
}

/**
 * Open "Post a Training Opportunity" postings this employee can still
 * express interest in - their own college's dean posted it directly
 * (sourced_college matches), it hasn't moved past the sign-up window yet
 * (pipeline_status = 'Training Found'), and they haven't already signed
 * up for it. See create_dean_sourced_training.php for the full design.
 */
function getOpenTrainingOpportunities($con, $userId, $userDept) {
    $collegeCode = canonicalTnaCollegeCode($userDept);
    if ($collegeCode === null) {
        return [];
    }
    $stmt = $con->prepare("
        SELECT d.id, d.title, d.found_training_title, d.description, d.training_type,
               d.found_provider, d.found_cost, d.is_free, d.found_dates, d.found_start_time, d.found_end_time,
               d.found_capacity, d.found_venue, d.actual_modality
        FROM training_demand d
        WHERE d.sourced_college = ? AND d.pipeline_status = 'Training Found'
          AND NOT EXISTS (SELECT 1 FROM training_recommendations tr WHERE tr.demand_id = d.id AND tr.user_id = ?)
        ORDER BY d.found_updated_at DESC
    ");
    $stmt->bind_param("si", $collegeCode, $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * The name to actually SHOW a human for this demand - the real found
 * training's title once known, falling back to the original idea/topic
 * title before that. Never use this for matching/lookup (findOrCreateTrainingDemand,
 * findExistingTrainingMatch, etc.) - those must keep using the raw `title`
 * column, which stays stable by design.
 */
function demandDisplayTitle(array $demand): string {
    return !empty($demand['found_training_title']) ? $demand['found_training_title'] : $demand['title'];
}

/**
 * Top N most-requested trainings, computed LIVE from what employees have
 * actually accepted/requested in the system right now - not a static
 * historical document. 2026-09-07 - originally this widget statically
 * displayed the 2027 TNA summary's own top-10 ranking (a fixed snapshot
 * from that one document), which the user correctly pointed out isn't
 * what "top 10 most requested" should mean once the system itself has
 * real submitted demand to rank - it should reflect current reality, not
 * a copy of one paper document. Reuses training_demand's existing
 * requester_count aggregation (the exact same signal the main Training
 * Demand table below already ranks by) rather than inventing a second,
 * different counting method.
 */
function getTopRequestedTrainings($con, int $limit = 10): array {
    $stmt = $con->prepare("
        SELECT d.id, d.title, d.found_training_title, d.pipeline_status, d.sourced_college,
               COUNT(tr.id) AS requester_count
        FROM training_demand d
        LEFT JOIN training_recommendations tr ON tr.demand_id = d.id
          AND tr.status IN ('Accepted', 'Training Available', 'Confirmed', 'Completed')
        GROUP BY d.id
        HAVING requester_count > 0
        ORDER BY requester_count DESC, d.created_at ASC
        LIMIT ?
    ");
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Self-heal: adds 'Training Completed' to pipeline_status (2026-08-28) -
 * set automatically (see update_training_recommendation.php) once every
 * Confirmed participant for a demand has actually completed it, not
 * something HR clicks manually. Purely additive, no data migration
 * needed since no existing row can already hold this value.
 */
function ensureTrainingDemandCompletedStatus($con) {
    $checkColumn = $con->query("SHOW COLUMNS FROM training_demand LIKE 'pipeline_status'");
    $columnInfo = $checkColumn ? $checkColumn->fetch_assoc() : null;
    if (!$columnInfo || strpos($columnInfo['Type'], "'Training Completed'") !== false) {
        return;
    }
    if (!$con->query("ALTER TABLE training_demand MODIFY COLUMN pipeline_status ENUM('Pending HR Review', 'Forwarded to Dean', 'Training Found', 'HR Approved', 'Confirmed', 'Training Completed', 'Closed') DEFAULT 'Pending HR Review'")) {
        error_log("Error adding 'Training Completed' to training_demand pipeline_status ENUM: " . $con->error);
    }
}

/**
 * Self-heal: adds 'Confirmed' to pipeline_status (2026-08-31) - sits
 * between 'HR Approved' and 'Training Completed'. Before this, the demand
 * itself stayed labeled "HR Approved" even after HR had already picked the
 * final attendees via confirm_training_participant.php, which only ever
 * flipped the per-employee training_recommendations rows to 'Confirmed' -
 * the demand-level badge HR actually watches in the Training Demand list
 * never reflected that a decision had been made. Set automatically (see
 * confirm_training_participant.php), never something HR clicks by hand -
 * same convention as 'Training Completed' above.
 */
function ensureTrainingDemandConfirmedStatus($con) {
    $checkColumn = $con->query("SHOW COLUMNS FROM training_demand LIKE 'pipeline_status'");
    $columnInfo = $checkColumn ? $checkColumn->fetch_assoc() : null;
    if (!$columnInfo || strpos($columnInfo['Type'], "'Confirmed'") !== false) {
        return;
    }
    if (!$con->query("ALTER TABLE training_demand MODIFY COLUMN pipeline_status ENUM('Pending HR Review', 'Forwarded to Dean', 'Training Found', 'HR Approved', 'Confirmed', 'Training Completed', 'Closed') DEFAULT 'Pending HR Review'")) {
        error_log("Error adding 'Confirmed' to training_demand pipeline_status ENUM: " . $con->error);
    }
}

/**
 * Flip the demand itself to 'Confirmed' once HR has picked at least one
 * final attendee - called from confirm_training_participant.php right
 * after it sets the individual rows to 'Confirmed'. Only moves demands
 * that are still at 'HR Approved' (idempotent - a second confirm round on
 * the same demand, e.g. filling a seat that opened up, doesn't need to
 * "re-confirm" a demand that's already there).
 */
function markTrainingDemandConfirmed($con, $demandId) {
    if (!$demandId) {
        return;
    }
    $stmt = $con->prepare("UPDATE training_demand SET pipeline_status = 'Confirmed' WHERE id = ? AND pipeline_status = 'HR Approved'");
    $stmt->bind_param("i", $demandId);
    $stmt->execute();
    $stmt->close();
}

/**
 * Mirror of markTrainingDemandConfirmed() for the reverse direction
 * (2026-08-31) - called from unconfirm_training_participant.php after
 * bumping someone out of Confirmed. If that leaves zero people actually
 * Confirmed or Completed, the demand itself shouldn't still claim
 * "Confirmed" either - drop it back to 'HR Approved' so the badge matches
 * reality. Only moves demands still at 'Confirmed' (never touches
 * 'Training Completed', which can't happen here anyway - it requires at
 * least one Completed row, and this function only fires when the
 * Confirmed+Completed count is zero).
 */
function maybeRevertTrainingDemandToApproved($con, $demandId) {
    if (!$demandId) {
        return;
    }
    $stmt = $con->prepare("SELECT COUNT(*) c FROM training_recommendations WHERE demand_id = ? AND status IN ('Confirmed', 'Completed')");
    $stmt->bind_param("i", $demandId);
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    if ($count === 0) {
        $updateStmt = $con->prepare("UPDATE training_demand SET pipeline_status = 'HR Approved' WHERE id = ? AND pipeline_status = 'Confirmed'");
        $updateStmt->bind_param("i", $demandId);
        $updateStmt->execute();
        $updateStmt->close();
    }
}

/**
 * Reopens anyone sitting at 'Not Selected' for this demand back to
 * 'Training Available' if there's now genuinely more room than people
 * locked in (Confirmed/Completed) - i.e. found_capacity minus that count
 * is positive. This is one shared check, not two: raising capacity and
 * bumping someone out of Confirmed both do the exact same thing from a
 * seat-math point of view (more room now exists than is used), so both
 * should reopen the pool the same way (2026-08-31, generalized after the
 * user asked whether a bumped person should become reconsiderable again -
 * yes, for the same reason a capacity increase already does this: it only
 * puts them back in the shortlist pool, HR still has to explicitly
 * confirm them again, nothing is undone automatically). Skipped once a
 * demand is 'Training Completed' - the shortlist UI only ever loads for
 * 'HR Approved'/'Confirmed' demands (see showDemandDetail() in
 * admin_page.php), so reopening candidates here would just strand them
 * with no screen left that surfaces them. Returns how many were reopened.
 */
function reopenNotSelectedIfRoomAvailable($con, $demandId) {
    if (!$demandId) {
        return 0;
    }
    $demandStmt = $con->prepare("SELECT title, pipeline_status, found_capacity FROM training_demand WHERE id = ?");
    $demandStmt->bind_param("i", $demandId);
    $demandStmt->execute();
    $demand = $demandStmt->get_result()->fetch_assoc();
    $demandStmt->close();
    if (!$demand || $demand['found_capacity'] === null || $demand['pipeline_status'] === 'Training Completed') {
        return 0;
    }

    $lockedInStmt = $con->prepare("SELECT COUNT(*) c FROM training_recommendations WHERE demand_id = ? AND status IN ('Confirmed', 'Completed')");
    $lockedInStmt->bind_param("i", $demandId);
    $lockedInStmt->execute();
    $lockedIn = (int)$lockedInStmt->get_result()->fetch_assoc()['c'];
    $lockedInStmt->close();

    if ((int)$demand['found_capacity'] <= $lockedIn) {
        return 0; // no room right now
    }

    $notSelectedStmt = $con->prepare("SELECT id, user_id FROM training_recommendations WHERE demand_id = ? AND status = 'Not Selected'");
    $notSelectedStmt->bind_param("i", $demandId);
    $notSelectedStmt->execute();
    $notSelectedRows = $notSelectedStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $notSelectedStmt->close();
    if (empty($notSelectedRows)) {
        return 0;
    }

    $ids = array_column($notSelectedRows, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $reopenStmt = $con->prepare("UPDATE training_recommendations SET status = 'Training Available' WHERE id IN ($placeholders)");
    $reopenStmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $reopenStmt->execute();
    $reopenStmt->close();

    $message = 'More slots opened up for "' . $demand['title'] . '" - you may be reconsidered by HR.';
    $notifStmt = $con->prepare("INSERT INTO notifications (user_id, message, related_id, related_type, is_read, created_at) VALUES (?, ?, ?, 'training_recommendation', 0, NOW())");
    foreach ($notSelectedRows as $r) {
        $notifStmt->bind_param("isi", $r['user_id'], $message, $r['id']);
        $notifStmt->execute();
    }
    $notifStmt->close();

    return count($notSelectedRows);
}

/**
 * If every Confirmed participant for this demand has now completed it
 * (none left still Confirmed, and at least one actually did complete),
 * the demand itself is done - flip it automatically rather than leaving
 * it sitting at "HR Approved" forever with no way to tell it's finished.
 * Called from update_training_recommendation.php right after an
 * individual employee's own status flips to Completed.
 */
function maybeMarkTrainingDemandCompleted($con, $demandId) {
    if (!$demandId) {
        return;
    }
    $stmt = $con->prepare("SELECT status, COUNT(*) c FROM training_recommendations WHERE demand_id = ? GROUP BY status");
    $stmt->bind_param("i", $demandId);
    $stmt->execute();
    $res = $stmt->get_result();
    $counts = [];
    while ($row = $res->fetch_assoc()) {
        $counts[$row['status']] = (int)$row['c'];
    }
    $stmt->close();

    $stillConfirmed = $counts['Confirmed'] ?? 0;
    $completedCount = $counts['Completed'] ?? 0;
    if ($stillConfirmed === 0 && $completedCount > 0) {
        $updateStmt = $con->prepare("UPDATE training_demand SET pipeline_status = 'Training Completed' WHERE id = ? AND pipeline_status IN ('HR Approved', 'Confirmed')");
        $updateStmt->bind_param("i", $demandId);
        $updateStmt->execute();
        $updateStmt->close();
    }
}

/**
 * Same idea as catchUpStaleTrainingDemands() below, one status earlier in
 * the pipeline: markTrainingDemandConfirmed() only runs at the moment
 * confirm_training_participant.php actually confirms someone, so a demand
 * whose participant was already Confirmed BEFORE the 'Confirmed'
 * pipeline_status existed stays stuck reading "Approved" forever, even
 * though that person can already see their training details and upload
 * proof. Found for real (2026-08-31): Carlos's "Inventory and Supply
 * Management" was confirmed through the real UI before this status
 * existed, and never got swept.
 */
function catchUpStaleConfirmedTrainingDemands($con) {
    $con->query("
        UPDATE training_demand d
        SET d.pipeline_status = 'Confirmed'
        WHERE d.pipeline_status = 'HR Approved'
          AND EXISTS (SELECT 1 FROM training_recommendations tr WHERE tr.demand_id = d.id AND tr.status = 'Confirmed')
    ");
}

/**
 * Catches demands maybeMarkTrainingDemandCompleted() above never got a
 * chance to flip - it only runs at the moment a specific person's own
 * status transitions to Completed, so a demand whose every participant
 * already completed BEFORE this auto-flip logic existed (or before
 * demand_id even got backfilled onto their row) stays stuck at "HR
 * Approved" forever with no new transition left to trigger the check.
 * Found for real (2026-08-29): "Advanced Teaching Methodologies Workshop"
 * had all 3 participants Completed from testing done before this feature
 * shipped, and never got swept. One cheap idempotent UPDATE, safe to run
 * on every page load given how small this table is.
 */
function catchUpStaleTrainingDemands($con) {
    $con->query("
        UPDATE training_demand d
        SET d.pipeline_status = 'Training Completed'
        WHERE d.pipeline_status IN ('HR Approved', 'Confirmed')
          AND EXISTS (SELECT 1 FROM training_recommendations tr WHERE tr.demand_id = d.id AND tr.status = 'Completed')
          AND NOT EXISTS (SELECT 1 FROM training_recommendations tr2 WHERE tr2.demand_id = d.id AND tr2.status = 'Confirmed')
    ");
}

/**
 * Find the training_demand row for an exact recommendation title, or
 * create it if this is the first time anyone has Accepted it. Called
 * from update_training_recommendation.php on the Recommended -> Accepted
 * transition.
 */
function findOrCreateTrainingDemand($con, $title, $description, $trainingType) {
    $stmt = $con->prepare("SELECT id FROM training_demand WHERE title = ?");
    $stmt->bind_param("s", $title);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        return (int)$row['id'];
    }

    $insertStmt = $con->prepare("INSERT INTO training_demand (title, description, training_type) VALUES (?, ?, ?)");
    $insertStmt->bind_param("sss", $title, $description, $trainingType);
    if (!$insertStmt->execute()) {
        // Lost a race with another Accept for the same title - the UNIQUE
        // constraint on `title` rejected the insert, so just look it up.
        $insertStmt->close();
        $stmt = $con->prepare("SELECT id FROM training_demand WHERE title = ?");
        $stmt->bind_param("s", $title);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int)$row['id'] : null;
    }
    $demandId = $insertStmt->insert_id;
    $insertStmt->close();
    return (int)$demandId;
}

/**
 * Self-heal: rows migrated from the old 'In Progress' status (see
 * ensureTrainingRecommendationsStatusEnum) become 'Accepted' but have no
 * demand_id yet, since that migration only touches the status column.
 * Without this, that pre-existing test data would be invisible to HR's
 * Training Demand view even though it's now sitting in an Accepted state.
 * Cheap to re-run: only touches rows demand_id IS NULL still applies to.
 */
function backfillTrainingDemandLinks($con) {
    $stmt = $con->query("
        SELECT id, title, description, training_type FROM training_recommendations
        WHERE demand_id IS NULL AND status IN ('Accepted', 'Training Available', 'Confirmed', 'Completed')
    ");
    $rows = $stmt ? $stmt->fetch_all(MYSQLI_ASSOC) : [];
    if (empty($rows)) {
        return;
    }

    $updateStmt = $con->prepare("UPDATE training_recommendations SET demand_id = ? WHERE id = ?");
    foreach ($rows as $row) {
        // 2026-09-09 fix - same reasoning as update_training_recommendation.php's
        // Accept-flow fix: don't seed a new demand row with the catalog's
        // training_type as if it were dean-confirmed. A backfilled row whose
        // real training has already been found/completed will just show "To
        // be determined" until someone reports it - honest gap, not a wrong fact.
        $demandId = findOrCreateTrainingDemand($con, $row['title'], $row['description'], null);
        if ($demandId === null) {
            continue;
        }
        $updateStmt->bind_param("ii", $demandId, $row['id']);
        $updateStmt->execute();
    }
    $updateStmt->close();
}

/**
 * Create the training_recommendations table if it doesn't exist yet.
 * user_id must match users.id's type (int(10) UNSIGNED) exactly, or the
 * FOREIGN KEY clause fails with errno 150.
 */
function ensureTrainingRecommendationsTable($con) {
    // Must exist first - training_recommendations.demand_id references it.
    ensureTrainingDemandTable($con);

    $checkTableQuery = $con->query("SHOW TABLES LIKE 'training_recommendations'");
    if ($checkTableQuery && $checkTableQuery->num_rows > 0) {
        ensureTrainingRecommendationsLinkColumn($con);
        ensureTrainingRecommendationsDeanColumns($con);
        ensureTrainingRecommendationsStatusEnum($con);
        ensureTrainingRecommendationsDemandColumn($con);
        ensureTrainingRecommendationsProofColumns($con);
        ensureTrainingRecommendationsNotSelectedStatus($con);
        ensureTrainingRecommendationsReasonColumn($con);
        ensureTrainingRecommendationsModalityColumn($con);
        backfillTrainingDemandLinks($con);
        return;
    }

    $createTableSQL = "
        CREATE TABLE IF NOT EXISTS training_recommendations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            demand_id INT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT,
            reason VARCHAR(500) NULL,
            training_type VARCHAR(100),
            preferred_modality VARCHAR(30) NULL,
            link VARCHAR(500) NULL,
            dean_link VARCHAR(500) NULL,
            dean_comment TEXT NULL,
            priority ENUM('High', 'Medium', 'Low') DEFAULT 'Medium',
            status ENUM('Recommended', 'Declined', 'Accepted', 'Training Available', 'Confirmed', 'Completed', 'Not Selected') DEFAULT 'Recommended',
            recommended_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            completion_date DATETIME NULL,
            is_read TINYINT(1) DEFAULT 0,
            proof_certificate_path VARCHAR(255) NULL,
            proof_approval_letter_path VARCHAR(255) NULL,
            proof_program_path VARCHAR(255) NULL,
            proof_hours DECIMAL(5,2) NULL,
            proof_uploaded_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (demand_id) REFERENCES training_demand(id) ON DELETE SET NULL,
            INDEX idx_user_read (user_id, is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    if (!$con->query($createTableSQL)) {
        error_log("Error creating training_recommendations table: " . $con->error);
    }
}

/**
 * Self-heal: the live table predates the HR demand-aggregation pipeline
 * and still has the old status ENUM ('Recommended','In Progress',
 * 'Completed','Declined'). MySQL rejects writing 'Accepted' into a
 * column whose ENUM doesn't contain it yet (even under a plain UPDATE),
 * so this widens the ENUM to a superset FIRST, migrates any existing
 * 'In Progress' rows to 'Accepted' (the closest equivalent - furthest
 * along besides Completed under the old flow), then narrows the ENUM to
 * its final form once no row can possibly hold the value being dropped.
 */
function ensureTrainingRecommendationsStatusEnum($con) {
    $checkColumn = $con->query("SHOW COLUMNS FROM training_recommendations LIKE 'status'");
    $columnInfo = $checkColumn ? $checkColumn->fetch_assoc() : null;
    if (!$columnInfo || strpos($columnInfo['Type'], "'Accepted'") !== false) {
        return;
    }

    if (!$con->query("ALTER TABLE training_recommendations MODIFY COLUMN status ENUM('Recommended', 'In Progress', 'Completed', 'Declined', 'Accepted', 'Training Available', 'Confirmed') DEFAULT 'Recommended'")) {
        error_log("Error widening training_recommendations status ENUM: " . $con->error);
        return;
    }
    if (!$con->query("UPDATE training_recommendations SET status = 'Accepted' WHERE status = 'In Progress'")) {
        error_log("Error migrating 'In Progress' rows before status ENUM change: " . $con->error);
    }
    if (!$con->query("ALTER TABLE training_recommendations MODIFY COLUMN status ENUM('Recommended', 'Declined', 'Accepted', 'Training Available', 'Confirmed', 'Completed') DEFAULT 'Recommended'")) {
        error_log("Error narrowing training_recommendations status ENUM: " . $con->error);
    }
}

/**
 * Self-heal: adds 'Not Selected' to the status ENUM (2026-08-28) - purely
 * additive, no data migration needed since no existing row can already
 * hold this value. Used by confirm_training_participant.php: when HR
 * confirms a subset of a demand's shortlist (e.g. the training only has
 * budget/room for 10 of 15 requesters), everyone else who was sitting at
 * 'Training Available' for that same demand moves here instead of being
 * left indefinitely on "awaiting HR's final list" with no closure.
 */
function ensureTrainingRecommendationsNotSelectedStatus($con) {
    $checkColumn = $con->query("SHOW COLUMNS FROM training_recommendations LIKE 'status'");
    $columnInfo = $checkColumn ? $checkColumn->fetch_assoc() : null;
    if (!$columnInfo || strpos($columnInfo['Type'], "'Not Selected'") !== false) {
        return;
    }
    if (!$con->query("ALTER TABLE training_recommendations MODIFY COLUMN status ENUM('Recommended', 'Declined', 'Accepted', 'Training Available', 'Confirmed', 'Completed', 'Not Selected') DEFAULT 'Recommended'")) {
        error_log("Error adding 'Not Selected' to training_recommendations status ENUM: " . $con->error);
    }
}

/**
 * Self-heal: adds demand_id, linking an employee's Accepted row to the
 * pooled cross-college training_demand row for that exact title.
 */
function ensureTrainingRecommendationsDemandColumn($con) {
    $checkColumn = $con->query("SHOW COLUMNS FROM training_recommendations LIKE 'demand_id'");
    if ($checkColumn && $checkColumn->num_rows > 0) {
        return;
    }
    if (!$con->query("ALTER TABLE training_recommendations ADD COLUMN demand_id INT NULL, ADD FOREIGN KEY (demand_id) REFERENCES training_demand(id) ON DELETE SET NULL")) {
        error_log("Error adding demand_id column to training_recommendations: " . $con->error);
    }
}

/**
 * Self-heal: the live table was created before the `link` column existed.
 * This was the AUTO-SUGGESTED free-course link from the old
 * catalog-link flow. Superseded by the HR demand-aggregation pipeline
 * (see training_demand above) - left in place for old rows, no longer
 * read by training_recommendations.php.
 */
function ensureTrainingRecommendationsLinkColumn($con) {
    $checkColumn = $con->query("SHOW COLUMNS FROM training_recommendations LIKE 'link'");
    if ($checkColumn && $checkColumn->num_rows > 0) {
        return;
    }
    if (!$con->query("ALTER TABLE training_recommendations ADD COLUMN link VARCHAR(500) NULL AFTER training_type")) {
        error_log("Error adding link column to training_recommendations: " . $con->error);
    }
}

/**
 * Self-heal: adds the dean's old per-recommendation review fields
 * (dean_link/dean_comment). These belonged to the deprecated
 * catalog-link-approval flow (see save_dean_note.php) - superseded by
 * the training_demand pipeline. Left in the schema for old rows, no
 * longer written to by any current code path.
 */
function ensureTrainingRecommendationsDeanColumns($con) {
    $checkColumn = $con->query("SHOW COLUMNS FROM training_recommendations LIKE 'dean_link'");
    if ($checkColumn && $checkColumn->num_rows > 0) {
        return;
    }
    if (!$con->query("ALTER TABLE training_recommendations ADD COLUMN dean_link VARCHAR(500) NULL, ADD COLUMN dean_comment TEXT NULL")) {
        error_log("Error adding dean review columns to training_recommendations: " . $con->error);
    }
}

/**
 * Self-heal: staging area for the 4 documents + hours an employee must
 * provide before Mark Complete is allowed on a Confirmed training (per
 * the 2026-08-27 requirement - real gate/UI wiring deferred to a
 * follow-up session, this schema ships now since it's purely additive
 * and safe to have in place early). All nullable - "has proof been
 * uploaded yet" is exactly "are these four non-null," no separate status
 * flag needed. getTrainingRecommendations() already does SELECT *, so
 * these become available with zero query changes.
 */
function ensureTrainingRecommendationsProofColumns($con) {
    $checkColumn = $con->query("SHOW COLUMNS FROM training_recommendations LIKE 'proof_certificate_path'");
    if ($checkColumn && $checkColumn->num_rows > 0) {
        return;
    }
    if (!$con->query("ALTER TABLE training_recommendations
        ADD COLUMN proof_certificate_path VARCHAR(255) NULL,
        ADD COLUMN proof_approval_letter_path VARCHAR(255) NULL,
        ADD COLUMN proof_program_path VARCHAR(255) NULL,
        ADD COLUMN proof_hours DECIMAL(5,2) NULL,
        ADD COLUMN proof_uploaded_at TIMESTAMP NULL")) {
        error_log("Error adding proof-of-completion columns to training_recommendations: " . $con->error);
    }
}

/**
 * Self-heal (2026-08-29): separates the ML's per-employee "why this was
 * recommended to you" sentence out of `description` into its own column.
 *
 * Found while verifying the exclusion-severity fix: refreshMLRecommendations()
 * was concatenating that reason straight onto `description` before storing
 * it (e.g. "...culturally responsive teaching... — Related to what
 * colleagues in your college have asked for..."). That's fine for a single
 * employee's own dashboard card, but findOrCreateTrainingDemand() reuses
 * whichever row happened to Accept FIRST as the training_demand's shared,
 * canonical description - so the demand's description ended up carrying
 * one specific person's reason text, non-deterministically depending on
 * accept order. That polluted description then feeds BOTH the topical-
 * exclusion/TNA-boost similarity text AND every dean's "Report to HR"
 * panel (all 13 college files read training_demand.description directly).
 * `reason` is purely additive and NULL for existing rows - old demands
 * already created from a polluted description are not retroactively
 * cleaned (would need re-splitting free text, not reliable); only new
 * Accepts going forward get a clean split.
 */
function ensureTrainingRecommendationsReasonColumn($con) {
    $checkColumn = $con->query("SHOW COLUMNS FROM training_recommendations LIKE 'reason'");
    if ($checkColumn && $checkColumn->num_rows > 0) {
        return;
    }
    if (!$con->query("ALTER TABLE training_recommendations ADD COLUMN reason VARCHAR(500) NULL AFTER description")) {
        error_log("Error adding reason column to training_recommendations: " . $con->error);
    }
}

/**
 * Self-heal (2026-08-29): the employee's modality preference (Face-to-Face
 * vs Online), captured at Accept time - per the adviser's guidance, since
 * the same catalog training can realistically run either way and LSPU
 * genuinely offers both. Deliberately a property of the REQUEST, not the
 * catalog entry - mirrors how department/college is a property of the
 * requesting employee, not the training. Shown to HR/deans as a breakdown
 * on the pooled demand ("5 want Face-to-Face, 3 want Online"), same
 * pattern as the existing college breakdown - not split into separate
 * demands (that was the explicit design decision, weighed against
 * splitting per-modality which would double the pipeline's moving parts).
 */
function ensureTrainingRecommendationsModalityColumn($con) {
    $checkColumn = $con->query("SHOW COLUMNS FROM training_recommendations LIKE 'preferred_modality'");
    if ($checkColumn && $checkColumn->num_rows > 0) {
        return;
    }
    if (!$con->query("ALTER TABLE training_recommendations ADD COLUMN preferred_modality VARCHAR(30) NULL AFTER training_type")) {
        error_log("Error adding preferred_modality column to training_recommendations: " . $con->error);
    }
}

/**
 * The permanent, structured completion record - written once Mark
 * Complete's hard gate is wired up (deferred). Kept deliberately
 * separate from assessments.training_history (a pre-existing,
 * self-reported JSON free-text field with its own unrelated purpose -
 * see CLAUDE.md/plan history for why these must not be merged).
 *
 * Column types matched deliberately: user_id INT UNSIGNED to users.id,
 * recommendation_id plain INT (NOT unsigned) to
 * training_recommendations.id - mismatching either reproduces the exact
 * errno 150 bug already documented and fixed once in this codebase.
 * ON DELETE SET NULL on recommendation_id mirrors the existing
 * training_recommendations.demand_id -> training_demand.id precedent:
 * denormalize title/type/hours/paths so the historical record survives
 * even if the source recommendation row is ever removed.
 */
function ensureTrainingHistoryLogTable($con) {
    ensureTrainingRecommendationsTable($con); // FK dependency - must exist first

    $check = $con->query("SHOW TABLES LIKE 'training_history_log'");
    if ($check && $check->num_rows > 0) {
        return;
    }
    $createTableSQL = "
        CREATE TABLE IF NOT EXISTS training_history_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            recommendation_id INT NULL,
            title VARCHAR(255) NOT NULL,
            training_type VARCHAR(100) NULL,
            hours DECIMAL(5,2) NULL,
            completion_date DATETIME NOT NULL,
            certificate_path VARCHAR(255) NULL,
            approval_letter_path VARCHAR(255) NULL,
            program_path VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (recommendation_id) REFERENCES training_recommendations(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    if (!$con->query($createTableSQL)) {
        error_log("Error creating training_history_log table: " . $con->error);
    }
}

/**
 * Fetch a user's structured, proof-backed completion history, most
 * recent first. Not yet called from anywhere - the dashboard display is
 * part of the deferred UI half of this feature.
 */
function getTrainingHistoryLog($con, $userId) {
    $stmt = $con->prepare("SELECT * FROM training_history_log WHERE user_id = ? ORDER BY completion_date DESC");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Decide whether it's worth calling the ML service again for this user.
 * Recommendations are assessment-driven only: a user with no submitted
 * assessment yet never gets a refresh, even on a first call, so
 * completing a profile alone can never generate a recommendation (per
 * adviser feedback - profile-only recommendations were redundant with,
 * and sometimes identical to, the assessment-based ones). Once at least
 * one assessment exists, refresh when there's no prior recommendation
 * yet, or the latest assessment submission is newer than the latest
 * recommendation batch.
 */
function needsMLRefresh($con, $userId) {
    $lastSubmission = null;
    $stmt = $con->prepare("SELECT MAX(submission_date) AS last_sub FROM assessments WHERE user_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $lastSubmission = $row['last_sub'] ?? null;
        $stmt->close();
    }

    if ($lastSubmission === null) {
        return false;
    }

    $lastRecommendation = null;
    $stmt = $con->prepare("SELECT MAX(recommended_date) AS last_rec FROM training_recommendations WHERE user_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $lastRecommendation = $row['last_rec'] ?? null;
        $stmt->close();
    }

    if ($lastRecommendation === null) {
        return true;
    }

    return strtotime($lastSubmission) > strtotime($lastRecommendation);
}

/**
 * Call the ML service for fresh recommendations and store them.
 * Returns true on success, false on any failure (details go to error_log).
 */
function refreshMLRecommendations($con, $userId) {
    $stmt = $con->prepare("SELECT department, designation, position, teaching_status, yearsInLSPU, educationalAttainment, specialization FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        error_log("refreshMLRecommendations: user $userId not found");
        return false;
    }

    $assessment = null;
    $stmt = $con->prepare("SELECT desired_skills, comments, training_history FROM assessments WHERE user_id = ? ORDER BY submission_date DESC LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $assessment = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    $payload = [
        'user_id' => (int)$userId,
        'department' => $user['department'] ?: null,
        'designation' => $user['designation'] ?: null,
        'position' => $user['position'] ?: null,
        'teaching_status' => $user['teaching_status'] ?: null,
        'years_in_lspu' => $user['yearsInLSPU'] ?: null,
        'educational_attainment' => $user['educationalAttainment'] ?: null,
        'specialization' => $user['specialization'] ?: null,
        'desired_skills' => $assessment['desired_skills'] ?? null,
        'comments' => $assessment['comments'] ?? null,
        'training_history' => $assessment['training_history'] ?? null,
    ];

    error_log("refreshMLRecommendations: payload for user $userId: " . json_encode($payload));

    $ch = curl_init(rtrim(ML_API_BASE_URL, '/') . '/recommend');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    error_log("refreshMLRecommendations: HTTP $httpCode for user $userId, response: " . substr((string)$response, 0, 2000));

    if ($response === false) {
        error_log("ML API call failed for user $userId: $curlError");
        return false;
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        error_log("ML API returned HTTP $httpCode for user $userId: $response");
        return false;
    }

    $data = json_decode($response, true);
    $recommendations = $data['recommendations'] ?? null;
    if (!is_array($recommendations)) {
        error_log("refreshMLRecommendations: malformed response for user $userId: $response");
        return false;
    }

    // Only replace recommendations the user hasn't started/completed/declined yet.
    $deleteStmt = $con->prepare("DELETE FROM training_recommendations WHERE user_id = ? AND status = 'Recommended'");
    $deleteStmt->bind_param("i", $userId);
    $deleteStmt->execute();
    $deleteStmt->close();

    // Never re-recommend a title the person is already actively on, or has
    // already finished (2026-09-01) - the ML service itself has no memory
    // of what this specific person has already done with this catalog, so
    // without this every refresh could hand someone a fresh "Recommended"
    // card for something they're mid-pipeline on or already completed,
    // found for real testing Ana against "Digital Forensics and Evidence
    // Handling" after she'd already finished it once. Declined is
    // deliberately NOT in this list - circumstances change, and someone
    // who passed on something once may want it reconsidered in a later
    // cycle; that's a real decision to leave re-offerable, not an oversight.
    $activeTitlesStmt = $con->prepare("
        SELECT DISTINCT title FROM training_recommendations
        WHERE user_id = ? AND status IN ('Accepted', 'Training Available', 'Confirmed', 'Not Selected', 'Completed')
    ");
    $activeTitlesStmt->bind_param("i", $userId);
    $activeTitlesStmt->execute();
    $activeTitles = array_column($activeTitlesStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'title');
    $activeTitlesStmt->close();
    $activeTitles = array_flip($activeTitles); // O(1) lookup below

    // description and reason are stored separately (2026-08-29 fix) - kept
    // concatenated together they used to pollute training_demand's shared
    // canonical description (see ensureTrainingRecommendationsReasonColumn
    // for the full story). description stays the catalog's actual
    // description of the training; reason is this employee's personal
    // "why this was recommended to you" line, joined back on at display
    // time only (training_recommendations.php).
    $insertStmt = $con->prepare("INSERT INTO training_recommendations (user_id, title, description, reason, training_type, link, priority) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $insertedCount = 0;
    foreach (array_values($recommendations) as $rec) {
        $title = $rec['title'] ?? 'Suggested Training';
        if (isset($activeTitles[$title])) {
            continue; // already Accepted/in-pipeline/Completed/Not Selected - don't re-offer it
        }
        $description = trim($rec['description'] ?? '');
        $reason = trim($rec['reason'] ?? '') ?: null;
        $trainingType = $rec['training_type'] ?? null;
        $link = $rec['reference_link'] ?? null;
        // Priority is ranked by position among what's actually being
        // inserted, not raw ML response order - a skipped title shouldn't
        // shift everything after it down a tier.
        $priority = $insertedCount < 2 ? 'High' : ($insertedCount < 4 ? 'Medium' : 'Low');

        $insertStmt->bind_param("issssss", $userId, $title, $description, $reason, $trainingType, $link, $priority);
        $insertStmt->execute();
        $insertedCount++;
    }
    $insertStmt->close();

    return true;
}

/**
 * Fetch stored recommendations for a user, highest priority first.
 */
function getTrainingRecommendations($con, $userId) {
    // Left-joined to training_demand (2026-08-31) so the employee's own
    // card can show what was actually found - provider/cost/dates/time/
    // venue/notes - instead of just a generic "HR is finalizing" status
    // line. LEFT JOIN, not INNER: most rows have no demand_id yet
    // (Recommended/Declined), and even once one exists found_* stays NULL
    // until something is actually reported.
    // Also left-joined to users (2026-09-01, aliased fb) on found_by_user_id
    // so the card can correctly say "What the Dean Found" vs "What HR
    // Found" - previously hardcoded to always say HR, which was wrong
    // whenever a dean was the one who actually reported it (the common
    // case). A dean's role always starts with 'admin_<college>'; HR's
    // plain role is just 'admin' - same distinction admin_page.php's
    // own has_dean logic already uses on the HR-facing side.
    $stmt = $con->prepare("
        SELECT tr.*,
               d.found_provider, d.found_cost, d.is_free, d.found_dates, d.found_start_time, d.found_end_time,
               d.found_capacity, d.found_venue, d.actual_modality, d.found_notes, d.found_updated_at,
               d.found_training_title,
               fb.role AS found_by_role
        FROM training_recommendations tr
        LEFT JOIN training_demand d ON d.id = tr.demand_id
        LEFT JOIN users fb ON fb.id = d.found_by_user_id
        WHERE tr.user_id = ?
        ORDER BY FIELD(tr.status, 'Recommended') DESC, FIELD(tr.priority, 'High', 'Medium', 'Low'), tr.created_at DESC
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $recommendations = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $recommendations;
}

// Empirically calibrated (2026-08-26) against real training_programs
// catalog description pairs via the /similarity_check endpoint:
// near-duplicate trainings scored ~0.85, genuinely-same-topic pairs
// (e.g. "Test Construction and Item Analysis" vs "Student Assessment
// and Evaluation Techniques") scored ~0.44, loosely-related-but-
// different-skill pairs (e.g. two different "research" trainings)
// scored ~0.39, and clearly unrelated pairs scored ~0.14-0.26. 0.42
// sits between the "genuinely same topic" and "loosely related" bands.
// 2026-08-29 correction: the single 0.42 cutoff was calibrated as "at or
// above genuinely-same-topic" (~0.44 in the original manual calibration),
// which turned out to hard-exclude people for merely being in the same
// subject area as a past training, not for the training being redundant.
// Live demo evidence: "Advanced Teaching Methodologies Workshop" (past)
// vs. "Inclusive Classroom Strategies" (new demand) scored in that same-
// topic band and hard-excluded every single requester, defeating the
// shortlist's purpose as decision support. Split into two tiers instead:
// SOFT_THRESHOLD keeps the old cutoff (still worth flagging to HR) but no
// longer removes the person; HARD_THRESHOLD is raised well clear of the
// same-topic band, into near-duplicate territory (~0.85 in the original
// calibration), reserved for "this is basically the same training again."
// Still only an n=4-ish manual calibration, not rigorously validated -
// documented as such, same honesty standard as the TNA threshold fix.
define('TOPICAL_SIMILARITY_SOFT_THRESHOLD', 0.42);
define('TOPICAL_SIMILARITY_EXCLUSION_THRESHOLD', 0.65);

/**
 * Ask the ML service which of a set of candidate texts are topically
 * similar enough to $queryText to be worth flagging - split into a HARD
 * tier (>= TOPICAL_SIMILARITY_EXCLUSION_THRESHOLD, removed from the
 * shortlist entirely - reserved for near-duplicate trainings) and a SOFT
 * tier (>= TOPICAL_SIMILARITY_SOFT_THRESHOLD but below hard - shown to HR
 * as a flag, candidate stays selectable). See the 2026-08-29 correction
 * note above for why this replaced a single hard cutoff.
 *
 * $candidates: [id => text, ...] - id is any caller-chosen identifier
 * (get_demand_shortlist.php uses the prior training_recommendations.id).
 * Returns ['hard' => [id => score, ...], 'soft' => [id => score, ...]].
 * Fails open (both empty) if the ML service is unreachable, so a down ML
 * service degrades this to "no flags/exclusions" rather than breaking
 * the shortlist or blocking HR's ability to work.
 */
function getTopicalExclusions($queryText, array $candidates) {
    $none = ['hard' => [], 'soft' => []];
    if (empty($candidates) || trim($queryText) === '') {
        return $none;
    }

    $payload = [
        'query_text' => $queryText,
        'candidates' => array_map(
            fn($id, $text) => ['id' => $id, 'text' => $text],
            array_keys($candidates),
            array_values($candidates)
        ),
    ];

    $ch = curl_init(rtrim(ML_API_BASE_URL, '/') . '/similarity_check');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        error_log("getTopicalExclusions: ML similarity check unavailable (HTTP $httpCode, curl: $curlError) - failing open, no exclusions applied");
        return $none;
    }

    $data = json_decode($response, true);
    $results = $data['results'] ?? null;
    if (!is_array($results)) {
        error_log("getTopicalExclusions: malformed response: $response");
        return $none;
    }

    $hard = [];
    $soft = [];
    foreach ($results as $r) {
        $score = $r['score'] ?? 0;
        if ($score >= TOPICAL_SIMILARITY_EXCLUSION_THRESHOLD) {
            $hard[$r['id']] = $score;
        } elseif ($score >= TOPICAL_SIMILARITY_SOFT_THRESHOLD) {
            $soft[$r['id']] = $score;
        }
    }
    return ['hard' => $hard, 'soft' => $soft];
}

/**
 * Boosts AI Shortlist scores toward candidates whose college officially
 * requested something topically similar to the demand being shortlisted,
 * in LSPU's 2025 Training Needs Assessment (see
 * trainwise-ml/scripts/seed_tna_2025.py, tna_2025_demand table). Per the
 * subject specialist's guidance (2026-08-27): real institutional demand
 * data, not a training signal for a model - grounds the shortlist in
 * real demand instead of only proxy criteria (tenure, repeat-avoidance).
 *
 * NOT 0.42 (TOPICAL_SIMILARITY_EXCLUSION_THRESHOLD above) - that value
 * compares two items within the same small training_programs catalog.
 * This compares against the much more heterogeneous TNA-2025 title list,
 * and reusing 0.42 produced a real false positive during manual
 * verification on the ML side (2026-08-27) - see
 * trainwise-ml/app/ml/recommender.py's TNA_EXPLANATION_THRESHOLD comment
 * for the exact case. Kept in sync with that corrected value (0.47).
 */
define('TNA_MATCH_SIMILARITY_THRESHOLD', 0.47);
// Flat point bonus added to a candidate's shortlist score - roughly 1.6x
// a single prior-confirmed-training penalty (3 points), meaningful
// without single-handedly dominating tenure/fairness in the ranking.
define('TNA_MATCH_SCORE_BONUS', 5);
// Only 4 of 13 colleges submitted anything official for 2025. For every
// other college, the boost falls back to what that college's OWN
// employees have already said via their own assessment submissions
// (assessments.desired_skills) - real data, just not reviewed/aggregated
// by college leadership the way an official TNA submission has been.
// Smaller bonus reflects that lower level of institutional validation,
// not that it's less real (same ~0.6 ratio as the ML side's
// SELF_REPORTED_WEIGHT/TNA_WEIGHT in recommender.py).
define('SELF_REPORTED_MATCH_SCORE_BONUS', 3);

// Mirrors trainwise-ml's app/ml/features.py::DEPARTMENT_CODE_MAP - all 13
// colleges + the non-teaching ADMIN bucket, so this boost can resolve any
// college, not just the 4 with official TNA-2025 data (the fallback path
// below needs every college resolvable too). users.department is
// inconsistently populated across the live DB (some rows have short
// codes, most have full college names) - both variants are covered here.
// Keep in sync with the Python side if either changes.
const TNA_COLLEGE_CODE_MAP = [
    'ccs' => 'CCS', 'college of computer studies' => 'CCS',
    'cas' => 'CAS', 'college of arts and sciences' => 'CAS',
    'college of arts and sciencecollege of arts and sciences' => 'CAS',
    'cbaa' => 'CBAA', 'college of business, administration and accountancy' => 'CBAA',
    'ccje' => 'CCJE', 'college of criminal justice education' => 'CCJE',
    'coe' => 'COE', 'college of engineering' => 'COE',
    'cit' => 'CIT', 'college of industrial technology' => 'CIT',
    'cfnd' => 'CFND', 'college of food, nutrition and dietetics' => 'CFND',
    'cof' => 'COF', 'college of fisheries' => 'COF',
    'chmt' => 'CHMT',
    'cte' => 'CTE', 'college of teacher education' => 'CTE',
    'conah' => 'CONAH', 'college of nursing and allied health' => 'CONAH',
    'col' => 'COL', 'college of law' => 'COL',
    'ca' => 'CA', 'college of agriculture' => 'CA',
    'main admin' => 'ADMIN', 'admin' => 'ADMIN',
    // 2026-09-02 - the profile/registration forms now offer non-teaching
    // staff a curated dropdown of specific offices instead of free text;
    // each resolves to the same pooled ADMIN bucket used for TNA-2025
    // matching and dean-routing purposes (there's no per-office TNA data
    // or dean role - only one blended ADMIN bucket exists). Kept in sync
    // with $nonTeachingOfficeAliases in admin_page.php and
    // DEPARTMENT_CODE_MAP in trainwise-ml's app/ml/features.py.
    "registrar's office" => 'ADMIN',
    'accounting/budget office' => 'ADMIN',
    'human resource management office (hrmo)' => 'ADMIN',
    'supply/property office' => 'ADMIN',
    'library' => 'ADMIN',
    // 2026-09-07 - real 13 offices, given directly by HR (Dr. Imee
    // Prescilla P. Sanchez, HRMO), replacing the placeholder 5 above as
    // the dropdown's actual options. The placeholder 5 stay mapped too -
    // see the note on $nonTeachingOfficeAliases in admin_page.php.
    'office of the campus director' => 'ADMIN',
    'guidance counselor' => 'ADMIN',
    'disbursing and cashiering' => 'ADMIN',
    'records office' => 'ADMIN',
    'general services unit' => 'ADMIN',
    'library services' => 'ADMIN',
    'supply' => 'ADMIN',
    'admission and registrarship' => 'ADMIN',
    'accounting office' => 'ADMIN',
    'budget and finance' => 'ADMIN',
    'human resource management' => 'ADMIN',
    'medical and dental services' => 'ADMIN',
    'procurement' => 'ADMIN',
];

function canonicalTnaCollegeCode($rawDepartment) {
    if (!$rawDepartment) return null;
    return TNA_COLLEGE_CODE_MAP[strtolower(trim($rawDepartment))] ?? null;
}

/**
 * Count of distinct training_demand rows currently forwarded to a given
 * college's dean and awaiting a response (>= 1 requester in that college).
 * 2026-09-04 - extracted so the sidebar "Training Demand" nav badge can be
 * computed identically on every page (dashboard, dedicated Training Demand
 * page, Assessment Form, IDP Forms, Evaluation) without duplicating the
 * canonicalTnaCollegeCode()-based aggregation logic in five places per
 * college x 13 colleges - the exact kind of duplication that caused the
 * evaluated_this_year/evaluation_stats scoping bug found earlier today.
 */
/**
 * Builds the dean topbar bell's notification list + unread badge count.
 *
 * 2026-09-06 fix - every dean dashboard built this inline, and the bug the
 * user found (click "Mark all as read", log out, log back in, the exact
 * same notifications and an active "Mark all as read" button are still
 * there) was caused by $notificationCount being count($notifications) -
 * i.e. it counted EVERY item shown, including two kinds that have no
 * concept of "read" at all:
 *   - "New evaluation submitted for X" - a live re-query of the 3 most
 *     recent evaluations, recomputed fresh on every page load. There was
 *     never a persisted read/unread state to begin with, so it can never
 *     be "cleared".
 *   - "N pending evaluations" - a live gauge of current outstanding work.
 *     Marking it "read" while the work is still outstanding would be a
 *     lie, so it deliberately has no read state either.
 * mark_notifications_read.php DOES correctly flip is_read=1 on the one
 * real, one-time event type - training_demand rows HR forwards to a dean
 * - but the badge count never looked at is_read, so it stayed stuck at
 * the total item count regardless of what got marked read.
 *
 * Fix: only genuinely dismissible events (training_demand, is_read-backed)
 * count toward the unread badge. The two ambient/live items above are
 * still shown in the list for context (so the bell isn't literally empty
 * most of the time) but are excluded from the count and never claim to be
 * "read" - a dean sees them until the underlying condition changes on its
 * own (a newer evaluation replaces the old one; pending hits zero).
 * Already-read training_demand items are NOT deleted after being marked
 * read - they stay in the list (ordered unread-first) so scrolling the
 * dropdown shows recent history, matching every mainstream notification
 * inbox (Gmail/Slack/GitHub): read items go quiet, they don't vanish.
 *
 * @return array{items: array<int, array>, unread_count: int}
 */
function getDeanNotifications(mysqli $con, int $userId, string $deptCode): array {
    $items = [];
    $unreadCount = 0;
    try {
        // Recent evaluations - ambient activity, always shown, no read state.
        $stmt = $con->prepare("
            SELECT 'evaluation' as type, CONCAT('New evaluation submitted for ', u.name) as message, e.created_at as timestamp
            FROM evaluations e JOIN users u ON e.user_id = u.id
            WHERE u.department = ? ORDER BY e.created_at DESC LIMIT 3
        ");
        $stmt->bind_param("s", $deptCode);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) { $items[] = $row; }
        $stmt->close();

        // Pending evaluations - ambient status, always shown, no read state.
        $stmt = $con->prepare("
            SELECT COUNT(*) as count FROM users u
            WHERE u.department = ? AND u.role = 'user' AND u.teaching_status IS NOT NULL AND u.teaching_status != ''
            AND NOT EXISTS (SELECT 1 FROM evaluations e WHERE e.user_id = u.id)
        ");
        $stmt->bind_param("s", $deptCode);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row && (int)$row['count'] > 0) {
            $items[] = ['type' => 'pending', 'message' => "You have {$row['count']} pending evaluations", 'timestamp' => date('Y-m-d H:i:s')];
        }

        // Training demand HR forwarded to this dean (see
        // forward_training_demand.php) - the one real, one-time event
        // type with a persisted read state. Unread first, so history
        // scrolls below without needing to be deleted.
        $stmt = $con->prepare("
            SELECT 'training_demand' as type, message, created_at as timestamp, is_read
            FROM notifications WHERE user_id = ? AND related_type = 'training_demand'
            ORDER BY is_read ASC, created_at DESC LIMIT 5
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $items[] = $row;
            if ((int)$row['is_read'] === 0) { $unreadCount++; }
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("getDeanNotifications error: " . $e->getMessage());
    }
    return ['items' => $items, 'unread_count' => $unreadCount];
}

function getForwardedDemandCountForCollege(mysqli $con, string $collegeCode): int {
    $result = $con->query("
        SELECT d.id, u.department
        FROM training_demand d
        JOIN training_recommendations tr ON tr.demand_id = d.id
        JOIN users u ON u.id = tr.user_id
        WHERE d.pipeline_status = 'Forwarded to Dean'
          AND tr.status IN ('Accepted', 'Training Available', 'Confirmed', 'Completed')
    ");
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $demandIds = [];
    foreach ($rows as $row) {
        if (canonicalTnaCollegeCode($row['department']) === $collegeCode) {
            $demandIds[$row['id']] = true;
        }
    }
    return count($demandIds);
}

/**
 * Shared curl call to the ML service's /similarity_check endpoint - used
 * by both getTna2025Boosts() (official + self-reported fallback) and
 * getTopicalExclusions() would use this too if it were refactored, but
 * that function predates this helper and is left as-is to avoid touching
 * already-verified code. Returns the raw `results` array, or null if the
 * service is unreachable/malformed (distinct from an empty array, which
 * means "reachable, nothing matched").
 */
function callSimilarityCheck($queryText, array $candidates) {
    if (empty($candidates) || trim($queryText) === '') {
        return [];
    }
    $payload = [
        'query_text' => $queryText,
        'candidates' => array_map(
            fn($id, $text) => ['id' => $id, 'text' => $text],
            array_keys($candidates),
            array_values($candidates)
        ),
    ];
    $ch = curl_init(rtrim(ML_API_BASE_URL, '/') . '/similarity_check');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        error_log("callSimilarityCheck: ML similarity check unavailable (HTTP $httpCode, curl: $curlError)");
        return null;
    }
    $data = json_decode($response, true);
    $results = $data['results'] ?? null;
    if (!is_array($results)) {
        error_log("callSimilarityCheck: malformed response: $response");
        return null;
    }
    return $results;
}

/**
 * Self-heal: tna_2025_demand is normally created by trainwise-ml's
 * scripts/seed_tna_2025.py (Python, same shared DB), but this PHP-side
 * guard means the shortlist never hard-fails just because that script
 * hasn't been run yet in a given environment - it just finds zero rows,
 * matching the "no TNA data = no boost" fail-open behavior everywhere
 * else in this feature.
 */
function ensureTna2025Table($con) {
    $check = $con->query("SHOW TABLES LIKE 'tna_2025_demand'");
    if ($check && $check->num_rows > 0) {
        return;
    }
    if (!$con->query("CREATE TABLE IF NOT EXISTS tna_2025_demand (
        id INT AUTO_INCREMENT PRIMARY KEY,
        college_code VARCHAR(20) NOT NULL,
        title VARCHAR(255) NOT NULL,
        source_year INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_college_title (college_code, title)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;")) {
        error_log("Error creating tna_2025_demand table: " . $con->error);
    }
}

/**
 * Self-heal for tna_top_priorities - the institution-wide (not
 * per-college) top-10 ranked training areas that appeared on page 1 of
 * the 2027 TNA summary, seeded by trainwise-ml's scripts/seed_tna_2027.py.
 * Distinct signal from tna_2025_demand's per-college rows - this is
 * aggregate institutional priority, not any one college's request.
 */
function ensureTnaTopPrioritiesTable($con) {
    if (!$con->query("CREATE TABLE IF NOT EXISTS tna_top_priorities (
        id INT AUTO_INCREMENT PRIMARY KEY,
        source_year INT NOT NULL,
        rank_position INT NOT NULL,
        training_area VARCHAR(255) NOT NULL,
        key_topics TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_year_rank (source_year, rank_position)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;")) {
        error_log("Error creating tna_top_priorities table: " . $con->error);
    }
}

/**
 * Returns the most recent year's top-10 institutional training
 * priorities, ranked, for display to HR. Fails open (empty array) if the
 * table doesn't exist yet or the seed script hasn't been run - never a
 * hard error, matching every other TNA lookup in this file.
 */
function getTnaTopPriorities($con) {
    ensureTnaTopPrioritiesTable($con);
    $yearResult = $con->query("SELECT MAX(source_year) AS latest_year FROM tna_top_priorities");
    $latestYear = $yearResult ? ($yearResult->fetch_assoc()['latest_year'] ?? null) : null;
    if ($latestYear === null) {
        return ['year' => null, 'items' => []];
    }
    $stmt = $con->prepare("SELECT rank_position, training_area, key_topics FROM tna_top_priorities WHERE source_year = ? ORDER BY rank_position ASC");
    $stmt->bind_param("i", $latestYear);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return ['year' => (int)$latestYear, 'items' => $items];
}

/**
 * $queryText: the training_demand row's title+description being shortlisted.
 * $collegeCodes: TNA college codes among current shortlist candidates
 * (from canonicalTnaCollegeCode() per candidate) - duplicates/nulls fine.
 *
 * Returns [collegeCode => ['score' => float, 'source' => 'official'|'self_reported'], ...]
 * for colleges that clear the similarity threshold. Only 4 of 13 colleges
 * have official tna_2025_demand rows - every other college falls back to
 * what its own employees have said via assessments.desired_skills (real
 * data, just not the official channel - see the self-reported comments
 * in trainwise-ml/app/ml/tna_matcher.py for the full reasoning). Official
 * data always wins when both exist for a college. Fails open ([]) if the
 * ML service is unreachable or a college has no data of either kind -
 * the shortlist just runs without the bonus, never blocked by it.
 */
function getTna2025Boosts($con, $queryText, array $collegeCodes) {
    ensureTna2025Table($con);
    $collegeCodes = array_values(array_unique(array_filter($collegeCodes)));
    if (empty($collegeCodes) || trim($queryText) === '') {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($collegeCodes), '?'));
    $stmt = $con->prepare("SELECT college_code, title FROM tna_2025_demand WHERE college_code IN ($placeholders)");
    $stmt->bind_param(str_repeat('s', count($collegeCodes)), ...$collegeCodes);
    $stmt->execute();
    $officialRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $officialCandidates = [];
    $officialCollegeByIndex = [];
    $collegesWithOfficialData = [];
    foreach ($officialRows as $row) {
        $collegesWithOfficialData[$row['college_code']] = true;
    }
    foreach ($officialRows as $i => $row) {
        $officialCandidates[$i] = $row['title'];
        $officialCollegeByIndex[$i] = $row['college_code'];
    }

    // Colleges with no official row at all fall back to self-reported
    // assessment text. users.department is messy (mixed codes/full
    // names), so this normalizes every row rather than trying a direct
    // WHERE department = code match.
    $fallbackCodes = array_diff($collegeCodes, array_keys($collegesWithOfficialData));
    $selfReportedCandidates = [];
    $selfReportedCollegeByIndex = [];
    if (!empty($fallbackCodes)) {
        $deptRows = $con->query("
            SELECT u.department, a.desired_skills
            FROM assessments a JOIN users u ON u.id = a.user_id
            WHERE a.desired_skills IS NOT NULL AND a.desired_skills != ''
        ")->fetch_all(MYSQLI_ASSOC);
        $i = 0;
        foreach ($deptRows as $row) {
            $code = canonicalTnaCollegeCode($row['department']);
            if ($code === null || !in_array($code, $fallbackCodes, true)) continue;
            $selfReportedCandidates[$i] = $row['desired_skills'];
            $selfReportedCollegeByIndex[$i] = $code;
            $i++;
        }
    }

    $maxByCollege = [];

    if (!empty($officialCandidates)) {
        $results = callSimilarityCheck($queryText, $officialCandidates);
        if ($results === null) {
            error_log("getTna2025Boosts: ML similarity check unavailable - failing open, no official TNA boost applied");
        } else {
            foreach ($results as $r) {
                $college = $officialCollegeByIndex[$r['id']] ?? null;
                if ($college === null) continue;
                $score = (float)($r['score'] ?? 0);
                if ($score < TNA_MATCH_SIMILARITY_THRESHOLD) continue;
                if (!isset($maxByCollege[$college]) || $score > $maxByCollege[$college]['score']) {
                    $maxByCollege[$college] = ['score' => $score, 'source' => 'official'];
                }
            }
        }
    }

    if (!empty($selfReportedCandidates)) {
        $results = callSimilarityCheck($queryText, $selfReportedCandidates);
        if ($results === null) {
            error_log("getTna2025Boosts: ML similarity check unavailable - failing open, no self-reported boost applied");
        } else {
            foreach ($results as $r) {
                $college = $selfReportedCollegeByIndex[$r['id']] ?? null;
                if ($college === null || isset($maxByCollege[$college])) continue; // official already won for this college
                $score = (float)($r['score'] ?? 0);
                if ($score < TNA_MATCH_SIMILARITY_THRESHOLD) continue;
                if (!isset($maxByCollege[$college]) || $score > $maxByCollege[$college]['score']) {
                    $maxByCollege[$college] = ['score' => $score, 'source' => 'self_reported'];
                }
            }
        }
    }

    return $maxByCollege;
}

/**
 * Self-heal: table for employee free-text "specific training" requests
 * (2026-09-01, per the adviser's direction during the pipeline demo) -
 * covers the case where none of the 5 AI-suggested catalog titles fit
 * what someone actually needs. One row per submission, NOT deduplicated
 * or grouped at insert time - grouping happens later, on demand, via
 * clusterCustomTrainingRequests() below, using SBERT similarity rather
 * than exact-text matching (so "Excel training" and "spreadsheet skills
 * workshop" can still end up in the same cluster).
 *
 * demand_id is plain INT (not UNSIGNED), matching training_demand.id's
 * own type and the same FK-type-mismatch lesson already learned once
 * this project (see training_history_log.recommendation_id) - user_id
 * IS UNSIGNED to match users.id.
 */
function ensureCustomTrainingRequestsTable($con) {
    $checkTableQuery = $con->query("SHOW TABLES LIKE 'custom_training_requests'");
    if ($checkTableQuery && $checkTableQuery->num_rows > 0) {
        return;
    }
    $createTableSQL = "
        CREATE TABLE IF NOT EXISTS custom_training_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            request_text TEXT NOT NULL,
            status ENUM('Unclustered', 'Clustered') DEFAULT 'Unclustered',
            cluster_label VARCHAR(255) NULL,
            demand_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (demand_id) REFERENCES training_demand(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    if (!$con->query($createTableSQL)) {
        error_log("Error creating custom_training_requests table: " . $con->error);
    }
}

// Audit log (2026-09-02, per the adviser during ISO testing) - a plain
// append-only record of who did what in the demand pipeline, for HR to
// review. Deliberately generic (actor/action/entity/description) rather
// than a typed table per action - the alternative (a column per possible
// action's specific fields) would need a schema change every time a new
// action type is added; a free-text description covers every case today
// and every case likely to come up, and this is a capstone-scale audit
// trail, not a compliance system that needs structured per-field replay.
function ensureAuditLogTable($con) {
    $checkTableQuery = $con->query("SHOW TABLES LIKE 'audit_log'");
    if ($checkTableQuery && $checkTableQuery->num_rows > 0) {
        return;
    }
    $createTableSQL = "
        CREATE TABLE IF NOT EXISTS audit_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            actor_user_id INT UNSIGNED NULL,
            actor_name VARCHAR(255) NULL,
            actor_role VARCHAR(50) NULL,
            action VARCHAR(100) NOT NULL,
            entity_type VARCHAR(50) NULL,
            entity_id INT NULL,
            description TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_audit_created (created_at),
            KEY idx_audit_entity (entity_type, entity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    if (!$con->query($createTableSQL)) {
        error_log("Error creating audit_log table: " . $con->error);
    }
}

// Fails open by design (wrapped in try/catch, never throws) - a logging
// failure must never block the actual pipeline action it's describing.
// $actorUserId/$actorRole are normally read straight from $_SESSION at
// the call site; actor_name is denormalized (stored as plain text, not
// looked up via a join every time the log is viewed) so the log stays
// readable even if that user's account is later renamed or removed.
function logAuditEvent($con, $actorUserId, $actorName, $actorRole, $action, $entityType, $entityId, $description) {
    try {
        ensureAuditLogTable($con);
        $stmt = $con->prepare("
            INSERT INTO audit_log (actor_user_id, actor_name, actor_role, action, entity_type, entity_id, description)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param("issssis", $actorUserId, $actorName, $actorRole, $action, $entityType, $entityId, $description);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        error_log("logAuditEvent failed (non-fatal): " . $e->getMessage());
    }
}

// Same "genuinely the same topic" calibration already used for the
// topical-exclusion feature (TOPICAL_SIMILARITY_EXCLUSION_THRESHOLD in
// this same file) and the TNA boost feature - reused here rather than
// invented fresh, since it's the same underlying judgment call: "are
// these two pieces of text about the same training idea." A slightly
// higher bar than TNA's 0.47 is used here (0.5) since clustering
// mistakes compound - grouping two genuinely different requests
// together silently merges their requesters into one wrong demand,
// which is worse than a TNA badge that's merely absent.
if (!defined('CUSTOM_REQUEST_CLUSTER_THRESHOLD')) {
    define('CUSTOM_REQUEST_CLUSTER_THRESHOLD', 0.50);
}

// 2026-09-07 fix - findExistingTrainingMatch() below was reusing this
// same 0.50 clustering threshold for a genuinely different decision.
// Real case that surfaced it: "I want training about improving my
// communication" scored 0.472 against the catalog's "Public Speaking and
// Presentation Skills" - a real, obvious match a human would recognize
// instantly - but missed the 0.50 bar by 0.028 and showed no "might
// already exist" note at all. The two decisions have opposite risk
// profiles: clustering (CUSTOM_REQUEST_CLUSTER_THRESHOLD) silently merges
// multiple people's requests together if wrong, so it deliberately stays
// conservative/high; this check is advisory only - HR sees a hint and
// still decides, so a false positive costs nothing while a false
// negative (this case) means a genuine near-duplicate goes completely
// unflagged. Matches this file's own already-established TNA-boost
// calibration (0.47 - see TNA_EXPLANATION_THRESHOLD in trainwise-ml's
// recommender.py) for exactly this "same topic, advisory, lower risk"
// judgment, rather than reusing a threshold calibrated for a different,
// higher-stakes decision.
if (!defined('EXISTING_MATCH_THRESHOLD')) {
    define('EXISTING_MATCH_THRESHOLD', 0.47);
}

// Used only to disambiguate catalog vs. demand candidate ids inside
// findExistingTrainingMatch() below - the /similarity_check endpoint's
// SimilarityCandidate.id is a strict int (see trainwise-ml/app/schemas.py),
// so string prefixes like "demand_12" aren't an option. A demand id this
// large will never collide with a real training_programs.id (that table
// stays under a few dozen rows by design - it's a curated catalog).
if (!defined('EXISTING_MATCH_DEMAND_ID_OFFSET')) {
    define('EXISTING_MATCH_DEMAND_ID_OFFSET', 1000000);
}

/**
 * Checks whether a piece of free text (an employee's custom request, or a
 * cluster's chosen label before promoting it) already matches something
 * that exists - either a catalog title (the same ~18 titles the AI
 * recommendation engine matches against) or an existing training_demand
 * title (catalog-sourced or a previously-promoted custom cluster).
 *
 * This closes a real gap found 2026-09-01 (confirmed valid by the
 * project's adviser): without it, a custom request describing something
 * already in the catalog - or a demand HR already promoted last week
 * under slightly different wording - silently spun up as a brand-new,
 * separate demand instead of pooling into what already exists, splitting
 * requester counts and creating duplicate sourcing work for HR instead of
 * the single combined view this whole pipeline exists to provide.
 * findOrCreateTrainingDemand() only ever does an exact title string
 * match, so it can't catch this on its own - this is the semantic check
 * that has to run before it.
 *
 * Returns ['title' => ..., 'source' => 'catalog'|'demand', 'score' =>
 * float] for the single best match at or above the threshold, or null if
 * nothing matches closely enough - including if the ML service is
 * unreachable, which fails open (no match found) rather than blocking
 * whatever called this, same as every other similarity check in this file.
 */
function findExistingTrainingMatch($con, $queryText) {
    if (trim($queryText) === '') {
        return null;
    }

    $candidates = [];
    $titleById = [];
    $sourceById = [];

    $catalogRows = $con->query("
        SELECT id, title FROM training_programs
        WHERE training_type IN ('Workshop', 'Seminar', 'Webinar', 'Conference')
    ")->fetch_all(MYSQLI_ASSOC);
    foreach ($catalogRows as $row) {
        $id = (int)$row['id'];
        $candidates[$id] = $row['title'];
        $titleById[$id] = $row['title'];
        $sourceById[$id] = 'catalog';
    }

    $demandRows = $con->query("SELECT id, title FROM training_demand")->fetch_all(MYSQLI_ASSOC);
    foreach ($demandRows as $row) {
        $id = (int)$row['id'] + EXISTING_MATCH_DEMAND_ID_OFFSET;
        $candidates[$id] = $row['title'];
        $titleById[$id] = $row['title'];
        $sourceById[$id] = 'demand';
    }

    if (empty($candidates)) {
        return null;
    }

    $results = callSimilarityCheck($queryText, $candidates);
    if ($results === null || empty($results)) {
        return null;
    }

    $best = null;
    foreach ($results as $r) {
        $id = (int)($r['id'] ?? -1);
        $score = (float)($r['score'] ?? 0);
        if ($score < EXISTING_MATCH_THRESHOLD || !isset($titleById[$id])) {
            continue;
        }
        if ($best === null || $score > $best['score']) {
            $best = ['title' => $titleById[$id], 'source' => $sourceById[$id], 'score' => $score];
        }
    }
    return $best;
}

/**
 * Runs the greedy single-link clustering loop over ONE already-scoped
 * list of requests (all from the same college/office bucket - see
 * clusterCustomTrainingRequests() below, which is the only caller).
 * Take the first request as a new cluster's seed, compare it against
 * every OTHER request still in this same bucket in one batched call,
 * pull in anything over the threshold, then move to the next
 * still-unassigned request and repeat. A request that matches nothing
 * becomes its own singleton cluster - still shown to HR, since even one
 * request might be worth acting on (e.g. a genuinely unique specialist
 * need).
 *
 * Returns ['clusters' => [...], 'failed' => bool]. On an ML failure
 * partway through this bucket, whatever is still unassigned at that
 * point is returned as singleton clusters (not silently dropped) and
 * 'failed' is set true, so the CALLER can decide whether to still show
 * these clusters with a "some groupings may be incomplete" note rather
 * than discarding a whole college's worth of real, already-computed
 * results just because one call hiccuped.
 */
function clusterRequestBucket($byId, $remaining) {
    $clusters = [];
    $failed = false;

    while (!empty($remaining)) {
        $seedId = array_shift($remaining);
        $seed = $byId[$seedId];
        $members = [$seed];

        if (!empty($remaining) && !$failed) {
            $candidates = [];
            foreach ($remaining as $id) {
                $candidates[$id] = $byId[$id]['request_text'];
            }
            $results = callSimilarityCheck($seed['request_text'], $candidates);
            if ($results === null) {
                // ML unreachable partway through this bucket - stop trying
                // to group anything further in it, but keep every request
                // seen so far (including this seed) as visible singleton
                // clusters rather than losing them.
                $failed = true;
            } else {
                $matchedIds = [];
                foreach ($results as $r) {
                    if ((float)($r['score'] ?? 0) >= CUSTOM_REQUEST_CLUSTER_THRESHOLD) {
                        $matchedIds[] = (int)$r['id'];
                    }
                }
                foreach ($matchedIds as $id) {
                    $members[] = $byId[$id];
                }
                $remaining = array_values(array_diff($remaining, $matchedIds));
            }
        }

        $clusters[] = [
            'label' => $seed['request_text'],
            'requests' => $members,
        ];
    }

    return ['clusters' => $clusters, 'failed' => $failed];
}

/**
 * Groups all still-'Unclustered' custom_training_requests by semantic
 * similarity, using the same pairwise SBERT /similarity_check endpoint
 * already proven for topical exclusion and TNA matching - no new ML-side
 * code needed.
 *
 * 2026-09-06 rework - the original version threw every pending request
 * company-wide into ONE pool and compared each seed against ALL of them,
 * which is both slow (a college with zero overlap in what it needs is
 * still N sequential AI calls in the worst case, since each call has to
 * finish before the next seed is picked) and pointless: a CCS employee's
 * request and a COE employee's request are, in practice, never the same
 * training - different colleges teach different things, so cross-college
 * matches among these free-text "specific training" requests essentially
 * don't happen (unlike training_demand, which genuinely IS meant to pool
 * cross-college once something's already a known catalog/demand title -
 * this function only ever deals with brand-new, uncategorized free text,
 * a different situation). Confirmed against this app's own existing data
 * model too: canonicalTnaCollegeCode() already treats every non-teaching
 * office as one shared 'ADMIN' bucket for the exact same reason (no
 * per-office distinction makes sense there either).
 *
 * Fix: group requests by college/office FIRST (a plain in-memory grouping
 * using data already fetched - zero extra queries, zero AI calls) and
 * only ever ask the AI to compare requests within the same bucket.
 * This shrinks both the size of each individual comparison AND the
 * worst-case number of sequential calls down to "how many pending
 * requests does THIS ONE college have right now" instead of "how many
 * pending requests exist across the entire university" - the more
 * colleges submit requests, the more this fix's advantage grows, since
 * total requests keeps splitting into more, smaller, independent buckets
 * rather than one ever-growing shared pool.
 *
 * Deliberately NOT persisted until HR actually promotes a cluster (see
 * promote_custom_request_cluster.php) - re-running this is cheap and
 * always reflects the current, real set of pending requests, rather than
 * needing a separate "clustering session" concept to keep in sync.
 *
 * Returns an array of clusters: each is
 *   ['label' => <representative request text, used as the demand title
 *                if promoted>, 'requests' => [ {id, user_id, name,
 *                department, request_text, created_at}, ... ],
 *    'bucket_incomplete' => bool]
 * or null only if EVERY bucket failed outright (fails open - HR still
 * sees the raw per-employee list either way, just not grouped). A
 * partial failure (some colleges' AI calls worked, one didn't) no longer
 * wipes out the colleges that succeeded - each cluster carries its own
 * 'bucket_incomplete' flag so the UI can flag just the affected ones.
 */
function clusterCustomTrainingRequests($con) {
    ensureCustomTrainingRequestsTable($con);
    $rows = $con->query("
        SELECT ctr.id, ctr.user_id, ctr.request_text, ctr.created_at, u.name, u.department
        FROM custom_training_requests ctr
        JOIN users u ON u.id = ctr.user_id
        WHERE ctr.status = 'Unclustered'
        ORDER BY ctr.created_at ASC
    ")->fetch_all(MYSQLI_ASSOC);

    if (empty($rows)) {
        return [];
    }

    $byId = [];
    $idsByBucket = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $byId[$id] = $r;
        // Same normalization already used everywhere else in this file -
        // full college names, short codes, and every non-teaching office
        // all fold into the same buckets the rest of the app already
        // treats as one unit. An unrecognized/blank department (bad or
        // missing data) gets its own catch-all bucket rather than being
        // dropped - still shown to HR, just not cross-checked against
        // anyone else's wording.
        $bucketKey = canonicalTnaCollegeCode($r['department']) ?? 'UNMAPPED';
        $idsByBucket[$bucketKey][] = $id;
    }

    $allClusters = [];
    $anyBucketSucceeded = false;

    foreach ($idsByBucket as $bucketKey => $bucketIds) {
        $result = clusterRequestBucket($byId, $bucketIds);
        if (!$result['failed']) {
            $anyBucketSucceeded = true;
        }
        foreach ($result['clusters'] as $cluster) {
            $cluster['bucket_incomplete'] = $result['failed'];
            // 2026-09-06 - so the UI can show which department/office pool
            // this cluster came from at a glance, instead of guessing from
            // one requester's raw department string (which, for the
            // shared non-teaching pool specifically, can legitimately
            // differ between members of the SAME cluster - e.g. one
            // Registrar's Office and one Library person pooled together).
            $cluster['bucket'] = $bucketKey;
            $allClusters[] = $cluster;
        }
    }

    // Only give up entirely if not a single bucket could reach the ML
    // service - a real, systemic outage, not just one hiccup.
    if (!$anyBucketSucceeded) {
        return null;
    }

    // Most-requested first - directly answers "which idea has the most
    // request" per the adviser's own framing of this feature.
    usort($allClusters, fn($a, $b) => count($b['requests']) <=> count($a['requests']));
    return $allClusters;
}
