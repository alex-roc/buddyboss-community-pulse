<?php
/** Run against a disposable WordPress + BuddyBoss/BuddyPress database with wp eval-file. */
use AlexROC\CommunityPulse\Report;
use AlexROC\CommunityPulse\Plugin;
if (!function_exists('bp_activity_add') || !function_exists('groups_create_group')) { throw new RuntimeException('Activity and Groups required'); }
$GLOBALS['cp_checks'] = 0;
function cp_verify($truth, $message) { $GLOBALS['cp_checks']++; if (!$truth) { throw new RuntimeException($message); } }
add_filter('pre_wp_mail', '__return_false');
$users = []; $groups = []; $activities = [];
$stamp = bin2hex(random_bytes(4));
$month = gmdate('Y-m');
$date1 = gmdate('Y-m-01') . ' 12:00:00';
$date2 = gmdate('Y-m-02') . ' 12:00:00';
try {
    for ($i=0; $i<6; $i++) { $users[] = wp_insert_user(['user_login'=>"cp-fixture-$stamp-$i",'user_pass'=>wp_generate_password(),'user_email'=>"cp-$stamp-$i@example.test",'role'=>'subscriber']); }
    $public = groups_create_group(['creator_id'=>$users[0],'name'=>'=Fixture public "quoted" & co '.$stamp,'status'=>'public']);
    $small = groups_create_group(['creator_id'=>$users[0],'name'=>'Small fixture '.$stamp,'status'=>'public']);
    $private = groups_create_group(['creator_id'=>$users[0],'name'=>'Private fixture '.$stamp,'status'=>'private']);
    $groups = [$public,$small,$private];
    $add = function($user, $group = 0, $options = []) use (&$activities,$date1) {
        $id = bp_activity_add(array_merge(['user_id'=>$user,'component'=>$group?'groups':'activity','type'=>'activity_update','item_id'=>$group,'content'=>'Synthetic fixture text must never be exported','recorded_time'=>$date1,'hide_sitewide'=>false,'privacy'=>'public'], $options));
        if (!$id) { throw new RuntimeException('Could not seed activity'); }
        $activities[] = $id; return $id;
    };
    foreach ($users as $user) { $add($user,$public); $add($user,$public,['recorded_time'=>$date2]); $add($user,$private); $add($user,0,['hide_sitewide'=>true]); $add($user,0,['is_spam'=>true]); }
    for ($i=0; $i<4; $i++) { $add($users[$i],$small); }
    if (defined('BP_PLATFORM_VERSION')) {
        foreach ($users as $user) { $add($user,0,['privacy'=>'onlyme']); $add($user,0,['status'=>'scheduled']); }
    }
    // Real posting APIs (what the activity form calls), not just bp_activity_add().
    $api = groups_create_group(['creator_id'=>$users[0],'name'=>'API fixture '.$stamp,'status'=>'public']);
    $apiPrivate = groups_create_group(['creator_id'=>$users[0],'name'=>'API private fixture '.$stamp,'status'=>'private']);
    $groups[] = $api; $groups[] = $apiPrivate;
    foreach ($users as $user) {
        groups_join_group($api, $user); groups_join_group($apiPrivate, $user);
        wp_set_current_user($user);
        foreach ([groups_post_update(['content'=>'Synthetic fixture via API','group_id'=>$api,'user_id'=>$user]),
                  groups_post_update(['content'=>'Synthetic fixture via API','group_id'=>$apiPrivate,'user_id'=>$user]),
                  bp_activity_post_update(['content'=>'Synthetic fixture via API','user_id'=>$user])] as $id) {
            if (!$id) { throw new RuntimeException('API post failed'); }
            $activities[] = $id;
        }
    }
    wp_set_current_user(0);
    $before = gmdate('Y-m-d H:i:s', strtotime('first day of last month UTC'));
    foreach ($users as $user) { $add($user,$public,['recorded_time'=>$before]); }
    $report = Report::fetch($month);
    if (getenv('CP_DEBUG')) { echo json_encode($report)."\n"; }
    cp_verify($report['summary']['contributors'] === 6, 'unique contributors');
    // 6 users x (2 public-group + 1 API group + 1 API profile) + 4 posts in the small public group.
    cp_verify($report['summary']['contributions'] === 28, 'exclude spam, hidden, private groups, other months and private/scheduled posts');
    cp_verify($report['summary']['returning'] === 6, 'recurrence on 2 UTC days');
    cp_verify(count($report['groups']) === 2, 'omit small and private groups');
    cp_verify($report['groups'][0]['contributions'] === 12, 'public group totals');
    cp_verify($report['groups'][0]['contributors'] === 6, 'public group distinct users');
    cp_verify($report['groups'][0]['name'] === '=Fixture public "quoted" & co '.$stamp, 'group label shown as on the site');
    cp_verify($report['groups'][1]['contributions'] === 6, 'API group posts counted');
    cp_verify($report['partial'] === true, 'current month flagged partial');
    $out = fopen('php://temp','w+'); Plugin::write_csv($out,$report); rewind($out); $csv=stream_get_contents($out); fclose($out);
    cp_verify(!str_contains($csv,'Synthetic fixture'), 'no content in CSV');
    cp_verify(!str_contains($csv,'@example.test'), 'no emails in CSV');
    cp_verify(!str_contains($csv,'Private fixture') && !str_contains($csv,'private fixture'), 'no private group names in CSV');
    cp_verify(str_contains($csv,"'=Fixture"), 'formula-prefixed group escaped');
    $admin = get_users(['role'=>'administrator','number'=>1])[0];
    wp_set_current_user($admin->ID);
    $_GET['month']=$month;
    ob_start(); Plugin::page(); $html=ob_get_clean();
    cp_verify(str_contains($html,'Download aggregate CSV'), 'admin screen renders');
    cp_verify(str_contains($html,'_wpnonce='), 'export has nonce');
    cp_verify(!str_contains($html,'Synthetic fixture'), 'screen has no post content');
    cp_verify(!str_contains($html,'\\&quot;'), 'no stray backslashes in group names');
    // The public page callback denies a subscriber, even if called directly.
    wp_set_current_user($users[0]);
    add_filter('wp_die_handler', function() { return function($message,$title,$args) { throw new RuntimeException('denied:'.$args['response']); }; });
    try { Plugin::authorize(); throw new RuntimeException('subscriber allowed'); }
    catch (RuntimeException $e) { cp_verify($e->getMessage()==='denied:403','subscriber denied'); }
    $cap = fn() => 'read';
    add_filter('community_pulse_capability', $cap);
    Plugin::authorize(); cp_verify(true, 'capability filter grants a custom role');
    remove_filter('community_pulse_capability', $cap);
    echo $GLOBALS['cp_checks']." integration checks passed (WordPress " . get_bloginfo('version') . '; '.(defined('BP_PLATFORM_VERSION')?'BuddyBoss '.BP_PLATFORM_VERSION:'BuddyPress '.bp_get_version()).")\n";
} finally {
    wp_set_current_user(0);
    foreach ($activities as $id) { bp_activity_delete(['id'=>$id]); }
    foreach ($groups as $id) { groups_delete_group($id); }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($users as $id) { wp_delete_user($id); }
}
