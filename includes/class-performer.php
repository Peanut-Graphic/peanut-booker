<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Performer functionality.
 *
 * @package Peanut_Booker
 * @since   1.0.0
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Performer functionality class.
 */
class Peanut_Booker_Performer {

    /**
     * Most featured performers a single request may return.
     */
    const FEATURED_MAX = 12;

    /**
     * Constructor.
     */
    public function __construct() {
        add_action( 'user_register', array( $this, 'maybe_create_performer_record' ) );
        add_action( 'save_post_pb_performer', array( $this, 'sync_performer_data' ), 10, 3 );
        add_action( 'wp_ajax_pb_update_performer_profile', array( $this, 'ajax_update_profile' ) );
        add_action( 'wp_ajax_pb_toggle_instant_booking', array( $this, 'ajax_toggle_instant_booking' ) );
        add_filter( 'template_include', array( $this, 'load_performer_template' ) );
    }

    /**
     * Create performer record when user registers as performer.
     *
     * @param int $user_id User ID.
     */
    public function maybe_create_performer_record( $user_id ) {
        $user = get_userdata( $user_id );
        if ( ! $user || ! in_array( 'pb_performer', (array) $user->roles, true ) ) {
            return;
        }

        $this->create_performer( $user_id );
    }

    /**
     * Create a new performer.
     *
     * @param int   $user_id User ID.
     * @param array $data    Optional performer data.
     * @return int|false Performer ID or false on failure.
     */
    public static function create_performer( $user_id, $data = array() ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return false;
        }

        // Check if performer already exists.
        $existing = Peanut_Booker_Database::get_row( 'performers', array( 'user_id' => $user_id ) );
        if ( $existing ) {
            return $existing->id;
        }

        // Create performer profile post.
        $profile_id = wp_insert_post(
            array(
                'post_type'   => 'pb_performer',
                'post_status' => 'pending',
                'post_title'  => $user->display_name,
                'post_author' => $user_id,
            )
        );

        if ( is_wp_error( $profile_id ) ) {
            return false;
        }

        // Link user ID to profile.
        update_post_meta( $profile_id, 'pb_user_id', $user_id );

        // Create performer record.
        $performer_data = array_merge(
            array(
                'user_id'              => $user_id,
                'profile_id'           => $profile_id,
                'tier'                 => 'free',
                'achievement_level'    => 'bronze',
                'achievement_score'    => 0,
                'completed_bookings'   => 0,
                'profile_completeness' => 0,
                'deposit_percentage'   => 25,
                'status'               => 'pending',
            ),
            $data
        );

        $performer_id = Peanut_Booker_Database::insert( 'performers', $performer_data );

        if ( $performer_id ) {
            // Trigger action for Peanut Suite integration.
            do_action( 'peanut_booker_performer_created', $performer_id, $user_id, $profile_id );
        }

