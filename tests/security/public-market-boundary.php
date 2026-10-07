<?php
/** Offline callback regression: real REST/market/customer classes, synthetic WP/DB seams. */
declare(strict_types=1);
define('ABSPATH', '/synthetic/wordpress/');
define('WPINC', 'wp-includes');
class Peanut_Booker_Database { public static function count(...$args): int { return 0; } }
class Peanut_Booker_Encryption { public static function decrypt($value) { return $value; } }
class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; }
class WP_Error { public function __construct(public string $code, public string $message, public array $data) {} }
class WP_Query {
    public int $found_posts = 1;
    public int $max_num_pages = 1;
    private bool $read = false;
    public function __construct(public array $args) { $GLOBALS['query_args'] = $args; }
    public function have_posts(): bool { return !$this->read; }
    public function the_post(): void { $this->read = true; }
}
$wpdb = new class { public string $prefix = 'synthetic_'; public function prepare(...$args) { return 'synthetic query'; } public function get_var(...$args) { return null; } };
$routes = [];
function register_rest_route($namespace, $route, $config): void { $GLOBALS['routes'][$route] = $config; }
function get_post($id) { return $GLOBALS['fixture_missing'] ? null : (object) ['ID' => $id, 'post_type' => $GLOBALS['fixture_type'], 'post_status' => $GLOBALS['fixture_status'], 'post_password' => $GLOBALS['fixture_password'], 'post_title' => 'Synthetic event', 'post_content' => 'Synthetic private description', 'post_date' => '2026-10-06']; }
function get_userdata($id) { return (object) ['display_name' => 'Synthetic Customer', 'user_email' => 'test@example.com', 'user_registered' => '2026-01-01']; }
function get_post_meta($id, $key, $single) { return ['pb_customer_id' => 7, 'pb_total_bids' => 0, 'pb_event_date' => '2026-12-01', 'pb_event_status' => 'open', 'pb_bid_deadline' => ''][$key] ?? ''; }
function get_user_meta($id, $key, $single) { return ['pb_phone' => '555-0100', 'pb_address' => '123 Synthetic Test Street', 'pb_zip' => '00000'][$key] ?? ''; }
function get_avatar_url(...$args) { return 'https://example.com/avatar'; }
function absint($value) { return abs((int)$value); }
function wp_get_post_terms(...$args) { return []; }
function wp_trim_words($value, ...$args) { return $value; }
function get_permalink($id) { return 'https://example.com/event/' . $id; }
function date_i18n($format, $timestamp) { return date($format, $timestamp); }
function get_option($key) { return 'Y-m-d'; }
function __($value, ...$args) { return $value; }
function rest_ensure_response($value) { return $value; }
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
function get_the_ID() { return 123; }
function wp_reset_postdata(): void {}
require dirname(__DIR__, 2) . '/includes/class-customer.php';
require dirname(__DIR__, 2) . '/includes/class-market.php';
require dirname(__DIR__, 2) . '/includes/class-rest-api.php';
$api = (new ReflectionClass(Peanut_Booker_REST_API::class))->newInstanceWithoutConstructor();
$api->register_routes();
$failures = [];
$checks = 0;
function verify(bool $condition, string $message): void { ++$GLOBALS['checks']; if (!$condition) { $GLOBALS['failures'][] = $message; } }
verify($routes['/market/(?P<id>\d+)']['permission_callback'] === '__return_true', 'market remains public');
$fixture_type = 'pb_market_event'; $fixture_missing = false;
foreach ([['draft', ''], ['private', ''], ['trash', ''], ['publish', 'secret'], ['publish', '0']] as [$fixture_status, $fixture_password]) {
    $result = $api->get_market_event(['id' => 123]);
    verify($result instanceof WP_Error && $result->data['status'] === 404, 'private detail refused: ' . $fixture_status . '/' . $fixture_password);
}
$fixture_status = 'publish'; $fixture_password = '';
$result = $api->get_market_event(['id' => 123]);
verify(is_array($result) && $result['title'] === 'Synthetic event', 'public detail remains available');
$public_customer = ['display_name' => 'Synthetic Customer', 'avatar_url' => 'https://example.com/avatar'];
verify(($result['customer'] ?? null) === $public_customer, 'detail customer is an exact public allowlist');
$listing = $api->get_market_events(['page' => 1, 'per_page' => 12, 'category' => '', 'service_area' => '', 'search' => '']);
verify(($listing['events'][0]['customer'] ?? null) === $public_customer, 'collection customer is an exact public allowlist');
verify(($query_args['has_password'] ?? null) === false, 'public collection excludes password protected events at query time');
verify($listing['total'] === 1 && $listing['max_pages'] === 1, 'collection paging contract preserved');
$internal = Peanut_Booker_Market::get_event_data(123);
verify($internal['customer']['email'] === 'test@example.com', 'internal customer projection preserved');
Peanut_Booker_Market::query();
verify(!array_key_exists('has_password', $query_args), 'internal query behavior unchanged');
$fixture_type = 'post';
verify($api->get_market_event(['id' => 123]) instanceof WP_Error, 'wrong post type refused');
$fixture_missing = true;
verify($api->get_market_event(['id' => 123]) instanceof WP_Error, 'missing post refused');
foreach ($failures as $failure) { fwrite(STDERR, "FAIL: $failure\n"); }
echo $checks . ' checks, ' . count($failures) . " failures\n";
exit($failures ? 1 : 0);
