<?php
/*
Plugin Name: Friday Sync
Plugin URI: https://wordpress.com/it/
Description: Allows user to sync post with multiple websites.
Version: 1.3.1
Author: Luca Terribili
Author URI: https://www.linkedin.com/in/luca-terribili-10916524/
Domain Path: /languages
Text Domain: friday_sync_text_domain
License: GPL2 or later
*/

/*
	Copyright 2019  wpsyncpost.productguide.best  (email : kp4coder@gmail.com)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License, version 2, as
	published by the Free Software Foundation.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	The PHP code portions are distributed under the GPL license. If not otherwise stated, all
	images, manuals, cascading stylesheets and included JavaScript are NOT GPL.
*/

// plugin definitions 
define( 'SPS_PLUGIN', '/friday-sync/');

// directory define
define( 'SPS_PLUGIN_DIR', WP_PLUGIN_DIR.SPS_PLUGIN);
define( 'SPS_INCLUDES_DIR', SPS_PLUGIN_DIR.'includes/' );

define( 'SPS_ASSETS_DIR', SPS_PLUGIN_DIR.'assets/' );
define( 'SPS_CSS_DIR', SPS_ASSETS_DIR.'css/' );
define( 'SPS_JS_DIR', SPS_ASSETS_DIR.'js/' );
define( 'SPS_IMAGES_DIR', SPS_ASSETS_DIR.'images/' );

// URL define
define( 'SPS_PLUGIN_URL', WP_PLUGIN_URL.SPS_PLUGIN);

define( 'SPS_ASSETS_URL', SPS_PLUGIN_URL.'assets/');
define( 'SPS_IMAGES_URL', SPS_ASSETS_URL.'images/');
define( 'SPS_CSS_URL', SPS_ASSETS_URL.'css/');
define( 'SPS_JS_URL', SPS_ASSETS_URL.'js/');

// define text domain
define( 'SPS_txt_domain', 'friday_sync_text_domain' );

global $sps_version;
$sps_version = '1.3.1';

require plugin_dir_path(__FILE__) . 'includes/sps_settings.class.php';

class FridaySync {

    protected $sps_setting = '';

    protected $sps_tools = 'sync_tools';

    protected $setting_class;
    
