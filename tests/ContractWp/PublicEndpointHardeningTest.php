<?php
/**
 * Public REST endpoints on a real WordPress with the real plugin schema:
 * performer availability, featured performers and microsite view tracking.
 */
namespace Peanut_Booker\Tests\ContractWp;

use Peanut_Booker_Activator;
use Peanut_Booker_Database;
use Peanut_Booker_Post_Types;
use Peanut_Booker_Rate_Limiter;
use Peanut_Booker_REST_API;
use WP_REST_Request;
use WP_UnitTestCase;

final class PublicEndpointHardeningTest extends WP_UnitTestCase {
    public static function wpSetUpBeforeClass($factory): void {
        $base = dirname(__DIR__, 2) . '/includes/';
        foreach (['activator', 'database', 'encryption', 'customer', 'market', 'roles', 'performer', 'availability', 'reviews', 'rate-limiter', 'post-types', 'rest-api'] as $name) {
            require_once $base . 'class-' . $name . '.php';
        }
        $schema = new \ReflectionMethod(Peanut_Booker_Activator::class, 'create_tables');
        $schema->invoke(null);
    }

    public function set_up(): void {
        parent::set_up();
        wp_set_current_user(0);
        foreach (['public', 'general', 'tracking'] as $bucket) {
            Peanut_Booker_Rate_Limiter::reset($bucket);
        }
        $types = new Peanut_Booker_Post_Types();
        $types->register_post_types();
        $types->register_taxonomies();
        new Peanut_Booker_REST_API();
        global $wp_rest_server;
        $wp_rest_server = null;
        do_action('rest_api_init');
    }

    private function get(string $path, array $params = []) {
        $request = new WP_REST_Request('GET', '/peanut-booker/v1/' . $path);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        return rest_get_server()->dispatch($request);
    }

    private function track(string $slug, string $event = 'page_view') {
        // The per-hour analytics table (pb_microsite_analytics) is referenced
        // by the handler but never created by the plugin's schema; that is a
        // separate, pre-existing defect. Keep its DB errors out of this output.
        global $wpdb;
        $wpdb->suppress_errors(true);
        $request = new WP_REST_Request('POST', '/peanut-booker/v1/microsites/' . $slug . '/track');
        $request->set_header('content-type', 'application/json');
        $request->set_body(wp_json_encode(['event' => $event, 'referrer' => 'https://referrer.example/page']));
        return rest_get_server()->dispatch($request);
    }

    /** @return array{0:int,1:int,2:int} performer id, profile id, user id */
    private function performer(string $status = 'publish', string $password = ''): array {
        $user = self::factory()->user->create();
        $post = self::factory()->post->create(['post_type' => 'pb_performer', 'post_status' => $status, 'post_password' => $password, 'post_title' => 'Synthetic performer']);
        $id = Peanut_Booker_Database::insert('performers', ['user_id' => $user, 'profile_id' => $post, 'status' => 'active']);
        $this->assertNotFalse($id);
        return [(int) $id, (int) $post, (int) $user];
    }

    private function private_slot(int $performer_id, string $date): void {
        $this->assertNotFalse(Peanut_Booker_Database::insert('availability', [
            'performer_id' => $performer_id, 'date' => $date, 'slot_type' => 'full_day', 'status' => 'booked',
            'booking_id' => 424242, 'block_type' => 'external_gig', 'event_name' => 'PRIVATE-EVENT', 'venue_name' => 'PRIVATE-VENUE',
            'event_location' => 'PRIVATE-LOCATION', 'notes' => 'PRIVATE-NOTES',
        ]));
    }

    public function test_anonymous_availability_shows_only_date_status_and_color(): void {
        [$id] = $this->performer();
        $month = gmdate('Y-m', strtotime('+1 month'));
        $this->private_slot($id, $month . '-15');

        $response = $this->get('performers/' . $id . '/availability', ['month' => $month]);
        $this->assertSame(200, $response->get_status());
        $calendar = $response->get_data()['calendar'];
        $day = $calendar[$month . '-15'];
        $this->assertSame(['color', 'date', 'status'], $this->sortedKeys($day));
        $this->assertSame('booked', $day['status']);
        foreach ($calendar as $date => $entry) {
            $this->assertSame(['color', 'date', 'status'], $this->sortedKeys($entry), $date);
        }
        $json = wp_json_encode($response->get_data());
        $this->assertStringNotContainsString('PRIVATE', $json);
        $this->assertStringNotContainsString('424242', $json);

        // Another signed-in user is still the public.
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $this->assertStringNotContainsString('PRIVATE', wp_json_encode($this->get('performers/' . $id . '/availability', ['month' => $month])->get_data()));
    }

