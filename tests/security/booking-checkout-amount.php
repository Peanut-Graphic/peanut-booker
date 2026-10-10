<?php
/**
 * Offline money-path regression for the WooCommerce booking checkout.
 *
 * Loads the REAL includes/class-woocommerce.php and drives its hooks the way
 * WooCommerce does on classic checkout: cart pricing
 * (woocommerce_before_calculate_totals), checkout validation, order creation
 * (woocommerce_checkout_create_order) and payment completion
 * (woocommerce_payment_complete). WooCommerce itself is not installed in the
 * test environment, so WC(), the cart, products and orders are synthetic seams
 * that mirror the WooCommerce methods the class calls. Booking storage is a
 * synthetic in-memory table.
 *
 * The defect this pins: the booking product is priced at $0, so classic
 * checkout totals $0, WooCommerce takes process_order_without_payment() and
 * still fires woocommerce_payment_complete, and Booker marked the booking
 * deposit-paid / in escrow with no money collected.
 *
 * Run: php tests/security/booking-checkout-amount.php (also run by the
 * Property suite via tests/Property/BookingCheckoutAmountTest.php).
 */
declare(strict_types=1);

define( 'ABSPATH', '/synthetic/wordpress/' );
define( 'WPINC', 'wp-includes' );

ini_set( 'error_log', sys_get_temp_dir() . '/peanut-booker-checkout-amount.log' );

$GLOBALS['hooks']        = array();
$GLOBALS['notices']      = array();
$GLOBALS['bookings']     = array();
$GLOBALS['transactions'] = array();
$GLOBALS['actions']      = array();
$GLOBALS['redirect']     = null;
$GLOBALS['orders']       = array();

class WP_Error {
    public $code;
    public $message;
    public $data;
    public function __construct( $code = '', $message = '', $data = '' ) {
        $this->code    = $code;
        $this->message = $message;
        $this->data    = $data;
    }
    public function get_error_code() {
        return $this->code;
    }
    public function get_error_message() {
        return $this->message;
    }
    public function add( $code, $message ) {
        $GLOBALS['checkout_errors'][] = $code;
    }
}