        return $performer_id;
    }

    /**
     * Get performer by user ID.
     *
     * @param int $user_id User ID.
     * @return object|null Performer object or null.
     */
    public static function get_by_user_id( $user_id ) {
        return Peanut_Booker_Database::get_row( 'performers', array( 'user_id' => $user_id ) );
    }

    /**
     * Get performer by profile post ID.
     *
     * @param int $profile_id Profile post ID.
     * @return object|null Performer object or null.
     */
    public static function get_by_profile_id( $profile_id ) {
        return Peanut_Booker_Database::get_row( 'performers', array( 'profile_id' => $profile_id ) );
    }

    /**
     * Get performer by ID.
     *
     * @param int $performer_id Performer ID.
     * @return object|null Performer object or null.
     */
    public static function get( $performer_id ) {
        return Peanut_Booker_Database::get_row( 'performers', array( 'id' => $performer_id ) );
    }

    /**
     * Update performer data.
     *
     * @param int   $performer_id Performer ID.
     * @param array $data         Data to update.
     * @return bool Success.
     */
    public static function update( $performer_id, $data ) {
        $result = Peanut_Booker_Database::update( 'performers', $data, array( 'id' => $performer_id ) );

        if ( $result !== false ) {
            $performer = self::get( $performer_id );
            do_action( 'peanut_booker_performer_updated', $performer_id, $performer );
        }

        return $result !== false;
    }

    /**
     * Get total earnings for a performer.
     *
     * @param int $performer_id Performer ID.
     * @return float Total earnings.
     */
    public static function get_total_earnings( $performer_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'pb_bookings';

        $total = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(performer_payout) FROM $table
                WHERE performer_id = %d
                AND booking_status = 'completed'
                AND escrow_status = 'released'",
                $performer_id
            )
        );

        return floatval( $total );
    }

    /**
     * Calculate and update achievement score.
     *
     * @param int $performer_id Performer ID.
     * @return int New achievement score.
     */
    public static function calculate_achievement_score( $performer_id ) {
        $performer = self::get( $performer_id );
        if ( ! $performer ) {
            return 0;
        }

        // Score = (completed_bookings × 10) + (avg_rating × 20) + (profile_completeness × 0.5)
        $bookings_score    = $performer->completed_bookings * 10;
        $rating_score      = ( $performer->average_rating ?? 0 ) * 20;
        $completeness_score = $performer->profile_completeness * 0.5;

        $total_score = (int) ( $bookings_score + $rating_score + $completeness_score );

        // Determine achievement level.
        $options         = get_option( 'peanut_booker_settings', array() );
        $platinum_threshold = $options['achievement_platinum'] ?? 2000;
        $gold_threshold     = $options['achievement_gold'] ?? 500;
        $silver_threshold   = $options['achievement_silver'] ?? 100;

        if ( $total_score >= $platinum_threshold ) {
            $level = 'platinum';
        } elseif ( $total_score >= $gold_threshold ) {
            $level = 'gold';
        } elseif ( $total_score >= $silver_threshold ) {
            $level = 'silver';
        } else {
            $level = 'bronze';
        }

        // Update performer.
        self::update(
            $performer_id,
            array(
                'achievement_score' => $total_score,
                'achievement_level' => $level,
            )
        );

        do_action( 'peanut_booker_achievement_updated', $performer_id, $level, $total_score );

        return $total_score;
    }

    /**
     * Calculate profile completeness percentage.
     *
     * @param int $profile_id Profile post ID.
     * @return int Completeness percentage (0-100).
     */
    public static function calculate_profile_completeness( $profile_id ) {
        $fields = array(
            'post_content'         => 20, // Bio.
            '_thumbnail_id'        => 15, // Profile photo.
            'pb_stage_name'        => 5,
            'pb_tagline'           => 5,
            'pb_hourly_rate'       => 10,
            'pb_location_city'     => 5,
            'pb_location_state'    => 5,
            'pb_gallery_images'    => 10,
            'pb_video_links'       => 10,
            'pb_experience_years'  => 5,
            'pb_service_areas'     => 10, // Taxonomy.
        );

        $score = 0;
        $post  = get_post( $profile_id );

        if ( ! $post ) {
            return 0;
        }

        // Check post content.
        if ( ! empty( $post->post_content ) && strlen( $post->post_content ) > 50 ) {
            $score += $fields['post_content'];
        }

        // Check thumbnail.
        if ( has_post_thumbnail( $profile_id ) ) {
            $score += $fields['_thumbnail_id'];
        }

        // Check meta fields.
        $meta_fields = array(
            'pb_stage_name',
            'pb_tagline',
            'pb_hourly_rate',
            'pb_location_city',
            'pb_location_state',
            'pb_experience_years',
        );

        foreach ( $meta_fields as $field ) {
            $value = get_post_meta( $profile_id, $field, true );
            if ( ! empty( $value ) ) {
                $score += $fields[ $field ];
            }
        }

        // Check gallery.
        $gallery = get_post_meta( $profile_id, 'pb_gallery_images', true );
        if ( ! empty( $gallery ) && is_array( $gallery ) && count( $gallery ) > 0 ) {
            $score += $fields['pb_gallery_images'];
        }

        // Check videos.
        $videos = get_post_meta( $profile_id, 'pb_video_links', true );
        if ( ! empty( $videos ) && is_array( $videos ) && count( $videos ) > 0 ) {
            $score += $fields['pb_video_links'];
        }

        // Check taxonomy.
        $terms = wp_get_post_terms( $profile_id, 'pb_service_area' );
        if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
            $score += $fields['pb_service_areas'];
        }

        return min( 100, $score );
    }

    /**
     * Sync performer data when profile post is saved.
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post object.
     * @param bool    $update  Whether this is an update.
     */
    public function sync_performer_data( $post_id, $post, $update ) {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }

        $performer = self::get_by_profile_id( $post_id );
        if ( ! $performer ) {
            return;
        }

        // Update profile completeness.
        $completeness = self::calculate_profile_completeness( $post_id );
        self::update( $performer->id, array( 'profile_completeness' => $completeness ) );

        // Recalculate achievement score.
        self::calculate_achievement_score( $performer->id );

        // Sync hourly rate.
        $hourly_rate = get_post_meta( $post_id, 'pb_hourly_rate', true );
        if ( $hourly_rate ) {
            self::update( $performer->id, array( 'hourly_rate' => floatval( $hourly_rate ) ) );
        }

        // Sync deposit percentage.
        $deposit = get_post_meta( $post_id, 'pb_deposit_percentage', true );
        if ( $deposit ) {
            self::update( $performer->id, array( 'deposit_percentage' => intval( $deposit ) ) );
        }
    }

    /**
     * Get performer's display data for frontend.
     *
     * @param int $profile_id Profile post ID.
     * @return array Performer display data.
     */
    public static function get_display_data( $profile_id ) {
        $post      = get_post( $profile_id );
        $performer = self::get_by_profile_id( $profile_id );

        if ( ! $post || ! $performer ) {
            return array();
        }

        $user = get_userdata( $performer->user_id );

        // Get pricing.
        $hourly_rate = get_post_meta( $profile_id, 'pb_hourly_rate', true );
        $sale_active = get_post_meta( $profile_id, 'pb_sale_active', true );
        $sale_price  = get_post_meta( $profile_id, 'pb_sale_price', true );

        // Get gallery (respect tier limits).
        $gallery     = get_post_meta( $profile_id, 'pb_gallery_images', true ) ?: array();
        $photo_limit = Peanut_Booker_Roles::get_photo_limit( $performer->user_id );
        if ( $photo_limit > 0 && count( $gallery ) > $photo_limit ) {
            $gallery = array_slice( $gallery, 0, $photo_limit );
        }

        // Get videos (respect tier limits).
        $videos      = get_post_meta( $profile_id, 'pb_video_links', true ) ?: array();
        $video_limit = Peanut_Booker_Roles::get_video_limit( $performer->user_id );
        if ( $video_limit > 0 && count( $videos ) > $video_limit ) {
            $videos = array_slice( $videos, 0, $video_limit );
        }

        // Get categories.
        $categories = wp_get_post_terms( $profile_id, 'pb_performer_category', array( 'fields' => 'names' ) );

        // Get service areas.
        $service_areas = wp_get_post_terms( $profile_id, 'pb_service_area', array( 'fields' => 'names' ) );

        return array(
            'id'                  => $performer->id,
            'profile_id'          => $profile_id,
            'user_id'             => $performer->user_id,
            'name'                => $post->post_title,
            'stage_name'          => get_post_meta( $profile_id, 'pb_stage_name', true ),
            'tagline'             => get_post_meta( $profile_id, 'pb_tagline', true ),
            'bio'                 => $post->post_content,
            'excerpt'             => $post->post_excerpt ?: wp_trim_words( $post->post_content, 30 ),
            'permalink'           => get_permalink( $profile_id ),
            'featured_image'      => get_the_post_thumbnail_url( $profile_id, 'large' ),
            'thumbnail'           => get_the_post_thumbnail_url( $profile_id, 'medium' ),
            'gallery'             => $gallery,
            'videos'              => $videos,
            'hourly_rate'         => floatval( $hourly_rate ),
            'sale_active'         => (bool) $sale_active,
            'sale_price'          => floatval( $sale_price ),
            'display_price'       => $sale_active && $sale_price ? $sale_price : $hourly_rate,
            'deposit_percentage'  => $performer->deposit_percentage,
            'tier'                => $performer->tier,
            'achievement_level'   => $performer->achievement_level,
            'achievement_score'   => $performer->achievement_score,
            'completed_bookings'  => $performer->completed_bookings,
            'average_rating'      => $performer->average_rating,
            'total_reviews'       => $performer->total_reviews,
            'profile_completeness' => $performer->profile_completeness,
            'is_verified'         => (bool) $performer->is_verified,
            'is_featured'         => (bool) $performer->is_featured,
            'categories'          => $categories,
            'service_areas'       => $service_areas,
            'location_city'       => get_post_meta( $profile_id, 'pb_location_city', true ),
            'location_state'      => get_post_meta( $profile_id, 'pb_location_state', true ),
            'travel_willing'      => (bool) get_post_meta( $profile_id, 'pb_travel_willing', true ),
            'travel_radius'       => (int) get_post_meta( $profile_id, 'pb_travel_radius', true ),
            'experience_years'    => (int) get_post_meta( $profile_id, 'pb_experience_years', true ),
            'website'             => get_post_meta( $profile_id, 'pb_website', true ),
            'social_links'        => get_post_meta( $profile_id, 'pb_social_links', true ) ?: array(),
        );
    }

    /**
     * Get performers with filters.
     *
     * @param array $args Query arguments.
     * @return array Array of performer data.
     */
    public static function query( $args = array() ) {
        $defaults = array(
            'status'        => 'publish',
            'tier'          => '',
            'category'      => '',
            'service_area'  => '',
            'min_rating'    => 0,
            'max_price'     => 0,
            'orderby'       => 'date',
            'order'         => 'DESC',
            'posts_per_page' => 12,
            'paged'         => 1,
            'search'        => '',
        );

        $args = wp_parse_args( $args, $defaults );

        $query_args = array(
            'post_type'      => 'pb_performer',
            'post_status'    => $args['status'],
            'posts_per_page' => $args['posts_per_page'],
            'paged'          => $args['paged'],
            'orderby'        => $args['orderby'],
            'order'          => $args['order'],
        );

        // Public REST catalogs must exclude protected profiles before pagination.
        // Internal callers retain their existing query semantics.
        if ( ! empty( $args['public_only'] ) ) {
            $query_args['has_password'] = false;
        }

        // Search.
        if ( ! empty( $args['search'] ) ) {
            $query_args['s'] = sanitize_text_field( $args['search'] );
        }

        // Category filter.
        if ( ! empty( $args['category'] ) ) {
            $query_args['tax_query'][] = array(
                'taxonomy' => 'pb_performer_category',
                'field'    => 'slug',
                'terms'    => $args['category'],
            );
        }

        // Service area filter.
        if ( ! empty( $args['service_area'] ) ) {
            $query_args['tax_query'][] = array(
                'taxonomy' => 'pb_service_area',
                'field'    => 'slug',
                'terms'    => $args['service_area'],
            );
        }

        // Price filter.
        if ( $args['max_price'] > 0 ) {
            $query_args['meta_query'][] = array(
                'key'     => 'pb_hourly_rate',
                'value'   => $args['max_price'],
                'type'    => 'NUMERIC',
                'compare' => '<=',
            );
        }

        $query   = new WP_Query( $query_args );
        $results = array();

        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) {
                $query->the_post();
                $performer_data = self::get_display_data( get_the_ID() );

                // Apply tier filter (done post-query since it's in custom table).
                if ( ! empty( $args['tier'] ) && $performer_data['tier'] !== $args['tier'] ) {
                    continue;
                }

                // Apply rating filter.
                if ( $args['min_rating'] > 0 && ( $performer_data['average_rating'] ?? 0 ) < $args['min_rating'] ) {
                    continue;
                }

                $results[] = $performer_data;
            }
            wp_reset_postdata();
        }

        return array(
            'performers'   => $results,
            'total'        => $query->found_posts,
            'max_pages'    => $query->max_num_pages,
            'current_page' => $args['paged'],
        );
    }

    /**
     * Whether a performer's profile is publicly listed.
     *
     * Public means a published pb_performer post with no password. Drafts,
     * pending, private, trashed and password-protected profiles are not.
     *
     * @param object $performer Performer row.
     * @return bool
     */
    public static function is_public_profile( $performer ) {
        if ( ! $performer || empty( $performer->profile_id ) ) {
            return false;
        }

        $post = get_post( $performer->profile_id );

        return $post
            && 'pb_performer' === $post->post_type
            && 'publish' === $post->post_status
            && '' === (string) $post->post_password;
    }

    /**
     * Get featured/sponsored performers.
     *
     * @param int $limit Number to return.
     * @return array Array of performer data.
     */
    public static function get_featured( $limit = 4 ) {
        global $wpdb;

        $limit = min( max( 1, absint( $limit ) ), self::FEATURED_MAX );

        $table = $wpdb->prefix . 'pb_sponsored_slots';
        $now   = current_time( 'mysql' );

        // Get active sponsored performers.
        $sponsored = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT performer_id FROM $table
                WHERE status = 'active'
                AND start_date <= %s
                AND end_date >= %s
                ORDER BY position ASC, RAND()
                LIMIT %d",
                $now,
                $now,
                $limit
            )
        );

        $results = array();

        foreach ( $sponsored as $performer_id ) {
            $performer = self::get( $performer_id );
            // A paid slot does not make an unpublished or protected profile public.
            if ( self::is_public_profile( $performer ) ) {
                $results[] = self::get_display_data( $performer->profile_id );
            }
        }

        // Fill remaining with featured performers.
        if ( count( $results ) < $limit ) {
            $remaining = $limit - count( $results );
            $exclude   = array_column( $results, 'profile_id' );

            $featured_query = new WP_Query(
                array(
                    'post_type'      => 'pb_performer',
                    'post_status'    => 'publish',
                    'has_password'   => false,
                    'posts_per_page' => $remaining,
                    'post__not_in'   => $exclude,
                    'meta_query'     => array(
                        array(
                            'key'   => 'pb_is_featured',
                            'value' => '1',
                        ),
                    ),
                    'orderby'        => 'rand',
                )
            );

            while ( $featured_query->have_posts() ) {
                $featured_query->the_post();
                $results[] = self::get_display_data( get_the_ID() );
            }
            wp_reset_postdata();
        }

        return $results;
    }

    /**
     * AJAX handler for updating performer profile.
     */
    public function ajax_update_profile() {
        check_ajax_referer( 'pb_performer_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'peanut-booker' ) ) );
        }

        $user_id   = get_current_user_id();
        $performer = self::get_by_user_id( $user_id );

        if ( ! $performer ) {
            wp_send_json_error( array( 'message' => __( 'Performer not found.', 'peanut-booker' ) ) );
        }

        $profile_id = $performer->profile_id;

        // Update post content (bio).
        if ( isset( $_POST['bio'] ) ) {
            wp_update_post(
                array(
                    'ID'           => $profile_id,
                    'post_content' => wp_kses_post( $_POST['bio'] ),
                )
            );
        }

        // Update meta fields.
        $meta_fields = array(
            'pb_stage_name',
            'pb_tagline',
            'pb_hourly_rate',
            'pb_minimum_booking',
            'pb_deposit_percentage',
            'pb_location_city',
            'pb_location_state',
            'pb_travel_willing',
            'pb_travel_radius',
            'pb_experience_years',
            'pb_website',
            'pb_phone',
            'pb_email_public',
            'pb_equipment_provided',
            'pb_equipment_details',
        );

        foreach ( $meta_fields as $field ) {
            $key = str_replace( 'pb_', '', $field );
            if ( isset( $_POST[ $key ] ) ) {
                update_post_meta( $profile_id, $field, sanitize_text_field( $_POST[ $key ] ) );
            }
        }

        // Handle gallery images.
        if ( isset( $_POST['gallery_images'] ) && is_array( $_POST['gallery_images'] ) ) {
            $limit  = Peanut_Booker_Roles::get_photo_limit( $user_id );
            $images = array_map( 'absint', $_POST['gallery_images'] );
            if ( $limit > 0 ) {
                $images = array_slice( $images, 0, $limit );
            }
            update_post_meta( $profile_id, 'pb_gallery_images', $images );
        }

        // Handle video links.
        if ( isset( $_POST['video_links'] ) && is_array( $_POST['video_links'] ) ) {
            $limit  = Peanut_Booker_Roles::get_video_limit( $user_id );
            $videos = array_map( 'esc_url_raw', $_POST['video_links'] );
            if ( $limit > 0 ) {
                $videos = array_slice( $videos, 0, $limit );
            }
            update_post_meta( $profile_id, 'pb_video_links', $videos );
        }

        // Handle social links.
        if ( isset( $_POST['social_links'] ) && is_array( $_POST['social_links'] ) ) {
            $social = array();
            foreach ( $_POST['social_links'] as $platform => $url ) {
                if ( ! empty( $url ) ) {
                    $social[ sanitize_key( $platform ) ] = esc_url_raw( $url );
                }
            }
            update_post_meta( $profile_id, 'pb_social_links', $social );
        }

        // Handle categories.
        if ( isset( $_POST['categories'] ) && is_array( $_POST['categories'] ) ) {
            $categories = array_map( 'absint', $_POST['categories'] );
            wp_set_post_terms( $profile_id, $categories, 'pb_performer_category' );
        }

        // Handle service areas.
        if ( isset( $_POST['service_areas'] ) && is_array( $_POST['service_areas'] ) ) {
            $areas = array_map( 'absint', $_POST['service_areas'] );
            wp_set_post_terms( $profile_id, $areas, 'pb_service_area' );
        }

        // Trigger sync.
        $this->sync_performer_data( $profile_id, get_post( $profile_id ), true );

        wp_send_json_success(
            array(
                'message'      => __( 'Profile updated successfully.', 'peanut-booker' ),
                'completeness' => self::calculate_profile_completeness( $profile_id ),
            )
        );
    }

    /**
     * Load custom template for performer single pages.
     *
     * @param string $template Template path.
     * @return string Modified template path.
     */
    public function load_performer_template( $template ) {
        if ( is_singular( 'pb_performer' ) ) {
            $custom_template = PEANUT_BOOKER_PATH . 'templates/single-performer.php';
            if ( file_exists( $custom_template ) ) {
                return $custom_template;
            }
        }

        if ( is_post_type_archive( 'pb_performer' ) ) {
            $custom_template = PEANUT_BOOKER_PATH . 'templates/archive-performer.php';
            if ( file_exists( $custom_template ) ) {
                return $custom_template;
            }
        }

        return $template;
    }

    /**
     * Get achievement badge HTML.
     *
     * @param string $level Achievement level.
     * @return string HTML badge.
     */
    public static function get_achievement_badge( $level ) {
        $badges = array(
            'bronze'   => array(
                'label' => __( 'Bronze', 'peanut-booker' ),
                'class' => 'pb-badge-bronze',
                'icon'  => '🥉',
            ),
            'silver'   => array(
                'label' => __( 'Silver', 'peanut-booker' ),
                'class' => 'pb-badge-silver',
                'icon'  => '🥈',
            ),
            'gold'     => array(
                'label' => __( 'Gold', 'peanut-booker' ),
                'class' => 'pb-badge-gold',
                'icon'  => '🥇',
            ),
            'platinum' => array(
                'label' => __( 'Platinum', 'peanut-booker' ),
                'class' => 'pb-badge-platinum',
                'icon'  => '💎',
            ),
        );

        $badge = $badges[ $level ] ?? $badges['bronze'];

        return sprintf(
            '<span class="pb-achievement-badge %s" title="%s">%s %s</span>',
            esc_attr( $badge['class'] ),
            esc_attr( $badge['label'] . ' ' . __( 'Performer', 'peanut-booker' ) ),
            $badge['icon'],
            esc_html( $badge['label'] )
        );
    }

    /**
     * Check if performer has instant booking enabled.
     *
     * @param int $performer_id Performer ID.
     * @return bool
     */
    public static function has_instant_booking( $performer_id ) {
        $performer = self::get( $performer_id );
        if ( ! $performer ) {
            return false;
        }

        return (bool) get_post_meta( $performer->profile_id, 'pb_instant_booking_enabled', true );
    }

    /**
     * Toggle instant booking for performer.
     *
     * @param int  $performer_id Performer ID.
     * @param bool $enabled      Enable or disable.
     * @return bool Success.
     */
    public static function set_instant_booking( $performer_id, $enabled ) {
        $performer = self::get( $performer_id );
        if ( ! $performer ) {
            return false;
        }

        return update_post_meta( $performer->profile_id, 'pb_instant_booking_enabled', (bool) $enabled );
    }

    /**
     * AJAX handler for toggling instant booking.
     */
    public function ajax_toggle_instant_booking() {
        check_ajax_referer( 'pb_performer_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'peanut-booker' ) ) );
        }

        $user_id   = get_current_user_id();
        $performer = self::get_by_user_id( $user_id );

        if ( ! $performer ) {
            wp_send_json_error( array( 'message' => __( 'Performer not found.', 'peanut-booker' ) ) );
        }

        $enabled = isset( $_POST['enabled'] ) && $_POST['enabled'] === 'true';
        $result  = self::set_instant_booking( $performer->id, $enabled );

        if ( $result ) {
            wp_send_json_success(
                array(
                    'message' => $enabled
                        ? __( 'Instant booking enabled.', 'peanut-booker' )
                        : __( 'Instant booking disabled.', 'peanut-booker' ),
                    'enabled' => $enabled,
                )
            );
        } else {
            wp_send_json_error( array( 'message' => __( 'Failed to update setting.', 'peanut-booker' ) ) );
        }
    }

    /**
     * Get performer metrics.
     *
     * @param int $performer_id Performer ID.
     * @return array Metrics data.
     */
    public static function get_metrics( $performer_id ) {
        global $wpdb;

        $performer = self::get( $performer_id );
        if ( ! $performer ) {
            return array();
        }

        $bookings_table = $wpdb->prefix . 'pb_bookings';

        // Calculate response time (average time from booking creation to performer confirmation).
        $avg_response_time = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, updated_at))
                FROM $bookings_table
                WHERE performer_id = %d
                AND performer_confirmed = 1
                AND created_at IS NOT NULL
                AND updated_at IS NOT NULL",
                $performer_id
            )
        );

        // Calculate acceptance rate.
        $total_bookings = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $bookings_table WHERE performer_id = %d",
                $performer_id
            )
        );

        $confirmed_bookings = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $bookings_table
                WHERE performer_id = %d
                AND booking_status IN ('confirmed', 'completed')",
                $performer_id
            )
        );

        $acceptance_rate = $total_bookings > 0 ? ( $confirmed_bookings / $total_bookings ) * 100 : 0;

        // Get response time stats from metadata.
        $response_times = get_post_meta( $performer->profile_id, 'pb_response_times', true ) ?: array();
        $first_response_avg = ! empty( $response_times ) ? array_sum( $response_times ) / count( $response_times ) : 0;

        return array(
            'response_time_hours'     => round( floatval( $avg_response_time ), 1 ),
            'first_response_avg_hours' => round( $first_response_avg, 1 ),
            'acceptance_rate'         => round( $acceptance_rate, 1 ),
            'total_bookings'          => intval( $total_bookings ),
            'confirmed_bookings'      => intval( $confirmed_bookings ),
            'completed_bookings'      => $performer->completed_bookings,
        );
    }

    /**
     * Track response time for a booking.
     *
     * @param int $performer_id Performer ID.
     * @param int $booking_id   Booking ID.
     */
    public static function track_response_time( $performer_id, $booking_id ) {
        $booking = Peanut_Booker_Booking::get( $booking_id );
        if ( ! $booking ) {
            return;
        }

        $performer = self::get( $performer_id );
        if ( ! $performer ) {
            return;
        }

        // Calculate time difference in hours.
        $created_time = strtotime( $booking->created_at );
        $response_time = ( time() - $created_time ) / 3600;

        // Store response time.
        $response_times = get_post_meta( $performer->profile_id, 'pb_response_times', true ) ?: array();
        $response_times[] = $response_time;

        // Keep only last 50 response times.
        if ( count( $response_times ) > 50 ) {
            $response_times = array_slice( $response_times, -50 );
        }

        update_post_meta( $performer->profile_id, 'pb_response_times', $response_times );
    }
}
