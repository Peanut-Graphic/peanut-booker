<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Subscriptions and Pro membership functionality.
 *
 * @package Peanut_Booker
 * @since   1.0.0
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Subscriptions class.
 */
class Peanut_Booker_Subscriptions {

    /**
     * Plan types.
     */
    const PLAN_MONTHLY = 'monthly';
    const PLAN_ANNUAL  = 'annual';

    /**
     * Subscription tiers.
     */
    const TIER_FREE     = 'free';
    const TIER_PRO      = 'pro';
    const TIER_FEATURED = 'featured';

    /**
     * Subscription statuses.
     */
    const STATUS_ACTIVE    = 'active';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_EXPIRED   = 'expired';
    const STATUS_PENDING   = 'pending';

    /**
     * Default pricing configuration.
     */
    const DEFAULT_PRICING = array(
        'pro_monthly_price'      => 19.00,
        'pro_annual_price'       => 149.00,
        'featured_monthly_price' => 39.00,
        'featured_annual_price'  => 299.00,
        'commission_free_tier'   => 20,
        'commission_pro_tier'    => 12,
        'commission_featured_tier' => 8,
    );

    /**
     * Constructor.
     */
    public function __construct() {
        // WooCommerce Subscriptions hooks.
        add_action( 'woocommerce_subscription_status_active', array( $this, 'subscription_activated' ) );
        add_action( 'woocommerce_subscription_status_cancelled', array( $this, 'subscription_cancelled' ) );
        add_action( 'woocommerce_subscription_status_expired', array( $this, 'subscription_expired' ) );
        add_action( 'woocommerce_subscription_status_on-hold', array( $this, 'subscription_on_hold' ) );

        // Create subscription products on activation.
        add_action( 'peanut_booker_activated', array( $this, 'create_subscription_products' ) );

        // AJAX handlers.
        add_action( 'wp_ajax_pb_get_subscription_status', array( $this, 'ajax_get_status' ) );
    }

    /**
     * Create WooCommerce subscription products for Pro and Featured memberships.
     */
    public function create_subscription_products() {
        if ( ! class_exists( 'WC_Product_Subscription' ) ) {
            return;
        }

        $options = get_option( 'peanut_booker_settings', array() );

        // Pro tier products.
        $pro_monthly_id = get_option( 'peanut_booker_pro_monthly_product' );
        $pro_annual_id  = get_option( 'peanut_booker_pro_annual_product' );

        if ( ! $pro_monthly_id || ! wc_get_product( $pro_monthly_id ) ) {
            $pro_monthly_id = $this->create_subscription_product(
                __( 'Peanut Booker Pro - Monthly', 'peanut-booker' ),
                $options['pro_monthly_price'] ?? self::DEFAULT_PRICING['pro_monthly_price'],
                'month',
                self::TIER_PRO
            );
            update_option( 'peanut_booker_pro_monthly_product', $pro_monthly_id );
        }

        if ( ! $pro_annual_id || ! wc_get_product( $pro_annual_id ) ) {
            $pro_annual_id = $this->create_subscription_product(
                __( 'Peanut Booker Pro - Annual', 'peanut-booker' ),
                $options['pro_annual_price'] ?? self::DEFAULT_PRICING['pro_annual_price'],
                'year',
                self::TIER_PRO
            );
            update_option( 'peanut_booker_pro_annual_product', $pro_annual_id );
        }

        // Featured tier products.
        $featured_monthly_id = get_option( 'peanut_booker_featured_monthly_product' );
        $featured_annual_id  = get_option( 'peanut_booker_featured_annual_product' );

        if ( ! $featured_monthly_id || ! wc_get_product( $featured_monthly_id ) ) {
            $featured_monthly_id = $this->create_subscription_product(
                __( 'Peanut Booker Featured - Monthly', 'peanut-booker' ),
                $options['featured_monthly_price'] ?? self::DEFAULT_PRICING['featured_monthly_price'],
                'month',
                self::TIER_FEATURED
            );
            update_option( 'peanut_booker_featured_monthly_product', $featured_monthly_id );
        }

        if ( ! $featured_annual_id || ! wc_get_product( $featured_annual_id ) ) {
            $featured_annual_id = $this->create_subscription_product(
                __( 'Peanut Booker Featured - Annual', 'peanut-booker' ),
                $options['featured_annual_price'] ?? self::DEFAULT_PRICING['featured_annual_price'],
                'year',
                self::TIER_FEATURED
            );
            update_option( 'peanut_booker_featured_annual_product', $featured_annual_id );
        }
    }

