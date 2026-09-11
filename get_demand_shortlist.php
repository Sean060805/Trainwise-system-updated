<?php
session_start();
require_once 'config.php';
require_once 'ml_recommendations.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit();
}

if (!isset($_GET['demand_id']) || !is_numeric($_GET['demand_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid demand ID']);
    exit();
}

$demandId = intval($_GET['demand_id']);

/*
 * Proxy ranking criteria only - see memory/plan note on real HR
 * training-history data being requested separately. Two named, weighted
 * signals, summed into one score:
 *   + tenure_years   - longer-serving staff weighted higher.
 *   - prior_confirmed_count * 3 - people already confirmed for past
 *     trainings are deprioritized, so opportunities spread across more
 *     people rather than repeatedly going to the same requesters
 *     ("hindi biased" - the user's own fairness requirement).
 * Add a real-HR-data term here later as one more named subexpression,
 * once that data clears HR's approval process - no strategy-pattern
 * abstraction needed for a single swappable term.
 */
$demandStmt = $con->prepare("SELECT title, description, found_capacity FROM training_demand WHERE id = ?");
$demandStmt->bind_param("i", $demandId);
$demandStmt->execute();
$demand = $demandStmt->get_result()->fetch_assoc();
$demandStmt->close();

if (!$demand) {
    echo json_encode(['success' => false, 'message' => 'Demand not found']);
    exit();
}
$queryText = trim($demand['title'] . ' ' . ($demand['description'] ?? ''));

