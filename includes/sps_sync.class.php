<?php
if (!class_exists('SPS_Sync')) {

    require plugin_dir_path(__FILE__) . 'traits/TermSyncSupport.php';

    class SPS_Sync
    {

        use TermSyncSupport;

        protected $is_website_post = true;
        protected $post_old_title = '';

        /**
         *
         */
        public function __construct()
        {
            add_filter('wp_insert_post_data', array($this, 'filter_post_data'), '99', 2);
            // save tags to post for guttenburg because save post not get the tags in guttenburg.
            //add_action("rest_insert_post", array($this, "sps_rest_insert_post"), 10, 3);
            //add_action("save_post", array($this, "sps_save_post"), 99, 3);
            add_action('wp_ajax_friday_sync', [$this, 'friday_handle_sps_save_post']);
            add_action("init", array($this, "sps_get_request"));
            add_action("spsp_after_save_data", array($this, "spsp_grab_content_images"), 10, 2);
            add_action('wp_trash_post', [$this, 'sync_trash_post']);
            add_action('untrash_post', [$this, 'sync_untrash_post'], 10, 2);
            add_action('delete_post', [$this, 'sync_delete_post'], 10, 2);
            add_action('add_attachment', [$this, 'new_post_check']);
            // HOOK TAXONOMY
            add_action('saved_term', [$this, 'sps_add_term'], 99, 3);
        }

        /**
         * @param $term_id
         * @param int $tt_id
         * @param string $taxonomy
         * @return void
         */
        public function sps_add_term($term_id, $tt_id, $taxonomy)
        {
            $term = get_term($term_id, 'category', ARRAY_A);
            $array_map1 = [];
            foreach (get_term_meta($term['term_id'], '', true) as $key => $value) {
                $array_map1[$key] = array_shift($value);
            }
            $array_map = $array_map1;
            $term_metas = $array_map;
            $thumb = get_option('z_taxonomy_image'.$term_id);
            $args = ['term' => $term, 'is_taxonomy' => true, 'term_metas' => $term_metas, 'thumb' => $thumb, 'source_url' => site_url()];
            $this->add_remote_data($args);
            $url = $args['sps']['host_name'] . "/?sps_action=add_update_term";
            $request = wp_remote_post($url, array('body' => $args));
            // TODO NOTIFICA
            var_dump(wp_remote_retrieve_body($request));
        }

        /**
         * @param $post_id
         * @return void
         */
        public function new_post_check($post_id)
        {
            $post = get_post($post_id);
            if ($post->post_type === 'attachment') {
                $args = get_post($post_id, ARRAY_A);
                $this->add_remote_data($args);
                $url = $args['sps']['host_name'] . "/?sps_action=add_update_attachment";
                wp_remote_post($url, array('body' => $args));
            }
        }

        function filter_post_data($data, $postarr)
        {
            global $post_old_title;
            if (isset($postarr['ID']) && !empty($postarr['ID'])) {
                $old_data = get_posts(array('ID' => $postarr['ID']));
                if ($old_data && isset($old_data[0]->post_title) && $postarr != $old_data[0]->post_title) {
                    $post_old_title = $old_data[0]->post_title;
                }
            }

            return $data;
        }

        /**
         * @param $post
         * @param $reqest
         * @param $creating
         * @return void
         */
        public function sps_rest_insert_post($post, $reqest, $creating)
        {
            $json = $reqest->get_json_params();
            if (isset($json['tags']) && !empty($json['tags'])) {
                $this->sps_save_post($post->ID, $post, $json['tags']);
            }
        }

        public function sps_send_data_to($action, $args = array(), $sps_website = array())
        {
            global $wpdb, $sps, $sps_settings, $post_old_title;
            $general_option = $sps_settings->sps_get_settings_func();

            if (!empty($general_option) && isset($general_option['sps_host_name']) && !empty($general_option['sps_host_name'])) {
                foreach ($general_option['sps_host_name'] as $sps_key => $sps_value) {

                    $args['sps']['roles'] = isset($general_option['sps_roles_allowed'][$sps_key]['roles']) ? $general_option['sps_roles_allowed'][$sps_key]['roles'] : array();
                    $args['sps']['host_name'] = !empty($sps_value) ? $sps_value : '';
                    $args['sps']['strict_mode'] = isset($general_option['sps_strict_mode'][$sps_key]) ? $general_option['sps_strict_mode'][$sps_key] : 1;
                    $args['sps']['roles']['administrator'] = 'on';

                    $args['sps']['content_match'] = isset($general_option['sps_content_match'][$sps_key]) ? $general_option['sps_content_match'][$sps_key] : 'title';
                    $args['sps']['content_username'] = isset($general_option['sps_content_username'][$sps_key]) ? $general_option['sps_content_username'][$sps_key] : '';
                    $args['sps']['content_password'] = isset($general_option['sps_content_password'][$sps_key]) ? $general_option['sps_content_password'][$sps_key] : '';

                    if (isset($args['post_content']) && isset($args['sps']['strict_mode']) && $args['sps']['strict_mode']) {
                        $args['post_content'] = addslashes($args['post_content']);
                    } else {
                        $args['post_content'] = do_shortcode($args['post_content']);
                    }

                    $loggedin_user_role = wp_get_current_user();
                    $matched_role = array_intersect($loggedin_user_role->roles, array_keys($args['sps']['roles']));
                    $args['source_url'] = site_url();
                    if (!empty($sps_value) && !empty($matched_role) && in_array($sps_value, $sps_website)) {
                        return $this->sps_remote_post($action, $args);
                    }
                }
            }
        }

        /**
         * @param $action
         * @param $args
         * @return array|WP_Error
         */
        protected function sps_remote_post($action, $args = array())
        {
            do_action('spsp_before_send_data', $args);
            if (array_key_exists('meta', $args) && array_key_exists('attachments', $args['meta'])) {
                $attachments = json_decode($args['meta']['attachments'], true);
                $media_url = $args['sps']['host_name'] . "/?sps_action=add_update_attachment";
                foreach ($attachments['attachments'] as $file) {
                    $media_args = get_post($file['id'], ARRAY_A);
                    $this->add_data_to_args($media_args['ID'], $media_args);
                    wp_remote_post($media_url, array('body' => $media_args));
                }
            }
            $url = $args['sps']['host_name'] . "/?sps_action=" . $action;
            return wp_remote_post($url, array('body' => $args));
        }

        /**
         * @param $args
         * @return void
         */
        public function add_remote_data(&$args)
        {
            global $sps_settings;
            $general_option = $sps_settings->sps_get_settings_func();
            if (!empty($general_option) && isset($general_option['sps_host_name']) && !empty($general_option['sps_host_name'])) {
                foreach ($general_option['sps_host_name'] as $sps_key => $sps_value) {

                    $args['sps']['roles'] = isset($general_option['sps_roles_allowed'][$sps_key]['roles']) ? $general_option['sps_roles_allowed'][$sps_key]['roles'] : array();
                    $args['sps']['host_name'] = !empty($sps_value) ? $sps_value : '';
                    $args['sps']['strict_mode'] = isset($general_option['sps_strict_mode'][$sps_key]) ? $general_option['sps_strict_mode'][$sps_key] : 1;
                    $args['sps']['roles']['administrator'] = 'on';

                    $args['sps']['content_match'] = isset($general_option['sps_content_match'][$sps_key]) ? $general_option['sps_content_match'][$sps_key] : 'title';
                    $args['sps']['content_username'] = isset($general_option['sps_content_username'][$sps_key]) ? $general_option['sps_content_username'][$sps_key] : '';
                    $args['sps']['content_password'] = isset($general_option['sps_content_password'][$sps_key]) ? $general_option['sps_content_password'][$sps_key] : '';

                    if (isset($args['post_content']) && isset($args['sps']['strict_mode']) && $args['sps']['strict_mode']) {
                        $args['post_content'] = addslashes($args['post_content']);
                    } elseif (isset($args['is_bulk']) || isset($args['is_taxonomy'])) {
                        $args['post_content'] = '';
                    } else {
                        $args['post_content'] = do_shortcode($args['post_content']);
                    }

                    $loggedin_user_role = wp_get_current_user();
                    $matched_role = array_intersect($loggedin_user_role->roles, array_keys($args['sps']['roles']));
                }
            }
        }

        /**
         * @param $post_ID
         * @param $args
         * @return void
         */
        protected function add_data_to_args($post_ID, &$args)
        {

            $post_metas = get_post_meta($post_ID);
            if (!empty($post_metas)) {
                foreach ($post_metas as $meta_key => $meta_value) {
                    if ($meta_key != 'sps_website') {
                        $args['meta'][$meta_key] = isset($meta_value['0']) ? maybe_unserialize($meta_value['0']) : '';
                    }
                }
            }
            $this->add_remote_data($args);
        }

        public function friday_handle_sps_save_post()
        {
            if (isset($_POST['post_id'])) {
                $post = get_post($_POST['post_id']);
                return $this->sps_save_post($_POST['post_id'], $post);
            }
            wp_send_json([
                'status' => 'fail',
                'message' => 'Impossibile sincronizzare il Post',
            ], 306);
            wp_die();
        }

        /**
         * @param $post_ID
         * @param $post
         * @param $tagids
         * @return void
         */
        public function sps_save_post($post_ID, $post, $tagids = '')
        {
            global $sps_settings, $post_old_title;
            $general_option = $sps_settings->sps_get_settings_func();
            if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
                return;
            }
            $sps_website = (isset($general_option['sps_host_name'])) ? $general_option['sps_host_name'] : [];
            $status_not = array('auto-draft', 'trash', 'inherit', 'draft');
            if ($this->is_website_post && isset($post->post_status) && !in_array($post->post_status,
                    $status_not) && !empty($sps_website)) {

                $args = (array)$post;

                if (!empty($post_old_title)) {
                    $args['post_old_title'] = $post_old_title;
                } else {
                    $args['post_old_title'] = $args['post_title'];
                }

                if (has_post_thumbnail($post_ID)) {
                    $args['featured_image'] = get_the_post_thumbnail_url($post_ID);
                }

                $taxonomies = get_object_taxonomies($args['post_type']);
                if (!empty($taxonomies)) {
                    $taxonomies_data = array();
                    foreach ($taxonomies as $taxonomy) {
                        $taxonomies_data[$taxonomy] = wp_get_post_terms($post_ID, $taxonomy);
                    }
                    $args['taxonomies'] = $taxonomies_data;
                }

                $post_metas = get_post_meta($post_ID);
                if (!empty($post_metas)) {
                    foreach ($post_metas as $meta_key => $meta_value) {
                        if ($meta_key != 'sps_website') {
                            $args['meta'][$meta_key] = isset($meta_value['0']) ? maybe_unserialize($meta_value['0']) : '';
                        }
                    }
                }
                $request = $this->sps_send_data_to('add_update_post', $args, $sps_website);
                $response = json_decode(wp_remote_retrieve_body( $request ), true);
                if(is_null($response)){
                    var_dump(wp_remote_retrieve_body( $request ));
                    /*
                    wp_send_json([
                        'status' => 'warning',
                        'message' => 'Il sito in produzione non ha risposto correttamente'
                    ]);
                    */
                } else {
                    wp_send_json($response);
                }
            }
            wp_send_json([
                'status' => 'warning',
                'message' => 'Impossibile procedere. Controlla se il post possiede un titolo, se è in stato PUBBLICATO o se è stato imposato correttamente il sito mirror in produzione'
            ]);
            wp_die();
        }

        /**
         * @param $post_id
         * @return void
         */
        public function sync_trash_post($post_id)
        {
            $args = get_post($post_id, ARRAY_A);
            $this->add_remote_data($args);
            $url = $args['sps']['host_name'] . "/?sps_action=trash";
            wp_remote_post($url, array('body' => $args));
        }

        /**
         * @param $post_id
         * @return void
         */
        public function sync_untrash_post($post_id, $previous_status)
        {
            $args = get_post($post_id, ARRAY_A);
            $this->add_remote_data($args);
            $args['previous_status'] = $previous_status;
            $url = $args['sps']['host_name'] . "/?sps_action=untrash";
            wp_remote_post($url, array('body' => $args));
        }

        /**
         * @param $post_id
         * @param $post
         * @return void
         */
        public function sync_delete_post($post_id, $post)
        {
            $args = $post->to_array();
            $this->add_remote_data($args);
            $url = $args['sps']['host_name'] . "/?sps_action=delete";
            wp_remote_post($url, array('body' => $args));
        }

        /******************************************************************************************************************
         * RECEIVE
         *****************************************************************************************************************/

        /******************************************************************************************************************
         * RECEIVE
         *****************************************************************************************************************/

        function sps_check_data($content_mach, $post_data)
        {

            global $wpdb;

            $the_slug = $post_data['post_name'];
            $the_title = isset($post_data['post_old_title']) ? $post_data['post_old_title'] : '';

            $args_title = array(
                'title' => $the_title,
                'post_type' => $post_data['post_type']
            );
            $args_slug = array(
                'name' => $the_slug,
                'post_type' => $post_data['post_type']
            );

            $post_id = '';
            if ($content_mach == "title") {
                $my_posts = get_posts($args_title);
                if ($my_posts) {
                    $post_id = $my_posts[0]->ID;
                }
            } else {
                if ($content_mach == "title-slug") {
                    $my_posts = get_posts($args_title);
                    if ($my_posts) {
                        $post_id = $my_posts[0]->ID;
                    } else {
                        $my_posts2 = get_posts($args_slug);
                        if ($my_posts2) {
                            $post_id = $my_posts2[0]->ID;
                        }
                    }
                } else {
                    if ($content_mach == "slug") {
                        $my_posts = get_posts($args_slug);
                        if ($my_posts) {
                            $post_id = $my_posts[0]->ID;
                        }
                    } else {
                        if ($content_mach == "slug-title") {
                            $my_posts = get_posts($args_slug);
                            if ($my_posts) {
                                $post_id = $my_posts[0]->ID;
                            } else {
                                $my_posts = get_posts($args_title);
                                if ($my_posts) {
                                    $post_id = $my_posts[0]->ID;
                                }
                            }
                        }
                    }
                }
            }

            return $post_id;
        }

        function grab_image($url, $saveto)
        {

            $data = wp_remote_request($url);

            if (isset($data['body']) && isset($data['response']['code']) && !empty($data['response']['code'])) {
                $raw = $data['body'];
                if (file_exists($saveto)) {
                    unlink($saveto);
                }
                $fp = fopen($saveto, 'x');
                fwrite($fp, $raw);
                fclose($fp);
            }
        }

        /**
         * @param $author
         * @param $sps_sync_data
         * @return void
         */
        protected function sps_add_update_post($author, $sps_sync_data)
        {
            $return = array();
            if (!get_userdata($sps_sync_data['post_author'])) {
                $sps_sync_data['post_author'] = $author->ID;
            }
            $post_id = $sps_sync_data['ID'];
            $post_type = $sps_sync_data['post_type'] ?? 'post';
            $sps_sync_data['post_content'] = stripslashes($sps_sync_data['post_content']);
            // Replace link interni
            $sps_sync_data['post_content'] = str_replace($sps_sync_data['source_url'], site_url(),
                $sps_sync_data['post_content']);
            $post_action = '';
            $sync_post = get_post($post_id, OBJECT);
            if ($sync_post) {
                $post_action = 'edit';
                $sps_sync_data['ID'] = $post_id;
                $post_id = wp_update_post($sps_sync_data);
            } else {
                $post_action = 'add';
                unset($sps_sync_data['ID']);
                $sps_sync_data['import_id'] = $post_id;
                $post_id = wp_insert_post($sps_sync_data, true);
            }
            if (isset($sps_sync_data['taxonomies']) && !empty($sps_sync_data['taxonomies'])) {
                foreach ($sps_sync_data['taxonomies'] as $taxonomy => $texonomy_data) {
                    if (is_taxonomy_hierarchical($taxonomy)) {
                        // For hierarchical taxonomy - Categories
                        if (isset($texonomy_data) && !empty($texonomy_data)) {
                            $post_categories = array();
                            foreach ($texonomy_data as $category) {
                                $term = term_exists($category['name'], $taxonomy);
                                if ($term) {
                                    $post_categories[] = $term['term_id'];
                                } else {
                                    $tag_id = $this->insertNewTaxonomy($category);
                                    $post_categories[] = $tag_id;
                                }
                            }
                            wp_set_post_terms($post_id, $post_categories, $taxonomy, false);
                        } else {
                            wp_set_post_terms($post_id);
                        }
                    } elseif (isset($texonomy_data) && !empty($texonomy_data)) {
                        $post_tags = array();
                        foreach ($texonomy_data as $tag) {
                            $post_tags[] = $tag['name'];
                        }
                        wp_set_post_terms($post_id, $post_tags, $taxonomy, false);
                    } else {
                        wp_set_post_terms($post_id);
                    }
                }
            }
            $this->cleanAllPostMeta($post_id);
            if (isset($sps_sync_data['meta']) && !empty($sps_sync_data['meta'])) {
                foreach ($sps_sync_data['meta'] as $meta_key => $meta_value) {
                    update_post_meta($post_id, $meta_key, $meta_value);
                }
            }
            if (isset($sps_sync_data['featured_image']) && !empty($sps_sync_data['featured_image'])) {
                $attach_id = $this->setThumbImage($sps_sync_data['featured_image'], $sps_sync_data['source_url'], $post_id);
                set_post_thumbnail($post_id, $attach_id);
            }
            do_action('spsp_after_save_data', $post_id, $sps_sync_data);
            $permalink = get_permalink($post_id);
            wp_send_json([
                'status' => 'success',
                'message' => 'Tutto ok',
                'permalink' => $permalink,
            ]);
        }

        public function sps_get_request()
        {
            if (isset($_REQUEST['sps_action']) && !empty($_REQUEST['sps_action'])) {
                $this->is_website_post = false;
                $sps_sync_data = $_REQUEST;
                $sps_host_name = isset($sps_sync_data['sps']['host_name']) ? esc_url_raw($sps_sync_data['sps']['host_name']) : '';
                $sps_content_username = isset($sps_sync_data['sps']['content_username']) ? sanitize_text_field($sps_sync_data['sps']['content_username']) : '';
                $sps_content_password = isset($sps_sync_data['sps']['content_password']) ? sanitize_text_field($sps_sync_data['sps']['content_password']) : '';
                $sps_strict_mode = isset($sps_sync_data['sps']['strict_mode']) ? sanitize_text_field($sps_sync_data['sps']['strict_mode']) : '';
                $sps_content_match = isset($sps_sync_data['sps']['content_match']) ? sanitize_text_field($sps_sync_data['sps']['content_match']) : '';
                $sps_roles = isset($sps_sync_data['sps']['roles']) ? sanitize_text_field($sps_sync_data['sps']['roles']) : '';
                $sps_action = isset($sps_sync_data['sps_action']) ? 'sps_' . sanitize_text_field($sps_sync_data['sps_action']) : '';
                unset($sps_sync_data['sps']);

                $return = array();
                if (!empty($sps_content_username) && !empty($sps_content_password)) {
                    $author = wp_authenticate($sps_content_username, $sps_content_password);
                    if (isset($author->ID) && !empty($author->ID)) {
                        unset($sps_sync_data['sps']);
                        unset($sps_sync_data['sps_action']);
                        /*
                        if( isset($sps_sync_data['ID']) ) {
                            unset($sps_sync_data['ID']);
                        }
                        */
                        if ($sps_action == 'sps_authenticate') {
                            $return['status'] = __('success', SPS_txt_domain);
                            $return['msg'] = __('Authenitcate successfully.', SPS_txt_domain);
                        } else {

                            $sps_sync_data['content_match'] = $sps_content_match;
                            $return = call_user_func(array($this, $sps_action), $author, $sps_sync_data);
                        }
                    } else {
                        $return['status'] = 'fail';
                        $return['msg'] = __('Authenitcate failed.', SPS_txt_domain);
                    }
                } else {
                    $return['status'] = 'fail';
                    $return['msg'] = __('Username or Password is null.', SPS_txt_domain);
                }
                echo json_encode($return);
                exit;
            }
        }

        /**
         * @param $author
         * @param $sps_sync_data
         * @return void
         */
        protected function sps_add_update_attachment($author, $sps_sync_data)
        {
            $file_url = $sps_sync_data['guid'];
            $file_arr = explode('/', $file_url);
            $file_name = end($file_arr);
            $upload_dir = wp_upload_dir();
            $unique_file_name = wp_unique_filename($upload_dir['path'], $file_name);
            $filename = basename($unique_file_name);
            // Check folder permission and define file location
            if (wp_mkdir_p($upload_dir['path'])) {
                $file = $upload_dir['path'] . '/' . $filename;
            } else {
                $file = $upload_dir['basedir'] . '/' . $filename;
            }
            // Create the image  file on the server
            $this->grab_image($file_url, $file);
            // Check image file type
            $wp_filetype = wp_check_filetype($filename, null);
            $post = get_post($sps_sync_data['ID']);
            if (!$post || $post->post_type != 'attachment') {
                $attachment = array(
                    'import_id' => $sps_sync_data['ID'],
                    'post_mime_type' => $wp_filetype['type'],
                    'post_title' => sanitize_file_name($filename),
                    'post_content' => '',
                    'post_status' => 'inherit'
                );
                // Create the attachment
                $attach_id = wp_insert_attachment($attachment, $file, $post_id);
                // Include image.php
                require_once(ABSPATH . 'wp-admin/includes/image.php');
                // Define attachment metadata
                $attach_data = wp_generate_attachment_metadata($attach_id, $file);
                // Assign metadata to attachment
                wp_update_attachment_metadata($attach_id, $attach_data);
            }
        }

        /**
         * @param $post_id
         * @param $sps_sync_data
         * @return void
         */
        function spsp_grab_content_images($post_id, $sps_sync_data)
        {
            $post_content = stripslashes($sps_sync_data['post_content']);
            preg_match_all('/<img[^>]+>/i', $post_content, $images_tag);

            if (isset($images_tag[0]) && !empty($images_tag[0])) {
                foreach ($images_tag[0] as $img_tag) {
                    preg_match_all('/(alt|title|src)=("[^"]*")/i', $img_tag, $img_data);
                    if (isset($img_data[2][0]) && !empty($img_data[2][0]) && isset($img_data[1][0]) && $img_data[1][0] == 'src') {
                        $image_url = str_replace('"', '', $img_data[2][0]);

                        // check image is exists
                        $args = array(
                            'post_type' => 'attachment',
                            'post_status' => 'inherit',
                            'meta_query' => array(
                                array(
                                    'key' => 'old_site_url',
                                    'value' => $image_url,
                                    'compare' => '='
                                ),
                            ),
                        );

                        $attachment = new WP_Query($args);

                        if (empty($attachment->posts)) {

                            $image_arr = explode('/', $image_url);
                            $image_name = end($image_arr);
                            $upload_dir = wp_upload_dir();
                            $unique_file_name = wp_unique_filename($upload_dir['path'], $image_name);
                            $filename = basename($unique_file_name);

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
                                'post_content' => $image_url,
                                'post_status' => 'inherit'
                            );

                            $attach_id = wp_insert_attachment($attachment, $file, $post_id);
                            update_post_meta($attach_id, 'old_site_url', $image_url);
                        } else {
                            $attachment_posts = $attachment->posts;
                            $attach_id = $attachment_posts[0]->ID;
                        }

                        $new_image_url = wp_get_attachment_url($attach_id);
                        $post_content = str_replace($image_url, $new_image_url, $post_content);
                    }
                }

                wp_update_post(array('ID' => $post_id, 'post_content' => $post_content));
            }
        }

        /**
         * @param $author
         * @param $sps_sync_data
         * @return void
         */
        protected function sps_trash($author, $sps_sync_data)
        {
            wp_trash_post($sps_sync_data['ID']);
        }

        /**
         * @param $author
         * @param $sps_sync_data
         * @return void
         */
        protected function sps_untrash($author, $sps_sync_data)
        {
            wp_untrash_post($sps_sync_data['ID']);
        }

        /**
         * @param $author
         * @param $sps_sync_data
         * @return void
         */
        protected function sps_delete($author, $sps_sync_data)
        {
            wp_delete_post($sps_sync_data['ID']);
        }

        protected function sps_add_update_term($author, $sps_sync_data)
        {
            if(isset($sps_sync_data['term'])) {
                $term = $sps_sync_data['term'];
                if (!$this->checkIfTermIsSynced($term)) {
                    $this->tryToSyncTaxonomy($term);
                }
                wp_update_term( $term['term_id'], $term['taxonomy'], $term);
                //
                if(isset($sps_sync_data['term_metas'])) {
                    $metas = $sps_sync_data['term_metas'];
                    foreach ($metas as $key => $value) {
                        //TODO Recuperare real valore del termine
                        update_term_meta($term['term_id'], $key, $value);
                    }
                }
                if(isset($sps_sync_data['thumb']) && ($sps_sync_data['thumb'] != false)) {
                    $new_file = $this->setThumbImage($sps_sync_data['thumb'], $sps_sync_data['source_url'], 'url');
                    update_option('z_taxonomy_image'.$term['term_id'], $new_file);
                }
            }
        }

        protected function sps_bulk_tax_sync($author, $sps_sync_data)
        {
            if (isset($sps_sync_data['terms'])) {
                try {
                    foreach ($sps_sync_data['terms'] as $term) {
                        $cloned_term = get_term_by('slug', $term['slug'], $term['taxonomy']);
                        // check taxonomy exists
                        if ($this->checkIfTermIsSynced($term)) {
                            continue;
                        } else {
                            $this->tryToSyncTaxonomy($term);
                        }
                    }

                } catch (\Exception $exception) {
                    wp_send_json([
                        'status' => 'fail',
                        'message' => $exception->getMessage(),
                    ], 306);
                }
                wp_send_json([
                    'status' => 'success'
                ], 200);
            } else {
                wp_send_json([
                    'status' => 'fail'
                ], 306);
            }
        }

        /**
         * @param $post_id
         * @return void
         */
        protected function cleanAllPostMeta($post_id)
        {
            $metas = get_post_meta($post_id);
            foreach ($metas as $meta_key => $meta_value) {
                $this->deletePostMeta($post_id, $meta_key);
            }
        }

        /**
         * @param $post_id
         * @param $meta_key
         * @return void
         */
        protected function deletePostMeta($post_id, $meta_key)
        {
            global $wpdb;
            $wpdb->delete($wpdb->postmeta, array('post_id' => $post_id, 'meta_key' => $meta_key));
        }
    }

    global $sps_sync, $post_old_title;
    $sps_sync = new SPS_Sync();

}