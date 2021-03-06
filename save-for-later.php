<?php

if ( !defined( 'ABSPATH' ) )
	die( '-1' );

if ( ! class_exists( 'WC_Wishlist' ) ) {
	/**
	 * Main WooCommerce Wishlist Class
	 *
	 * Contains the main hooks, functions & vars for WooCommerce Wishlist
	 *
	 * @class WC_Wishlist
	 * @version 1.0
	 * @package WC_Wishlist
	 * @category Extension
	 * @author codearachnid
	 */
	class WC_Wishlist {

		protected static $instance;

		private $default_ajax_response = array(
			'msg' => null,
			'status' => false,
			'code' => null,
			'wishlist' => array(),
			'products' => array(),
			'product' => array()
		);

		public $path;
		public $dir;
		public $url;
		public $version = '1.0';

		const PLUGIN_NAME = 'WC: Save For Later';
		const MIN_WC_VERSION = '1.6.5';
		const MIN_WP_VERSION = '3.4';
		const MIN_PHP_VERSION = '5.3';
		const POST_TYPE = 'woocommerce_wishlist';

		function __construct() {

			// register lazy autoloading
			spl_autoload_register( 'self::lazy_loader' );

			$this->check_install();

			// enable the settings
			if ( is_admin() )
				new WC_Wishlist_Settings();

			// set core vars
			$this->path = self::get_plugin_path();
			$this->dir = trailingslashit( basename( $this->path ) );
			$this->url = plugins_url() . '/' . $this->dir;
			$this->base_slug = apply_filters( 'woocommerce_wishlist_base_slug', 'wishlist' );

			// core plugin items
			add_action( 'init', array( $this, 'register_post_type' ) );

			// ajax handlers
			add_action( 'wp_ajax_woocommerce_wishlist', array( $this, 'ajax_handler' ) ); // authenticated users
			add_action( 'wp_ajax_nopriv_woocommerce_wishlist', array( $this, 'ajax_handler' ) ); // authenticated users

			// templating
			add_shortcode( 'woocommerce_create_account', array( $this, 'shortcode_create_account' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 100 );
			add_action( 'wp_footer', array( $this, 'wp_footer' ) );
			add_action( 'woocommerce_wishlist_dock_meta', array( 'WC_Wishlist_Template', 'dock_title' ) );

			// hook into WC for templating
			add_filter( 'woocommerce_cart_item_remove_link', array('WC_Wishlist_Template', 'cart_item_save_link'), 10, 2 );
			add_action( 'woocommerce_ajax_added_to_cart', array( $this, 'wc_ajax_added_to_cart' ) );
			add_action( 'woocommerce_before_cart_table', array( 'WC_Wishlist_Template', 'checkout_notice' ) );
			add_action( 'woocommerce_before_my_account', array( 'WC_Wishlist_Template', 'my_account_dashboard' ) );
			add_action( 'woocommerce_before_shop_loop_item_title', array( 'WC_Wishlist_Template', 'product_image_overlay' ), 20 );
			add_action( 'woocommerce_after_shop_loop_item', array( 'WC_Wishlist_Template', 'product_button' ), 20 ); // link on product collections page
			add_action( 'woocommerce_after_add_to_cart_button', array( 'WC_Wishlist_Template', 'product_button' ), 20 ); // link on product single page
		}

		/**
		 * Sniff out request to move items from cart to wishlist
		 * 
		 * @return mixed $status or $response
		 */
		// function cart_item_listener(){
		// 	global $woocommerce;

		// 	$status = false;

		// 	if ( !empty($_GET['save_for_later']) && $product_id = absint( $_GET['save_for_later'] ) && $product_id &&
		// 		$woocommerce->verify_nonce('cart', '_GET') && 
		// 		is_user_logged_in() && 
		// 		$this->add_product_to_wishlist( $product_id, null, array() ) ){

		// 		// woocommerce_update_cart_action();

		// 	}

		//  	// if( is_ajax() ) {

		//  	// 	$response = apply_filters( 'woocommerce_wishlist_ajax_cart_item_listener', wp_parse_args( $this->ajax_get_products(), $this->ajax_response_default() ) );
		// 		// echo json_encode( $response );
		// 		// die();

		//  	// } else {
		//  	// 	return $status;
		//  	// }
		// }

		/**
		 * Leverage 'woocommerce_ajax_added_to_cart' to remove the product from wishlist
		 * 
		 * @param  int $product_id
		 * @return int $product_id
		 */
		function wc_ajax_added_to_cart( $product_id ) {
			if ( $wishlist_id = $this->get_wishlist_id() ) {
				woocommerce_wishlist_delete_meta( $wishlist_id, $product_id );
			}
			return $product_id;
		}

		/**
		 * Output a simple registration form when shortcode '[woocommerce_create_account]' is used
		 *
		 * @return void
		 */
		function shortcode_create_account() {
			WC_Wishlist_Template::register_form();
		}

		function ajax_response_default(){
			$defaults = apply_filters( 'woocommerce_wishlist_ajax_response_default', array(
				'msg' => null,
				'status' => false,
				'code' => null,
				'wishlist' => array(),
				'products' => array(),
				'product' =>  (object) array(
					'ID' => null,
					'title' => null,
					'permalink' => null,
					'thumbnail' => null
				)));
			return $defaults;
		}

		function ajax_handler(){

			$defaults = $this->ajax_response_default();

			$allowed_request = apply_filters( 'woocommerce_wishlist_ajax_allowed_request', array(
				'wishlist_id' => null,
				'product_id' => null,
				'do_action' => null
			));

			$request = wp_parse_args( $_REQUEST, $allowed_request );

			switch( $request['do_action'] ){
				case 'lookup':
					if ( ! $product_id = absint( $request['product_id'] ) ) {
						$response = array(
							'status' => 'error',
							'code' => 501,
							'msg' => __( 'You must supply a valid product ID to lookup.', 'woocommerce_wishlist' )
							);
					} else if ( ! $product = WC_Wishlist_Query::get_product( $product_id ) ) {
						$response = array(
							'status' => 'error',
							'code' => 404,
							'msg' => __( 'Product information could not be found by the ID you supplied.', 'woocommerce_wishlist' )
							);
					} else {
						$response = array(
							'status'=>'success',
							'code' => 100,
							'product' => $product
							);
					}
					break;
				case 'add':
					global $woocommerce;
					$wishlist_id = $this->get_wishlist_id();
					if ( !empty( $request['product_id'] ) && $this->add_product_to_wishlist( $request['product_id'], null, $request['form'] ) ) {

						// Sniff out request to move items from cart to wishlist
						$woocommerce->cart->set_quantity( $request['remove_item'], 0 );
						$response = $this->ajax_get_products();

					} else {
						$response = array(
							'status'=>'error',
							'code' => 501,
							'msg' => __( 'The request to add the product to your wishlist failed.' )
							);
					}
					break;
				case 'get':
					$response = $this->ajax_get_products();
					break;
				case 'remove':
					$wishlist_id = $this->get_wishlist_id();
					if ( !empty( $request['product_id'] ) && !empty($wishlist_id) && woocommerce_wishlist_delete_meta( $wishlist_id, $request['product_id'] ) ) {

						$response = $this->ajax_get_products();

					} else {
						$response = array(
							'status'=>'error',
							'code' => 501,
							'msg' => __( 'The request to remove the product from the wishlist failed.' )
						);
					}
					break;
				default:
					$response = array(
						'status'=>'error',
						'code' => 503,
						'msg' => __( 'The AJAX request is improperly formatted.', 'woocommerce_wishlist' )
					);
					break;
			}

			// for debugging pruposes
			if( defined('WP_DEBUG') && WP_DEBUG )
				$response['request'] = $request;

			$response = apply_filters( 'woocommerce_wishlist_ajax_response', wp_parse_args( $response, $defaults ) );

			echo json_encode( $response );

			die();
		}

		function ajax_get_products() {

			$wishlist = woocommerce_wishlist_get_active_wishlist();

			$response = array(
				'status' => 'success',
				'code' => '100',
				'wishlist' => $wishlist,
				'products' => WC_Wishlist_Query::get_products( $wishlist->ID )
			);
		
			return $response;
		}

		function add_product_to_wishlist( $product_id, $wishlist_id = null, $attributes = array() ) {

			$defaults = array(
				'quantity' => 1,
				'added' => time()
			);

			if ( ! $product_id = absint( $product_id ) )
				return false;

			$wishlist_id = $this->get_wishlist_id( $wishlist_id );

			// if no wishlists are returned then let's protect
			if ( empty( $wishlist_id ) ) {

				// create a wishlist for current user | anon
				$wishlist_id = WC_Wishlist_Query::create_wishlist();

			}

			// set wishlist meta and defaults
			$attributes = wp_parse_args( $attributes, $defaults );
			foreach ( $attributes as $key => $attribute ) {
				woocommerce_wishlist_add_meta( $wishlist_id, $product_id, $key, $attribute );
			}

			return true;
		}

		/**
		 * Attempt to find the right wishlist ID if unknown
		 * 
		 * will return anon wishlists if userid isn't known
		 * if the wishlist is provided and not a legit wishlist
		 * then we try to get the active wishlist
		 * @param  int $wishlist_id
		 * @return int $wishlist_id
		 */
		function get_wishlist_id( $wishlist_id = null ){

			$wishlist_id = ! empty( $wishlist_id ) && woocommerce_wishlist_is_wishlist( $wishlist_id ) ? $wishlist_id : woocommerce_wishlist_get_active_wishlist();
			$wishlist_id = is_object( $wishlist_id ) && !empty( $wishlist_id->ID ) ? $wishlist_id->ID : $wishlist_id;

			return apply_filters( 'woocommerce_wishlist_get_wishlist_id', $wishlist_id );

		}

		/**
		 * Stores the custom user capability string
		 *
		 * @return string $capability
		 */
		function get_user_capability() {
			return 'woocommerce_wishlist_manage';
		}

		/**
		 * Setup the wishlist post type
		 *
		 * @return void
		 */
		function register_post_type() {
			if ( post_type_exists( self::POST_TYPE ) ) return;

			$capability = self::get_user_capability();

			$post_type_args = apply_filters( 'woocommerce_wishlist_post_type_args', array(
					'labels' => array(
						'name'      => __( 'WooWishLists', 'woocommerce_wishlist' ),
						'singular_name'   => __( 'WooWishList', 'woocommerce_wishlist' ),
						'menu_name'    => _x( 'WooWishLists', 'Admin menu name', 'woocommerce_wishlist' ),
						'add_new'     => __( 'Add WooWishList', 'woocommerce_wishlist' ),
						'add_new_item'    => __( 'Add New WooWishList', 'woocommerce_wishlist' ),
						'edit'      => __( 'Edit', 'woocommerce_wishlist' ),
						'edit_item'    => __( 'Edit WooWishList', 'woocommerce_wishlist' ),
						'new_item'     => __( 'New WooWishList', 'woocommerce_wishlist' ),
						'view'      => __( 'View WooWishList', 'woocommerce_wishlist' ),
						'view_item'    => __( 'View WooWishList', 'woocommerce_wishlist' ),
						'search_items'    => __( 'Search WooWishLists', 'woocommerce_wishlist' ),
						'not_found'    => __( 'No WooWishLists found', 'woocommerce_wishlist' ),
						'not_found_in_trash'  => __( 'No WooWishLists found in trash', 'woocommerce_wishlist' ),
						'parent'     => __( 'Parent WooWishList', 'woocommerce_wishlist' )
					),
					'description'    => __( 'This is where you can add new woo-wish-lists to your store.', 'woocommerce_wishlist' ),
					'public'     => true,
					'show_ui'     => true,
					'capability_type'   => 'post',
					'capabilities' => array(
						'publish_posts'   => $capability,
						'edit_posts'    => $capability,
						'edit_others_posts'  => $capability,
						'delete_posts'    => $capability,
						'delete_others_posts' => $capability,
						'read_private_posts' => $capability,
						'edit_post'    => $capability,
						'delete_post'    => $capability,
						'read_post'    => $capability
					),
					'publicly_queryable'  => false,
					'exclude_from_search'  => true,
					'hierarchical'    => false,
					'rewrite'     => array(
						'slug' => $this->base_slug,
						'with_front' => true ),
					'query_var'    => true,
					'supports'     => array( 'title', 'custom-fields', 'author' ),
					'has_archive'    => false,
					'show_in_menu'  => false,
					'show_in_nav_menus'  => false
				) );
			register_post_type( self::POST_TYPE, $post_type_args );

			do_action( 'woocommerce_wishlist_register_post_type' );
		}

		/**
		 * Enqueue styles and scripts on the frontend
		 * 
		 * @return void
		 */
		function enqueue_assets() {
			if ( !is_admin() ) {
				wp_enqueue_style( 'woocommerce_wishlist_style', $this->url . 'assets/save-for-later.css', array( 'woocommerce_frontend_styles' ), 1.0, 'screen' );
				wp_enqueue_script( 'woocommerce_wishlist_localstorage', $this->url . 'assets/jQuery.localStorage.js', array( 'jquery' ), 1.0, true );
				wp_enqueue_script( 'woocommerce_wishlist_script', $this->url . 'assets/save-for-later.js', array( 'woocommerce_wishlist_localstorage' ), 1.0, true );

				$localize_script = apply_filters( 'woocommerce_wishlist_localize_script', array(
						'ajaxurl' => admin_url( 'admin-ajax.php' ),
						'user_status' => is_user_logged_in(),
						'css_colors_enabled' => WC_Wishlist_Settings::get_option( 'css_colors_enabled' ),
						'css_colors' => WC_Wishlist_Settings::get_option( 'css_colors' ),
						'header_show' => sprintf( '%s %s',
							__( 'Show' ),
							WC_Wishlist_Settings::get_option( 'frontend_label' ) ),
						'header_hide' => sprintf( '%s %s',
							__( 'Hide' ),
							WC_Wishlist_Settings::get_option( 'frontend_label' ) ),
						'template' => array(
							'product' => WC_Wishlist_Template::dock_product_template(),
							'not_found' => WC_Wishlist_Template::not_found()
						)
					) );

				// using localized js namespace
				wp_localize_script( 'woocommerce_wishlist_script', 'wc_wishlist_settings' , $localize_script );
			}
		}

		/**
		 * Display the dock in the footer per options
		 *
		 * @return void
		 */
		function wp_footer() {

			$wp_footer_enabled = WC_Wishlist_Settings::get_option( 'wp_footer_enabled' );

			if ( $wp_footer_enabled == 'yes' &&
				( WC_Wishlist_Settings::get_option( 'store_only' ) == 'no' ||
				( WC_Wishlist_Settings::get_option( 'store_only' ) == 'yes' && ( is_WC() || is_cart() ) ) )
			) {
				// display the wishlist dock
				WC_Wishlist_Template::dock();

			}

			do_action( 'woocommerce_wishlist_wp_footer', $wp_footer_enabled );
		}

		/**
		 * Generic SPL autoload registration method to load plugin classes
		 *
		 * @param string  $class_name to load
		 * @return void
		 */
		public static function lazy_loader( $class_name ) {

			$file = apply_filters( 'woocommerce_wishlist_lazy_loader', self::get_plugin_path() . 'classes/' . $class_name . '.php', $class_name );

			if ( !empty( $file ) && file_exists( $file ) )
				require_once $file;
		}

		/**
		 * Get the full path of the plugin on the server
		 *
		 * @return string
		 */
		public static function get_plugin_path() {
			return trailingslashit( dirname( __FILE__ ) );
		}

		/**
		 * Ensure the plugin has everything it needs to run properly
		 * @return void
		 */
		public function check_install() {
			register_activation_hook( __FILE__, array( 'WC_Wishlist_Install', 'activate' ) );
			register_activation_hook( __FILE__, array( 'WC_Wishlist_Install', 'flush_rewrite_rules' ) );
			if ( is_admin() && get_option( 'woocommerce_wishlist_db_version' ) != $this->version )
				add_action( 'init', array( 'WC_Wishlist_Install', 'install_or_upgrade' ), 1 );
		}

		/**
		 * Check the minimum PHP & WP versions
		 *
		 * @static
		 * @return bool Whether the test passed
		 */
		public static function prerequisites() {;
			$pass = TRUE;
			// $pass = $pass && defined( WC_VERSION ) && version_compare( WC_VERSION, self::MIN_WC_VERSION, '>=' );
			$pass = $pass && version_compare( phpversion(), self::MIN_PHP_VERSION, '>=' );
			$pass = $pass && version_compare( get_bloginfo( 'version' ), self::MIN_WP_VERSION, '>=' );
			return $pass;
		}

		public static function min_version_fail_notice() {
			echo '<div class="error"><p>';
			_e( sprintf( '%s requires the minimum versions of PHP v%s, WordPress v%s, and WooCommerce v%s in order to run properly.',
					self::PLUGIN_NAME,
					self::MIN_PHP_VERSION,
					self::MIN_WP_VERSION,
					self::MIN_WC_VERSION
				), 'woocommerce_wishlist' );
			echo '</p></div>';
		}

		public static function fail_notice() {
			echo '<div class="error"><p>';
			_e( sprintf( '%s requires that WooCommerce be active in order to be succesfully activated.',
					self::PLUGIN_NAME
				), 'woocommerce_wishlist' );
			echo '</p></div>';
		}

		/**
		 * Static Singleton Factory Method
		 *
		 * @return object $instance
		 */
		public static function instance() {
			if ( !isset( self::$instance ) ) {
				$class_name = __CLASS__;
				self::$instance = new $class_name;
			}
			return self::$instance;
		}
	}
}