    /**
     * Create a subscription product.
     *
     * @param string $name   Product name.
     * @param float  $price  Product price.
     * @param string $period Subscription period (month/year).
     * @param string $tier   Subscription tier (pro/featured).
     * @return int Product ID.
     */
    private function create_subscription_product( $name, $price, $period, $tier = self::TIER_PRO ) {
        $product = new WC_Product_Subscription();

        $product->set_name( $name );
        $product->set_status( 'publish' );
        $product->set_catalog_visibility( 'hidden' );
        $product->set_regular_price( $price );
        $product->set_virtual( true );

        // Set subscription meta.
        $product->update_meta_data( '_subscription_price', $price );
        $product->update_meta_data( '_subscription_period', $period );
        $product->update_meta_data( '_subscription_period_interval', 1 );
        $product->update_meta_data( '_subscription_length', 0 );
        $product->update_meta_data( '_subscription_sign_up_fee', 0 );
        $product->update_meta_data( '_subscription_trial_period', '' );
        $product->update_meta_data( '_subscription_trial_length', 0 );

        // Mark as Peanut Booker product with tier.
        $product->update_meta_data( '_pb_subscription_product', 'yes' );
        $product->update_meta_data( '_pb_subscription_tier', $tier );

        $product->save();

        return $product->get_id();
    }

    /**
     * Handle subscription activation.
     *
     * @param WC_Subscription $subscription WooCommerce subscription object.
     */
    public function subscription_activated( $subscription ) {
        $tier = $this->get_subscription_tier( $subscription );
        if ( ! $tier ) {
            return;
        }

        $user_id = $subscription->get_user_id();

        // Grant tier capabilities.
        Peanut_Booker_Roles::grant_tier_capabilities( $user_id, $tier );

        // Record subscription.
        $plan_type = $this->get_subscription_plan_type( $subscription );

        // Check if record exists.
        $existing = Peanut_Booker_Database::get_row(
            'subscriptions',
            array( 'user_id' => $user_id )
        );

        // Note: $tier (free/pro) is applied to the user's capabilities above via
        // Peanut_Booker_Roles::grant_tier_capabilities(). The pb_subscriptions
        // table has no `tier` column (only `plan_type` = monthly/annual), so we
        // do NOT write $tier here — doing so silently dropped the value and
        // constituted schema drift.
        $subscription_data = array(
            'wc_subscription_id' => $subscription->get_id(),
            'plan_type'          => $plan_type,
            'status'             => self::STATUS_ACTIVE,
            'start_date'         => current_time( 'mysql' ),
            'next_billing_date'  => $subscription->get_date( 'next_payment' ),
            'amount'             => $subscription->get_total(),
        );

        if ( $existing ) {
            Peanut_Booker_Database::update( 'subscriptions', $subscription_data, array( 'id' => $existing->id ) );
        } else {
            $subscription_data['user_id'] = $user_id;
            Peanut_Booker_Database::insert( 'subscriptions', $subscription_data );
        }

        do_action( 'peanut_booker_subscription_activated', $user_id, $subscription );
    }

