<?php
/**
 * Current-contract tests for Booker security-critical production controls.
 *
 * @package Peanut_Booker\Tests
 */

namespace Peanut_Booker\Tests\SecurityCore;

use Peanut_Booker\Tests\TestCase;
use Peanut_Booker_Encryption;
use Peanut_Booker_Rate_Limiter;
use Peanut_Booker_Roles;
use WP_REST_Response;

class EncryptionContractTest extends TestCase {

    public function test_round_trip_uses_tagged_randomized_ciphertext(): void {
        $plaintext = '123 Test Street, Montclair, NJ';

        $first = Peanut_Booker_Encryption::encrypt( $plaintext );
        $second = Peanut_Booker_Encryption::encrypt( $plaintext );

        $this->assertStringStartsWith( Peanut_Booker_Encryption::ENCRYPTED_PREFIX, $first );
        $this->assertTrue( Peanut_Booker_Encryption::is_encrypted( $first ) );
        $this->assertNotSame( $plaintext, $first );
        $this->assertNotSame( $first, $second );
        $this->assertSame( $plaintext, Peanut_Booker_Encryption::decrypt( $first ) );
        $this->assertSame( $first, Peanut_Booker_Encryption::encrypt( $first ) );
    }

    public function test_passthrough_contract_preserves_non_encrypted_values(): void {
        $this->assertSame( '', Peanut_Booker_Encryption::encrypt( '' ) );
        $this->assertNull( Peanut_Booker_Encryption::encrypt( null ) );
        $this->assertSame( 'plain text', Peanut_Booker_Encryption::decrypt( 'plain text' ) );
        $this->assertNull( Peanut_Booker_Encryption::decrypt( null ) );
        $this->assertFalse( Peanut_Booker_Encryption::is_encrypted( array() ) );
    }

    public function test_corrupt_tagged_payload_is_not_mistaken_for_plaintext(): void {
        $corrupt = Peanut_Booker_Encryption::ENCRYPTED_PREFIX . 'not-valid-base64***';

        $this->assertSame( $corrupt, Peanut_Booker_Encryption::decrypt( $corrupt ) );
        $this->assertTrue( Peanut_Booker_Encryption::is_encrypted( $corrupt ) );
    }

    public function test_array_and_object_field_helpers_only_transform_selected_fields(): void {
        $array = array(
            'event_address' => '123 Main Street',
            'event_zip'     => '07042',
            'event_city'    => 'Montclair',
        );

        $encrypted = Peanut_Booker_Encryption::encrypt_booking_data( $array );
        $this->assertTrue( Peanut_Booker_Encryption::is_encrypted( $encrypted['event_address'] ) );
        $this->assertTrue( Peanut_Booker_Encryption::is_encrypted( $encrypted['event_zip'] ) );
        $this->assertSame( 'Montclair', $encrypted['event_city'] );
        $this->assertSame( $array, Peanut_Booker_Encryption::decrypt_booking_data( $encrypted ) );

        $object = (object) $encrypted;
        $decrypted = Peanut_Booker_Encryption::decrypt_booking_data( $object );
        $this->assertSame( '123 Main Street', $decrypted->event_address );
        $this->assertSame( '07042', $decrypted->event_zip );
    }

    public function test_sensitive_field_lists_are_explicit(): void {
        $this->assertSame(
            array( 'event_address', 'event_zip' ),
            Peanut_Booker_Encryption::get_booking_encrypted_fields()
        );
        $this->assertSame(
            array( 'phone', 'billing_phone' ),
            Peanut_Booker_Encryption::get_customer_encrypted_fields()
        );
    }
}

class RateLimiterContractTest extends TestCase {

    public function test_booking_limit_blocks_the_eleventh_request(): void {
        for ( $request = 1; $request <= 10; $request++ ) {
            $result = Peanut_Booker_Rate_Limiter::check( 'booking', 'customer-1' );
            $this->assertTrue( $result['allowed'] );
            $this->assertSame( 10 - $request, $result['remaining'] );
        }

        $blocked = Peanut_Booker_Rate_Limiter::check( 'booking', 'customer-1' );
        $this->assertFalse( $blocked['allowed'] );
        $this->assertSame( 0, $blocked['remaining'] );
        $this->assertGreaterThan( time(), $blocked['reset'] );
    }

