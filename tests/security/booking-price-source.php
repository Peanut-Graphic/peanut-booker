<?php
/**
 * Offline regression: where a booking's price comes from.
 *
 * Loads the REAL includes/class-booking.php and calls Peanut_Booker_Booking::create()
 * with synthetic storage. Before the fix, a performer with no hourly rate let
 * the customer's own `total_amount` become the booking price (and therefore the
 * deposit charged and the payout owed). The price must come from the server:
 * the performer's hourly rate, or an accepted bid the performer made.
 *
 * Run: php tests/security/booking-price-source.php (also run by the Property
 * suite via tests/Property/BookingPriceSourceTest.php).
 */
declare(strict_types=1);

define( 'ABSPATH', '/synthetic/wordpress/' );
define( 'WPINC', 'wp-includes' );

ini_set( 'error_log', sys_get_temp_dir() . '/peanut-booker-price-source.log' );

class WP_Error {
    public $code;
    public $message;
    public function __construct( $code = '', $message = '', $data = '' ) {
        $this->code    = $code;
        $this->message = $message;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function absint( $value ) { return abs( (int) $value ); }
function __( $text, $domain = 'default' ) { return $text; }
function add_action( ...$args ) {}
function do_action( ...$args ) {}
function sanitize_text_field( $value ) { return (string) $value; }
function sanitize_textarea_field( $value ) { return (string) $value; }
function get_option( $name, $default = false ) { return $default; }
function get_post_status( $id ) { return 'publish'; }

$GLOBALS['performers'] = array();
$GLOBALS['bids']       = array();
$GLOBALS['inserted']   = array();

class Peanut_Booker_Performer {
    public static function get( $id ) {
        return isset( $GLOBALS['performers'][ $id ] ) ? (object) $GLOBALS['performers'][ $id ] : null;
    }
}

class Peanut_Booker_Database {
    public static function get_row( $table, $where ) {
        if ( 'bids' === $table && isset( $GLOBALS['bids'][ $where['id'] ] ) ) {
            return (object) $GLOBALS['bids'][ $where['id'] ];
        }
        return null;
    }
    public static function insert( $table, $data ) {
        $GLOBALS['inserted'][] = $data;
        return count( $GLOBALS['inserted'] );
    }
    public static function generate_booking_number() { return 'PB-TEST'; }
}

class Peanut_Booker_Encryption {
    public static function encrypt_booking_data( $data ) { return $data; }
}

class Peanut_Booker_Roles {
    public static function get_commission_rate( $tier ) { return 10; }
}

class Peanut_Booker_Availability {
    public static function block_date( ...$args ) {}
}

class Peanut_Booker_Notifications {
    public static function send( ...$args ) {}
}

/** The WooCommerce integration owns checkout links (it adds the CSRF nonce). */
class Peanut_Booker_WooCommerce {
    public static function get_checkout_url( $booking_id ) {
        return 'https://example.com/checkout/?pb_booking=' . $booking_id . '&action=checkout&_wpnonce=signed';
    }
}
function wc_get_checkout_url() { return 'https://example.com/checkout/'; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }

require dirname( __DIR__, 2 ) . '/includes/class-booking.php';

$failures = array();
$checks   = 0;

function verify( bool $condition, string $message ): void {
    ++$GLOBALS['checks'];
    if ( ! $condition ) {
        $GLOBALS['failures'][] = $message;
    }
}

function performer( int $id, float $hourly_rate ): void {
    $GLOBALS['performers'][ $id ] = array(
        'id'                 => $id,
        'user_id'            => 100 + $id,
        'profile_id'         => 0,
        'status'             => 'approved',
        'hourly_rate'        => $hourly_rate,
        'deposit_percentage' => 25,
        'tier'               => 'free',
    );
}

function request( int $performer_id, array $extra = array() ): array {
    return array_merge(
        array(
            'performer_id' => $performer_id,
            'customer_id'  => 42,
            'event_date'   => '2026-12-01',
            'event_title'  => 'Synthetic event',
        ),
        $extra
    );
}

function last_insert(): array {
    return end( $GLOBALS['inserted'] ) ?: array();
}

performer( 1, 0.0 );   // No hourly rate: price must come from an accepted bid.
performer( 2, 100.0 ); // Hourly rate: price is checked against it.
performer( 3, 0.0 );

$GLOBALS['bids'][10] = array( 'id' => 10, 'performer_id' => 1, 'event_id' => 5, 'bid_amount' => '750.00', 'status' => 'accepted' );
$GLOBALS['bids'][11] = array( 'id' => 11, 'performer_id' => 3, 'event_id' => 5, 'bid_amount' => '900.00', 'status' => 'accepted' );
$GLOBALS['bids'][12] = array( 'id' => 12, 'performer_id' => 1, 'event_id' => 6, 'bid_amount' => '800.00', 'status' => 'pending' );

// 1. No hourly rate, no bid: a client-supplied total is refused.
$count  = count( $GLOBALS['inserted'] );
$result = Peanut_Booker_Booking::create( request( 1, array( 'total_amount' => 1.00 ) ) );
verify( is_wp_error( $result ), 'no-rate performer + client total 1.00 is refused' );
verify( count( $GLOBALS['inserted'] ) === $count, 'no booking row is written for a client-priced booking' );

// 2. No hourly rate, accepted bid: the bid amount is the price, whatever the client sent.
$result = Peanut_Booker_Booking::create( request( 1, array( 'bid_id' => 10, 'total_amount' => 1.00 ) ) );
verify( ! is_wp_error( $result ), 'accepted bid prices a no-rate booking' );
$row = last_insert();
verify( abs( (float) ( $row['total_amount'] ?? 0 ) - 750.00 ) < 0.001, 'booking total is the bid amount (750.00), not the client total' );
verify( abs( (float) ( $row['deposit_amount'] ?? 0 ) - 187.50 ) < 0.001, 'deposit is computed from the bid amount' );

// 3. Same, with no client total at all.
$result = Peanut_Booker_Booking::create( request( 1, array( 'bid_id' => 10 ) ) );
verify( ! is_wp_error( $result ), 'bid-priced booking does not need a client total' );

// 4. A bid made by a different performer cannot price this booking.
$count  = count( $GLOBALS['inserted'] );
$result = Peanut_Booker_Booking::create( request( 1, array( 'bid_id' => 11, 'total_amount' => 900.00 ) ) );
verify( is_wp_error( $result ) && count( $GLOBALS['inserted'] ) === $count, 'another performer\'s bid is refused' );

// 5. A bid that was not accepted cannot price the booking.
$result = Peanut_Booker_Booking::create( request( 1, array( 'bid_id' => 12, 'total_amount' => 800.00 ) ) );
verify( is_wp_error( $result ), 'a pending bid is refused' );

// 6. A missing bid cannot price the booking.
$result = Peanut_Booker_Booking::create( request( 1, array( 'bid_id' => 999, 'total_amount' => 800.00 ) ) );
verify( is_wp_error( $result ), 'an unknown bid is refused' );

// 7. Hourly-rate path is unchanged: matching total accepted, mismatch refused.
$result = Peanut_Booker_Booking::create( request( 2, array( 'total_amount' => 100.00 ) ) );
verify( ! is_wp_error( $result ), 'hourly performer, matching total is accepted' );
$result = Peanut_Booker_Booking::create( request( 2, array( 'total_amount' => 50.00 ) ) );
verify( is_wp_error( $result ) && 'amount_mismatch' === $result->get_error_code(), 'hourly performer, mismatched total is refused' );
$result = Peanut_Booker_Booking::create( request( 2 ) );
verify( is_wp_error( $result ), 'hourly performer still needs a total' );

// 8. Booking checkout links carry the nonce the checkout handler requires.
verify( str_contains( Peanut_Booker_Booking::get_checkout_url( 5 ), '_wpnonce=signed' ), 'Booking::get_checkout_url() returns the nonced WooCommerce link' );

foreach ( $failures as $failure ) {
    fwrite( STDERR, "FAIL: $failure\n" );
}
echo $checks . ' checks, ' . count( $failures ) . " failures\n";
exit( $failures ? 1 : 0 );