    /**
     * Handle subscription cancellation.
     *
     * @param WC_Subscription $subscription WooCommerce subscription object.
     */
    public function subscription_cancelled( $subscription ) {
        $tier = $this->get_subscription_tier( $subscription );
        if ( ! $tier ) {
            return;
        }

        $user_id = $subscription->get_user_id();

        // Revoke tier capabilities.
        Peanut_Booker_Roles::revoke_tier_capabilities( $user_id );

        // Update record.
        Peanut_Booker_Database::update(
            'subscriptions',
            array(
                'status'   => self::STATUS_CANCELLED,
                'end_date' => current_time( 'mysql' ),
            ),
            array( 'user_id' => $user_id )
        );

        do_action( 'peanut_booker_subscription_cancelled', $user_id, $subscription );
    }

    /**
     * Handle subscription expiration.
     *
     * @param WC_Subscription $subscription WooCommerce subscription object.
     */
    public function subscription_expired( $subscription ) {
        $tier = $this->get_subscription_tier( $subscription );
        if ( ! $tier ) {
            return;
        }

        $user_id = $subscription->get_user_id();

        // Revoke tier capabilities.
        Peanut_Booker_Roles::revoke_tier_capabilities( $user_id );

        // Update record.
        Peanut_Booker_Database::update(
            'subscriptions',
            array(
                'status'   => self::STATUS_EXPIRED,
                'end_date' => current_time( 'mysql' ),
            ),
            array( 'user_id' => $user_id )
        );

        do_action( 'peanut_booker_subscription_expired', $user_id, $subscription );
    }

    /**
     * Handle subscription on hold.
     *
     * @param WC_Subscription $subscription WooCommerce subscription object.
     */
    public function subscription_on_hold( $subscription ) {
        $tier = $this->get_subscription_tier( $subscription );
        if ( ! $tier ) {
            return;
        }

        $user_id = $subscription->get_user_id();

        // Temporarily revoke tier capabilities.
        Peanut_Booker_Roles::revoke_tier_capabilities( $user_id );

        do_action( 'peanut_booker_subscription_on_hold', $user_id, $subscription );
    }

    /**
     * Get the subscription tier from a WooCommerce subscription.
     *
     * @param WC_Subscription $subscription Subscription object.
     * @return string|false Tier name or false if not a PB subscription.
     */
    private function get_subscription_tier( $subscription ) {
        foreach ( $subscription->get_items() as $item ) {
            $product = $item->get_product();
            if ( $product && 'yes' === $product->get_meta( '_pb_subscription_product' ) ) {
                $tier = $product->get_meta( '_pb_subscription_tier' );
                return $tier ? $tier : self::TIER_PRO; // Default to pro for legacy products.
            }
        }
        return false;
    }

    /**
     * Get subscription plan type.
     *
     * @param WC_Subscription $subscription Subscription object.
     * @return string Plan type.
     */
    private function get_subscription_plan_type( $subscription ) {
        $monthly_id = get_option( 'peanut_booker_pro_monthly_product' );

        foreach ( $subscription->get_items() as $item ) {
            $product_id = $item->get_product_id();
            if ( $product_id == $monthly_id ) {
                return self::PLAN_MONTHLY;
            }
        }

        return self::PLAN_ANNUAL;
    }

    /**
     * Get user's subscription status.
     *
     * @param int $user_id User ID.
     * @return array Subscription status data.
     */
    public static function get_user_subscription( $user_id ) {
        $subscription = Peanut_Booker_Database::get_row(
            'subscriptions',
            array( 'user_id' => $user_id )
        );

        if ( ! $subscription ) {
            return array(
                'has_subscription' => false,
                'is_pro'           => false,
                'status'           => null,
                'plan_type'        => null,
                'expires'          => null,
            );
        }

        return array(
            'has_subscription'  => true,
            'is_pro'            => self::STATUS_ACTIVE === $subscription->status,
            'status'            => $subscription->status,
            'status_label'      => self::get_status_label( $subscription->status ),
            'plan_type'         => $subscription->plan_type,
            'plan_label'        => self::get_plan_label( $subscription->plan_type ),
            'start_date'        => $subscription->start_date,
            'end_date'          => $subscription->end_date,
            'next_billing_date' => $subscription->next_billing_date,
            'amount'            => floatval( $subscription->amount ),
        );
    }