    public function test_identifiers_are_isolated_and_resettable(): void {
        Peanut_Booker_Rate_Limiter::check( 'review', 'reviewer-a' );
        $second = Peanut_Booker_Rate_Limiter::check( 'review', 'reviewer-a' );
        $other = Peanut_Booker_Rate_Limiter::check( 'review', 'reviewer-b' );

        $this->assertSame( 3, $second['remaining'] );
        $this->assertSame( 4, $other['remaining'] );

        Peanut_Booker_Rate_Limiter::reset( 'review', 'reviewer-a' );
        $reset = Peanut_Booker_Rate_Limiter::check( 'review', 'reviewer-a' );
        $this->assertSame( 4, $reset['remaining'] );
    }

    public function test_unknown_actions_use_the_general_contract(): void {
        $this->assertSame( 60, Peanut_Booker_Rate_Limiter::get_limit( 'unknown' ) );
        $this->assertSame( 60, Peanut_Booker_Rate_Limiter::get_window( 'unknown' ) );
        $this->assertNull( Peanut_Booker_Rate_Limiter::check_or_respond( 'unknown', 'reader-1' ) );
    }

    public function test_enforce_returns_a_bounded_rest_response(): void {
        for ( $request = 0; $request < 5; $request++ ) {
            Peanut_Booker_Rate_Limiter::check( 'review', 'reviewer-locked' );
        }

        $response = Peanut_Booker_Rate_Limiter::enforce( 'review', 'reviewer-locked' );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 429, $response->get_status() );
        $this->assertSame( 'rate_limit_exceeded', $response->get_data()['code'] );
        $this->assertSame( 5, $response->headers['X-RateLimit-Limit'] );
        $this->assertSame( 0, $response->headers['X-RateLimit-Remaining'] );
        $this->assertArrayHasKey( 'Retry-After', $response->headers );
    }

    public function test_add_headers_reflects_current_usage(): void {
        Peanut_Booker_Rate_Limiter::check( 'message', 'sender-1' );

        $response = Peanut_Booker_Rate_Limiter::add_headers(
            new WP_REST_Response( array( 'ok' => true ) ),
            'message',
            'sender-1'
        );

        $this->assertSame( 20, $response->headers['X-RateLimit-Limit'] );
        $this->assertSame( 19, $response->headers['X-RateLimit-Remaining'] );
        $this->assertGreaterThan( time(), $response->headers['X-RateLimit-Reset'] );
    }
}

class RolesContractTest extends TestCase {

    public function test_role_identity_uses_current_user_records(): void {
        $this->create_mock_performer( 11 );
        $this->create_mock_customer( 12 );

        $this->assertTrue( Peanut_Booker_Roles::is_performer( 11 ) );
        $this->assertFalse( Peanut_Booker_Roles::is_customer( 11 ) );
        $this->assertTrue( Peanut_Booker_Roles::is_customer( 12 ) );
        $this->assertFalse( Peanut_Booker_Roles::is_performer( 999 ) );
    }

    public function test_booking_access_allows_admin_and_owner_but_denies_stranger(): void {
        $this->create_mock_admin( 1 );
        $this->create_mock_customer( 2 );
        $this->create_mock_customer( 3 );
        $booking = (object) array( 'customer_id' => 2 );

        $this->assertTrue( Peanut_Booker_Roles::can_view_booking( $booking, 1 ) );
        $this->assertTrue( Peanut_Booker_Roles::can_view_booking( $booking, 2 ) );
        $this->assertTrue( Peanut_Booker_Roles::can_manage_booking( $booking, 2 ) );
        $this->assertFalse( Peanut_Booker_Roles::can_view_booking( $booking, 3 ) );
        $this->assertFalse( Peanut_Booker_Roles::can_view_booking( null, 2 ) );
        $this->assertTrue( Peanut_Booker_Roles::is_booking_customer( $booking, 2 ) );
        $this->assertFalse( Peanut_Booker_Roles::is_booking_customer( $booking, 3 ) );
    }

    public function test_performer_booking_access_uses_the_current_database_contract(): void {
        $this->create_mock_performer( 21 );
        $this->set_db_mock_row( (object) array( 'id' => 7, 'user_id' => 21 ) );
        $booking = (object) array( 'customer_id' => 4, 'performer_id' => 7 );

        $this->assertTrue( Peanut_Booker_Roles::is_booking_performer( $booking, 21 ) );
        $this->assertTrue( Peanut_Booker_Roles::can_view_booking( $booking, 21 ) );
    }

