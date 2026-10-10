<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce integration.
 *
 * @package Peanut_Booker
 * @since   1.0.0
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * WooCommerce integration class.
 */
class Peanut_Booker_WooCommerce {

    /**
     * Payment types a booking checkout can charge.
     */
    const PAYMENT_DEPOSIT   = 'deposit';
    const PAYMENT_FULL      = 'full';
    const PAYMENT_REMAINING = 'remaining';

    /**
     * Largest difference, in currency units, tolerated between what an order
     * charged and what the booking says is owed (rounding only).
     */
    const AMOUNT_TOLERANCE = 0.01;

    /**
     * Constructor.
     */
    public function __construct() {
        // Register custom order statuses.
        add_action( 'init', array( $this, 'register_order_statuses' ) );
        add_filter( 'wc_order_statuses', array( $this, 'add_order_statuses' ) );

        // Handle booking checkout.
        add_action( 'wp', array( $this, 'handle_booking_checkout' ) );
        add_action( 'woocommerce_checkout_create_order', array( $this, 'add_booking_to_order' ), 10, 2 );
        add_action( 'woocommerce_payment_complete', array( $this, 'payment_complete' ) );

        // Price the booking line from the booking record. The booking product
        // itself is priced at 0, so without this the cart totals $0 and
        // WooCommerce completes the order without taking payment.
        add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_booking_prices' ), 20 );
        add_action( 'woocommerce_check_cart_items', array( $this, 'check_booking_cart_items' ) );
        add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_booking_checkout' ), 10, 2 );

        // Order display.
        add_filter( 'woocommerce_get_order_item_totals', array( $this, 'add_booking_info_to_order' ), 10, 2 );

        // Custom product handling.
        add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_booking_cart_data' ), 10, 2 );
        add_filter( 'woocommerce_get_item_data', array( $this, 'display_booking_cart_data' ), 10, 2 );
        add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_booking_order_item_meta' ), 10, 4 );
    }

    /**
     * Register custom order statuses for escrow.
     */
    public function register_order_statuses() {
        register_post_status(
            'wc-escrow-held',
            array(
                'label'                     => _x( 'Escrow Held', 'Order status', 'peanut-booker' ),
                'public'                    => true,
                'show_in_admin_status_list' => true,
                'show_in_admin_all_list'    => true,
                'exclude_from_search'       => false,
                /* translators: %s: number of orders */
                'label_count'               => _n_noop( 'Escrow Held <span class="count">(%s)</span>', 'Escrow Held <span class="count">(%s)</span>', 'peanut-booker' ),
            )
        );

        register_post_status(
            'wc-event-complete',
            array(
                'label'                     => _x( 'Event Complete', 'Order status', 'peanut-booker' ),
                'public'                    => true,
                'show_in_admin_status_list' => true,
                'show_in_admin_all_list'    => true,
                'exclude_from_search'       => false,
                /* translators: %s: number of orders */
                'label_count'               => _n_noop( 'Event Complete <span class="count">(%s)</span>', 'Event Complete <span class="count">(%s)</span>', 'peanut-booker' ),
            )
        );

        register_post_status(
            'wc-funds-released',
            array(
                'label'                     => _x( 'Funds Released', 'Order status', 'peanut-booker' ),
                'public'                    => true,
                'show_in_admin_status_list' => true,
                'show_in_admin_all_list'    => true,
                'exclude_from_search'       => false,
                /* translators: %s: number of orders */
                'label_count'               => _n_noop( 'Funds Released <span class="count">(%s)</span>', 'Funds Released <span class="count">(%s)</span>', 'peanut-booker' ),
            )
        );
    }

    /**
     * Add custom statuses to WooCommerce.
     *
     * @param array $statuses Existing statuses.
     * @return array Modified statuses.
     */
    public function add_order_statuses( $statuses ) {
        $statuses['wc-escrow-held']    = _x( 'Escrow Held', 'Order status', 'peanut-booker' );
        $statuses['wc-event-complete'] = _x( 'Event Complete', 'Order status', 'peanut-booker' );
        $statuses['wc-funds-released'] = _x( 'Funds Released', 'Order status', 'peanut-booker' );

        return $statuses;
    }

