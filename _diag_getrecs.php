<?php
chdir('c:/New folder/htdocs/trainwise');
$_SERVER['SERVER_NAME'] = 'localhost';
require 'config.php';
require 'ml_recommendations.php';

$recs = getTrainingRecommendations($con, 134);
foreach ($recs as $r) {
    if ($r['id'] == 72) {
        echo "id=72 raw array:\n";
        var_dump($r['title']);
        var_dump($r['description']);
        var_dump($r['reason']);
        echo "\nFull key list: " . implode(', ', array_keys($r)) . "\n";
    }
}
