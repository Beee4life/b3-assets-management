# B3 Assets management

## Getting Up and Running

NOTE: This plugin is built on a Bedrock setup, which makes use of .env files. It is not yet built for the use of constants, but will be soon.

### Install Google Storage Client

You need to have google cloud storage client installed. It's not included yet with this plugin, so run the following command:

```
composer require google/cloud-storage
```

You can either run it from your project root, or from the plugin's folder.

### Setup Google Service Account

You need to create a service account for your google bucket. That will give you a downloadable json file with your credentials needed to connect to the bucket.

Upload this to the folder above your `public_html` folder.

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

Best not to run them together, but individually.

### Available filters
**b3_assets_folder**

```
function pb_upload_folder( $folder ) {
    return 'app';
}
add_filter( 'b3_assets_folder', 'pb_upload_folder' );
```

### Available actions
**do_after_upload**

```
function b3_do_after_gsc_upload( $attachment_id, $file_path ) {
    // do something
}
add_action( 'after_successful_gsc_upload', 'b3_do_after_gsc_upload', 10, 2 );
```
#### @TODO

* add option to delete media after upload
* add metadata if file is successfully uploaded
* add admin page for bucket settings
