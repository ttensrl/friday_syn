<?php
declare(strict_types=1);

/**
 *
 */
trait SyncMediaSupport {

    protected function setThumbImage($image, $source_url, $return = 'id')
    {
        $image_url = $image;
        $image_arr = explode('/', $image);
        $image_name = end($image_arr);
        $upload_dir = wp_upload_dir();
        //$unique_file_name = wp_unique_filename($upload_dir['path'], $image_name);
        $candidate_file_name = $upload_dir['path'] . '/' . $image_name;
        $filename = basename($candidate_file_name);
        // Check folder permission and define file location
        if (wp_mkdir_p($upload_dir['path'])) {
            $file = $upload_dir['path'] . '/' . $filename;
        } else {
            $file = $upload_dir['basedir'] . '/' . $filename;
        }
        // Create the image  file on the server
        $this->grab_image($image_url, $file);
        // Check image file type
        $wp_filetype = wp_check_filetype($filename, null);
        // Set attachment data
        $attachment = array(
            'post_mime_type' => $wp_filetype['type'],
            'post_title' => sanitize_file_name($filename),
            'post_content' => '',
            'post_status' => 'inherit'
        );
        $new_url = str_replace($source_url, site_url(), $image_url);
        $attach_import_id = attachment_url_to_postid($new_url);
        if ($attach_import_id > 0) {
            $attachment['import_id'] = $attach_import_id;
        }
        // Create the attachment
        $attach_id = wp_insert_attachment($attachment, $file, $post_id);

        // Include image.php
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        // Define attachment metadata
        $attach_data = wp_generate_attachment_metadata($attach_id, $file);

        // Assign metadata to attachment
        wp_update_attachment_metadata($attach_id, $attach_data);
        return ($return === 'id') ? $attach_id : $new_url;
    }
}