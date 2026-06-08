<?php
/**
 * Real-WordPress REST contract test (net 7) for the public performers listing route.
 *
 * Pins the REAL `GET /peanut-booker/v1/performers` route registered by
 * Peanut_Booker_REST_API::register_routes(). The route is
 * `permission_callback => '__return_true'` (public by design — read-only
 * performer catalog the frontend pulls before sign-in), so it is the stable,
 * gettable surface to lock down.
 *
 * Documented response shape (see Peanut_Booker_Performer::query, returned via
 * Peanut_Booker_REST_API::get_performers):
 *   200 => [
 *     'performers'   => array<...>,
 *     'total'        => int,
 *     'max_pages'    => int,
 *     'current_page' => int,
 *   ]
 *
 * This boots a real WordPress and dispatches through the real REST server —
 * NO mocks. If the route or shape regresses, this fails.
 *
 * The plugin's production boot (peanut_booker_run) bails without WooCommerce,
 * so here we load the real REST/dependency classes, register the real
 * pb_performer post type, instantiate the real REST controller, and fire the
 * real rest_api_init — exercising the production registration + callback paths.
 */

namespace Peanut_Booker\Tests\ContractWp;

use WP_UnitTestCase;
use WP_REST_Request;
use Peanut_Booker_REST_API;
use Peanut_Booker_Post_Types;

class PerformersRouteContractTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();

        $base = dirname(__DIR__, 2) . '/includes/';
        require_once $base . 'class-rate-limiter.php';
        require_once $base . 'class-performer.php';
        require_once $base . 'class-post-types.php';
        require_once $base . 'class-rest-api.php';

        // Register the real custom post type the performers query targets, so the
        // real callback executes against a real (empty) result set rather than
        // failing on an unregistered post type.
        ( new Peanut_Booker_Post_Types() )->register_post_types();

        // Instantiate the real REST controller; its constructor hooks
        // register_routes onto rest_api_init.
        new Peanut_Booker_REST_API();

        // Rebuild the REST server so the just-registered route is live.
        global $wp_rest_server;
        $wp_rest_server = null;
        do_action('rest_api_init');
    }

    public function test_performers_route_is_registered(): void {
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey(
            '/peanut-booker/v1/performers',
            $routes,
            'Public performers listing route must be registered on a real WordPress.'
        );
    }

    public function test_get_performers_returns_documented_contract(): void {
        $request  = new WP_REST_Request('GET', '/peanut-booker/v1/performers');
        $response = rest_get_server()->dispatch($request);

        // Real status from the real callback.
        $this->assertSame(
            200,
            $response->get_status(),
            'Public performers listing must return HTTP 200.'
        );

        $data = $response->get_data();

        // Documented response-shape keys.
        $this->assertIsArray($data);
        $this->assertArrayHasKey('performers', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('max_pages', $data);
        $this->assertArrayHasKey('current_page', $data);

        $this->assertIsArray($data['performers']);
    }
}