    public function test_performer_and_admin_still_see_their_full_calendar(): void {
        [$id, , $user] = $this->performer();
        $month = gmdate('Y-m', strtotime('+1 month'));
        $this->private_slot($id, $month . '-15');

        foreach ([$user, self::factory()->user->create(['role' => 'administrator'])] as $viewer) {
            wp_set_current_user($viewer);
            $day = $this->get('performers/' . $id . '/availability', ['month' => $month])->get_data()['calendar'][$month . '-15'];
            $this->assertSame('PRIVATE-EVENT', $day['slots'][0]['event_name']);
            $this->assertSame('PRIVATE-NOTES', $day['slots'][0]['notes']);
        }
    }

    private function sortedKeys(array $value): array {
        $keys = array_keys($value);
        sort($keys);
        return $keys;
    }

    private function sponsor(int $performer_id): void {
        $this->assertNotFalse(Peanut_Booker_Database::insert('sponsored_slots', [
            'performer_id' => $performer_id, 'slot_type' => 'homepage', 'position' => 1,
            'start_date' => gmdate('Y-m-d H:i:s', strtotime('-1 day')), 'end_date' => gmdate('Y-m-d H:i:s', strtotime('+1 day')),
            'amount_paid' => 10, 'status' => 'active',
        ]));
    }

    public function test_featured_excludes_unpublished_sponsored_profiles(): void {
        [$published, $published_post] = $this->performer();
        $this->sponsor($published);
        foreach ([['draft', ''], ['private', ''], ['pending', ''], ['publish', 'secret']] as [$status, $password]) {
            [$hidden] = $this->performer($status, $password);
            $this->sponsor($hidden);
        }

        $response = $this->get('performers/featured', ['limit' => 10]);
        $this->assertSame(200, $response->get_status());
        $this->assertSame([$published_post], array_map('intval', array_column($response->get_data(), 'profile_id')));
    }

    public function test_featured_limit_is_capped(): void {
        for ($i = 0; $i < 15; $i++) {
            [$id] = $this->performer();
            $this->sponsor($id);
        }
        $this->assertCount(12, $this->get('performers/featured', ['limit' => 100000])->get_data());
        $this->assertCount(1, $this->get('performers/featured', ['limit' => 1])->get_data());
    }

    private function microsite(string $slug, string $status): int {
        [$performer, , $user] = $this->performer();
        $id = Peanut_Booker_Database::insert('microsites', ['performer_id' => $performer, 'user_id' => $user, 'status' => $status, 'slug' => $slug]);
        $this->assertNotFalse($id);
        return (int) $id;
    }

    private function views(int $id): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT view_count FROM {$wpdb->prefix}pb_microsites WHERE id = %d", $id));
    }

    public function test_tracking_does_not_reveal_whether_a_slug_exists(): void {
        $active = $this->microsite('real-site', 'active');
        $known = $this->track('real-site');
        $unknown = $this->track('no-such-site');
        $this->assertSame($known->get_status(), $unknown->get_status());
        $this->assertSame($known->get_data(), $unknown->get_data());
        $this->assertSame(1, $this->views($active));
    }

    public function test_tracking_counts_only_active_microsites(): void {
        $pending = $this->microsite('pending-site', 'pending');
        $this->track('pending-site');
        $this->assertSame(0, $this->views($pending));
    }

    public function test_tracking_is_rate_limited(): void {
        $active = $this->microsite('busy-site', 'active');
        $limit = Peanut_Booker_Rate_Limiter::get_limit('tracking');
        $this->assertLessThan(Peanut_Booker_Rate_Limiter::get_limit('general'), $limit, 'tracking has its own, tighter bucket');
        for ($i = 0; $i < $limit; $i++) {
            $this->assertSame(200, $this->track('busy-site')->get_status());
        }
        $this->assertSame(429, $this->track('busy-site')->get_status());
        $this->assertSame(429, $this->track('no-such-site')->get_status());
        $this->assertSame($limit, $this->views($active));
    }
}
