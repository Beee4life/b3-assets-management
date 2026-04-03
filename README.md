# B3 Assets management

## Getting Up and Running

NOTE: This plugin is built on a Bedrock setup, which makes use of .env files. It is not yet built for the use of constants, but will be soon.

### Install Google Storage Client

You need to have Google Cloud Storage client installed. It's not included yet with this plugin, so run the following command:

```
composer require google/cloud-storage
```

You can either run it from your project root, or from the plugin's folder.

### Setup Google Service Account

You need to create a service account for your google bucket. That will give you a downloadable json file with your credentials needed to connect to the bucket.

Upload this to the folder ABOVE your `public_html` (or `web`) folder.

Add the following values to your .env file.
```
GSC_BUCKET_ID='your-bucket-id'
GSC_BUCKET_NAME='bucketurl.com'
GSC_KEY_FILE_PATH='/absolute/path/to/file-you-just-downloaded.json'
```

### Available tests

There are 3 test functions in the test file.
1. Test to see if the connection works
2. Test to see if the uploading and deleting works
3. Test to see if any attachments are queried for possible deletion

Best not to run them together, but one by one.

### Available filters
**b3_assets_folder**

Default the uploads are stored in `wp-content/uploads`, but the bedrock setup uses `app/uploads`.

That's why you can filter the content folder with the filter `b3_content_folder`.

```
add_filter( 'b3_content_folder', function( $folder ) {
    return 'app';
} );
```

### Available actions
**after_successful_gsc_upload**

Assets are not deleted from your webserver right away, but you can achieve this with a simple action.

See example below. You don't need to delete the file of course, that depends on your setup. You can do other things as well of course.

This action fires after it's confirmed the asset has been moved to the bucket.

```
add_action( 'after_successful_gsc_upload', function( $attachment_id, $file_path ) {
    do_action( 'remove_local_file', $file_path );
}, 10, 2 );
```

Do not use `wp_delete_file($attachment_id)` because if the first (resized) image size is successfully uploaded and you would remove the entire attachment, all other resized image sizes will also be deleted.

### Meta
Every uploaded asset will get a meta value of 1, with the key `_uploaded_to_bucket`.
