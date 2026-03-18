<?php
    /*
        Plugin Name: B3 Assets management
        Description: Manages assets handling for Google Cloud Storage
        Version: 0.1
        Author: Beee
        Author URI: https://berryplasman.com
    */
    use Google\Cloud\Storage\StorageClient;

    /*
     * Class B3AssetsManagement
     */
    class B3AssetsManagement {
        protected array $settings = array();
        private $storage_client = null; // Store client here for reuse

        public function __construct() {
            $this->settings = [
                'gsc-bucket-name'   => getenv( 'GSC_BUCKET_NAME' ),
                'gsc-key-file-path' => getenv( 'GSC_KEY_FILE_PATH' ),
                'block_connection'  => getenv( 'BLOCK_CONNECTION' ) ? true : false,
            ];

            // (de)activation hooks
            register_activation_hook( __FILE__,     [ $this, 'pb_plugin_activation' ] );
            register_deactivation_hook( __FILE__,   [ $this, 'pb_plugin_deactivation' ] );

            add_action( 'remove_assets_by_cron',            [ $this, 'pb_remove_local_files' ] );
            add_action( 'add_assets_to_gcs',                [ $this, 'pb_add_to_bucket' ], 10, 2 );
            add_action( 'delete_assets_from_gcs',           [ $this, 'pb_delete_from_bucket' ] );
            add_action( 'delete_local_folder',              [ $this, 'pb_check_folder_to_delete' ] );
            add_action( 'delete_video',                     [ $this, 'pb_immediate_local_video_deletion' ] );
            add_action( 'delete_attachment',                [ $this, 'pb_delete_media_straight_away' ], 10, 2 );

            add_filter( 'wp_generate_attachment_metadata',  [ $this, 'pb_filter_save_post_metadata' ], 1, 2 );
            add_filter( 'wp_generate_attachment_metadata',  [ $this, 'pb_filter_get_post_metadata' ], 25, 2 );
            add_filter( 'wp_generate_attachment_metadata',  [ $this, 'pb_after_insert_asset' ], 30, 2 );
            add_filter( 'wp_handle_upload_prefilter',       [ $this, 'pb_rename_file' ] );

            include_once 'B3AssetsManagementTest.php';
        }

        /*
         * Function which runs upon plugin activation
         */
        public function pb_plugin_activation() {
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

        /*
         * Function which runs upon plugin deactivation to delete any cron jobs
         */
        public function pb_plugin_deactivation() {
            $ts_cron_reminder = wp_next_scheduled( 'remove_assets_by_cron' );
            wp_unschedule_event( $ts_cron_reminder, 'remove_assets_by_cron' );
        }

        /*
         * Reuses the StorageClient to avoid re-authenticating for every file.
         */
        protected function get_gcs_client() {
            if ( null === $this->storage_client ) {
                $this->storage_client = new Google\Cloud\Storage\StorageClient( [
                    'keyFilePath' => $this->settings[ 'gsc-key-file-path' ],
                ] );
            }

            return $this->storage_client;
        }

        /*
         * Function triggered by job to check if assets should be deleted
         */
        public function pb_remove_local_files() {
            $attachment_ids = $this->get_posts_to_delete();

            if ( is_array( $attachment_ids ) && ! empty( $attachment_ids ) ) {
                foreach ( $attachment_ids as $asset_id ) {
                    $paths         = self::get_file_paths( $asset_id );
                    $wp_upload_dir = wp_upload_dir();

                    foreach( $paths as $path ) {
                        $full_path = sprintf( '%s/%s', $wp_upload_dir[ 'basedir' ], $path );

                        if ( file_exists( $full_path ) ) {
                            unlink( $full_path );
                            do_action( 'delete_local_folder', $full_path );
                        }
                    }
                }
            }
        }

        public function pb_strip_file_name( string $path ) {
            if ( ! $path ) {
                return;
            }

            $parsed = explode( '/', $path );
            unset( $parsed[ count( $parsed ) - 1 ] ); // remove last item, which is file name
            $folder_path = implode( '/', $parsed ); // build path back up again

            return $folder_path;
        }

        public function pb_check_folder_to_delete( string $path ) {
            if ( ! $path ) {
                return;
            }

            $folder_path = $this->pb_strip_file_name( $path );

            if ( is_dir( $folder_path ) ) {
                $folder_contents = pb_scan_folder( $folder_path );

                if ( empty( $folder_contents ) ) {
                    rmdir( $folder_path );
                }
            }
        }

        public function pb_add_to_bucket( array $file_paths, int $attachment_id ) {
            if ( empty( $file_paths ) ) {
                return;
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
                    $bucket_destination = 'app/uploads/' . ltrim( $clean_name, '/' );

                    if ( false === $this->settings[ 'block_connection' ] ) {
                        $bucket->upload(
                            file_get_contents( $real_path ),
                            [ 'name' => $bucket_destination ]
                        );

                        // Only delete if Google confirms the object exists in the bucket
                        if ( $bucket && $bucket->exists() ) {
                            if ( wp_attachment_is( 'video', $attachment_id ) ) {
                                do_action( 'delete_video', $attachment_id );
                            }
                        }
                    }
                }
            } catch ( \Exception $e ) {
                error_log( sprintf( "GCS Critical Error: %s, but we'll retry", $e->getMessage() ) );

                // Use a static variable to prevent infinite recursion in the same request
                static $retry_count = 0;
                if ( $retry_count < 2 ) {
                    $retry_count++;
                    do_action( 'add_assets_to_gcs', $file_paths, $attachment_id );
                } else {
                    error_log( "GCS Max retries reached for Attachment $attachment_id. Giving up." );
                }
            }
        }

        public function pb_delete_from_bucket( array $file_ids ) {
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

        /*
         * Get file paths
         * Uses relative upload path to match bucket structure perfectly.
         */
        public static function get_file_paths( $post_id ) {
            $paths = [];
            $full_path = get_attached_file( $post_id );

            if ( ! $full_path ) {
                return $paths;
            }

            $relative_path = _wp_relative_upload_path( $full_path );
            $paths[]       = $relative_path;

            if ( wp_attachment_is_image( $post_id ) ) {
                // Add thumbnails
                $metadata = wp_get_attachment_metadata( $post_id );
                if ( isset( $metadata[ 'sizes' ] ) ) {
                    $base_dir = dirname( $relative_path );
                    foreach( $metadata[ 'sizes' ] as $size ) {
                        $paths[] = $base_dir . '/' . $size[ 'file' ];
                    }
                }
            } else {
                // No image, so no thumbnails needed
            }

            return array_unique( $paths );
        }

        public function pb_filter_save_post_metadata( $metadata, $post_id ) {
            update_post_meta( $post_id, 'temp_metadata', serialize( $metadata ) );
            return $metadata;
        }

        public function pb_filter_get_post_metadata( $metadata, $post_id ) {
            $stored_meta = get_post_meta( $post_id, 'temp_metadata', true );
            if ( ! empty( $stored_meta ) ) {
                return unserialize( $stored_meta );
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
            $assets = get_posts( $asset_args );

            return $assets;
        }

        public function pb_rename_file( $file ) {
            $file[ 'name' ] = strtolower( $file[ 'name' ] );

            return $file;
        }

        /*
         * Function which triggers the upload to bucket
         */
        public function pb_after_insert_asset( array $meta_data, int $attachment_id ) : array {
            if ( is_array( $meta_data ) && $attachment_id ) {
                if ( false === $this->settings[ 'block_connection' ] ) {
                    $paths = B3AssetsManagement::get_file_paths( $attachment_id );
                    if ( ! empty( $paths ) ) {
                        do_action( 'add_assets_to_gcs', $paths, $attachment_id );
                    }
                }
            }

            return $meta_data;
        }

        /*
         * Function to delete asset straight away (upon delete attachment)
         */
        public function pb_delete_media_straight_away( int $attachment_id, WP_Post $post ) {
            if ( false === $this->settings[ 'block_connection' ] ) {
                do_action( 'delete_assets_from_gcs', [ $attachment_id ] );
            }
        }

        /*
         * If it's a video we can delete it locally right away, since we don't need it for thumbnails.
         */
        public function pb_immediate_local_video_deletion( int $attachment_id ) {
            if ( wp_attachment_is( 'video', $attachment_id ) ) {
                $local_path = get_attached_file( $attachment_id );
                if ( file_exists( $local_path ) ) {
                    unlink( $local_path );
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
