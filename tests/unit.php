<?php
// No external test framework required.
define('ABSPATH', __DIR__);
require __DIR__ . '/../includes/class-report.php';
require __DIR__ . '/../includes/class-plugin.php';
use AlexROC\CommunityPulse\Report;
use AlexROC\CommunityPulse\Plugin;
$count = 0;
function verify($truth, $message) { global $count; $count++; if (!$truth) { throw new RuntimeException($message); } }
$now = new DateTimeImmutable('2026-11-18', new DateTimeZone('UTC'));
verify(Report::window('2026-11', $now) === ['2026-11-01 00:00:00','2026-12-01 00:00:00'], 'month boundary');
verify(Report::window('2026-02', $now)[1] === '2026-03-01 00:00:00', 'February');
verify(Report::window('2025-12', $now)[0] === '2025-12-01 00:00:00', 'oldest valid month');
foreach (['2025-11','2026-12','2026-13','2026-1','2026-11-01',"2026-11' OR 1=1"] as $invalid) {
    try { Report::window($invalid, $now); throw new RuntimeException('accepted invalid input'); }
    catch (InvalidArgumentException $e) { verify(true, 'rejected invalid date'); }
}
verify(Report::protect(['contributors'=>4,'contributions'=>999,'returning'=>3]) === ['suppressed'=>true,'contributors'=>null,'contributions'=>null,'returning'=>null], 'small cohort entirely hidden');
verify(Report::protect(['contributors'=>5,'contributions'=>8,'returning'=>2])['returning'] === null, 'small returning subset hidden');
verify(Report::safe_subcount(6,10) === null, 'small complement hidden');
verify(Report::safe_subcount(5,10) === 5, 'safe subset retained');
verify(Report::safe_subcount(10,10) === 10, 'whole cohort retained');
verify(Report::safe_subcount(11,10) === null, 'invalid subset hidden');
foreach (['=SUM(1,2)',' +cmd','@evil','-1'] as $value) { verify(str_starts_with(Report::csv_label($value), "'"), 'formula escaped'); }
verify(Report::csv_label('Normal group') === 'Normal group', 'normal label preserved');
$out = fopen('php://temp','w+');
Plugin::write_csv($out, ['month'=>'2026-11','summary'=>Report::protect(['contributors'=>4]),'groups'=>[['name'=>'=1+1','contributors'=>5,'contributions'=>8]],'truncated'=>false]);
rewind($out); $csv = stream_get_contents($out); fclose($out);
verify(str_contains($csv, 'suppressed,suppressed,suppressed'), 'CSV preserves suppression');
verify(str_contains($csv, "'=1+1"), 'CSV formula neutralised');
echo "$count unit checks passed\n";