    /**
     * Get status label.
     *
     * @param string $status Status.
     * @return string Label.
     */
    public static function get_status_label( $status ) {
        $labels = array(
            self::STATUS_ACTIVE    => __( 'Active', 'peanut-booker' ),
            self::STATUS_CANCELLED => __( 'Cancelled', 'peanut-booker' ),
            self::STATUS_EXPIRED   => __( 'Expired', 'peanut-booker' ),
            self::STATUS_PENDING   => __( 'Pending', 'peanut-booker' ),
        );

        return $labels[ $status ] ?? $status;
    }

    /**
     * Get plan label.
     *
     * @param string $plan Plan type.
     * @return string Label.
     */
    public static function get_plan_label( $plan ) {
        $labels = array(
            self::PLAN_MONTHLY => __( 'Monthly', 'peanut-booker' ),
            self::PLAN_ANNUAL  => __( 'Annual', 'peanut-booker' ),
        );

        return $labels[ $plan ] ?? $plan;
    }

    /**
     * Get subscription checkout URL.
     *
     * @param string $plan_type Plan type (monthly/annual).
     * @param string $tier      Subscription tier (pro/featured).
     * @return string Checkout URL.
     */
    public static function get_checkout_url( $plan_type = self::PLAN_MONTHLY, $tier = self::TIER_PRO ) {
        $option_key = 'peanut_booker_' . $tier . '_' . ( self::PLAN_ANNUAL === $plan_type ? 'annual' : 'monthly' ) . '_product';
        $product_id = get_option( $option_key );

        if ( ! $product_id ) {
            return '';
        }

        return add_query_arg( 'add-to-cart', $product_id, wc_get_checkout_url() );
    }

