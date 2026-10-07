<?php
/** Populated real-WordPress routes and real plugin schema; no production mocks. */
namespace Peanut_Booker\Tests\ContractWp;

use WP_UnitTestCase;
use WP_REST_Request;
use Peanut_Booker_Activator;
use Peanut_Booker_Database;
use Peanut_Booker_Market;
use Peanut_Booker_Performer;
use Peanut_Booker_Post_Types;
use Peanut_Booker_Rate_Limiter;
use Peanut_Booker_REST_API;

final class PublicCatalogPrivacyTest extends WP_UnitTestCase {
    public static function wpSetUpBeforeClass($factory): void {
        $base = dirname(__DIR__, 2) . '/includes/';
        foreach (['activator', 'database', 'encryption', 'customer', 'market', 'roles', 'performer', 'availability', 'reviews', 'rate-limiter', 'post-types', 'rest-api'] as $name) {
            require_once $base . 'class-' . $name . '.php';
        }
        // Use the actual schema on the ephemeral test database, before test
        // transactions. Avoid activation's pages, options and scheduled work.
        $schema = new \ReflectionMethod(Peanut_Booker_Activator::class, 'create_tables');
        $schema->setAccessible(true);
        $schema->invoke(null);
    }

    public function set_up(): void {
        parent::set_up();
        wp_set_current_user(0);
        Peanut_Booker_Rate_Limiter::reset('public');
        $types = new Peanut_Booker_Post_Types();
        $types->register_post_types();
        $types->register_taxonomies();
        new Peanut_Booker_REST_API();
        global $wp_rest_server;
        $wp_rest_server = null;
        do_action('rest_api_init');
    }

    private function request(string $path, array $params = []) {
        $request = new WP_REST_Request('GET', '/peanut-booker/v1/' . $path);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        return rest_get_server()->dispatch($request);
    }

    private function performer(string $status = 'publish', string $password = ''): array {
        $user = self::factory()->user->create();
        $post = self::factory()->post->create(['post_type' => 'pb_performer', 'post_status' => $status, 'post_password' => $password, 'post_title' => 'Synthetic performer', 'post_content' => 'Synthetic biography']);
        $id = Peanut_Booker_Database::insert('performers', ['user_id' => $user, 'profile_id' => $post, 'status' => 'active']);
        $this->assertNotFalse($id);
        return [(int) $id, (int) $post];
    }

    private function market(string $status = 'publish', string $password = ''): int {
        $user = self::factory()->user->create(['user_email' => 'test-' . wp_generate_uuid4() . '@example.com']);
        update_user_meta($user, 'pb_address', '123 Synthetic Test Street');
        update_user_meta($user, 'pb_zip', '00000');
        $id = self::factory()->post->create(['post_type' => 'pb_market_event', 'post_status' => $status, 'post_password' => $password, 'post_title' => 'Synthetic event']);
        update_post_meta($id, 'pb_customer_id', $user);
        update_post_meta($id, 'pb_event_date', '2026-12-01');
        update_post_meta($id, 'pb_event_status', 'open');
        return (int) $id;
    }

    public static function protectedProfiles(): array {
        return [['draft', ''], ['pending', ''], ['private', ''], ['trash', ''], ['publish', 'secret'], ['publish', '0']];
    }

    /** @dataProvider protectedProfiles */
    public function test_protected_performer_routes_refuse_anonymous_reads(string $status, string $password): void {
        [$id] = $this->performer($status, $password);
        foreach (['', '/availability', '/reviews'] as $suffix) {
            $response = $this->request('performers/' . $id . $suffix);
            $this->assertSame(404, $response->get_status(), $suffix);
            $this->assertSame('not_found', $response->get_data()['code']);
        }
    }

    /** @dataProvider protectedProfiles */
    public function test_protected_market_routes_refuse_anonymous_reads(string $status, string $password): void {
        $id = $this->market($status, $password);
        $response = $this->request('market/' . $id);
        $this->assertSame(404, $response->get_status());
        $this->assertSame('not_found', $response->get_data()['code']);
    }

    public function test_public_performer_routes_and_filtered_pagination(): void {
        [$id, $post] = $this->performer();
        $this->performer('publish', '0');
        $this->performer('draft');
        foreach (['', '/availability', '/reviews'] as $suffix) {
            $this->assertSame(200, $this->request('performers/' . $id . $suffix, ['month' => '2026-10'])->get_status(), $suffix);
        }
        $response = $this->request('performers', ['per_page' => 1]);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertSame(1, $data['total']);
        $this->assertSame(1, $data['max_pages']);
        $this->assertSame([$post], array_map('intval', array_column($data['performers'], 'profile_id')));
        // Internal consumers keep their old semantics, including password posts.
        $this->assertSame(2, Peanut_Booker_Performer::query()['total']);
    }

    public function test_public_market_projection_and_filtered_pagination(): void {
        $id = $this->market();
        $this->market('publish', '0');
        $this->market('draft');
        $detail = $this->request('market/' . $id);
        $this->assertSame(200, $detail->get_status());
        $listing = $this->request('market', ['per_page' => 1]);
        $this->assertSame(200, $listing->get_status());
        $data = $listing->get_data();
        $this->assertSame(1, $data['total']);
        $this->assertSame(1, $data['max_pages']);
        $this->assertSame([$id], array_map('intval', array_column($data['events'], 'id')));
        foreach ([$detail->get_data(), $data['events'][0]] as $event) {
            $keys = array_keys($event['customer']);
            sort($keys);
            $this->assertSame(['avatar_url', 'display_name'], $keys);
        }
        $internal = Peanut_Booker_Market::get_event_data($id);
        $this->assertStringEndsWith('@example.com', $internal['customer']['email']);
        $this->assertSame('123 Synthetic Test Street', $internal['customer']['address']);
        $this->assertSame(2, Peanut_Booker_Market::query()['total']);
    }
}
