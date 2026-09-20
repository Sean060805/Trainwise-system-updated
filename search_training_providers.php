<?php
/**
 * "Search for Providers" - a research-assist tool for whoever is trying to
 * source a real training for a demand (dean, or HR on the self-report
 * path). Built 2026-09-16 per a real suggestion from a CFND faculty
 * respondent during testing: recommendations are currently just topic
 * IDEAS ("Aquaculture Techniques"), not something bookable, and finding a
 * real provider is manual work the dean has to do after acceptance. This
 * doesn't remove that work - a real, current, bookable listing almost
 * never exists in structured form anywhere online (confirmed live: even
 * BFAR's own training pages just say "email us to ask") - but it gives
 * whoever's doing the sourcing a running start instead of a blank Google
 * search, and does it with real, live results, not an AI guess.
 *
 * Deliberately NOT an open web search. A live test of "BFAR training
 * seminar fisheries" (unrestricted) put a completely unrelated
 * breastfeeding-support site ("BFAR" = Breastfeeding After Reduction) at
 * position 1, with the real BFAR government site at position 2 - open
 * search is too noisy to trust for this. Every query here is restricted
 * (via Google's site: operator) to a curated list of real, legitimate
 * Philippine training-provider domains per college - verified live via
 * search, not guessed from memory, while building this feature.
 */
session_start();
require_once 'config.php';
require_once 'ml_recommendations.php';

header('Content-Type: application/json');

$role = $_SESSION['user_role'] ?? '';
$isDean = strpos($role, 'admin_') === 0;
$isHr = $role === 'admin';
if (!isset($_SESSION['user_id']) || (!$isDean && !$isHr)) {
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit();
}

if (!isset($_GET['demand_id']) || !is_numeric($_GET['demand_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid demand ID']);
    exit();
}

$demandId = intval($_GET['demand_id']);

$demandStmt = $con->prepare("SELECT title, found_training_title FROM training_demand WHERE id = ?");
$demandStmt->bind_param("i", $demandId);
$demandStmt->execute();
$demandRow = $demandStmt->get_result()->fetch_assoc();
$demandStmt->close();
if (!$demandRow) {
    echo json_encode(['success' => false, 'message' => 'Demand not found']);
    exit();
}
$searchTopic = !empty($demandRow['found_training_title']) ? $demandRow['found_training_title'] : $demandRow['title'];