$stmt = $con->prepare("
    SELECT
        tr.id AS recommendation_id,
        u.id AS user_id,
        u.name,
        u.department,
        u.designation,
        COALESCE(CAST(NULLIF(u.yearsInLSPU, '') AS UNSIGNED), 0) AS tenure_years,
        (SELECT COUNT(*) FROM training_recommendations tr2
         WHERE tr2.user_id = u.id AND tr2.status IN ('Confirmed', 'Completed')) AS prior_confirmed_count,
        (
            COALESCE(CAST(NULLIF(u.yearsInLSPU, '') AS UNSIGNED), 0) * 1.0
            - (SELECT COUNT(*) FROM training_recommendations tr2
               WHERE tr2.user_id = u.id AND tr2.status IN ('Confirmed', 'Completed')) * 3.0
        ) AS score
    FROM training_recommendations tr
    JOIN users u ON u.id = tr.user_id
    WHERE tr.demand_id = ? AND tr.status = 'Training Available'
    ORDER BY score DESC, tenure_years DESC
");
$stmt->bind_param("i", $demandId);
$stmt->execute();
$result = $stmt->get_result();
$candidates = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Hard-exclude anyone who's already completed something topically similar
// to this demand - a stronger signal than the prior_confirmed_count
// penalty above, per the 2026-08-26 requirement that this be an actual
// exclusion, not just a lower score. Gathers ALL candidates' prior
// completed trainings into one batched call to the ML service.
$priorTrainingTextsById = [];
$priorTrainingToUserId = [];
$priorTrainingTitleById = [];
if (!empty($candidates)) {
    $userIds = array_column($candidates, 'user_id');
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $types = str_repeat('i', count($userIds));
    $priorStmt = $con->prepare("
        SELECT id, user_id, title, description
        FROM training_recommendations
        WHERE user_id IN ($placeholders) AND status IN ('Confirmed', 'Completed')
    ");
    $priorStmt->bind_param($types, ...$userIds);
    $priorStmt->execute();
    $priorRows = $priorStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $priorStmt->close();

    foreach ($priorRows as $p) {
        $priorTrainingTextsById[$p['id']] = trim($p['title'] . ' ' . ($p['description'] ?? ''));
        $priorTrainingToUserId[$p['id']] = (int)$p['user_id'];
        $priorTrainingTitleById[$p['id']] = $p['title'];
    }
}

$exclusions = getTopicalExclusions($queryText, $priorTrainingTextsById);

// Map hard-excluded AND soft-flagged users to WHY (which prior training
// triggered it), so HR sees a reason instead of someone silently
// disappearing or being unexplainedly flagged. Someone can have multiple
// prior trainings above a threshold - keep the highest-scoring (most
// similar) one as the shown reason, per tier.
function reduceToReasonsByUser(array $scores, array $priorTrainingToUserId, array $priorTrainingTitleById) {
    $reasons = [];
    foreach ($scores as $priorRecId => $score) {
        $userId = $priorTrainingToUserId[$priorRecId] ?? null;
        if ($userId === null) {
            continue;
        }
        if (!isset($reasons[$userId]) || $score > $reasons[$userId]['score']) {
            $reasons[$userId] = [
                'similar_to' => $priorTrainingTitleById[$priorRecId],
                'score' => round($score, 2),
            ];
        }
    }
    return $reasons;
}
$excludedUserReasons = reduceToReasonsByUser($exclusions['hard'], $priorTrainingToUserId, $priorTrainingTitleById);
$flaggedUserReasons = reduceToReasonsByUser($exclusions['soft'], $priorTrainingToUserId, $priorTrainingTitleById);

// Boost candidates whose college officially requested something similar
// to this demand in the 2025 TNA - real institutional demand, layered on
// top of the proxy tenure/fairness score above. Fails open (no bonus) if
// the ML service is unreachable or the college submitted nothing.
$tnaCollegeByUserId = [];
foreach ($candidates as $row) {
    $tnaCollegeByUserId[$row['user_id']] = canonicalTnaCollegeCode($row['department']);
}
$tnaBoosts = getTna2025Boosts($con, $queryText, array_values($tnaCollegeByUserId));

$shortlist = [];
$excluded = [];
foreach ($candidates as $row) {
    $entry = [
        'recommendation_id' => (int)$row['recommendation_id'],
        'name' => htmlspecialchars($row['name']),
        'department' => htmlspecialchars($row['department'] ?? 'N/A'),
        'designation' => htmlspecialchars($row['designation'] ?? 'N/A'),
        'tenure_years' => (int)$row['tenure_years'],
        'prior_confirmed_count' => (int)$row['prior_confirmed_count'],
        'score' => round((float)$row['score'], 1),
    ];

    $collegeCode = $tnaCollegeByUserId[$row['user_id']] ?? null;
    $tnaMatch = ($collegeCode !== null) ? ($tnaBoosts[$collegeCode] ?? null) : null;
    if ($tnaMatch !== null) {
        $bonus = ($tnaMatch['source'] === 'official') ? TNA_MATCH_SCORE_BONUS : SELF_REPORTED_MATCH_SCORE_BONUS;
        $entry['score'] = round((float)$row['score'] + $bonus, 1);
        $entry['tna_requested'] = true;
        $entry['tna_source'] = $tnaMatch['source']; // 'official' | 'self_reported'
    } else {
        $entry['tna_requested'] = false;
    }

    if (isset($excludedUserReasons[$row['user_id']])) {
        $entry['excluded_reason'] = 'Already completed "' . htmlspecialchars($excludedUserReasons[$row['user_id']]['similar_to']) . '" (a near-duplicate training)';
        $excluded[] = $entry;
    } else {
        // Soft flag: worth showing to HR, but the candidate stays
        // selectable - the AI is decision support, not the decision.
        if (isset($flaggedUserReasons[$row['user_id']])) {
            $entry['related_flag'] = 'Already completed "' . htmlspecialchars($flaggedUserReasons[$row['user_id']]['similar_to']) . '" (a related training)';
        }
        $shortlist[] = $entry;
    }
}

// Re-sort by the TNA-adjusted score - the SQL-level ORDER BY no longer
// reflects it now that the bonus is applied in PHP.
usort($shortlist, fn($a, $b) => $b['score'] <=> $a['score']);

echo json_encode([
    'success' => true,
    'shortlist' => $shortlist,
    'excluded' => $excluded,
    'capacity' => $demand['found_capacity'],
    // Distinguishes "no one requested this at all" (candidates empty from
    // the start) from "everyone who requested it got hard-excluded" (the
    // dead-end case the Close action below is for) - the frontend can't
    // tell those apart from shortlist/excluded counts alone once both are
    // empty for the first case.
    'total_requesters' => count($candidates),
]);
