<?php
    /*
        Plugin Name: B3 Assets management (dev)
        Description: Manages assets handling for Google Cloud Storage
        Version: 0.6
        Author: Beee
        Author URI: https://berryplasman.com
    */
    use Google\Cloud\Storage\StorageClient;

    /*
     * Class B3AssetsManagement
     */
    class B3AssetsManagement {
        protected array $settings = array();
        private $storage_client = null;

        public function __construct() {
            $this->settings = [
                'block_connection'  => (bool) (getenv('BLOCK_CONNECTION') ?: get_option('b3_gsc_bucket_name')),
                'gsc-bucket-name'   => getenv('GSC_BUCKET_NAME') ?: get_option('b3_gsc_bucket_name'),
                'gsc-key-file-path' => getenv('GSC_KEY_FILE_PATH') ?: '',
                'version'           => '0.6',
            ];

            // (de)activation hooks
            register_activation_hook( __FILE__,     [ $this, 'plugin_activation' ] );
            register_deactivation_hook( __FILE__,   [ $this, 'plugin_deactivation' ] );

            if ( static::class === 'B3AssetsManagement' ) {
                add_action( 'admin_menu', [ $this, 'add_admin_pages' ] );
                add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ $this, 'plugin_settings_link' ] );
                add_action( 'remove_assets_by_cron', [ $this, 'remove_local_files_by_cron' ] );
            }

            add_action( 'admin_init',                       [ $this, 'form_handling' ] );
            add_action( 'admin_enqueue_scripts',            [ $this, 'enqueue_admin_style' ] );
            add_action( 'remove_local_file',                [ $this, 'remove_local_file' ] );
            add_action( 'add_assets_to_gcs',                [ $this, 'add_to_bucket' ], 10, 2 );
            add_action( 'delete_assets_from_gcs',           [ $this, 'delete_from_bucket' ] );
            add_action( 'delete_local_folder',              [ $this, 'check_folder_to_delete' ] );
            add_action( 'delete_attachment',                [ $this, 'delete_media_straight_away' ], 10, 2 );

            add_filter( 'wp_generate_attachment_metadata',  [ $this, 'filter_save_post_metadata' ], 1, 2 );
            add_filter( 'wp_generate_attachment_metadata',  [ $this, 'filter_get_post_metadata' ], 25, 2 );
            add_filter( 'wp_generate_attachment_metadata',  [ $this, 'after_insert_asset' ], 30, 2 );
            add_filter( 'wp_handle_upload_prefilter',       [ $this, 'rename_file' ] );

            include_once 'B3AssetsManagementTest.php';
        }

        public function plugin_activation() {
            $cron = 'remove_assets_by_cron';
            if ( ! wp_next_scheduled( $cron ) ) {
                $scheduled = wp_schedule_event( time(), 'daily', $cron );
                if ( is_wp_error( $scheduled ) ) {
                    error_log( sprintf( 'Cron %s != scheduled', $cron ) );
                } else {
                    error_log( sprintf( 'Cron %s == scheduled', $cron ) );
                }
            }
        }

        public function plugin_deactivation() {
            $ts_cron_reminder = wp_next_scheduled( 'remove_assets_by_cron' );
            wp_unschedule_event( $ts_cron_reminder, 'remove_assets_by_cron' );
        }

        public function add_admin_pages() {
            include_once 'b3-admin-page.php';
            add_submenu_page( 'upload.php', 'Assets Management', 'Assets Management', 'manage_options', 'b3-assets-management', 'b3_assets_management_admin' );
        }

        public function form_handling() {
            if ( isset( $_POST[ 'b3_settings_nonce' ] ) ) {
                if ( ! wp_verify_nonce( $_POST[ 'b3_settings_nonce' ], 'b3-settings-nonce' ) ) {
                    $message = esc_html__( 'Link expired', 'b3-assets-management');
                    self::b3am_errors()->add( 'error_settings_saved', $message );
                } else {
                    // all ok
                    if ( ! empty( $_POST[ 'b3_bucket_name' ] ) ) {
                        update_option( 'b3_gsc_bucket_name', sanitize_text_field( $_POST[ 'b3_bucket_name' ] ) );
                    } else {
                        delete_option( 'b3_gsc_bucket_name' );
                    }
                    if ( ! empty( $_POST[ 'b3_delete_by_cron' ] ) ) {
                        update_option( 'b3_delete_by_cron', (int) $_POST[ 'b3_delete_by_cron' ] );
                    } else {
                        delete_option( 'b3_delete_by_cron' );
                    }
                    $message = esc_html__( 'Settings saved.', 'b3-assets-management');
                    self::b3am_errors()->add( 'success_settings_saved', $message );
                }
            }
        }

        public function enqueue_admin_style() {
            wp_register_style( 'b3am', plugins_url( 'style.css', __FILE__ ), false, get_plugin_data( __FILE__ )[ 'Version' ] );
            wp_enqueue_style( 'b3am' );
        }

        protected function get_gcs_client() {
            if ( null === $this->storage_client ) {
                $this->storage_client = new Google\Cloud\Storage\StorageClient( [
                    'keyFilePath' => $this->settings[ 'gsc-key-file-path' ],
                ] );
            }

            return $this->storage_client;
        }

        public function remove_local_files_by_cron() {
            $delete = get_option( 'b3_delete_by_cron' );

            if ( $delete ) {
                $attachment_ids = $this->get_posts_to_delete();

                if ( is_array( $attachment_ids ) && ! empty( $attachment_ids ) ) {
                    foreach ( $attachment_ids as $asset_id ) {
                        do_action( 'remove_local_file', $asset_id );
                    }
                }
            }
        }

        public function remove_local_file( $asset_id ) {
            if ( ! $asset_id ) {
                return;
            }

            $paths = self::get_file_paths( $asset_id );
            $sizes = get_intermediate_image_sizes();

            if ( is_array( $paths ) && ! empty( $paths ) ) {
                foreach( $sizes as $size ) {
                    $local_url = get_attached_file( $asset_id, $size );

                    if ( file_exists( $local_url ) ) {
                        unlink( $local_url );
                        do_action( 'delete_local_folder', $local_url );
                    }
                }
            }
        }

        public function strip_file_name( string $path ) {
            if ( ! $path ) {
                return;
            }

            $parsed = explode( '/', $path );
            unset( $parsed[ count( $parsed ) - 1 ] ); // remove last item, which is file name
            $folder_path = implode( '/', $parsed ); // build path back up again

            return $folder_path;
        }

        public function check_folder_to_delete( string $path ) {
            if ( ! $path ) {
                return;
            }

            $folder_path = $this->strip_file_name( $path );

            if ( is_dir( $folder_path ) ) {
                $folder_contents = pb_scan_folder( $folder_path );

                if ( empty( $folder_contents ) ) {
                    rmdir( $folder_path );
                }
            }
        }

        public function add_to_bucket( int $attachment_id, array $file_paths ) {
            if ( empty( $file_paths ) ) {
                return;
            }

            static $retry_counts = [];
            if ( ! isset( $retry_counts[ $attachment_id ] ) ) {
                $retry_counts[ $attachment_id ] = 0;
            }

            try {
                $storage = $this->get_gcs_client();
                $bucket  = $storage->bucket( $this->settings['gsc-bucket-name'] );
                $wp_dir  = wp_upload_dir();

                foreach ( $file_paths as $file_path ) {
                    // 1. Resolve the physical file on the server (handles the /shared/ symlink)
                    $full_path = sprintf( '%s/%s', $wp_dir[ 'basedir' ], $file_path );
                    $real_path = realpath( $full_path );

                    if ( ! $real_path || ! file_exists( $real_path ) ) {
                        error_log( "GCS Error: Physical file not found at $full_path. Skipping." );
                        continue;
                    }

                    // 2. Clean the incoming $file_path
                    $clean_name = ltrim( $file_path, '/' );
                    if ( str_contains( $clean_name, 'uploads/' ) ) {
                        $parts      = explode( 'uploads/', $clean_name );
                        $clean_name = end( $parts );
                    }

                    // 3. Construct the Bucket Destination
                    $bucket_destination = sprintf( '%s/uploads/%s', apply_filters( 'b3_content_folder', 'wp-content' ), ltrim( $clean_name, '/' ) );

                    if ( false === $this->settings[ 'block_connection' ] ) {
                        $bucket->upload(
                            file_get_contents( $real_path ),
                            [ 'name' => $bucket_destination ]
                        );

                        // Only do if Google confirms the object exists in the bucket
                        if ( $bucket && $bucket->exists() ) {
                            // do_action( 'after_successful_gsc_upload', $attachment_id, $file_path );
                        }
                    }
                }

            } catch ( \Exception $e ) {
                error_log( sprintf( "GCS Error for Attachment %d: %s", $attachment_id, $e->getMessage() ) );

                // Only retry once
                if ( $retry_counts[ $attachment_id ] < 1 ) {
                    $retry_counts[ $attachment_id ]++;
                    error_log( "Retrying upload for Attachment $attachment_id..." );

                    // Call the method directly instead of do_action to avoid overhead
                    $this->add_to_bucket( $attachment_id, $file_paths );
                } else {
                    error_log( "GCS Max retries reached for Attachment $attachment_id. Giving up." );
                }
            }
        }

        public function delete_from_bucket( array $file_ids ) {
            if ( empty( $file_ids ) ) {
                return;
            }

            $storage       = $this->get_gcs_client();
            $bucket        = $storage->bucket( $this->settings[ 'gsc-bucket-name' ] );
            $wp_upload_dir = wp_upload_dir();

            foreach ( (array) $file_ids as $asset_id ) {
                $paths = self::get_file_paths( $asset_id );
                $gsc_path = 'app/uploads';

                foreach ( $paths as $path ) {
                    $upload_path = sprintf( '%s/%s', $gsc_path, $path );

                    if ( false === $this->settings[ 'block_connection' ] ) {
                        try {
                            $object = $bucket->object( $upload_path );
                            // Check if exists to avoid 404 exceptions in logs
                            if ( $object->exists() ) {
                                $object->delete();
                            }
                        } catch ( \Exception $e ) {
                            error_log( "GCS Deletion Error: " . $e->getMessage() );
                        }
                    } else {
                        error_log( sprintf( 'Delete: %s', $upload_path ) );
                    }
                }
            }
        }

        public function filter_save_post_metadata( $metadata, $post_id ) {
            update_post_meta( $post_id, '_uploaded_to_bucket', 1 );
            update_post_meta( $post_id, 'temp_metadata', serialize( $metadata ) );
            return $metadata;
        }

        public function filter_get_post_metadata( $metadata, $post_id ) {
            $stored_meta = get_post_meta( $post_id, 'temp_metadata', true );
            if ( ! empty( $stored_meta ) ) {
                delete_post_meta( $post_id, 'temp_metadata' );
                return unserialize( $stored_meta );
            }

            return $metadata;
        }

        public function after_insert_asset( array $metadata, int $attachment_id ) : array {
            if ( is_array( $metadata ) && $attachment_id ) {
                if ( false === $this->settings[ 'block_connection' ] ) {
                    $file_paths = B3AssetsManagement::get_file_paths( $attachment_id, $metadata );

                    if ( ! empty( $file_paths ) ) {
                        do_action( 'add_assets_to_gcs', $attachment_id, $file_paths );
                    }
                }
            }

            return $metadata;
        }

        public function get_posts_to_delete() {
            $asset_args = [
                'post_type'      => 'attachment',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'date_query'     => [
                    [
                        'after'     => '48 hours ago', // Targets assets newer than 48 hours
                        'inclusive' => true,
                    ],
                    [
                        'before'    => '24 hours ago', // Targets assets older than 24 hours
                        'inclusive' => true,
                    ],
                ],
            ];
            $assets = get_posts( apply_filters( 'b3_assets_query_args', $asset_args ) );

            return $assets;
        }

        public function rename_file( $file ) {
            $file[ 'name' ] = strtolower( $file[ 'name' ] );

            return $file;
        }

        // Delete asset straight away (when attachment gets deleted)
        public function delete_media_straight_away( int $attachment_id, WP_Post $post ) {
            if ( false === $this->settings[ 'block_connection' ] ) {
                do_action( 'delete_assets_from_gcs', [ $attachment_id ] );
            }
        }

        /*
         * Get file paths for files to upload
         * Uses relative upload path to match bucket structure perfectly.
         */
        public static function get_file_paths( $post_id, $metadata = null ) {
            $paths = [];
            $full_path = get_attached_file( $post_id );

            if ( ! $full_path ) {
                return $paths;
            }

            $relative_path = _wp_relative_upload_path( $full_path );
            $paths[]       = $relative_path;

            if ( ! empty( $metadata ) && isset( $metadata[ 'sizes' ] ) ) {
                $base_dir = dirname( $relative_path );
                foreach( $metadata[ 'sizes' ] as $size ) {
                    $paths[] = $base_dir . '/' . $size[ 'file' ];
                }
            }

            return array_unique( $paths );
        }

        public function plugin_settings_link( $links ) {
            $settings_link = sprintf( '<a href="%s">%s</a>', admin_url( 'media.php?page=b3-assets-management' ), esc_html__( 'Settings', 'b3-onboarding' ) );
            array_unshift( $links, $settings_link );

            return $links;
        }

        public static function b3am_errors() {
            static $wp_error; // Will hold global variable safely

            return isset( $wp_error ) ? $wp_error : ( $wp_error = new WP_Error( null, null, null ) );
        }

        public static function show_admin_notices() {
            if ( $codes = self::b3am_errors()->get_error_codes() ) {
                if ( is_wp_error( self::b3am_errors() ) ) {

                    // Loop error codes and display errors
                    $span_class = false;
                    $prefix     = false;
                    foreach ( $codes as $code ) {
                        if ( strpos( $code, 'success' ) !== false ) {
                            $span_class = 'updated ';
                            $prefix     = false;
                        } elseif ( strpos( $code, 'warning' ) !== false ) {
                            $span_class = 'notice-warning ';
                            $prefix     = esc_html( __( 'Warning', 'csv2wp' ) );
                        } elseif ( strpos( $code, 'info' ) !== false ) {
                            $span_class = 'notice-info ';
                            $prefix     = false;
                        } else {
                            $span_class = 'notice-error ';
                            $prefix     = esc_html( __( 'Error', 'csv2wp' ) );
                        }
                    }
                    echo '<div id="message" class="notice ' . $span_class . 'csv2wp__notice is-dismissible">';
                    foreach ( $codes as $code ) {
                        $message = self::b3am_errors()->get_error_message( $code );
                        echo '<div class="">';
                        if ( true == $prefix ) {
                            echo '<strong>' . $prefix . ':</strong> ';
                        }
                        echo $message;
                        echo '</div>';
                    }
                    echo '</div>';
                }
            }
        }

        public static function get_instance() {
            static $instance;

            if ( null === $instance ) {
                $instance = new self();
            }

            return $instance;
        }
    }

    B3AssetsManagement::get_instance();
