<?php
    $bucket_id   = get_option( 'b3_gsc_bucket_id' );
    $bucket_name = get_option( 'b3_gsc_bucket_name' );
?>
<div class="b3-form">

    <h2>Settings</h2>

    <form name="" class="" method="POST" action="">
        <input type="hidden" name="b3_settings_nonce" value="<?php echo wp_create_nonce( 'b3-settings-nonce' ); ?>" />

        <div class="row">
            <label for="bucket-id">Bucket ID</label>
            <input type="text" id="bucket-id" name="b3_bucket_id" value="<?php echo $bucket_id; ?>" placeholder="<?php echo getenv( 'GSC_BUCKET_ID' ); ?>" />
        </div>

        <div class="row">
            <label for="bucket-name">Bucket name</label>
            <input type="text" id="bucket-name" name="b3_bucket_name" value="<?php echo $bucket_name; ?>" placeholder="<?php echo getenv( 'GSC_BUCKET_NAME' ); ?>" />
        </div>

        <div class="row row--submit">
            <input type="submit" value="<?php esc_attr_e( 'Save settings', 'b3-assets-management' ); ?>" />
        </div>
    </form>
</div>