    /**
     * Get all tier pricing for display.
     *
     * @return array Pricing data for all tiers.
     */
    public static function get_pricing() {
        $options = get_option( 'peanut_booker_settings', array() );

        // Pro tier pricing.
        $pro_monthly = $options['pro_monthly_price'] ?? self::DEFAULT_PRICING['pro_monthly_price'];
        $pro_annual  = $options['pro_annual_price'] ?? self::DEFAULT_PRICING['pro_annual_price'];
        $pro_annual_monthly  = $pro_annual / 12;
        $pro_savings         = ( $pro_monthly * 12 ) - $pro_annual;
        $pro_savings_percent = round( ( $pro_savings / ( $pro_monthly * 12 ) ) * 100 );

        // Featured tier pricing.
        $featured_monthly = $options['featured_monthly_price'] ?? self::DEFAULT_PRICING['featured_monthly_price'];
        $featured_annual  = $options['featured_annual_price'] ?? self::DEFAULT_PRICING['featured_annual_price'];
        $featured_annual_monthly  = $featured_annual / 12;
        $featured_savings         = ( $featured_monthly * 12 ) - $featured_annual;
        $featured_savings_percent = round( ( $featured_savings / ( $featured_monthly * 12 ) ) * 100 );

        // Commission rates.
        $commission_free     = $options['commission_free_tier'] ?? self::DEFAULT_PRICING['commission_free_tier'];
        $commission_pro      = $options['commission_pro_tier'] ?? self::DEFAULT_PRICING['commission_pro_tier'];
        $commission_featured = $options['commission_featured_tier'] ?? self::DEFAULT_PRICING['commission_featured_tier'];

        return array(
            'tiers' => array(
                self::TIER_FREE => array(
                    'name'        => __( 'Free', 'peanut-booker' ),
                    'description' => __( 'Get started for free', 'peanut-booker' ),
                    'commission'  => $commission_free,
                    'photos'      => 1,
                    'videos'      => 0,
                    'features'    => array(
                        __( 'Basic profile', 'peanut-booker' ),
                        __( '1 photo', 'peanut-booker' ),
                        __( 'Receive booking requests', 'peanut-booker' ),
                        __( 'In-app messaging', 'peanut-booker' ),
                    ),
                ),
                self::TIER_PRO => array(
                    'name'        => __( 'Pro', 'peanut-booker' ),
                    'description' => __( 'Grow your business', 'peanut-booker' ),
                    'commission'  => $commission_pro,
                    'photos'      => 10,
                    'videos'      => 5,
                    'monthly'     => array(
                        'price'        => $pro_monthly,
                        'formatted'    => wc_price( $pro_monthly ),
                        'period'       => __( '/month', 'peanut-booker' ),
                        'checkout_url' => self::get_checkout_url( self::PLAN_MONTHLY, self::TIER_PRO ),
                    ),
                    'annual'      => array(
                        'price'             => $pro_annual,
                        'formatted'         => wc_price( $pro_annual ),
                        'period'            => __( '/year', 'peanut-booker' ),
                        'monthly_price'     => $pro_annual_monthly,
                        'monthly_formatted' => wc_price( $pro_annual_monthly ),
                        'savings'           => $pro_savings,
                        'savings_formatted' => wc_price( $pro_savings ),
                        'savings_percent'   => $pro_savings_percent,
                        'checkout_url'      => self::get_checkout_url( self::PLAN_ANNUAL, self::TIER_PRO ),
                    ),
                    'features'    => array(
                        __( 'Up to 10 photos', 'peanut-booker' ),
                        __( 'Up to 5 videos', 'peanut-booker' ),
                        __( 'Event Market bidding', 'peanut-booker' ),
                        __( 'Lower commission rate', 'peanut-booker' ),
                        __( 'Analytics dashboard', 'peanut-booker' ),
                        __( 'Priority in search', 'peanut-booker' ),
                    ),
                ),
                self::TIER_FEATURED => array(
                    'name'        => __( 'Featured', 'peanut-booker' ),
                    'description' => __( 'Maximum visibility', 'peanut-booker' ),
                    'commission'  => $commission_featured,
                    'photos'      => -1, // Unlimited.
                    'videos'      => -1, // Unlimited.
                    'monthly'     => array(
                        'price'        => $featured_monthly,
                        'formatted'    => wc_price( $featured_monthly ),
                        'period'       => __( '/month', 'peanut-booker' ),
                        'checkout_url' => self::get_checkout_url( self::PLAN_MONTHLY, self::TIER_FEATURED ),
                    ),
                    'annual'      => array(
                        'price'             => $featured_annual,
                        'formatted'         => wc_price( $featured_annual ),
                        'period'            => __( '/year', 'peanut-booker' ),
                        'monthly_price'     => $featured_annual_monthly,
                        'monthly_formatted' => wc_price( $featured_annual_monthly ),
                        'savings'           => $featured_savings,
                        'savings_formatted' => wc_price( $featured_savings ),
                        'savings_percent'   => $featured_savings_percent,
                        'checkout_url'      => self::get_checkout_url( self::PLAN_ANNUAL, self::TIER_FEATURED ),
                    ),
                    'features'    => array(
                        __( 'Unlimited photos', 'peanut-booker' ),
                        __( 'Unlimited videos', 'peanut-booker' ),
                        __( 'Lowest commission rate', 'peanut-booker' ),
                        __( 'Featured badge', 'peanut-booker' ),
                        __( 'Homepage spotlight', 'peanut-booker' ),
                        __( 'Priority support', 'peanut-booker' ),
                        __( 'Custom microsite domain', 'peanut-booker' ),
                    ),
                ),
            ),
            // Legacy format for backward compatibility.
            'monthly'          => array(
                'price'        => $pro_monthly,
                'formatted'    => wc_price( $pro_monthly ),
                'period'       => __( '/month', 'peanut-booker' ),
                'checkout_url' => self::get_checkout_url( self::PLAN_MONTHLY, self::TIER_PRO ),
            ),
            'annual'           => array(
                'price'             => $pro_annual,
                'formatted'         => wc_price( $pro_annual ),
                'period'            => __( '/year', 'peanut-booker' ),
                'monthly_price'     => $pro_annual_monthly,
                'monthly_formatted' => wc_price( $pro_annual_monthly ),
                'savings'           => $pro_savings,
                'savings_formatted' => wc_price( $pro_savings ),
                'savings_percent'   => $pro_savings_percent,
                'checkout_url'      => self::get_checkout_url( self::PLAN_ANNUAL, self::TIER_PRO ),
            ),
            'free_commission'     => $commission_free,
            'pro_commission'      => $commission_pro,
            'featured_commission' => $commission_featured,
        );
    }

