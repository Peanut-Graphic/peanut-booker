<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Performer-related REST API admin endpoints.
 *
 * This trait contains all performer management endpoints for the admin REST API.
 * It is designed to be used by Peanut_Booker_REST_API_Admin class.
 *
 * Methods included:
 * - Route registration
 * - Listing performers (get_admin_performers)
 * - Getting single performer (get_admin_performer)
 * - Updating performers (update_performer)
 * - Verifying performers (verify_performer)
 * - Featuring performers (feature_performer)
 * - Bulk operations (bulk_delete_performers)
 * - Performer editor (get_performer_editor, update_performer_editor)
 * - Analytics (get_performer_analytics)
 * - External gigs CRUD
 * - Performer bookings list
 *
 * @package Peanut_Booker
 * @since   2.0.0
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Trait for performer admin endpoints.
 */
trait Peanut_Booker_REST_Admin_Performers {

    /**
     * Register performer-related routes.
     *
     * Called from main register_routes() method.
     */
    protected function register_performer_routes() {
        // Admin Performers list.
        register_rest_route(
            $this->namespace,
            '/admin/performers',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_admin_performers' ),
                'permission_callback' => array( $this, 'check_manage_performers' ),
                'args'                => $this->get_collection_params(),
            )
        );

        // Single performer.
        register_rest_route(
            $this->namespace,
            '/admin/performers/(?P<id>\d+)',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_admin_performer' ),
                    'permission_callback' => array( $this, 'check_manage_performers' ),
                ),
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'update_performer' ),
                    'permission_callback' => array( $this, 'check_manage_performers' ),
                ),
            )
        );

        // Verify performer.
        register_rest_route(
            $this->namespace,
            '/admin/performers/(?P<id>\d+)/verify',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'verify_performer' ),
                'permission_callback' => array( $this, 'check_manage_performers' ),
            )
        );

        // Feature performer.
        register_rest_route(
            $this->namespace,
            '/admin/performers/(?P<id>\d+)/feature',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'feature_performer' ),
                'permission_callback' => array( $this, 'check_manage_performers' ),
            )
        );

        // Bulk delete.
        register_rest_route(
            $this->namespace,
            '/admin/performers/bulk',
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => array( $this, 'bulk_delete_performers' ),
                'permission_callback' => array( $this, 'check_manage_performers' ),
            )
        );

        // Performer editor.
        register_rest_route(
            $this->namespace,
            '/admin/performers/(?P<id>\d+)/editor',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_performer_editor' ),
                    'permission_callback' => array( $this, 'check_manage_performers' ),
                ),
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'update_performer_editor' ),
                    'permission_callback' => array( $this, 'check_manage_performers' ),
                ),
            )
        );

        // Analytics.
        register_rest_route(
            $this->namespace,
            '/admin/performers/(?P<id>\d+)/analytics',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_performer_analytics' ),
                'permission_callback' => array( $this, 'check_manage_performers' ),
            )
        );

        // External gigs.
        register_rest_route(
            $this->namespace,
            '/admin/performers/(?P<id>\d+)/external-gigs',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_performer_external_gigs_endpoint' ),
                    'permission_callback' => array( $this, 'check_manage_performers' ),
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'create_performer_external_gig' ),
                    'permission_callback' => array( $this, 'check_manage_performers' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/admin/performers/(?P<id>\d+)/external-gigs/(?P<gig_id>\d+)',
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'update_performer_external_gig' ),
                    'permission_callback' => array( $this, 'check_manage_performers' ),
                ),
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array( $this, 'delete_performer_external_gig' ),
                    'permission_callback' => array( $this, 'check_manage_performers' ),
                ),
            )
        );

        // Performer's Bookings (for performer dashboard).
        register_rest_route(
            $this->namespace,
            '/admin/performers/(?P<id>\d+)/bookings',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_performer_bookings' ),
                'permission_callback' => array( $this, 'check_manage_performers' ),
                'args'                => array(
                    'id' => array(
                        'required'          => true,
                        'validate_callback' => function( $param ) {
                            return is_numeric( $param );
                        },
                    ),
                    'status' => array(
                        'default' => '',
                    ),
                    'page' => array(
                        'default' => 1,
                    ),
                    'per_page' => array(
                        'default' => 20,
                    ),
                ),
            )
        );

        // Performer's Reviews (for performer dashboard).
        register_rest_route(
            $this->namespace,
            '/admin/performers/(?P<id>\d+)/reviews',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_performer_reviews' ),
                'permission_callback' => array( $this, 'check_manage_performers' ),
            )
        );
    }

    /**
     * Get admin performers list.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response object.
     */
    public function get_admin_performers( $request ) {
        global $wpdb;

        $page     = $request->get_param( 'page' );
        $per_page = $request->get_param( 'per_page' );
        $search   = $request->get_param( 'search' );
        $tier     = $request->get_param( 'tier' );
        $status   = $request->get_param( 'status' );
        $offset   = ( $page - 1 ) * $per_page;

        $performers_table = Peanut_Booker_Database::get_table( 'performers' );

        // Search matches the performer's stage name (stored in postmeta on the
        // linked profile_id as `_pb_stage_name`) and the WordPress user email
        // (wp_users.user_email). Neither is a column of pb_performers, so we
        // join the real sources rather than referencing non-existent columns.
        // The joins are shared by both the COUNT and the SELECT so the two
        // queries always agree on the matched set.
        $joins = "LEFT JOIN {$wpdb->users} u ON p.user_id = u.ID
                  LEFT JOIN {$wpdb->postmeta} pm_sn ON pm_sn.post_id = p.profile_id AND pm_sn.meta_key = '_pb_stage_name'";

        $where_clauses = array( '1=1' );
        $where_values  = array();

        if ( ! empty( $search ) ) {
            $where_clauses[] = '(pm_sn.meta_value LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s)';
            $search_like     = '%' . $wpdb->esc_like( $search ) . '%';
            $where_values[]  = $search_like;
            $where_values[]  = $search_like;
            $where_values[]  = $search_like;
        }

        if ( ! empty( $tier ) ) {
            $where_clauses[] = 'p.tier = %s';
            $where_values[]  = $tier;
        }

        if ( ! empty( $status ) ) {
            $where_clauses[] = 'p.status = %s';
            $where_values[]  = $status;
        }

        $where_sql = implode( ' AND ', $where_clauses );

        // Get total count.
        $count_sql = "SELECT COUNT(DISTINCT p.id) FROM $performers_table p $joins WHERE $where_sql";
        if ( ! empty( $where_values ) ) {
            $count_sql = $wpdb->prepare( $count_sql, $where_values );
        }
        $total = (int) $wpdb->get_var( $count_sql );

        // Get results.
        $sql = "SELECT p.*, u.user_email as email
                FROM $performers_table p
                $joins
                WHERE $where_sql
                GROUP BY p.id
                ORDER BY p.id DESC
                LIMIT %d OFFSET %d";

        $query_values = array_merge( $where_values, array( $per_page, $offset ) );
        $sql          = $wpdb->prepare( $sql, $query_values );

        $results = $wpdb->get_results( $sql );

        // Batch load data to prevent N+1 queries.
        $this->prime_performer_caches( $results );

        // Format results.
        $performers = array();
        foreach ( $results as $performer ) {
            $performers[] = $this->format_performer( $performer );
        }

        return rest_ensure_response(
            array(
                'success' => true,
                'data'    => array(
                    'data'        => $performers,
                    'total'       => $total,
                    'page'        => $page,
                    'per_page'    => $per_page,
                    'total_pages' => ceil( $total / $per_page ),
                ),
            )
        );
    }

    /**
     * Get single admin performer.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error Response object or error.
     */
    public function get_admin_performer( $request ) {
        $performer = Peanut_Booker_Database::get_row( 'performers', array( 'id' => $request['id'] ) );

        if ( ! $performer ) {
            return new WP_Error( 'not_found', 'Performer not found.', array( 'status' => 404 ) );
        }

        return rest_ensure_response(
            array(
                'success' => true,
                'data'    => $this->format_performer( $performer ),
            )
        );
    }

    /**
     * Update performer.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response object.
     */
    public function update_performer( $request ) {
        $id   = $request['id'];
        $data = $request->get_json_params();

        // Remove non-updatable fields.
        unset( $data['id'], $data['user_id'], $data['created_at'] );

        // Update performer table.
        $performer_fields = array(
            'tier', 'achievement_level', 'hourly_rate', 'deposit_percentage',
            'service_radius', 'is_verified', 'is_featured', 'status',
        );

        $performer_data = array_intersect_key( $data, array_flip( $performer_fields ) );
        if ( ! empty( $performer_data ) ) {
            $performer_data['updated_at'] = current_time( 'mysql' );
            Peanut_Booker_Database::update( 'performers', $performer_data, array( 'id' => $id ) );
        }

        // Update post meta if profile_id exists.
        $performer = Peanut_Booker_Database::get_row( 'performers', array( 'id' => $id ) );
        if ( $performer && $performer->profile_id ) {
            $meta_fields = array(
                'stage_name', 'tagline', 'experience_years', 'website', 'phone',
                'email_public', 'minimum_booking', 'sale_price', 'sale_active',
                'location_city', 'location_state', 'travel_willing', 'travel_radius',
            );

            foreach ( $meta_fields as $field ) {
                if ( isset( $data[ $field ] ) ) {
                    update_post_meta( $performer->profile_id, '_pb_' . $field, $data[ $field ] );
                }
            }
        }

        return rest_ensure_response(
            array(
                'success' => true,
                'data'    => $this->format_performer( Peanut_Booker_Database::get_row( 'performers', array( 'id' => $id ) ) ),
            )
        );
    }

    /**
     * Verify performer.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response object.
     */
    public function verify_performer( $request ) {
        $id       = $request['id'];
        $verified = $request->get_param( 'verified' );

        Peanut_Booker_Database::update(
            'performers',
            array(
                'is_verified' => $verified ? 1 : 0,
                'updated_at'  => current_time( 'mysql' ),
            ),
            array( 'id' => $id )
        );

        return rest_ensure_response(
            array(
                'success' => true,
                'data'    => $this->format_performer( Peanut_Booker_Database::get_row( 'performers', array( 'id' => $id ) ) ),
            )
        );
    }

    /**
     * Feature performer.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response object.
     */
    public function feature_performer( $request ) {
        $id       = $request['id'];
        $featured = $request->get_param( 'featured' );

        Peanut_Booker_Database::update(
            'performers',
            array(
                'is_featured' => $featured ? 1 : 0,
                'updated_at'  => current_time( 'mysql' ),
            ),
            array( 'id' => $id )
        );

        return rest_ensure_response(
            array(
                'success' => true,
                'data'    => $this->format_performer( Peanut_Booker_Database::get_row( 'performers', array( 'id' => $id ) ) ),
            )
        );
    }

    /**
     * Bulk delete performers.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error Response object or error.
     */
    public function bulk_delete_performers( $request ) {
        $ids = $request->get_param( 'ids' );

        if ( empty( $ids ) || ! is_array( $ids ) ) {
            return new WP_Error( 'invalid_ids', 'Invalid performer IDs.', array( 'status' => 400 ) );
        }

        foreach ( $ids as $id ) {
            $performer = Peanut_Booker_Database::get_row( 'performers', array( 'id' => $id ) );
            if ( $performer ) {
                // Delete profile post.
                if ( $performer->profile_id ) {
                    wp_delete_post( $performer->profile_id, true );
                }
                // Delete performer record.
                Peanut_Booker_Database::delete( 'performers', array( 'id' => $id ) );
            }
        }

        return rest_ensure_response(
            array(
                'success' => true,
                'message' => sprintf( 'Deleted %d performer(s).', count( $ids ) ),
            )
        );
    }

    /**
     * Batch load data to prevent N+1 queries.
     *
     * @param array $performers Array of performer objects.
     */
    private function prime_performer_caches( array $performers ): void {
        if ( empty( $performers ) ) {
            return;
        }

        global $wpdb;

        // Collect IDs for batch loading.
        $user_ids      = array();
        $profile_ids   = array();
        $performer_ids = array();

        foreach ( $performers as $performer ) {
            if ( $performer->user_id ) {
                $user_ids[] = (int) $performer->user_id;
            }
            if ( $performer->profile_id ) {
                $profile_ids[] = (int) $performer->profile_id;
            }
            $performer_ids[] = (int) $performer->id;
        }

        // Prime user object cache.
        if ( ! empty( $user_ids ) ) {
            $user_ids_unique = array_unique( $user_ids );
            // Pre-fetch users - this primes the WP object cache.
            new WP_User_Query(
                array(
                    'include' => $user_ids_unique,
                    'fields'  => 'all_with_meta',
                )
            );
        }

        // Prime post meta cache for profiles.
        if ( ! empty( $profile_ids ) ) {
            $profile_ids_unique = array_unique( $profile_ids );
            update_meta_cache( 'post', $profile_ids_unique );
        }

        // Batch load microsites into a static cache.
        if ( ! empty( $performer_ids ) ) {
            $placeholders     = implode( ',', array_fill( 0, count( $performer_ids ), '%d' ) );
            $microsites_table = $wpdb->prefix . 'pb_microsites';
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $microsites = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$microsites_table} WHERE performer_id IN ({$placeholders})",
                    $performer_ids
                )
            );

            // Store in static property for format_performer to use.
            self::$microsites_cache = array();
            foreach ( $microsites as $microsite ) {
                self::$microsites_cache[ (int) $microsite->performer_id ] = $microsite;
            }
        }
    }

    /**
     * Static cache for microsites to avoid N+1 queries.
     *
     * @var array
     */
    private static array $microsites_cache = array();

    /**
     * Format performer for API response.
     *
     * @param object $performer Performer database row.
     * @return array|null Formatted performer data or null.
     */
    private function format_performer( $performer ) {
        if ( ! $performer ) {
            return null;
        }

        $user = get_userdata( $performer->user_id );

        // Get meta from profile if exists.
        $stage_name = '';
        if ( $performer->profile_id ) {
            $stage_name = get_post_meta( $performer->profile_id, '_pb_stage_name', true );
            if ( empty( $stage_name ) ) {
                $stage_name = get_the_title( $performer->profile_id );
            }
        }

        // Get microsite info from cache or database.
        $performer_id = (int) $performer->id;
        $microsite = self::$microsites_cache[ $performer_id ]
            ?? Peanut_Booker_Database::get_row( 'microsites', array( 'performer_id' => $performer_id ) );

        return array(
            'id'                   => (int) $performer->id,
            'user_id'              => (int) $performer->user_id,
            'profile_id'           => (int) $performer->profile_id,
            'stage_name'           => $stage_name ?: ( $user ? $user->display_name : '' ),
            'email'                => $user ? $user->user_email : '',
            'tier'                 => $performer->tier ?: 'free',
            'achievement_level'    => $performer->achievement_level ?: 'bronze',
            'achievement_score'    => (int) $performer->achievement_score,
            'completed_bookings'   => (int) $performer->completed_bookings,
            'average_rating'       => (float) $performer->average_rating,
            'total_reviews'        => (int) $performer->total_reviews,
            'profile_completeness' => (int) $performer->profile_completeness,
            'hourly_rate'          => (float) $performer->hourly_rate,
            'deposit_percentage'   => (float) $performer->deposit_percentage,
            'service_radius'       => (int) $performer->service_radius,
            'is_verified'          => (bool) $performer->is_verified,
            'is_featured'          => (bool) $performer->is_featured,
            'status'               => $performer->status ?: 'active',
            'location_city'        => $performer->profile_id ? get_post_meta( $performer->profile_id, '_pb_location_city', true ) : '',
            'location_state'       => $performer->profile_id ? get_post_meta( $performer->profile_id, '_pb_location_state', true ) : '',
            // Microsite info.
            'microsite_id'         => $microsite ? (int) $microsite->id : null,
            'microsite_status'     => $microsite ? $microsite->status : null,
            'microsite_slug'       => $microsite ? $microsite->slug : null,
            'microsite_created_at' => $microsite ? $microsite->created_at : null,
            'microsite_views'      => $microsite ? (int) $microsite->view_count : null,
            'created_at'           => $performer->created_at,
            'updated_at'           => $performer->updated_at,
        );
    }

    /**
     * Get performer editor data (combined performer + microsite data).
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error Response object or error.
     */
    public function get_performer_editor( $request ) {
        $performer_id = (int) $request['id'];
        $performer = Peanut_Booker_Database::get_row( 'performers', array( 'id' => $performer_id ) );

        if ( ! $performer ) {
            return new WP_Error( 'not_found', 'Performer not found.', array( 'status' => 404 ) );
        }

        // Get or create microsite for this performer.
        $microsite = Peanut_Booker_Database::get_row( 'microsites', array( 'performer_id' => $performer_id ) );

        if ( ! $microsite ) {
            // Create a new microsite for this performer.
            $stage_name = '';
            if ( $performer->profile_id ) {
                $stage_name = get_post_meta( $performer->profile_id, '_pb_stage_name', true );
                if ( empty( $stage_name ) ) {
                    $stage_name = get_the_title( $performer->profile_id );
                }
            }
            $microsite_data = array(
                'performer_id'    => $performer_id,
                'user_id'         => $performer->user_id,
                'status'          => 'pending',
                'slug'            => sanitize_title( $stage_name ?: 'performer' ) . '-' . $performer_id,
                'design_settings' => wp_json_encode( $this->get_performer_default_design_settings() ),
                'created_at'      => current_time( 'mysql' ),
                'updated_at'      => current_time( 'mysql' ),
            );
            Peanut_Booker_Database::insert( 'microsites', $microsite_data );
            $microsite = Peanut_Booker_Database::get_row( 'microsites', array( 'performer_id' => $performer_id ) );
        }

        return rest_ensure_response( $this->build_performer_editor_data( $performer, $microsite ) );
    }

    /**
     * Update performer editor data.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error Response object or error.
     */
    public function update_performer_editor( $request ) {
        $performer_id = (int) $request['id'];
        $performer = Peanut_Booker_Database::get_row( 'performers', array( 'id' => $performer_id ) );

        if ( ! $performer ) {
            return new WP_Error( 'not_found', 'Performer not found.', array( 'status' => 404 ) );
        }

        $params = $request->get_json_params();

        // Update performer profile data.
        if ( ! empty( $params['performer'] ) && $performer->profile_id ) {
            $profile_data = $params['performer'];
            $profile_id = $performer->profile_id;

            if ( isset( $profile_data['stage_name'] ) ) {
                update_post_meta( $profile_id, '_pb_stage_name', sanitize_text_field( $profile_data['stage_name'] ) );
            }
            if ( isset( $profile_data['tagline'] ) ) {
                update_post_meta( $profile_id, '_pb_tagline', sanitize_text_field( $profile_data['tagline'] ) );
            }
            if ( isset( $profile_data['bio'] ) ) {
                wp_update_post( array( 'ID' => $profile_id, 'post_content' => wp_kses_post( $profile_data['bio'] ) ) );
            }
            if ( isset( $profile_data['location_city'] ) ) {
                update_post_meta( $profile_id, '_pb_location_city', sanitize_text_field( $profile_data['location_city'] ) );
            }
            if ( isset( $profile_data['location_state'] ) ) {
                update_post_meta( $profile_id, '_pb_location_state', sanitize_text_field( $profile_data['location_state'] ) );
            }

            $performer_update = array();
            if ( isset( $profile_data['hourly_rate'] ) ) {
                $performer_update['hourly_rate'] = (float) $profile_data['hourly_rate'];
            }
            if ( ! empty( $performer_update ) ) {
                $performer_update['updated_at'] = current_time( 'mysql' );
                Peanut_Booker_Database::update( 'performers', $performer_update, array( 'id' => $performer_id ) );
            }
        }

        // Update microsite data.
        $microsite = Peanut_Booker_Database::get_row( 'microsites', array( 'performer_id' => $performer_id ) );

        if ( $microsite ) {
            $microsite_update = array();

            if ( ! empty( $params['microsite'] ) ) {
                $ms = $params['microsite'];
                if ( isset( $ms['slug'] ) ) {
                    $microsite_update['slug'] = sanitize_title( $ms['slug'] );
                }
                if ( isset( $ms['status'] ) ) {
                    $microsite_update['status'] = sanitize_text_field( $ms['status'] );
                }
            }

            if ( ! empty( $params['design_settings'] ) ) {
                $microsite_update['design_settings'] = wp_json_encode( $params['design_settings'] );
            }

            if ( ! empty( $microsite_update ) ) {
                $microsite_update['updated_at'] = current_time( 'mysql' );
                Peanut_Booker_Database::update( 'microsites', $microsite_update, array( 'id' => $microsite->id ) );
                $microsite = Peanut_Booker_Database::get_row( 'microsites', array( 'id' => $microsite->id ) );
            }
        }

        $performer = Peanut_Booker_Database::get_row( 'performers', array( 'id' => $performer_id ) );
        return rest_ensure_response( $this->build_performer_editor_data( $performer, $microsite ) );
    }

    /**
     * Build performer editor data.
     *
     * @param object $performer Performer database row.
     * @param object $microsite Microsite database row.
     * @return array Editor data.
     */
    private function build_performer_editor_data( $performer, $microsite ) {
        $performer_data = null;

        if ( $performer && $performer->profile_id ) {
            $profile_id = $performer->profile_id;
            $performer_data = array(
                'id'                 => (int) $performer->id,
                'stage_name'         => html_entity_decode( get_post_meta( $profile_id, '_pb_stage_name', true ) ?: get_the_title( $profile_id ), ENT_QUOTES, 'UTF-8' ),
                'tagline'            => get_post_meta( $profile_id, '_pb_tagline', true ),
                'bio'                => get_post_field( 'post_content', $profile_id ),
                'hourly_rate'        => (float) $performer->hourly_rate,
                'minimum_booking'    => (int) ( $performer->minimum_booking ?? 1 ),
                'deposit_percentage' => (float) ( $performer->deposit_percentage ?? 25 ),
                'location_city'      => get_post_meta( $profile_id, '_pb_location_city', true ),
                'location_state'     => get_post_meta( $profile_id, '_pb_location_state', true ),
                'profile_photo'      => get_the_post_thumbnail_url( $profile_id, 'large' ) ?: '',
                'gallery'            => $this->get_performer_gallery( $profile_id ),
            );
        }

        // Get reviews.
        // Reviews table uses reviewee_id (user_id of performer) not performer_id.
        $reviews = array();
        if ( $performer && $performer->user_id ) {
            $review_results = Peanut_Booker_Database::get_results(
                'reviews',
                array( 'reviewee_id' => $performer->user_id, 'is_visible' => 1 ),
                'created_at',
                'DESC',
                5
            );
            foreach ( $review_results as $review ) {
                $reviews[] = array(
                    'id'       => (int) $review->id,
                    'rating'   => (int) $review->rating,
                    'content'  => $review->content,
                    'reviewer' => get_user_meta( $review->reviewer_id, 'first_name', true ) ?: 'Customer',
                );
            }
        }

        // Get external gigs.
        $external_gigs = $this->get_performer_external_gigs( $performer->id );

        // Parse design settings.
        $design_settings = $this->get_performer_default_design_settings();
        if ( $microsite && ! empty( $microsite->design_settings ) ) {
            $saved_settings = json_decode( $microsite->design_settings, true ) ?: array();
            $design_settings = wp_parse_args( $saved_settings, $design_settings );
        }

        return array(
            'microsite'     => $microsite ? $this->format_microsite( $microsite ) : null,
            'performer'     => $performer_data,
            'reviews'       => $reviews,
            'external_gigs' => $external_gigs,
            'preview_url'   => $microsite ? home_url( '/performer/' . $microsite->slug . '/?preview=1' ) : '',
        );
    }

    /**
     * Get default design settings for performer microsites.
     *
     * @return array Default design settings.
     */
    private function get_performer_default_design_settings() {
        return array(
            'template'            => 'classic',
            'primary_color'       => '#3b82f6',
            'secondary_color'     => '#1e40af',
            'background_color'    => '#ffffff',
            'text_color'          => '#1e293b',
            'font_family'         => 'Inter',
            'show_reviews'        => true,
            'show_calendar'       => true,
            'show_booking_button' => true,
            'custom_css'          => '',
            'social_links'        => array(),
            'layout_settings'     => array(
                'hero_style'           => 'full_width',
                'sections_order'       => array( 'hero', 'bio', 'gallery', 'reviews', 'calendar', 'social', 'booking' ),
                'show_external_gigs'   => true,
                'external_gig_privacy' => 'public',
            ),
        );
    }

    /**
     * Get performer gallery.
     *
     * @param int $profile_id Profile post ID.
     * @return array Gallery items.
     */
    private function get_performer_gallery( $profile_id ) {
        $gallery = get_post_meta( $profile_id, '_pb_gallery', true );
        if ( empty( $gallery ) || ! is_array( $gallery ) ) {
            return array();
        }
        return array_map( function( $id ) {
            return array( 'id' => (int) $id, 'url' => wp_get_attachment_url( $id ) ?: '' );
        }, $gallery );
    }

    /**
     * Get performer analytics.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response object.
     */
    public function get_performer_analytics( $request ) {
        global $wpdb;

        $performer_id = (int) $request['id'];
        $days = (int) ( $request->get_param( 'days' ) ?: 30 );
        $microsite = Peanut_Booker_Database::get_row( 'microsites', array( 'performer_id' => $performer_id ) );

        if ( ! $microsite ) {
            return rest_ensure_response( array(
                'total_views'     => 0,
                'unique_visitors' => 0,
                'booking_clicks'  => 0,
                'views_by_day'    => array(),
                'top_referrers'   => array(),
                'popular_hours'   => array(),
            ) );
        }

        $analytics_table = $wpdb->prefix . 'pb_microsite_analytics';
        $start_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

        // Get totals.
        $totals = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COALESCE(SUM(page_views), 0) as total_views,
                COALESCE(SUM(unique_visitors), 0) as unique_visitors,
                COALESCE(SUM(booking_clicks), 0) as booking_clicks
             FROM $analytics_table
             WHERE microsite_id = %d AND date >= %s",
            $microsite->id,
            $start_date
        ) );

        // Get views by day.
        $views_by_day = $wpdb->get_results( $wpdb->prepare(
            "SELECT date, SUM(page_views) as views
             FROM $analytics_table
             WHERE microsite_id = %d AND date >= %s
             GROUP BY date
             ORDER BY date ASC",
            $microsite->id,
            $start_date
        ) );

        // Get top referrers.
        $top_referrers = $wpdb->get_results( $wpdb->prepare(
            "SELECT referrer_domain as domain, SUM(page_views) as count
             FROM $analytics_table
             WHERE microsite_id = %d AND date >= %s AND referrer_domain IS NOT NULL AND referrer_domain != ''
             GROUP BY referrer_domain
             ORDER BY count DESC
             LIMIT 10",
            $microsite->id,
            $start_date
        ) );

        // Get popular hours.
        $popular_hours = $wpdb->get_results( $wpdb->prepare(
            "SELECT hour_of_day as hour, SUM(page_views) as count
             FROM $analytics_table
             WHERE microsite_id = %d AND date >= %s AND hour_of_day IS NOT NULL
             GROUP BY hour_of_day
             ORDER BY hour_of_day ASC",
            $microsite->id,
            $start_date
        ) );

        return rest_ensure_response( array(
            'total_views'     => (int) ( $totals->total_views ?? 0 ),
            'unique_visitors' => (int) ( $totals->unique_visitors ?? 0 ),
            'booking_clicks'  => (int) ( $totals->booking_clicks ?? 0 ),
            'views_by_day'    => array_map( function( $row ) {
                return array( 'date' => $row->date, 'views' => (int) $row->views );
            }, $views_by_day ?: array() ),
            'top_referrers'   => array_map( function( $row ) {
                return array( 'domain' => $row->domain, 'count' => (int) $row->count );
            }, $top_referrers ?: array() ),
            'popular_hours'   => array_map( function( $row ) {
                return array( 'hour' => (int) $row->hour, 'count' => (int) $row->count );
            }, $popular_hours ?: array() ),
        ) );
    }

    /**
     * Get external gigs for a performer (endpoint).
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response object.
     */
    public function get_performer_external_gigs_endpoint( $request ) {
        $performer_id = (int) $request['id'];
        $gigs = $this->get_performer_external_gigs( $performer_id );
        return rest_ensure_response( array( 'data' => $gigs ) );
    }

    /**
     * Create external gig for a performer.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error Response object or error.
     */
    public function create_performer_external_gig( $request ) {
        $performer_id = (int) $request['id'];
        $params = $request->get_json_params();

        if ( empty( $params['date'] ) || empty( $params['event_name'] ) ) {
            return new WP_Error( 'invalid_data', 'Date and event name are required.', array( 'status' => 400 ) );
        }

        // Validate ticket_url if provided (must be HTTPS).
        $ticket_url = '';
        if ( ! empty( $params['ticket_url'] ) ) {
            $ticket_url = esc_url_raw( $params['ticket_url'] );
            if ( ! empty( $ticket_url ) && strpos( $ticket_url, 'https://' ) !== 0 ) {
                return new WP_Error( 'invalid_url', 'Ticket URL must use HTTPS.', array( 'status' => 400 ) );
            }
        }

        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'pb_availability',
            array(
                'performer_id'   => $performer_id,
                'date'           => sanitize_text_field( $params['date'] ),
                'event_name'     => sanitize_text_field( $params['event_name'] ),
                'venue_name'     => sanitize_text_field( $params['venue_name'] ?? '' ),
                'event_location' => sanitize_text_field( $params['event_location'] ?? '' ),
                'event_time'     => ! empty( $params['event_time'] ) ? sanitize_text_field( $params['event_time'] ) : null,
                'is_public'      => isset( $params['is_public'] ) ? ( $params['is_public'] ? 1 : 0 ) : 1,
                'ticket_url'     => $ticket_url,
                'block_type'     => 'external_gig',
                'status'         => 'blocked',
                'created_at'     => current_time( 'mysql' ),
                'updated_at'     => current_time( 'mysql' ),
            )
        );

        $gig_id = $wpdb->insert_id;
        $gig = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}pb_availability WHERE id = %d",
            $gig_id
        ) );

        return rest_ensure_response( array(
            'success' => true,
            'id'      => $gig_id,
            'data'    => $this->format_external_gig( $gig ),
        ) );
    }

    /**
     * Update external gig.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error Response object or error.
     */
    public function update_performer_external_gig( $request ) {
        $gig_id = (int) $request['gig_id'];
        $performer_id = (int) $request['id'];
        $params = $request->get_json_params();

        global $wpdb;

        // Verify gig belongs to performer.
        $gig = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}pb_availability WHERE id = %d AND performer_id = %d AND block_type = 'external_gig'",
            $gig_id,
            $performer_id
        ) );

        if ( ! $gig ) {
            return new WP_Error( 'not_found', 'External gig not found.', array( 'status' => 404 ) );
        }

        // Validate ticket_url if provided.
        $ticket_url = $gig->ticket_url;
        if ( isset( $params['ticket_url'] ) ) {
            if ( empty( $params['ticket_url'] ) ) {
                $ticket_url = '';
            } else {
                $ticket_url = esc_url_raw( $params['ticket_url'] );
                if ( ! empty( $ticket_url ) && strpos( $ticket_url, 'https://' ) !== 0 ) {
                    return new WP_Error( 'invalid_url', 'Ticket URL must use HTTPS.', array( 'status' => 400 ) );
                }
            }
        }

        $update_data = array(
            'updated_at' => current_time( 'mysql' ),
        );

        if ( isset( $params['date'] ) ) {
            $update_data['date'] = sanitize_text_field( $params['date'] );
        }
        if ( isset( $params['event_name'] ) ) {
            $update_data['event_name'] = sanitize_text_field( $params['event_name'] );
        }
        if ( isset( $params['venue_name'] ) ) {
            $update_data['venue_name'] = sanitize_text_field( $params['venue_name'] );
        }
        if ( isset( $params['event_location'] ) ) {
            $update_data['event_location'] = sanitize_text_field( $params['event_location'] );
        }
        if ( isset( $params['event_time'] ) ) {
            $update_data['event_time'] = ! empty( $params['event_time'] ) ? sanitize_text_field( $params['event_time'] ) : null;
        }
        if ( isset( $params['is_public'] ) ) {
            $update_data['is_public'] = $params['is_public'] ? 1 : 0;
        }
        if ( isset( $params['ticket_url'] ) ) {
            $update_data['ticket_url'] = $ticket_url;
        }

        $wpdb->update(
            $wpdb->prefix . 'pb_availability',
            $update_data,
            array( 'id' => $gig_id )
        );

        $updated_gig = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}pb_availability WHERE id = %d",
            $gig_id
        ) );

        return rest_ensure_response( array(
            'success' => true,
            'data'    => $this->format_external_gig( $updated_gig ),
        ) );
    }

    /**
     * Delete external gig.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error Response object or error.
     */
    public function delete_performer_external_gig( $request ) {
        $gig_id = (int) $request['gig_id'];
        $performer_id = (int) $request['id'];

        global $wpdb;

        // Verify gig belongs to performer.
        $gig = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}pb_availability WHERE id = %d AND performer_id = %d AND block_type = 'external_gig'",
            $gig_id,
            $performer_id
        ) );

        if ( ! $gig ) {
            return new WP_Error( 'not_found', 'External gig not found.', array( 'status' => 404 ) );
        }

        $wpdb->delete( $wpdb->prefix . 'pb_availability', array( 'id' => $gig_id ) );

        return rest_ensure_response( array( 'success' => true ) );
    }

    /**
     * Get external gigs for performer.
     *
     * @param int $performer_id Performer ID.
     * @return array External gigs.
     */
    private function get_performer_external_gigs( $performer_id ) {
        global $wpdb;
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}pb_availability WHERE performer_id = %d AND block_type = 'external_gig' ORDER BY date DESC",
            $performer_id
        ) );

        return array_map( array( $this, 'format_external_gig' ), $results ?: array() );
    }

    /**
     * Format external gig for API response.
     *
     * @param object $gig Gig database row.
     * @return array Formatted gig data.
     */
    private function format_external_gig( $gig ) {
        return array(
            'id'             => (int) $gig->id,
            'performer_id'   => (int) $gig->performer_id,
            'date'           => $gig->date,
            'event_name'     => $gig->event_name ?? '',
            'venue_name'     => $gig->venue_name ?? '',
            'event_location' => $gig->event_location ?? '',
            'event_time'     => $gig->event_time ?? null,
            'is_public'      => (bool) ( $gig->is_public ?? true ),
            'ticket_url'     => $gig->ticket_url ?? '',
        );
    }

    /**
     * Get bookings for a specific performer (for performer dashboard).
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error Response object or error.
     */
    public function get_performer_bookings( $request ) {
        global $wpdb;

        $performer_id = (int) $request->get_param( 'id' );
        $status       = $request->get_param( 'status' );
        $page         = (int) $request->get_param( 'page' );
        $per_page     = (int) $request->get_param( 'per_page' );
        $offset       = ( $page - 1 ) * $per_page;

        // Verify performer exists.
        $performer = Peanut_Booker_Database::get_row( 'performers', array( 'id' => $performer_id ) );
        if ( ! $performer ) {
            return new WP_Error( 'performer_not_found', 'Performer not found', array( 'status' => 404 ) );
        }

        $bookings_table = Peanut_Booker_Database::get_table( 'bookings' );

        $where_clauses = array( 'b.performer_id = %d' );
        $where_values  = array( $performer_id );

        if ( ! empty( $status ) ) {
            $where_clauses[] = 'b.booking_status = %s';
            $where_values[]  = $status;
        }

        $where_sql = implode( ' AND ', $where_clauses );

        // Get total count.
        $count_sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM $bookings_table b WHERE $where_sql",
            $where_values
        );
        $total = (int) $wpdb->get_var( $count_sql );

        // Get bookings with customer info.
        $sql = $wpdb->prepare(
            "SELECT b.*,
                    u_customer.display_name as customer_name,
                    u_customer.user_email as customer_email
             FROM $bookings_table b
             LEFT JOIN {$wpdb->users} u_customer ON b.customer_id = u_customer.ID
             WHERE $where_sql
             ORDER BY b.event_date DESC
             LIMIT %d OFFSET %d",
            array_merge( $where_values, array( $per_page, $offset ) )
        );

        $results = $wpdb->get_results( $sql );

        // Format bookings.
        $bookings = array();
        foreach ( $results as $booking ) {
            $bookings[] = array(
                'id'              => (int) $booking->id,
                'customer_name'   => $booking->customer_name ?: 'Guest',
                'customer_email'  => $booking->customer_email ?: '',
                'event_date'      => $booking->event_date,
                'event_time'      => $booking->event_time ?? '',
                'event_type'      => $booking->event_type ?? '',
                'venue'           => $booking->venue ?? '',
                'booking_status'  => $booking->booking_status,
                'total_amount'    => (float) $booking->total_amount,
                'deposit_amount'  => (float) ( $booking->deposit_amount ?? 0 ),
                'created_at'      => $booking->created_at,
            );
        }

        return rest_ensure_response( array(
            'success' => true,
            'data'    => array(
                'data'        => $bookings,
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $per_page,
                'total_pages' => ceil( $total / $per_page ),
            ),
        ) );
    }

    /**
     * Get reviews for a specific performer.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error Response object or error.
     */
    public function get_performer_reviews( $request ) {
        $performer_id = (int) $request['id'];
        $performer = Peanut_Booker_Database::get_row( 'performers', array( 'id' => $performer_id ) );

        if ( ! $performer ) {
            return new WP_Error( 'not_found', 'Performer not found.', array( 'status' => 404 ) );
        }

        $reviews = array();
        if ( $performer->user_id ) {
            $review_results = Peanut_Booker_Database::get_results(
                'reviews',
                array( 'reviewee_id' => $performer->user_id ),
                'created_at',
                'DESC',
                50
            );
            foreach ( $review_results as $review ) {
                $reviews[] = array(
                    'id'         => (int) $review->id,
                    'rating'     => (int) $review->rating,
                    'content'    => $review->content,
                    'reviewer'   => get_user_meta( $review->reviewer_id, 'first_name', true ) ?: 'Customer',
                    'is_visible' => (bool) $review->is_visible,
                    'created_at' => $review->created_at,
                );
            }
        }

        return rest_ensure_response( array(
            'success' => true,
            'data'    => $reviews,
        ) );
    }
}
