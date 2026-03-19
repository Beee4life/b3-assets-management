<?php
    /**
     * Output for dashboard page
     */
    function b3_assets_management_admin() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Sorry, you do not have sufficient permissions to access this page.', 'b3-assets-management' ) );
        }
        ?>

        <div class="wrap assets-management">
            <div id="icon-options-general" class="icon32"><br/></div>

            <h2>Assets Management</h2>

            <?php B3AssetsManagement::show_admin_notices(); ?>

            <div class="admin_left">
                <div class="content">
                    <?php include 'settings-form.php'; ?>
                </div>
            </div>
        </div>

<?php }
