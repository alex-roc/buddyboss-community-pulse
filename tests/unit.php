<?php
// Pure-PHP checks: no WordPress, database or test framework required.
define('ABSPATH', __DIR__);
foreach (['report', 'snapshots', 'csv'] as $file) {
    require __DIR__ . "/../includes/class-{$file}.php";
}
use AlexROC\CommunityPulse\Csv;
use AlexROC\CommunityPulse\Report;
use AlexROC\CommunityPulse\Snapshots;

$count = 0;
function verify($truth, $message) { global $count; $count++; if (!$truth) { throw new RuntimeException($message); } }
function csv_lines(string $csv): array {
    return array_map(fn($l) => str_getcsv($l, ',', '"', ''), explode("\n", trim(substr($csv, 3))));
}
function capture(callable $writer): string {
    $out = fopen('php://temp', 'w+'); $writer($out); rewind($out); $csv = stream_get_contents($out); fclose($out); return $csv;
}

// Version header and constant agree.
$main = file_get_contents(__DIR__ . '/../community-pulse.php');
preg_match('/Version:\s*(\S+)/', $main, $h); preg_match("/COMMUNITY_PULSE_VERSION = '([^']+)'/", $main, $c);
verify($h[1] === $c[1], 'plugin header and COMMUNITY_PULSE_VERSION match');

// Month window.
$now = new DateTimeImmutable('2026-11-18', new DateTimeZone('UTC'));
verify(Report::window('2026-11', $now) === ['2026-11-01 00:00:00','2026-12-01 00:00:00'], 'month boundary');
verify(Report::window('2026-02', $now)[1] === '2026-03-01 00:00:00', 'February');
verify(Report::window('2025-12', $now)[0] === '2025-12-01 00:00:00', 'oldest valid month');
foreach (['2025-11','2026-12','2026-13','2026-1','2026-11-01',"2026-11' OR 1=1"] as $invalid) {
    try { Report::window($invalid, $now); throw new RuntimeException('accepted invalid input'); }
    catch (InvalidArgumentException $e) { verify(true, 'rejected invalid date'); }
}
$months = Report::selectable_months($now);
verify(count($months) === 12 && $months[0] === '2026-11' && $months[11] === '2025-12', 'selectable months, newest first');
verify(Report::is_partial('2026-11', $now) === true, 'current month is partial');
verify(Report::is_partial('2026-10', $now) === false, 'past month is complete');

// Threshold: never below 5.
verify(Report::threshold() === 5, 'default threshold');

// Suppression.
verify(Report::protect(['contributors'=>4,'contributions'=>999,'returning'=>3,'retained'=>2,'new'=>1], 10) === ['suppressed'=>true,'contributors'=>null,'contributions'=>null,'returning'=>null,'retained'=>null,'new'=>null], 'small cohort entirely hidden');
verify(Report::protect(['contributors'=>5,'contributions'=>8,'returning'=>2])['returning'] === null, 'small returning subset hidden');
verify(Report::safe_subcount(6,10) === null, 'small complement hidden');
verify(Report::safe_subcount(5,10) === 5, 'safe subset retained');
verify(Report::safe_subcount(10,10) === 10, 'whole cohort retained');
verify(Report::safe_subcount(11,10) === null, 'invalid subset hidden');
verify(Report::safe_subcount(7,20,10) === null && Report::safe_subcount(10,20,10) === 10, 'raised threshold applies');

// Retention needs both complements: this month and the month before.
$row = ['contributors'=>20,'contributions'=>50,'returning'=>10,'retained'=>8,'new'=>6];
verify(Report::protect($row, 30)['retained'] === 8, 'retained shown when both complements are large');
verify(Report::protect($row, 10)['retained'] === null, 'retained hidden: only 2 people stopped since last month');
verify(Report::protect($row, 4)['retained'] === null, 'retained hidden: previous month too small');
verify(Report::protect($row, null)['retained'] === null, 'retained hidden when previous month unknown');
verify(Report::protect($row, 30)['new'] === 6, 'first-time contributors shown');
verify(Report::protect(['contributors'=>20,'new'=>17], 30)['new'] === null, 'first-time hidden: only 3 returning people');
verify(Report::protect(['contributors'=>20,'new'=>20], 30)['new'] === 20, 'everyone new is not a small complement');