    /**
     * Get tier label.
     *
     * @param string $tier Tier slug.
     * @return string Label.
     */
    public static function get_tier_label( $tier ) {
        $labels = array(
            self::TIER_FREE     => __( 'Free', 'peanut-booker' ),
            self::TIER_PRO      => __( 'Pro', 'peanut-booker' ),
            self::TIER_FEATURED => __( 'Featured', 'peanut-booker' ),
        );

        return $labels[ $tier ] ?? ucfirst( $tier );
    }

    /**
     * Render Pro upgrade prompt.
     *
     * @return string HTML.
     */
    public static function render_upgrade_prompt() {
        $pricing = self::get_pricing();

        ob_start();
        ?>
        <div class="pb-upgrade-prompt">
            <h3><?php esc_html_e( 'Upgrade to Pro', 'peanut-booker' ); ?></h3>
            <p><?php esc_html_e( 'Unlock all features and grow your performance business.', 'peanut-booker' ); ?></p>

            <ul class="pb-pro-features">
                <li><?php esc_html_e( 'Unlimited photos and videos', 'peanut-booker' ); ?></li>
                <li><?php esc_html_e( 'Access to Event Market bidding', 'peanut-booker' ); ?></li>
                <li><?php esc_html_e( 'Lower commission rates', 'peanut-booker' ); ?> (<?php echo esc_html( $pricing['pro_commission'] ); ?>% <?php esc_html_e( 'vs', 'peanut-booker' ); ?> <?php echo esc_html( $pricing['free_commission'] ); ?>%)</li>
                <li><?php esc_html_e( 'Priority in search results', 'peanut-booker' ); ?></li>
                <li><?php esc_html_e( 'Advanced analytics', 'peanut-booker' ); ?></li>
            </ul>

            <div class="pb-pricing-options">
                <div class="pb-pricing-option">
                    <span class="pb-price"><?php echo wp_kses_post( $pricing['monthly']['formatted'] ); ?></span>
                    <span class="pb-period"><?php echo esc_html( $pricing['monthly']['period'] ); ?></span>
                    <a href="<?php echo esc_url( $pricing['monthly']['checkout_url'] ); ?>" class="pb-button">
                        <?php esc_html_e( 'Choose Monthly', 'peanut-booker' ); ?>
                    </a>
                </div>

                <div class="pb-pricing-option pb-recommended">
                    <span class="pb-badge"><?php esc_html_e( 'Save', 'peanut-booker' ); ?> <?php echo esc_html( $pricing['annual']['savings_percent'] ); ?>%</span>
                    <span class="pb-price"><?php echo wp_kses_post( $pricing['annual']['formatted'] ); ?></span>
                    <span class="pb-period"><?php echo esc_html( $pricing['annual']['period'] ); ?></span>
                    <span class="pb-note"><?php echo wp_kses_post( $pricing['annual']['monthly_formatted'] ); ?><?php esc_html_e( '/month', 'peanut-booker' ); ?></span>
                    <a href="<?php echo esc_url( $pricing['annual']['checkout_url'] ); ?>" class="pb-button pb-button-primary">
                        <?php esc_html_e( 'Choose Annual', 'peanut-booker' ); ?>
                    </a>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX: Get subscription status.
     */
    public function ajax_get_status() {
        // Verify nonce for security
        if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'pb_get_subscription_status' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed', 'peanut-booker' ) ) );
        }

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'Not logged in.', 'peanut-booker' ) ) );
        }

        $status = self::get_user_subscription( get_current_user_id() );
        wp_send_json_success( $status );
    }
}
