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
foreach (['=SUM(1,2)',' +cmd','@evil','-1',"\t=1","\rcmd"] as $value) { verify(str_starts_with(Report::csv_label($value), "'"), 'formula escaped'); }
verify(Report::csv_label('Normal group') === 'Normal group', 'normal label preserved');
verify(Report::group_label('Tom & Jerry x \\"q\\"') === 'Tom & Jerry x "q"', 'stored slashes removed from group label');
verify(Report::group_label('Caf&eacute; &amp; <b>Co</b>') === 'Café & Co', 'entities decoded and tags stripped');
verify(Report::is_partial('2026-11', $now) === true, 'current month is partial');
verify(Report::is_partial('2026-10', $now) === false, 'past month is complete');
$out = fopen('php://temp','w+');
Plugin::write_csv($out, ['month'=>'2026-11','partial'=>true,'summary'=>Report::protect(['contributors'=>4]),'groups'=>[['name'=>'=1+1','contributors'=>5,'contributions'=>8],['name'=>'overall','contributors'=>6,'contributions'=>9]],'truncated'=>true]);
rewind($out); $csv = stream_get_contents($out); fclose($out);
verify(str_starts_with($csv, "\xEF\xBB\xBF"), 'CSV starts with UTF-8 BOM');
$lines = array_map(fn($l) => str_getcsv($l, ',', '"', ''), explode("\n", trim(substr($csv, 3))));
verify($lines[0] === ['month_utc','partial_month','scope','group_name','contributors','public_updates','contributors_2plus_days'], 'CSV header');
verify($lines[1] === ['2026-11','yes','overall','','suppressed','suppressed','suppressed'], 'CSV preserves suppression');
verify($lines[2][2] === 'group' && $lines[2][3] === "'=1+1", 'CSV formula neutralised');
verify($lines[3][2] === 'group' && $lines[3][3] === 'overall', 'group named "overall" stays distinguishable');
verify($lines[4][2] === 'note', 'truncation note');
echo "$count unit checks passed\n";