// Labels and CSV escaping.
foreach (['=SUM(1,2)',' +cmd','@evil','-1',"\t=1","\rcmd"] as $value) { verify(str_starts_with(Report::csv_label($value), "'"), 'formula escaped'); }
verify(Report::csv_label('Normal group') === 'Normal group', 'normal label preserved');
verify(Report::group_label('Tom & Jerry x \\"q\\"') === 'Tom & Jerry x "q"', 'stored slashes removed from group label');
verify(Report::group_label('Caf&eacute; &amp; <b>Co</b>') === 'Café & Co', 'entities decoded and tags stripped');

// Monthly CSV.
$report = ['month'=>'2026-11','partial'=>true,'summary'=>Report::protect(['contributors'=>4]),'groups'=>[['name'=>'=1+1','contributors'=>5,'contributions'=>8],['name'=>'overall','contributors'=>6,'contributions'=>9]],'truncated'=>true];
$csv = capture(fn($out) => Csv::monthly($out, $report));
verify(str_starts_with($csv, "\xEF\xBB\xBF"), 'CSV starts with UTF-8 BOM');
$lines = csv_lines($csv);
verify($lines[0] === ['month_utc','partial_month','scope','group_name','contributors','public_updates','contributors_2plus_days','retained_from_previous_month','first_time_contributors'], 'CSV header');
verify($lines[1] === ['2026-11','yes','overall','','suppressed','suppressed','suppressed','suppressed','suppressed'], 'CSV preserves suppression');
verify($lines[2][2] === 'group' && $lines[2][3] === "'=1+1", 'CSV formula neutralised');
verify($lines[3][2] === 'group' && $lines[3][3] === 'overall', 'group named "overall" stays distinguishable');
verify($lines[4][2] === 'note', 'truncation note');

// Trend CSV.
$trend = ['months'=>[['month'=>'2026-10','partial'=>false] + Report::protect($row, 30), ['month'=>'2026-11','partial'=>true] + Report::protect(['contributors'=>3])]];
$lines = csv_lines(capture(fn($out) => Csv::trend($out, $trend)));
verify($lines[1] === ['2026-10','no','20','50','10','8','6'], 'trend CSV row');
verify($lines[2] === ['2026-11','yes','suppressed','suppressed','suppressed','suppressed','suppressed'], 'trend CSV suppression');

// Public snapshots: rounding and freezing.
verify(Snapshots::round_base(7) === 5 && Snapshots::round_base(8) === 10 && Snapshots::round_base(12) === 10 && Snapshots::round_base(13) === 15, 'round to nearest 5');
verify(Snapshots::round_base(5) === 5 && Snapshots::round_base(null) === null, 'rounding keeps protected values ≥ 5 and hidden values hidden');
$full = ['month'=>'2026-10','threshold'=>5,'platform'=>'BuddyBoss Platform','summary'=>Report::protect($row, 30),'groups'=>[['name'=>'Circle','contributors'=>7,'contributions'=>23]],'truncated'=>false];
$snap = Snapshots::build($full, false, true, '2026-11-20 10:00:00');
verify($snap['summary']['contributors'] === 20 && $snap['summary']['contributions'] === 50 && $snap['summary']['retained'] === 10 && $snap['summary']['new'] === 5, 'snapshot rounds every figure');
verify($snap['groups'] === [] && $snap['groups_included'] === false, 'groups excluded unless requested');
$snap = Snapshots::build($full, true, false, '2026-11-20 10:00:00');
verify($snap['groups'][0] === ['name'=>'Circle','contributors'=>7,'contributions'=>23] && $snap['summary']['retained'] === 8, 'unrounded snapshot with groups');
$hidden = Snapshots::build(['month'=>'2026-09','summary'=>Report::protect(['contributors'=>2]),'groups'=>[['name'=>'X','contributors'=>5,'contributions'=>5]],'truncated'=>false], true, true, '2026-11-20 10:00:00');
verify($hidden['summary']['suppressed'] === true && $hidden['summary']['contributors'] === null && $hidden['groups'] === [], 'suppressed month publishes no figures or groups');
verify(!str_contains(json_encode($snap), 'user'), 'snapshot holds no user identifiers');

echo "$count unit checks passed\n";
