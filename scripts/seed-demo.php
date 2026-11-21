<?php
/**
 * Synthetic demo community for screenshots and manual testing (dev environment only):
 * 12 months of growing public activity with churn and newcomers, four public groups
 * and one private group. Deterministic (fixed seed). Run: scripts/dev-env.sh demo
 * Never run on a real site: it inserts rows directly into the activity table.
 */
global $wpdb;
if (!function_exists('buddypress')) { throw new RuntimeException('BuddyBoss or BuddyPress required'); }
mt_srand(42);
$bp = buddypress(); $A = $bp->activity->table_name; $G = $bp->groups->table_name;
if ($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$G}` WHERE slug = %s", 'demo-0'))) {
    echo "Demo data already present\n"; return;
}
$groups = [];
foreach ([['Newcomers & Introductions', 'public'], ['Data Literacy Circle', 'public'], ['Open Government Lab', 'public'],
          ['Volunteer Coordinators', 'public'], ['Board (private)', 'private']] as $i => [$name, $status]) {
    $wpdb->insert($G, ['creator_id' => 1, 'name' => $name, 'slug' => 'demo-' . $i, 'description' => '', 'status' => $status, 'date_created' => gmdate('Y-m-d H:i:s')]);
    $groups[] = [$wpdb->insert_id, $status];
}
$active = []; $next = 5000; $rows = 0;
$base = new DateTimeImmutable('first day of this month', new DateTimeZone('UTC'));
for ($m = 11; $m >= 0; $m--) {
    $start = $base->modify("-$m months");
    $days = $m === 0 ? (int) gmdate('j') : (int) $start->format('t');
    $target = (int) round(70 + (11 - $m) * 11 + mt_rand(-8, 8));
    $active = array_values(array_filter($active, fn() => mt_rand(1, 100) <= 62));
    while (count($active) < $target) { $active[] = $next++; }
    foreach ($active as $user) {
        $posts = mt_rand(1, 100) <= 45 ? 1 : mt_rand(2, 7);
        for ($p = 0; $p < $posts; $p++) {
            $r = mt_rand(1, 100);
            [$gid, $status] = $r <= 30 ? [0, ''] : $groups[min(4, intdiv($r - 31, 15))];
            $when = $start->modify('+' . mt_rand(0, $days - 1) . ' days')->setTime(mt_rand(0, 23), mt_rand(0, 59))->format('Y-m-d H:i:s');
            $wpdb->insert($A, ['user_id' => $user, 'component' => $gid ? 'groups' : 'activity', 'type' => 'activity_update', 'action' => '',
                'content' => 'demo', 'primary_link' => '', 'item_id' => $gid, 'secondary_item_id' => 0, 'date_recorded' => $when,
                'date_updated' => $when, 'hide_sitewide' => $status === 'private' ? 1 : 0, 'is_spam' => 0]
                + (defined('BP_PLATFORM_VERSION') ? ['privacy' => 'public', 'status' => 'published'] : []));
            $rows++;
        }
    }
}
// Publish the last four finished months and a page that shows them.
$months = array_slice(AlexROC\CommunityPulse\Report::selectable_months(), 1, 4);
foreach (array_reverse($months) as $i => $month) {
    AlexROC\CommunityPulse\Snapshots::publish($month, $i === 3, true);
}
wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Community report', 'post_name' => 'community-report',
    'post_content' => '<!-- wp:paragraph --><p>Every month we publish how many people take part in the public spaces of our community. Figures are aggregated and protected: nothing here identifies anyone.</p><!-- /wp:paragraph --><!-- wp:shortcode -->[community_pulse]<!-- /wp:shortcode --><!-- wp:shortcode -->[community_pulse view="history"]<!-- /wp:shortcode -->']);
echo "$rows demo updates by " . ($next - 5000) . " people; published " . implode(', ', $months) . "; page /community-report/\n";