class SyntheticRedirect extends RuntimeException {}

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['hooks'][ $hook ][] = $callback; }
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['hooks'][ $hook ][] = $callback; }
function do_action( $hook, ...$args ) { $GLOBALS['actions'][] = array( $hook, $args ); }
function __( $text, $domain = 'default' ) { return $text; }
function _x( $text, $context, $domain = 'default' ) { return $text; }
function esc_html( $text ) { return $text; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return strtolower( (string) $value ); }
function sanitize_text_field( $value ) { return (string) $value; }
function wp_unslash( $value ) { return $value; }
function wp_verify_nonce( $nonce, $action ) { return 'valid' === $nonce ? 1 : false; }
function wp_create_nonce( $action ) { return 'valid'; }
function is_user_logged_in() { return true; }
function get_current_user_id() { return 42; }
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function wc_add_notice( $message, $type = 'success' ) { $GLOBALS['notices'][] = array( $type, $message ); }
function wc_get_checkout_url() { return 'https://example.com/checkout/'; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function wp_safe_redirect( $url ) { $GLOBALS['redirect'] = $url; throw new SyntheticRedirect( $url ); }
function get_option( $name, $default = false ) { return 'peanut_booker_booking_product' === $name ? 900 : $default; }
function update_option( $name, $value ) { return true; }
function wc_get_product( $id ) { return 900 === (int) $id ? new SyntheticProduct() : false; }
function wc_get_order( $id ) { return $GLOBALS['orders'][ $id ] ?? false; }
function date_i18n( $format, $timestamp ) { return gmdate( $format, $timestamp ); }

class SyntheticProduct {
    public $price = 0.0;
    public function set_price( $price ) { $this->price = (float) $price; }
    public function get_price() { return $this->price; }
}

class SyntheticCart {
    public $cart_contents = array();
    public function empty_cart() { $this->cart_contents = array(); }
    public function add_to_cart( $product_id, $quantity = 1, $variation_id = 0, $variation = array(), $data = array() ) {
        $key = 'item' . count( $this->cart_contents );
        $this->cart_contents[ $key ] = array_merge(
            $data,
            array(
                'product_id' => $product_id,
                'quantity'   => $quantity,
                'data'       => new SyntheticProduct(),
            )
        );
        return $key;
    }
    public function get_cart() { return $this->cart_contents; }
    /** WooCommerce computes the line total from the product price the hook set. */
    public function line_total( $key ) {
        return $this->cart_contents[ $key ]['data']->get_price() * $this->cart_contents[ $key ]['quantity'];
    }
}

class SyntheticWooCommerce {
    public $cart;
    public function __construct() { $this->cart = new SyntheticCart(); }
}

function WC() { return $GLOBALS['wc']; }

class SyntheticOrderItem {
    public $meta = array();
    public $total;
    public $quantity;
    public function __construct( $total, $quantity = 1 ) {
        $this->total    = (float) $total;
        $this->quantity = $quantity;
    }
    public function add_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
    public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
    public function get_total() { return $this->total; }
    public function get_quantity() { return $this->quantity; }
}

class SyntheticOrder {
    public $id;
    public $meta       = array();
    public $items      = array();
    public $total      = 0.0;
    public $total_tax  = 0.0;
    public $status     = 'pending';
    public $notes      = array();
    public $total_sets = 0;
    public function __construct( $id ) { $this->id = $id; }
    public function get_id() { return $this->id; }
    public function add_meta_data( $key, $value, $unique = false ) { $this->meta[ $key ] = $value; }
    public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
    public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
    public function save() { return $this->id; }
    public function set_total( $total ) { $this->total = (float) $total; ++$this->total_sets; }
    public function get_total() { return $this->total; }
    public function get_total_tax() { return $this->total_tax; }
    public function get_items( $type = 'line_item' ) { return $this->items; }
    public function get_payment_method() { return 'synthetic'; }
    public function get_transaction_id() { return 'txn-' . $this->id; }
    public function add_order_note( $note ) { $this->notes[] = $note; }
    public function update_status( $status, $note = '' ) {
        $this->status  = $status;
        $this->notes[] = $note;
    }
}

class Peanut_Booker_Booking {
    const STATUS_PENDING   = 'pending';
    const STATUS_CONFIRMED = 'confirmed';
    const ESCROW_PENDING   = 'pending';
    const ESCROW_DEPOSIT   = 'deposit_held';
    const ESCROW_FULL      = 'full_held';
    public static function get( $id ) {
        return isset( $GLOBALS['bookings'][ $id ] ) ? (object) $GLOBALS['bookings'][ $id ] : null;
    }
    public static function update( $id, $data ) {
        $GLOBALS['bookings'][ $id ] = array_merge( $GLOBALS['bookings'][ $id ], $data );
        return true;
    }
    public static function update_status( $id, $status ) {
        $GLOBALS['bookings'][ $id ]['booking_status'] = $status;
        return true;
    }
}

class Peanut_Booker_Database {
    public static function insert( $table, $data ) {
        $GLOBALS['transactions'][] = $data;
        return count( $GLOBALS['transactions'] );
    }
}

require dirname( __DIR__, 2 ) . '/includes/class-woocommerce.php';

$failures = array();
$checks   = 0;

// Any PHP warning/notice from the code under test is a failure (the checkout
// handler once read a column that does not exist and refused every booking).
set_error_handler(
    static function ( $severity, $message, $file, $line ) {
        $GLOBALS['failures'][] = "PHP error: $message at " . basename( $file ) . ":$line";
        return true;
    }
);

function verify( bool $condition, string $message ): void {
    ++$GLOBALS['checks'];
    if ( ! $condition ) {
        $GLOBALS['failures'][] = $message;
    }
}

function fixture_booking( int $id, float $total, float $deposit, array $overrides = array() ): void {
    $GLOBALS['bookings'][ $id ] = array_merge(
        array(
            'id'                  => $id,
            'booking_number'      => 'PB-' . $id,
            'customer_id'         => 42,
            'performer_id'        => 7,
            'event_title'         => 'Synthetic event',
            'event_date'          => '2026-12-01',
            'booking_status'      => 'pending',
            'total_amount'        => number_format( $total, 2, '.', '' ),
            'deposit_amount'      => number_format( $deposit, 2, '.', '' ),
            'remaining_amount'    => number_format( $total - $deposit, 2, '.', '' ),
            'deposit_paid'        => 0,
            'fully_paid'          => 0,
            'escrow_status'       => 'pending',
            'performer_confirmed' => 0,
        ),
        $overrides
    );
}

/** Start checkout through the real handler; returns the cart key it added. */
function start_checkout( Peanut_Booker_WooCommerce $wc, int $booking_id, string $payment = '' ): ?string {
    $GLOBALS['wc'] = new SyntheticWooCommerce();
    $_GET          = array( 'pb_booking' => (string) $booking_id, 'action' => 'checkout', '_wpnonce' => 'valid' );
    if ( '' !== $payment ) {
        $_GET['payment'] = $payment;
    }
    try {
        $wc->handle_booking_checkout();
    } catch ( SyntheticRedirect $redirect ) {
        // Expected: the handler redirects to checkout and exits.
    }
    verify( array() === array_filter( $GLOBALS['notices'], static function ( $notice ) { return 'error' === $notice[0]; } ), "checkout for booking $booking_id raises no error notice" );
    $GLOBALS['notices'] = array();
    $keys = array_keys( WC()->cart->get_cart() );
    return $keys[0] ?? null;
}

/** Price the cart the way WooCommerce does before totalling. */
function calculate_totals(): void {
    foreach ( $GLOBALS['hooks']['woocommerce_before_calculate_totals'] ?? array() as $callback ) {
        call_user_func( $callback, WC()->cart );
    }
}

/**
 * Turn the current cart into an order as WC_Checkout::create_order does:
 * order total = cart total (+ tax), line items carry the cart line total, then
 * the plugin's create-order hooks run.
 */
function create_order( Peanut_Booker_WooCommerce $wc, int $order_id, float $tax = 0.0 ): SyntheticOrder {
    $order = new SyntheticOrder( $order_id );
    $total = 0.0;
    foreach ( WC()->cart->get_cart() as $key => $values ) {
        $item = new SyntheticOrderItem( WC()->cart->line_total( $key ), $values['quantity'] );
        $wc->save_booking_order_item_meta( $item, $key, $values, $order );
        $order->items[] = $item;
        $total         += $item->get_total();
    }
    $order->total     = $total + $tax;
    $order->total_tax = $tax;
    $wc->add_booking_to_order( $order, array() );
    $GLOBALS['orders'][ $order_id ] = $order;
    return $order;
}

function completed_transactions( int $booking_id ): array {
    return array_values(
        array_filter(
            $GLOBALS['transactions'],
            static function ( $row ) use ( $booking_id ) {
                return (int) $row['booking_id'] === $booking_id && 'completed' === $row['status'];
            }
        )
    );
}

$plugin = new Peanut_Booker_WooCommerce();

// --- 1. The cart must be priced from the booking, not left at $0. ------------
verify( isset( $GLOBALS['hooks']['woocommerce_before_calculate_totals'] ), 'cart pricing hook woocommerce_before_calculate_totals is registered' );

fixture_booking( 1, 500.00, 125.00 );
$key = start_checkout( $plugin, 1 );
verify( null !== $key, 'deposit checkout adds the booking to the cart' );
calculate_totals();
verify( null !== $key && abs( WC()->cart->line_total( $key ) - 125.00 ) < 0.001, 'deposit booking line is priced at the DB deposit (125.00), not $0' );
verify( null !== $key && true === ( WC()->cart->get_cart()[ $key ]['pb_is_deposit'] ?? null ), 'deposit booking is flagged as a deposit' );

// --- 2. Classic checkout with a $0 cart: payment_complete must NOT mark paid. -
// Reproduce the shipped flow exactly: cart left at its $0 product price (no
// pricing hook ran), order created from it, then WooCommerce's
// process_order_without_payment() fires payment_complete.
fixture_booking( 2, 500.00, 125.00 );
$key   = start_checkout( $plugin, 2 );
$order = create_order( $plugin, 1002 );
verify( 0 === $order->total_sets, 'checkout does not overwrite the order total after the cart was totalled' );
$plugin->payment_complete( 1002 );
verify( 0 === (int) $GLOBALS['bookings'][2]['deposit_paid'], '$0 order does not mark the deposit paid' );
verify( 'pending' === $GLOBALS['bookings'][2]['escrow_status'], '$0 order does not put the booking in escrow' );
verify( array() === completed_transactions( 2 ), '$0 order records no completed transaction' );
verify( 'on-hold' === $order->status, '$0 order is put on hold for review' );
verify( '' !== (string) $order->get_meta( '_pb_payment_review' ), '$0 order carries a review flag' );
$review_rows = array_filter( $GLOBALS['transactions'], static function ( $row ) { return 2 === (int) $row['booking_id'] && 'needs_review' === $row['status']; } );
verify( 1 === count( $review_rows ), 'the booking gets a needs_review transaction row' );
verify( in_array( 'peanut_booker_payment_review_required', array_column( $GLOBALS['actions'], 0 ), true ), 'review action fires' );
verify( ! in_array( 'peanut_booker_payment_received', array_column( $GLOBALS['actions'], 0 ), true ), 'payment_received does not fire for a $0 order' );

// --- 3. Legacy shape: order total forced to the deposit, line item still $0. -
fixture_booking( 3, 500.00, 125.00 );
$order = new SyntheticOrder( 1003 );
$item  = new SyntheticOrderItem( 0.0 );
$item->add_meta_data( '_pb_booking_id', 3 );
$order->items[] = $item;
$order->add_meta_data( '_pb_booking_id', 3 );
$order->add_meta_data( '_pb_is_deposit', true );
$order->total              = 125.00; // What the old set_total() wrote.
$GLOBALS['orders'][1003] = $order;
$plugin->payment_complete( 1003 );
verify( 0 === (int) $GLOBALS['bookings'][3]['deposit_paid'], 'order whose total was forced but whose line was $0 is not marked paid' );

// --- 4. Wrong amount (e.g. a coupon or tampered price) is held for review. ---
fixture_booking( 4, 500.00, 125.00 );
$key = start_checkout( $plugin, 4 );
calculate_totals();
if ( null !== $key ) {
    WC()->cart->cart_contents[ $key ]['data']->set_price( 100.00 );
}
$order = create_order( $plugin, 1004 );
$plugin->payment_complete( 1004 );
verify( 0 === (int) $GLOBALS['bookings'][4]['deposit_paid'], 'underpaid order (100.00 of 125.00) is not marked paid' );
verify( 'on-hold' === $order->status, 'underpaid order is held' );

// --- 5. The correct deposit is recorded exactly once. -------------------------
fixture_booking( 5, 500.00, 125.00, array( 'performer_confirmed' => 1 ) );
$key = start_checkout( $plugin, 5 );
calculate_totals();
$order = create_order( $plugin, 1005 );
$plugin->payment_complete( 1005 );
verify( 1 === (int) $GLOBALS['bookings'][5]['deposit_paid'], 'correct deposit marks deposit_paid' );
verify( 'deposit_held' === $GLOBALS['bookings'][5]['escrow_status'], 'correct deposit holds escrow' );
verify( 0 === (int) $GLOBALS['bookings'][5]['fully_paid'], 'deposit does not mark fully paid' );
verify( 'confirmed' === $GLOBALS['bookings'][5]['booking_status'], 'confirmed performer + paid deposit confirms booking' );
verify( 'escrow-held' === $order->status, 'paid deposit order moves to escrow-held' );
$rows = completed_transactions( 5 );
verify( 1 === count( $rows ) && abs( (float) $rows[0]['amount'] - 125.00 ) < 0.001 && 'deposit' === $rows[0]['transaction_type'], 'one completed 125.00 deposit transaction' );
$plugin->payment_complete( 1005 );
verify( 1 === count( completed_transactions( 5 ) ), 'a repeated payment_complete does not record the payment twice' );

// --- 6. Tax on top of the correct price is accepted. --------------------------
fixture_booking( 6, 500.00, 125.00 );
$key = start_checkout( $plugin, 6 );
calculate_totals();
create_order( $plugin, 1006, 8.75 );
$plugin->payment_complete( 1006 );
verify( 1 === (int) $GLOBALS['bookings'][6]['deposit_paid'], 'correct deposit plus tax is accepted' );

// --- 7. No deposit configured: charge the full amount, not a $0 "deposit". ----
fixture_booking( 7, 300.00, 0.00 );
$key = start_checkout( $plugin, 7 );
calculate_totals();
verify( null !== $key && false === ( WC()->cart->get_cart()[ $key ]['pb_is_deposit'] ?? null ), 'no-deposit booking is not flagged as a deposit' );
verify( null !== $key && abs( WC()->cart->line_total( $key ) - 300.00 ) < 0.001, 'no-deposit booking is priced at the full total' );
create_order( $plugin, 1007 );
$plugin->payment_complete( 1007 );
verify( 1 === (int) $GLOBALS['bookings'][7]['fully_paid'] && 1 === (int) $GLOBALS['bookings'][7]['deposit_paid'], 'full upfront payment marks deposit and balance paid' );
verify( 'full_held' === $GLOBALS['bookings'][7]['escrow_status'], 'full upfront payment holds full escrow' );

// --- 8. Remaining balance charges the remaining amount and marks fully paid. --
fixture_booking( 8, 500.00, 125.00, array( 'deposit_paid' => 1, 'escrow_status' => 'deposit_held' ) );
$key = start_checkout( $plugin, 8, 'remaining' );
calculate_totals();
verify( null !== $key && abs( WC()->cart->line_total( $key ) - 375.00 ) < 0.001, 'remaining balance is priced at 375.00, not the deposit' );
verify( null !== $key && false === ( WC()->cart->get_cart()[ $key ]['pb_is_deposit'] ?? null ), 'remaining balance is not flagged as a deposit' );
create_order( $plugin, 1008 );
$plugin->payment_complete( 1008 );
verify( 1 === (int) $GLOBALS['bookings'][8]['fully_paid'], 'remaining balance marks fully paid' );
verify( 'full_held' === $GLOBALS['bookings'][8]['escrow_status'], 'remaining balance holds full escrow' );

// --- 9. A booking that cannot be priced is blocked at checkout. ---------------
fixture_booking( 9, 0.00, 0.00 );
$GLOBALS['checkout_errors'] = array();
$GLOBALS['wc']              = new SyntheticWooCommerce();
WC()->cart->add_to_cart( 900, 1, 0, array(), array( 'pb_booking_id' => 9, 'pb_payment_type' => 'full', 'pb_is_deposit' => false ) );
calculate_totals();
foreach ( $GLOBALS['hooks']['woocommerce_after_checkout_validation'] ?? array() as $callback ) {
    call_user_func( $callback, array(), new WP_Error() );
}
verify( array() !== $GLOBALS['checkout_errors'], 'an unpriceable booking blocks classic checkout validation' );

// --- 10. Paying a deposit twice (second order) is held, not double-counted. ---
fixture_booking( 10, 500.00, 125.00 );
$key = start_checkout( $plugin, 10 );
calculate_totals();
create_order( $plugin, 1010 );
$key = start_checkout( $plugin, 10 );
calculate_totals();
$second = create_order( $plugin, 1011 );
$plugin->payment_complete( 1010 );
$plugin->payment_complete( 1011 );
verify( 1 === count( completed_transactions( 10 ) ), 'a second deposit order for the same booking is not recorded as paid' );
verify( 'on-hold' === $second->status, 'a second deposit order is held for review' );

foreach ( $failures as $failure ) {
    fwrite( STDERR, "FAIL: $failure\n" );
}
echo $checks . ' checks, ' . count( $failures ) . " failures\n";
exit( $failures ? 1 : 0 );
