<?php
/** Actual REST/performer classes with synthetic WordPress and storage seams. */
declare(strict_types=1);
define('ABSPATH', '/synthetic/wordpress/');
define('WPINC', 'wp-includes');
class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; }
class WP_Error { public function __construct(public string $code, public string $message, public array $data) {} }
class SyntheticRequest extends ArrayObject { public function get_param($name) { return $this[$name] ?? null; } }
class Peanut_Booker_Database {
    public static function get_row($table, $where) { return $GLOBALS['missing_performer'] ? null : (object) array_merge(array_fill_keys(['deposit_percentage', 'achievement_score', 'completed_bookings', 'average_rating', 'total_reviews', 'profile_completeness', 'is_verified', 'is_featured'], 0), ['id' => 7, 'profile_id' => 123, 'user_id' => 9, 'tier' => 'free', 'achievement_level' => 'bronze']); }
}
class Peanut_Booker_Roles { public static function get_photo_limit($id) { return 1; } public static function get_video_limit($id) { return 0; } }
// The REAL availability class runs; only its storage ($wpdb) is synthetic. The
// rows carry the private detail a performer keeps on their own calendar.
$wpdb = new class {
    public string $prefix = 'synthetic_';
    public function prepare(...$args) { return 'synthetic query'; }
    public function get_results(...$args) {
        ++$GLOBALS['related_reads'];
        return [
            (object) ['id' => 501, 'date' => '2026-10-20', 'slot_type' => 'full_day', 'start_time' => '19:00:00', 'end_time' => '23:00:00', 'status' => 'booked', 'booking_id' => 9001, 'block_type' => 'booking', 'event_name' => 'PRIVATE-EVENT-NAME', 'venue_name' => 'PRIVATE-VENUE', 'event_type' => 'PRIVATE-TYPE', 'event_location' => 'PRIVATE-LOCATION', 'notes' => 'PRIVATE-NOTES'],
            (object) ['id' => 502, 'date' => '2026-10-21', 'slot_type' => 'full_day', 'start_time' => null, 'end_time' => null, 'status' => 'blocked', 'booking_id' => null, 'block_type' => 'external_gig', 'event_name' => 'PRIVATE-GIG', 'venue_name' => 'PRIVATE-GIG-VENUE', 'event_type' => '', 'event_location' => 'PRIVATE-GIG-LOCATION', 'notes' => 'PRIVATE-GIG-NOTES'],
        ];
    }
};
$current_user = 0; $current_caps = [];
function get_current_user_id() { return $GLOBALS['current_user']; }
function current_user_can($cap) { return in_array($cap, $GLOBALS['current_caps'], true); }
class Peanut_Booker_Reviews { public static function get_performer_reviews(...$args) { ++$GLOBALS['related_reads']; return ['synthetic']; } }
class WP_Query {
    public int $found_posts = 1;
    public int $max_num_pages = 1;
    private bool $read = false;
    public function __construct(public array $args) { $GLOBALS['query_args'] = $args; }
    public function have_posts(): bool { return !$this->read; }
    public function the_post(): void { $this->read = true; }
}
function register_rest_route($namespace, $route, $config): void { $GLOBALS['routes'][$route] = $config; }
function get_post($id) { return $GLOBALS['missing_post'] ? null : (object) ['ID' => $id, 'post_type' => $GLOBALS['post_type'], 'post_status' => $GLOBALS['status'], 'post_password' => $GLOBALS['password'], 'post_title' => 'Synthetic performer', 'post_content' => 'Private biography', 'post_excerpt' => '']; }
function get_userdata($id) { return (object) ['display_name' => 'Synthetic Performer']; }
function get_post_meta(...$args) { return ''; }
function get_the_post_thumbnail_url(...$args) { return false; }
function wp_get_post_terms(...$args) { return []; }
function wp_trim_words($value, ...$args) { return $value; }
function get_permalink($id) { return 'https://example.com/performer/' . $id; }
function __($value, ...$args) { return $value; }
function rest_ensure_response($value) { return $value; }
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
function get_the_ID() { return 123; }
function wp_reset_postdata(): void {}
require dirname(__DIR__, 2) . '/includes/class-performer.php';
require dirname(__DIR__, 2) . '/includes/class-availability.php';
require dirname(__DIR__, 2) . '/includes/class-rest-api.php';
$api = (new ReflectionClass(Peanut_Booker_REST_API::class))->newInstanceWithoutConstructor();
$api->register_routes();
$failures = []; $checks = 0;
function verify(bool $condition, string $message): void { ++$GLOBALS['checks']; if (!$condition) { $GLOBALS['failures'][] = $message; } }
$request = new SyntheticRequest(['id' => 7, 'month' => '2026-10', 'page' => 1, 'per_page' => 12, 'category' => '', 'service_area' => '', 'search' => '']);
$missing_performer = false; $missing_post = false; $post_type = 'pb_performer';
foreach (['', '/availability', '/reviews'] as $suffix) {
    verify($routes['/performers/(?P<id>\d+)' . $suffix]['permission_callback'] === '__return_true', 'route stays public: ' . $suffix);
}
foreach ([['draft', ''], ['pending', ''], ['private', ''], ['trash', ''], ['publish', 'secret'], ['publish', '0']] as [$status, $password]) {
    $related_reads = 0;
    foreach (['get_performer', 'get_performer_availability', 'get_performer_reviews'] as $callback) {
        $result = $api->$callback($request);
        verify($result instanceof WP_Error && $result->data['status'] === 404, "$callback refuses $status/$password");
    }
    verify($related_reads === 0, 'protected profile has no related reads');
}
$status = 'publish'; $password = ''; $related_reads = 0;
verify(($api->get_performer($request)['bio'] ?? null) === 'Private biography', 'published detail works');
$availability = $api->get_performer_availability($request);
$calendar = $availability['calendar'] ?? [];
verify(count($calendar) === 31, 'published availability returns every day of the month');
verify(($calendar['2026-10-20']['status'] ?? null) === 'booked', 'public calendar keeps the booked status');
verify(($calendar['2026-10-21']['color'] ?? null) === '#9333ea', 'public calendar keeps the status color');
$public_keys = [];
foreach ($calendar as $day) { $public_keys = array_unique(array_merge($public_keys, array_keys($day))); }
sort($public_keys);
verify($public_keys === ['color', 'date', 'status'], 'anonymous availability exposes only date/status/color, got: ' . implode(',', $public_keys));
verify(!str_contains(json_encode($availability), 'PRIVATE'), 'anonymous availability leaks no event/venue/location/notes');
verify(!str_contains(json_encode($availability), '9001'), 'anonymous availability leaks no booking_id');
foreach ([[55, []], [55, ['read']]] as [$user, $caps]) {
    $current_user = $user; $current_caps = $caps;
    verify(!str_contains(json_encode($api->get_performer_availability($request)), 'PRIVATE'), 'another logged-in user sees only public availability');
}
foreach ([[9, [], 'the performer themselves'], [1, ['pb_manage_performers'], 'a performer manager'], [1, ['manage_options'], 'a site administrator']] as [$user, $caps, $who]) {
    $current_user = $user; $current_caps = $caps;
    $detail = $api->get_performer_availability($request);
    verify(($detail['calendar']['2026-10-20']['slots'][0]['event_name'] ?? null) === 'PRIVATE-EVENT-NAME', "$who still sees full availability detail");
}
$current_user = 0; $current_caps = [];
$bad_month = new SyntheticRequest(['id' => 7, 'month' => '2026-10-01 +1 year']);
verify(($api->get_performer_availability($bad_month)['month'] ?? '') !== '2026-10-01 +1 year', 'unparseable month is not echoed back or used');
verify(($api->get_performer_reviews($request)['reviews'] ?? null) === ['synthetic'], 'published reviews work');
$listing = $api->get_performers($request);
verify(($query_args['has_password'] ?? null) === false, 'catalog filters passwords before pagination');
verify($listing['total'] === 1 && $listing['max_pages'] === 1, 'pagination retained');
Peanut_Booker_Performer::query();
verify(!array_key_exists('has_password', $query_args), 'internal query unchanged');
foreach (['missing_performer', 'missing_post', 'wrong_type'] as $case) {
    $missing_performer = $case === 'missing_performer'; $missing_post = $case === 'missing_post'; $post_type = $case === 'wrong_type' ? 'post' : 'pb_performer';
    foreach (['get_performer', 'get_performer_availability', 'get_performer_reviews'] as $callback) {
        verify($api->$callback($request) instanceof WP_Error, "$callback refuses $case");
    }
}
foreach ($failures as $failure) { fwrite(STDERR, "FAIL: $failure\n"); }
echo $checks . ' checks, ' . count($failures) . " failures\n";
exit($failures ? 1 : 0);