    /**
     * Handle booking checkout requests.
     */
    public function handle_booking_checkout() {
        if ( ! isset( $_GET['pb_booking'] ) || ! isset( $_GET['action'] ) ) {
            return;
        }

        if ( 'checkout' !== $_GET['action'] ) {
            return;
        }

        // SECURITY: Verify nonce to prevent CSRF attacks.
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'pb_checkout_booking' ) ) {
            wc_add_notice( __( 'Security verification failed. Please try again.', 'peanut-booker' ), 'error' );
            return;
        }

        // SECURITY: Require user to be logged in.
        if ( ! is_user_logged_in() ) {
            wc_add_notice( __( 'You must be logged in to checkout.', 'peanut-booker' ), 'error' );
            wp_safe_redirect( wp_login_url( add_query_arg( array() ) ) );
            exit;
        }

        $booking_id = absint( $_GET['pb_booking'] );

        // SECURITY: Validate booking ID is a positive integer.
        if ( $booking_id <= 0 ) {
            wc_add_notice( __( 'Invalid booking ID.', 'peanut-booker' ), 'error' );
            return;
        }

        $booking = Peanut_Booker_Booking::get( $booking_id );

        if ( ! $booking ) {
            wc_add_notice( __( 'Invalid booking.', 'peanut-booker' ), 'error' );
            return;
        }

        // SECURITY: Verify customer owns this booking.
        if ( (int) $booking->customer_id !== get_current_user_id() ) {
            // Log potential unauthorized access attempt.
            error_log( sprintf(
                'Peanut Booker SECURITY: Unauthorized checkout attempt. Booking ID: %d, Owner: %d, Attempted by: %d, IP: %s',
                $booking_id,
                $booking->customer_id,
                get_current_user_id(),
                sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) )
            ) );
            wc_add_notice( __( 'Not authorized.', 'peanut-booker' ), 'error' );
            return;
        }

        // SECURITY: Verify booking is in a valid status for checkout.
        $valid_checkout_statuses = array(
            Peanut_Booker_Booking::STATUS_PENDING,
            Peanut_Booker_Booking::STATUS_CONFIRMED,
        );
        if ( ! in_array( $booking->status, $valid_checkout_statuses, true ) ) {
            wc_add_notice(
                sprintf(
                    /* translators: %s: booking status */
                    __( 'This booking cannot be checked out. Current status: %s', 'peanut-booker' ),
                    esc_html( $booking->status )
                ),
                'error'
            );
            return;
        }

        // SECURITY: Determine if this is a deposit or remaining balance payment.
        $is_remaining_payment = isset( $_GET['payment'] ) && 'remaining' === $_GET['payment'];

        if ( $is_remaining_payment ) {
            // Paying remaining balance - must have deposit already paid.
            if ( ! $booking->deposit_paid ) {
                wc_add_notice( __( 'Deposit must be paid before remaining balance.', 'peanut-booker' ), 'error' );
                return;
            }
            if ( $booking->fully_paid ) {
                wc_add_notice( __( 'This booking has already been fully paid.', 'peanut-booker' ), 'error' );
                return;
            }
        } else {
            // Paying deposit - must not already be paid.
            if ( $booking->deposit_paid ) {
                wc_add_notice( __( 'Deposit for this booking has already been paid.', 'peanut-booker' ), 'error' );
                return;
            }
        }

        // The amount and payment type come from the stored booking only.
        $payment_type = self::resolve_payment_type( $booking, $is_remaining_payment );
        $charge       = self::get_expected_charge( $booking, $payment_type );
        if ( is_wp_error( $charge ) ) {
            wc_add_notice( $charge->get_error_message(), 'error' );
            return;
        }

        // Clear cart and add booking product.
        WC()->cart->empty_cart();

        // Get or create booking product.
        $product_id = $this->get_booking_product_id();

        // Add to cart with booking data.
        WC()->cart->add_to_cart(
            $product_id,
            1,
            0,
            array(),
            array(
                'pb_booking_id'     => $booking_id,
                'pb_booking_number' => $booking->booking_number,
                'pb_event_title'    => $booking->event_title,
                'pb_event_date'     => $booking->event_date,
                // Display only. The price charged is re-read from the booking
                // in apply_booking_prices() and verified in payment_complete().
                'pb_total_amount'   => $charge['amount'],
                'pb_payment_type'   => $charge['type'],
                'pb_is_deposit'     => $charge['is_deposit'],
            )
        );

        wp_safe_redirect( wc_get_checkout_url() );
        exit;
    }

    /**
     * Get or create the booking product.
     *
     * @return int Product ID.
     */
    private function get_booking_product_id() {
        $product_id = get_option( 'peanut_booker_booking_product' );

        if ( $product_id && wc_get_product( $product_id ) ) {
            return $product_id;
        }

        // Create product.
        $product = new WC_Product_Simple();
        $product->set_name( __( 'Performer Booking', 'peanut-booker' ) );
        $product->set_status( 'publish' );
        $product->set_catalog_visibility( 'hidden' );
        $product->set_price( 0 );
        $product->set_regular_price( 0 );
        $product->set_virtual( true );
        $product->set_sold_individually( true );
        $product->update_meta_data( '_pb_booking_product', 'yes' );
        $product->save();

        $product_id = $product->get_id();
        update_option( 'peanut_booker_booking_product', $product_id );

        return $product_id;
    }

    /**
     * Add booking data to cart item.
     *
     * @param array $cart_item_data Cart item data.
     * @param int   $product_id     Product ID.
     * @return array Modified cart item data.
     */
    public function add_booking_cart_data( $cart_item_data, $product_id ) {
        $booking_product_id = get_option( 'peanut_booker_booking_product' );

        if ( $product_id != $booking_product_id ) {
            return $cart_item_data;
        }

        // Data is already added in handle_booking_checkout.
        return $cart_item_data;
    }

    /**
     * Display booking info in cart.
     *
     * @param array $item_data Existing item data.
     * @param array $cart_item Cart item.
     * @return array Modified item data.
     */
    public function display_booking_cart_data( $item_data, $cart_item ) {
        if ( ! isset( $cart_item['pb_booking_id'] ) ) {
            return $item_data;
        }

        $item_data[] = array(
            'key'   => __( 'Booking', 'peanut-booker' ),
            'value' => '#' . $cart_item['pb_booking_number'],
        );

        $item_data[] = array(
            'key'   => __( 'Event', 'peanut-booker' ),
            'value' => $cart_item['pb_event_title'],
        );

        $item_data[] = array(
            'key'   => __( 'Date', 'peanut-booker' ),
            'value' => date_i18n( get_option( 'date_format' ), strtotime( $cart_item['pb_event_date'] ) ),
        );

        $payment_type = self::cart_item_payment_type( $cart_item );
        if ( self::PAYMENT_DEPOSIT === $payment_type ) {
            $item_data[] = array(
                'key'   => __( 'Payment Type', 'peanut-booker' ),
                'value' => __( 'Deposit', 'peanut-booker' ),
            );
        } elseif ( self::PAYMENT_REMAINING === $payment_type ) {
            $item_data[] = array(
                'key'   => __( 'Payment Type', 'peanut-booker' ),
                'value' => __( 'Remaining balance', 'peanut-booker' ),
            );
        }

        return $item_data;
    }

    /**
     * Save booking meta to order item.
     *
     * @param WC_Order_Item_Product $item          Order item.
     * @param string                $cart_item_key Cart item key.
     * @param array                 $values        Cart item values.
     * @param WC_Order              $order         Order object.
     */
    public function save_booking_order_item_meta( $item, $cart_item_key, $values, $order ) {
        if ( ! isset( $values['pb_booking_id'] ) ) {
            return;
        }

        $item->add_meta_data( '_pb_booking_id', $values['pb_booking_id'] );
        $item->add_meta_data( '_pb_booking_number', $values['pb_booking_number'] );
        $item->add_meta_data( '_pb_event_title', $values['pb_event_title'] );
        $item->add_meta_data( '_pb_event_date', $values['pb_event_date'] );
        $item->add_meta_data( '_pb_is_deposit', ! empty( $values['pb_is_deposit'] ) );
        $item->add_meta_data( '_pb_payment_type', self::cart_item_payment_type( $values ) );
    }

    /**
     * Add booking to order on checkout.
     *
     * @param WC_Order $order Order object.
     * @param array    $data  Checkout data.
     */
    public function add_booking_to_order( $order, $data ) {
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            if ( isset( $cart_item['pb_booking_id'] ) ) {
                $order->add_meta_data( '_pb_booking_id', absint( $cart_item['pb_booking_id'] ) );
                $order->add_meta_data( '_pb_is_deposit', ! empty( $cart_item['pb_is_deposit'] ) );
                $order->add_meta_data( '_pb_payment_type', self::cart_item_payment_type( $cart_item ) );

                // The order total is NOT overwritten here. WooCommerce has
                // already totalled the cart (priced in apply_booking_prices())
                // and decided from that total whether payment is needed;
                // rewriting the total afterwards only disguised a $0 order.
            }
        }
    }

    /**
     * Handle successful payment.
     *
     * WooCommerce fires this for every completed order, including orders it
     * completed WITHOUT taking payment because the cart totalled $0
     * (WC_Checkout::process_order_without_payment()). A booking is therefore
     * only marked paid when the order actually charged the amount the stored
     * booking says is owed; anything else is held for review.
     *
     * @param int $order_id Order ID.
     */
    public function payment_complete( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $booking_id = absint( $order->get_meta( '_pb_booking_id' ) );
        if ( ! $booking_id ) {
            return;
        }

        // Idempotent: a payment is applied to a booking at most once.
        if ( $order->get_meta( '_pb_payment_recorded' ) || $order->get_meta( '_pb_payment_review' ) ) {
            return;
        }

        $booking = Peanut_Booker_Booking::get( $booking_id );
        if ( ! $booking ) {
            return;
        }

        $payment_type = self::order_payment_type( $order );
        $verified     = self::verify_order_payment( $order, $booking, $payment_type );

        if ( is_wp_error( $verified ) ) {
            self::flag_payment_for_review( $order, $booking, $payment_type, $verified );
            return;
        }

        $is_deposit  = self::PAYMENT_DEPOSIT === $payment_type;
        $update_data = array(
            'order_id' => $order->get_id(),
        );

        if ( self::PAYMENT_DEPOSIT === $payment_type ) {
            $update_data['deposit_paid']  = 1;
            $update_data['escrow_status'] = Peanut_Booker_Booking::ESCROW_DEPOSIT;
        } elseif ( self::PAYMENT_FULL === $payment_type ) {
            // Paid in full up front: the deposit is covered as well.
            $update_data['deposit_paid']  = 1;
            $update_data['fully_paid']    = 1;
            $update_data['escrow_status'] = Peanut_Booker_Booking::ESCROW_FULL;
        } else {
            $update_data['fully_paid']    = 1;
            $update_data['escrow_status'] = Peanut_Booker_Booking::ESCROW_FULL;
        }

        Peanut_Booker_Booking::update( $booking_id, $update_data );

        $order->update_meta_data( '_pb_payment_recorded', 1 );
        $order->save();

        $transaction_types = array(
            self::PAYMENT_DEPOSIT   => 'deposit',
            self::PAYMENT_FULL      => 'full_payment',
            self::PAYMENT_REMAINING => 'remaining_payment',
        );

        // Record transaction.
        Peanut_Booker_Database::insert(
            'transactions',
            array(
                'booking_id'       => $booking_id,
                'order_id'         => $order->get_id(),
                'transaction_type' => $transaction_types[ $payment_type ],
                'amount'           => $verified['amount'],
                'payment_method'   => $order->get_payment_method(),
                'payment_id'       => $order->get_transaction_id(),
                'payer_id'         => $booking->customer_id,
                'status'           => 'completed',
            )
        );

        // Update order status.
        $order->update_status( 'escrow-held', __( 'Payment received, held in escrow.', 'peanut-booker' ) );

        // If performer already confirmed and deposit paid, confirm booking.
        if ( $booking->performer_confirmed && ! empty( $update_data['deposit_paid'] ) ) {
            Peanut_Booker_Booking::update_status( $booking_id, Peanut_Booker_Booking::STATUS_CONFIRMED );
        }

        do_action( 'peanut_booker_payment_received', $booking_id, $order->get_id(), $is_deposit );
    }

    /**
     * Pick the payment type for a booking checkout from the booking itself.
     *
     * @param object $booking              Booking row.
     * @param bool   $is_remaining_request Whether the customer asked to pay the balance.
     * @return string One of the PAYMENT_* constants.
     */
    public static function resolve_payment_type( $booking, $is_remaining_request ) {
        if ( $is_remaining_request ) {
            return self::PAYMENT_REMAINING;
        }

        $total   = self::to_cents( $booking->total_amount );
        $deposit = self::to_cents( $booking->deposit_amount );

        // A deposit only exists when it is a real part-payment. A booking with
        // no deposit (0%) or a 100% deposit is charged in full.
        if ( $deposit > 0 && $deposit < $total ) {
            return self::PAYMENT_DEPOSIT;
        }

        return self::PAYMENT_FULL;
    }

    /**
     * Amount owed for a payment type, from the stored booking only.
     *
     * Also checks the booking is in a state where that payment is still due,
     * so a second order for an already-paid deposit cannot be recorded again.
     *
     * @param object $booking      Booking row.
     * @param string $payment_type One of the PAYMENT_* constants.
     * @return array|WP_Error {type, amount, is_deposit} or error.
     */
    public static function get_expected_charge( $booking, $payment_type ) {
        $deposit_paid = ! empty( $booking->deposit_paid );
        $fully_paid   = ! empty( $booking->fully_paid );

        switch ( $payment_type ) {
            case self::PAYMENT_DEPOSIT:
                if ( $deposit_paid || $fully_paid ) {
                    return new WP_Error( 'already_paid', __( 'Deposit for this booking has already been paid.', 'peanut-booker' ) );
                }
                if ( self::PAYMENT_DEPOSIT !== self::resolve_payment_type( $booking, false ) ) {
                    return new WP_Error( 'no_deposit', __( 'This booking does not take a deposit.', 'peanut-booker' ) );
                }
                $cents = self::to_cents( $booking->deposit_amount );
                break;

            case self::PAYMENT_FULL:
                if ( $deposit_paid || $fully_paid ) {
                    return new WP_Error( 'already_paid', __( 'This booking has already been paid.', 'peanut-booker' ) );
                }
                $cents = self::to_cents( $booking->total_amount );
                break;

            case self::PAYMENT_REMAINING:
                if ( ! $deposit_paid ) {
                    return new WP_Error( 'deposit_unpaid', __( 'Deposit must be paid before remaining balance.', 'peanut-booker' ) );
                }
                if ( $fully_paid ) {
                    return new WP_Error( 'already_paid', __( 'This booking has already been fully paid.', 'peanut-booker' ) );
                }
                $cents = self::to_cents( $booking->remaining_amount );
                break;

            default:
                return new WP_Error( 'invalid_payment_type', __( 'Invalid booking payment type.', 'peanut-booker' ) );
        }

        if ( $cents <= 0 ) {
            return new WP_Error( 'no_price', __( 'This booking has no price set and cannot be paid yet.', 'peanut-booker' ) );
        }

        return array(
            'type'       => $payment_type,
            'amount'     => $cents / 100,
            'is_deposit' => self::PAYMENT_DEPOSIT === $payment_type,
        );
    }

    /**
     * Check that an order charged exactly what the booking owes.
     *
     * Compares the booking line item (what the cart priced, before tax) and
     * the order total net of tax against the expected amount. Both must match
     * to the cent, and the order must be for more than zero.
     *
     * @param WC_Order $order        Order.
     * @param object   $booking      Booking row.
     * @param string   $payment_type One of the PAYMENT_* constants.
     * @return array|WP_Error Expected charge on success, error otherwise.
     */
    public static function verify_order_payment( $order, $booking, $payment_type ) {
        $expected = self::get_expected_charge( $booking, $payment_type );
        if ( is_wp_error( $expected ) ) {
            return $expected;
        }

        $order_total = (float) $order->get_total();
        if ( $order_total <= 0 ) {
            return new WP_Error( 'zero_total', __( 'The order total is zero; no payment was collected.', 'peanut-booker' ) );
        }

        $line_total = null;
        $lines      = 0;
        foreach ( $order->get_items() as $item ) {
            if ( absint( $item->get_meta( '_pb_booking_id' ) ) !== absint( $booking->id ) ) {
                continue;
            }
            ++$lines;
            $line_total = (float) $item->get_total();
        }

        if ( 1 !== $lines ) {
            return new WP_Error( 'line_mismatch', __( 'The order does not contain exactly one line for this booking.', 'peanut-booker' ) );
        }

        if ( ! self::amounts_match( $line_total, $expected['amount'] ) ) {
            return new WP_Error(
                'amount_mismatch',
                sprintf(
                    /* translators: 1: amount charged, 2: amount owed */
                    __( 'The booking line charged %1$s but %2$s is owed.', 'peanut-booker' ),
                    number_format( $line_total, 2, '.', '' ),
                    number_format( $expected['amount'], 2, '.', '' )
                )
            );
        }

        $net_total = $order_total - (float) $order->get_total_tax();
        if ( ! self::amounts_match( $net_total, $expected['amount'] ) ) {
            return new WP_Error(
                'amount_mismatch',
                sprintf(
                    /* translators: 1: order total before tax, 2: amount owed */
                    __( 'The order total before tax is %1$s but %2$s is owed.', 'peanut-booker' ),
                    number_format( $net_total, 2, '.', '' ),
                    number_format( $expected['amount'], 2, '.', '' )
                )
            );
        }

        return $expected;
    }

    /**
     * Hold an order whose payment does not match the booking, and record why.
     *
     * The booking is not marked paid and its escrow state is not changed. A
     * needs_review transaction row ties the problem to the booking for staff.
     *
     * @param WC_Order $order        Order.
     * @param object   $booking      Booking row.
     * @param string   $payment_type One of the PAYMENT_* constants.
     * @param WP_Error $error        Why verification failed.
     */
    private static function flag_payment_for_review( $order, $booking, $payment_type, $error ) {
        $reason = $error->get_error_code();

        $order->update_meta_data( '_pb_payment_review', $reason );
        $order->save();

        Peanut_Booker_Database::insert(
            'transactions',
            array(
                'booking_id'       => absint( $booking->id ),
                'order_id'         => $order->get_id(),
                'transaction_type' => $payment_type,
                'amount'           => (float) $order->get_total(),
                'payment_method'   => $order->get_payment_method(),
                'payment_id'       => $order->get_transaction_id(),
                'payer_id'         => $booking->customer_id,
                'status'           => 'needs_review',
                'notes'            => $error->get_error_message(),
            )
        );

        $order->update_status(
            'on-hold',
            sprintf(
                /* translators: %s: reason */
                __( 'Peanut Booker: payment not applied to the booking and held for review. %s', 'peanut-booker' ),
                $error->get_error_message()
            )
        );

        error_log(
            sprintf(
                'Peanut Booker PAYMENT REVIEW: order %d for booking %d (%s) not marked paid: %s',
                $order->get_id(),
                absint( $booking->id ),
                $payment_type,
                $reason
            )
        );

        do_action( 'peanut_booker_payment_review_required', absint( $booking->id ), $order->get_id(), $reason );
    }

    /**
     * Price booking lines from the stored booking before WooCommerce totals.
     *
     * @param WC_Cart $cart Cart.
     */
    public function apply_booking_prices( $cart ) {
        if ( is_admin() && ! wp_doing_ajax() ) {
            return;
        }

        foreach ( $cart->get_cart() as $cart_item ) {
            if ( empty( $cart_item['pb_booking_id'] ) || ! isset( $cart_item['data'] ) ) {
                continue;
            }

            $charge = self::get_cart_item_charge( $cart_item );

            // An unpriceable booking stays at 0 here and is refused by
            // check_booking_cart_items() / validate_booking_checkout(); the
            // payment-complete check is the final backstop.
            $cart_item['data']->set_price( is_wp_error( $charge ) ? 0 : $charge['amount'] );
        }
    }

    /**
     * Show an error for booking lines that cannot be charged (cart/checkout page).
     */
    public function check_booking_cart_items() {
        foreach ( self::get_booking_cart_errors( WC()->cart ) as $message ) {
            wc_add_notice( $message, 'error' );
        }
    }

    /**
     * Refuse classic checkout when a booking line cannot be charged.
     *
     * @param array    $data   Posted checkout data.
     * @param WP_Error $errors Checkout errors.
     */
    public function validate_booking_checkout( $data, $errors ) {
        foreach ( self::get_booking_cart_errors( WC()->cart ) as $message ) {
            $errors->add( 'pb_booking_price', $message );
        }
    }

    /**
     * Messages for booking cart lines that cannot be charged.
     *
     * @param WC_Cart $cart Cart.
     * @return string[] Error messages.
     */
    private static function get_booking_cart_errors( $cart ) {
        $errors = array();
        if ( ! $cart ) {
            return $errors;
        }

        foreach ( $cart->get_cart() as $cart_item ) {
            if ( empty( $cart_item['pb_booking_id'] ) ) {
                continue;
            }

            $charge = self::get_cart_item_charge( $cart_item );
            if ( is_wp_error( $charge ) ) {
                $errors[] = $charge->get_error_message();
            }
        }

        return $errors;
    }

    /**
     * Expected charge for a booking cart line, re-read from the database.
     *
     * @param array $cart_item Cart item.
     * @return array|WP_Error
     */
    private static function get_cart_item_charge( $cart_item ) {
        $booking = Peanut_Booker_Booking::get( absint( $cart_item['pb_booking_id'] ) );
        if ( ! $booking ) {
            return new WP_Error( 'invalid_booking', __( 'Invalid booking.', 'peanut-booker' ) );
        }

        // Only the booking's own customer can pay for it.
        if ( (int) $booking->customer_id !== get_current_user_id() ) {
            return new WP_Error( 'not_authorized', __( 'Not authorized.', 'peanut-booker' ) );
        }

        return self::get_expected_charge( $booking, self::cart_item_payment_type( $cart_item ) );
    }

    /**
     * Payment type stored on a cart line (lines from before this field existed
     * fall back to their deposit flag).
     *
     * @param array $cart_item Cart item.
     * @return string
     */
    private static function cart_item_payment_type( $cart_item ) {
        $type = isset( $cart_item['pb_payment_type'] ) ? (string) $cart_item['pb_payment_type'] : '';
        if ( in_array( $type, array( self::PAYMENT_DEPOSIT, self::PAYMENT_FULL, self::PAYMENT_REMAINING ), true ) ) {
            return $type;
        }

        return ! empty( $cart_item['pb_is_deposit'] ) ? self::PAYMENT_DEPOSIT : self::PAYMENT_FULL;
    }

    /**
     * Payment type stored on an order (orders from before this field existed
     * fall back to their deposit flag).
     *
     * @param WC_Order $order Order.
     * @return string
     */
    private static function order_payment_type( $order ) {
        return self::cart_item_payment_type(
            array(
                'pb_payment_type' => $order->get_meta( '_pb_payment_type' ),
                'pb_is_deposit'   => $order->get_meta( '_pb_is_deposit' ),
            )
        );
    }

    /**
     * Convert a money value to integer cents.
     *
     * @param mixed $amount Amount.
     * @return int
     */
    private static function to_cents( $amount ) {
        return (int) round( (float) $amount * 100 );
    }

    /**
     * Whether two money values agree within AMOUNT_TOLERANCE.
     *
     * @param float $actual   Charged amount.
     * @param float $expected Owed amount.
     * @return bool
     */
    private static function amounts_match( $actual, $expected ) {
        return abs( self::to_cents( $actual ) - self::to_cents( $expected ) ) <= (int) round( self::AMOUNT_TOLERANCE * 100 );
    }

    /**
     * Add booking info to order totals display.
     *
     * @param array    $total_rows Order total rows.
     * @param WC_Order $order      Order object.
     * @return array Modified total rows.
     */
    public function add_booking_info_to_order( $total_rows, $order ) {
        $booking_id = $order->get_meta( '_pb_booking_id' );

        if ( ! $booking_id ) {
            return $total_rows;
        }

        $booking = Peanut_Booker_Booking::get( $booking_id );
        if ( ! $booking ) {
            return $total_rows;
        }

        // Add booking info at the beginning.
        $booking_rows = array(
            'booking_number' => array(
                'label' => __( 'Booking Number:', 'peanut-booker' ),
                'value' => $booking->booking_number,
            ),
            'event_title'    => array(
                'label' => __( 'Event:', 'peanut-booker' ),
                'value' => $booking->event_title,
            ),
            'event_date'     => array(
                'label' => __( 'Event Date:', 'peanut-booker' ),
                'value' => date_i18n( get_option( 'date_format' ), strtotime( $booking->event_date ) ),
            ),
        );

        return array_merge( $booking_rows, $total_rows );
    }

    /**
     * Get checkout URL for a booking deposit.
     *
     * @param int $booking_id Booking ID.
     * @return string Checkout URL with nonce.
     */
    public static function get_checkout_url( $booking_id ) {
        $booking = Peanut_Booker_Booking::get( $booking_id );

        if ( ! $booking ) {
            return '';
        }

        // Don't generate URL if deposit already paid.
        if ( $booking->deposit_paid ) {
            return '';
        }

        // SECURITY: Include nonce for CSRF protection.
        return add_query_arg(
            array(
                'pb_booking' => $booking_id,
                'action'     => 'checkout',
                '_wpnonce'   => wp_create_nonce( 'pb_checkout_booking' ),
            ),
            wc_get_checkout_url()
        );
    }

    /**
     * Process remaining balance payment.
     *
     * @param int $booking_id Booking ID.
     * @return string Checkout URL.
     */
    public static function get_remaining_balance_checkout_url( $booking_id ) {
        $booking = Peanut_Booker_Booking::get( $booking_id );

        if ( ! $booking || ! $booking->deposit_paid || $booking->fully_paid ) {
            return '';
        }

        // SECURITY: Include nonce for CSRF protection.
        return add_query_arg(
            array(
                'pb_booking' => $booking_id,
                'action'     => 'checkout',
                'payment'    => 'remaining',
                '_wpnonce'   => wp_create_nonce( 'pb_checkout_booking' ),
            ),
            wc_get_checkout_url()
        );
    }
}
