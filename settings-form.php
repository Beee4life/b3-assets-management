<?php
    if ( ! defined( 'ABSPATH' ) ) exit;

    $bucket_name    = get_option( 'b3_gsc_bucket_name' );
    $delete_by_cron = get_option( 'b3_delete_by_cron' );
?>
<div class="b3-form">

    <h2>Settings</h2>

    <form name="" class="" method="POST" action="">
        <input type="hidden" name="b3_settings_nonce" value="<?php echo wp_create_nonce( 'b3-settings-nonce' ); ?>" />

        <p>
            <?php esc_html_e( 'If you see any values as placeholder, that means you have added the values in your .env file already.', 'b3-assets-management' ); ?>
        </p>

        <div class="row">
            <label for="bucket-name">Bucket name</label>
            <input type="text" id="bucket-name" name="b3_bucket_name" value="<?php echo $bucket_name; ?>" placeholder="<?php echo getenv( 'GSC_BUCKET_NAME' ); ?>" />
        </div>

        <div class="row">
            <label for="delete_by_cron">Delete assets by cron</label>
            <input type="checkbox" id="delete_by_cron" name="b3_delete_by_cron" value="1" <?php if ( $delete_by_cron == 1 ) echo 'checked="checked"'; ?> />
        </div>

        <div class="row row--submit">
            <input type="submit" value="<?php esc_attr_e( 'Save settings', 'b3-assets-management' ); ?>" />
        </div>
    </form>
</div>
