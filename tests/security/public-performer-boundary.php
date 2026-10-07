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
class Peanut_Booker_Availability { public static function get_calendar_data(...$args) { ++$GLOBALS['related_reads']; return ['synthetic']; } }
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
verify(($api->get_performer_availability($request)['calendar'] ?? null) === ['synthetic'], 'published availability works');
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