	public function __construct() {
        global $wpdb;
        $this->sps_setting = 'sps_setting';
        $this->setting_class = new SPS_Settings();
		register_activation_hook( __FILE__,  array( &$this, 'sps_install' ) );
        register_deactivation_hook( __FILE__, array( &$this, 'sps_deactivation' ) );
		add_action( 'admin_menu', array( $this, 'sps_add_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'sps_enqueue_scripts' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'sps_front_enqueue_scripts' ) );
        add_action( 'plugins_loaded', array( $this, 'sps_load_textdomain' ) );
        add_action('wp_ajax_process_bulk_taxonony', [$this, 'processBulkTaxonomy' ] );
        
	}

    public function sps_load_textdomain() {
        load_plugin_textdomain( SPS_txt_domain, false, basename(dirname(__FILE__)) . '/languages' ); //Loads plugin text domain for the translation
        do_action('SPS_txt_domain');
    }

	static function sps_install() {

		global $wpdb, $sps, $sps_version;

        $charset_collate = $wpdb->get_charset_collate();
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

        update_option( "sps_plugin", true );
        update_option( "sps_version", $sps_version );

	}

    static function sps_deactivation() {
        // deactivation process here
    }

	function sps_get_sub_menu() {
		$sps_admin_menu = array(
			array(
				'name' => __('Setting', SPS_txt_domain),
				'cap'  => 'manage_options',
				'slug' => $this->sps_setting,
			),
            array(
                'name' => __('Tools', SPS_txt_domain),
                'cap'  => 'manage_options',
                'slug' => $this->sps_tools,
            ),
		);
		return $sps_admin_menu;
	}

	function sps_add_menu() {

		$sps_main_page_name = __('Sync Post', SPS_txt_domain);
		$sps_main_page_capa = 'manage_options';
		$sps_main_page_slug = $this->sps_setting; 

		$sps_get_sub_menu   = $this->sps_get_sub_menu();
		/* set capablity here.... Right now manage_options capability given to all page and sub pages. <span class="dashicons dashicons-money"></span>*/	 
		add_menu_page($sps_main_page_name, $sps_main_page_name, $sps_main_page_capa, $sps_main_page_slug, array( &$this, 'sps_route' ), 'dashicons-update-alt', 11 );

		foreach ($sps_get_sub_menu as $sps_menu_key => $sps_menu_value) {
			add_submenu_page(
				$sps_main_page_slug, 
				$sps_menu_value['name'], 
				$sps_menu_value['name'], 
				$sps_menu_value['cap'], 
				$sps_menu_value['slug'], 
				array( $this, 'sps_route') 
			);	
		}
	}

	function sps_is_activate(){
		if(get_option("sps_plugin")) {
			return true;
		} else {
			return false;
		}
	}

	function sps_admin_slugs() {
		$sps_pages_slug = array(
			$this->sps_setting,
            $this->sps_tools,
		);
		return $sps_pages_slug;
	}

	function sps_is_page() {
		if( isset( $_REQUEST['page'] ) && in_array( $_REQUEST['page'], $this->sps_admin_slugs() ) ) {
			return true;
		} else {
			return false;
		}
	} 

    function sps_admin_msg( $key ) { 
        $admin_msg = array(
            "sps_msg" => __("Enter message here", SPS_txt_domain)
        );

        if( $key == 'script' ){
            $script = '<script type="text/javascript">';
            $script.= 'var __sps_msg = '.json_encode($admin_msg);
            $script.= '</script>';
            return $script;
        } else {
            return isset($admin_msg[$key]) ? $admin_msg[$key] : false;
        }
    }

	function sps_enqueue_scripts() {
		global $sps_version;
		/* must register style and than enqueue */
		if( $this->sps_is_page() ) {
			/*********** register and enqueue styles ***************/
            wp_register_style( 'sps_admin_style_css',  SPS_CSS_URL.'sps_admin_style.css', false, $sps_version );
            wp_enqueue_style( 'sps_admin_style_css' );
			/*********** register and enqueue scripts ***************/
            echo $this->sps_admin_msg( 'script' );
            wp_register_script( 'sps_admin_js', SPS_JS_URL.'sps_admin_js.js', 'jQuery', $sps_version, true );
			wp_enqueue_script( 'jquery' );
            wp_enqueue_script( 'sps_admin_js' );
		}
        wp_enqueue_script('friday-sync-post', SPS_JS_URL.'friday_sync_post.js', 'jQuery', $sps_version, true);
    }

    function sps_front_enqueue_scripts() {
        global $sps_version;
        // need to check here if its front section than enqueue script
        // if( $ncm_template_loader->ncm_is_front_page() ) {
        /*********** register and enqueue styles ***************/

            wp_register_style( 
                'sps_front_css',  
                SPS_CSS_URL.'sps_front_style.css?rand='.rand(1,999), 
                false, 
                $sps_version 
            );

            wp_enqueue_style( 'sps_front_css' );


            /*********** register and enqueue scripts ***************/
            echo "<script> var ajaxurl = '".admin_url( 'admin-ajax.php' )."'; </script>";

            wp_register_script( 
                'sps_front_js', 
                SPS_JS_URL.'sps_front_js.js?rand='.rand(1,999), 
                'jQuery', 
                $sps_version, 
                true 
            );

            wp_enqueue_script( 'jquery' );
            wp_enqueue_script( 'sps_front_js' );
        // }
        
	}

	public function sps_route() {
		if( isset($_REQUEST['page']) && $_REQUEST['page'] != '' ){
			switch ( $_REQUEST['page'] ) {
				case $this->sps_setting:
					$this->setting_class->sps_display_settings();
					break;
                case $this->sps_tools:
                    $this->setting_class->sps_display_tools();
                    break;
				default:
					_e( "Product Listing will be here", SPS_txt_domain );
					break;
			}
		}
	}

    function sps_write_log( $content = '', $file_name = 'sps_log.txt' ) {
        $file = __DIR__ . '/log/' . $file_name;    
        $file_content = "=============== Write At => " . date( "y-m-d H:i:s" ) . " =============== \r\n";
        $file_content .= $content . "\r\n\r\n";
        file_put_contents( $file, $file_content, FILE_APPEND | LOCK_EX );
    }

    /**
     * @return void
     */
    public function processBulkTaxonomy()
    {
        global $sps_settings, $sps_sync;
        $general_option = $sps_settings->sps_get_settings_func();
        if (!empty($general_option) && isset($general_option['sps_host_name']) && !empty($general_option['sps_host_name'])) {
            $offset = $_POST['last_row'];
            $limit = 20;
            $terms = get_terms( array(
                'hide_empty' => false,
                'orderby'    => 'term_id',
                'order'      => 'ASC',
                'offset' => $offset,
                'number' => $limit,
            ) );
            if(sizeof($terms) < 1) {
                wp_send_json([
                    'status' => 'finish',
                    'message' => 'Sincronizzazione completata'
                ], 200);
            } else {
                $args = ['terms' => $terms, 'is_bulk' => true];
                $sps_sync->add_remote_data($args);
                $url = $args['sps']['host_name'] . "/?sps_action=bulk_tax_sync";
                $request = wp_remote_post($url, array('body' => $args));
                $response = json_decode(wp_remote_retrieve_body( $request ), true);
                if(is_null($response)){
                    var_dump(wp_remote_retrieve_body( $request ));
                    wp_die();
                }
                if($response['status'] === 'fail') {
                    wp_send_json([
                        'status' => 'fail',
                        'message' => 'Si è verificato un errore',
                    ], 500);
                } else {
                    wp_send_json([
                        'status' => 'continue',
                        'message' => 'Sincronizzazione in corso',
                        'offset' => $offset + $limit
                    ], 200);
                }

            }
        } else {
            wp_send_json([
                'status' => 'fail',
                'message' => 'Impossibile procedere con la sincronizzazione',
            ], 306);
        }
        wp_die();
    }
    
}


// begin!
global $sps;
$sps = new FridaySync();

if( $sps->sps_is_activate() && file_exists( SPS_INCLUDES_DIR . "sps_settings.class.php" ) ) {
    include_once( SPS_INCLUDES_DIR . "sps_settings.class.php" );
}

if( $sps->sps_is_activate() && file_exists( SPS_INCLUDES_DIR . "sps_sync.class.php" ) ) {
    include_once( SPS_INCLUDES_DIR . "sps_sync.class.php" );
}

if( $sps->sps_is_activate() && file_exists( SPS_INCLUDES_DIR . "sps_post_meta.class.php" ) ) {
    include_once( SPS_INCLUDES_DIR . "sps_post_meta.class.php" );
}