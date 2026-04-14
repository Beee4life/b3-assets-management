<?php
    /**
     * Class B3AssetsManagementTest
     */
    class B3AssetsManagementTest extends B3AssetsManagement {
        public function __construct() {
            parent::__construct(); // Inherit base settings and hooks

            // add_action( 'admin_init',           [ $this, 'connection_test' ] );
            // add_action( 'admin_init',           [ $this, 'upload_delete_test' ] );
            // add_action( 'admin_init',           [ $this, 'just_a_test' ] );
        }

        public function connection_test() {
            try {
                $storage = $this->get_gcs_client();
                $bucket  = $storage->bucket( $this->settings[ 'gsc-bucket-name' ] );

                if ( $bucket->exists() ) {
                    wp_die( "✅ Connection Successful! Bucket found." );
                } else {
                    wp_die( "❌ Connection failed: Bucket does not exist." );
                }
            } catch ( Exception $e ) {
                wp_die( "❌ Error: " . $e->getMessage() );
            }
        }

        public function upload_delete_test() {
            $storage = $this->get_gcs_client();
            $bucket  = $storage->bucket( $this->settings[ 'gsc-bucket-name' ] );

            $object = $bucket->upload( 'Hello World', [
                'name' => 'test-connection-delete.txt',
            ] );

            if ( $object && $object->exists() ) {
                error_log( "✅ File uploaded!" );

                $object->delete();
                error_log( "✅ File deleted! Your permissions are perfect." );
            }
        }

        public function just_a_test() {
            $asset_args = [
                'post_type'      => 'attachment',
                'posts_per_page' => 100,
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
        }

        public static function get_instance() {
            static $instance;

            if ( null === $instance ) {
                $instance = new self();
            }

            return $instance;
        }
    }

    B3AssetsManagementTest::get_instance();