    public function test_review_permission_requires_relationship_and_capability(): void {
        $this->create_mock_customer( 31 );
        $this->create_mock_customer( 32 );
        $booking = (object) array( 'customer_id' => 31 );

        $this->assertTrue( Peanut_Booker_Roles::can_review_booking( $booking, 31 ) );
        $this->assertFalse( Peanut_Booker_Roles::can_review_booking( $booking, 32 ) );
    }

    public function test_profile_visibility_and_editing_respect_owner_and_admin_caps(): void {
        $this->create_mock_admin( 41 );
        $this->create_mock_performer( 42 );
        $this->create_mock_performer( 43 );
        $private = (object) array( 'user_id' => 42, 'is_public' => 0 );
        $public = (object) array( 'user_id' => 42, 'is_public' => 1 );

        $this->assertTrue( Peanut_Booker_Roles::can_view_performer( $public, 0 ) );
        $this->assertTrue( Peanut_Booker_Roles::can_view_performer( $private, 41 ) );
        $this->assertTrue( Peanut_Booker_Roles::can_edit_performer( $private, 41 ) );
        $this->assertTrue( Peanut_Booker_Roles::can_edit_performer( $private, 42 ) );
        $this->assertFalse( Peanut_Booker_Roles::can_edit_performer( $private, 43 ) );
    }

    public function test_market_event_management_is_limited_to_admin_or_owner(): void {
        $this->create_mock_admin( 51 );
        $this->create_mock_customer( 52 );
        $this->create_mock_customer( 53 );
        $event = (object) array( 'customer_id' => 52 );

        $this->assertTrue( Peanut_Booker_Roles::can_manage_market_event( $event, 51 ) );
        $this->assertTrue( Peanut_Booker_Roles::can_manage_market_event( $event, 52 ) );
        $this->assertFalse( Peanut_Booker_Roles::can_manage_market_event( $event, 53 ) );
    }

    public function test_commission_defaults_and_overrides_are_explicit(): void {
        $this->assertSame( 20.0, (float) Peanut_Booker_Roles::get_commission_rate( 'free' ) );
        $this->assertSame( 12.0, (float) Peanut_Booker_Roles::get_commission_rate( 'pro' ) );
        $this->assertSame( 8.0, (float) Peanut_Booker_Roles::get_commission_rate( 'featured' ) );

        $this->set_option(
            'peanut_booker_settings',
            array(
                'commission_free_tier'     => '18.5',
                'commission_pro_tier'      => '10',
                'commission_featured_tier' => '6.5',
            )
        );

        $this->assertSame( 18.5, Peanut_Booker_Roles::get_commission_rate( 'free' ) );
        $this->assertSame( 10.0, Peanut_Booker_Roles::get_commission_rate( 'pro' ) );
        $this->assertSame( 6.5, Peanut_Booker_Roles::get_commission_rate( 'featured' ) );
    }

    public function test_tier_limits_follow_the_persisted_performer_tier(): void {
        $this->create_mock_performer( 61 );

        $this->set_db_mock_row( (object) array( 'tier' => 'free' ) );
        $this->assertSame( 1, Peanut_Booker_Roles::get_photo_limit( 61 ) );
        $this->assertSame( 0, Peanut_Booker_Roles::get_video_limit( 61 ) );

        $this->set_db_mock_row( (object) array( 'tier' => 'pro' ) );
        $this->assertTrue( Peanut_Booker_Roles::can_bid_on_events( 61 ) );
        $this->assertSame( 10, Peanut_Booker_Roles::get_photo_limit( 61 ) );
        $this->assertSame( 5, Peanut_Booker_Roles::get_video_limit( 61 ) );

        $this->set_db_mock_row( (object) array( 'tier' => 'featured' ) );
        $this->assertTrue( Peanut_Booker_Roles::is_featured_performer( 61 ) );
        $this->assertTrue( Peanut_Booker_Roles::has_unlimited_photos( 61 ) );
        $this->assertTrue( Peanut_Booker_Roles::has_unlimited_videos( 61 ) );
    }
}