// Same candidate-department resolution as report_training_demand.php /
// reject_training_demand.php - see those files for the full reasoning on
// why canonicalTnaCollegeCode() is used instead of a raw string match.
$candStmt = $con->prepare("
    SELECT DISTINCT u.department
    FROM training_demand d
    JOIN training_recommendations tr ON tr.demand_id = d.id
    JOIN users u ON u.id = tr.user_id
    WHERE d.id = ? AND tr.status IN ('Accepted', 'Training Available', 'Confirmed', 'Completed')
");
$candStmt->bind_param("i", $demandId);
$candStmt->execute();
$candidateDepartments = array_column($candStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'department');
$candStmt->close();
$collegeCodes = array_values(array_unique(array_filter(array_map('canonicalTnaCollegeCode', $candidateDepartments))));

if ($isDean) {
    // A dean only ever searches within their OWN college's scope - even
    // if this demand also has requesters from other colleges, sourcing it
    // is this dean's job for their own people, not a cross-college search.
    $deptCode = strtoupper(substr($role, strlen('admin_')));
    if (!in_array($deptCode, $collegeCodes, true)) {
        echo json_encode(['success' => false, 'message' => 'Demand not found for your department']);
        exit();
    }
    $collegeCodes = [$deptCode];
}
// HR (the self-report path, or just researching generally) searches
// across every candidate college's provider list.

/**
 * Curated, real training-provider domains per LSPU college - verified
 * live via search while building this feature (2026-09-16), not guessed
 * from memory (a guess would risk stale/wrong domains, exactly the kind
 * of mistake this whole feature exists to avoid making for employees).
 * Revisit periodically - agencies restructure and domains change.
 */
const TRAINING_PROVIDER_DOMAINS = [
    'CA'    => ['ati.da.gov.ph'],                              // Agricultural Training Institute (DA)
    'CAS'   => ['sei.dost.gov.ph', 'ched.gov.ph'],             // DOST Science Education Institute, CHED
    'CBAA'  => ['picpa.com.ph', 'cpdas.prc.gov.ph'],           // PICPA, PRC CPD Accreditation System
    'CCS'   => ['tesda.gov.ph', 'dict.gov.ph'],                // TESDA, DICT
    'CCJE'  => ['ppsc.gov.ph'],                                // Philippine Public Safety College
    'COE'   => ['pice.org.ph', 'cpdas.prc.gov.ph'],            // PICE, PRC CPD
    'CIT'   => ['tesda.gov.ph'],                               // TESDA
    'CFND'  => ['nnc.gov.ph'],                                 // National Nutrition Council
    'COF'   => ['bfar.da.gov.ph'],                             // BFAR
    'CHMT'  => ['tourism.gov.ph'],                             // Department of Tourism
    'CTE'   => ['deped.gov.ph', 'training.deped.gov.ph'],      // DepEd / NEAP
    'CONAH' => ['cpdas.prc.gov.ph', 'doh.gov.ph'],             // PRC CPD, DOH
    'COL'   => ['mcle.judiciary.gov.ph', 'ibp.ph'],            // MCLE, IBP
];

$domains = [];
foreach ($collegeCodes as $code) {
    if (isset(TRAINING_PROVIDER_DOMAINS[$code])) {
        $domains = array_merge($domains, TRAINING_PROVIDER_DOMAINS[$code]);
    }
}
$domains = array_values(array_unique($domains));

if (empty($domains)) {
    // ADMIN (non-teaching) has no single natural provider, and a college
    // code might not be in the curated map yet - fail into an honest
    // "nothing curated yet" response rather than silently falling back to
    // an open, noisy search (see this file's header comment for why open
    // search isn't trustworthy here).
    echo json_encode(['success' => false, 'message' => 'No curated training providers are set up yet for this office/college. Try a general web search instead.']);
    exit();
}

if (empty(SERPAPI_KEY)) {
    echo json_encode(['success' => false, 'message' => 'Search is not configured on this server.']);
    exit();
}

$siteFilter = '(' . implode(' OR ', array_map(fn($d) => 'site:' . $d, $domains)) . ')';
$query = $searchTopic . ' training seminar ' . $siteFilter;

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://serpapi.com/search.json?' . http_build_query([
        'engine' => 'google',
        'q' => $query,
        'api_key' => SERPAPI_KEY,
        'num' => 8,
        'gl' => 'ph',
        'hl' => 'en',
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 12,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false || $curlError) {
    error_log("search_training_providers.php: cURL error - $curlError");
    echo json_encode(['success' => false, 'message' => 'Could not reach the search service. Please try again.']);
    exit();
}

$data = json_decode($response, true);
if ($httpCode !== 200 || !$data || isset($data['error'])) {
    error_log("search_training_providers.php: SerpApi error - " . ($data['error'] ?? "HTTP $httpCode"));
    echo json_encode(['success' => false, 'message' => 'Search failed. Please try again later.']);
    exit();
}

$results = [];
foreach (($data['organic_results'] ?? []) as $item) {
    $results[] = [
        'title' => $item['title'] ?? '',
        'link' => $item['link'] ?? '',
        'snippet' => $item['snippet'] ?? '',
        'displayed_link' => $item['displayed_link'] ?? '',
    ];
}

echo json_encode([
    'success' => true,
    'query_topic' => $searchTopic,
    'colleges_searched' => $collegeCodes,
    'domains_searched' => $domains,
    'results' => $results,
]);
