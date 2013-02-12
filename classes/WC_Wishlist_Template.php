<?php

if ( !defined( 'ABSPATH' ) )
	die( '-1' );

if ( !class_exists( 'woocommerce_Wishlist_Template' ) ) {
	class WC_Wishlist_Template {

		public static function my_account_dashboard(){
			$html = sprintf( '<div id="wc_wishlist_myaccount"><h2>%s</h2><p>%s</p><div class="products">',
				__('Wishlist', 'woocommerce_wishlist'),
				__('These are items you have decided to save for later for easy access to add to your cart.', 'woocommerce_wishlist')
				);

			// $wishlist = WC_wishlist_get_active_wishlist_by_user();

			// foreach( WC_wishlist_get_wishlist_meta( $wishlist, null, 'quantity' ) as $item ){
			// 	$html .= apply_filters( 'woocommerce_wishlist_my_account_dashboard_product', sprintf( '<a class="product" href="%s">%s<span class="add_to_cart" data-icon="i" data-id="%s"></span><span class="remove" data-icon="x" data-id="%s"></span></a>',
			// 		get_permalink( $item->product_id ),
			// 		get_the_post_thumbnail( $item->product_id, 'shop_thumbnail' ),
			// 		$item->product_id,
			// 		$item->product_id
			// 		), $item );
			// }

			$html .= '</div></div>';

			echo apply_filters('woocommerce_wishlist_my_account_dashboard', $html );
		}

		public static function dock_product_template(){
			$html = sprintf( '<a class="product" href="{0}">{1}<span class="add_to_cart" data-icon="i" data-product_id="{2}"></span><span class="remove" data-icon="x" data-product_id="{2}"></span><div class="quick_view">%s</div></a>',
				__('Quick View', 'woocommerce_wishlist')
				);
			return apply_filters( 'woocommerce_wishlist_dock_product_template', $html );
		}

		public static function product_image_overlay() {
			$overlay_image = sprintf( '<i data-icon="o" data-product_id="%s" class="product_image_overlay save_for_later">%s</i>',
				get_the_ID(),
				__( 'Save For Later', 'woocommerce_wishlist' )
			);
			echo apply_filters( 'woocommerce_wishlist_template_product_image_overlay', $overlay_image );
		}

		public static function product_button() {
			global $product;

			$button = sprintf( '<a class="save_for_later button product_type_%s" data-product_id="%s" rel="nofollow">%s</a>',
				$product->product_type,
				$product->id,
				__( 'Save for Later', 'woocommerce_wishlist' )
				);

			echo apply_filters( 'woocommerce_wishlist_template_product_button', $button, $product );
		}

		public static function dock_title(){
			$dock = sprintf('<h3 data-icon="j">%s</h3>',
				WC_Wishlist_Settings::get_option( 'frontend_label' )
				);

			if( is_user_logged_in() ) {
				$myaccount_page_id = get_option( 'woocommerce_myaccount_page_id' );
				$class = 'my_account';
				$icon = '(';
				$title = __('My Account', 'woocommerce_wishlist');
			} else {
				$myaccount_page_id = (get_option('woocommerce_enable_myaccount_registration')=='yes') ? get_option( 'woocommerce_myaccount_page_id' ) : get_option( 'woocommerce_createaccount_page_id' );
				$permalink = '#';
				$class = 'my_account create';
				$icon = 'o';
				$title = __('Create an Account', 'woocommerce_wishlist');
			}

			$permalink = ( $myaccount_page_id ) ? get_permalink( $myaccount_page_id ) : '';

			$dock .= apply_filters('woocommerce_wishlist_template_dock_account_link',
				!empty($permalink) ? sprintf('<a href="%s" class="%s" data-icon="%s">%s</a>',
					$permalink,
					$class,
					$icon,
					$title
					) : '', is_user_logged_in(), $permalink, $class, $icon, $title );

			echo apply_filters( 'woocommerce_wishlist_template_dock_title', $dock );
		}

		public static function dock() {
			include apply_filters( 'woocommerce_wishlist_template_dock_file', WC_Wishlist::instance()->path . 'views/wishlist-dock.php' );
		}

		public static function register_form() {
			global $WC;
			include apply_filters( 'woocommerce_wishlist_template_register_form_file', WC_Wishlist::instance()->path . 'views/register-form.php' );
		}

		public static function not_found() {
			$message = sprintf( '<span>%s <a href="%s">%s</a></span',
				__( 'It seems you haven\'t added any items into your wishlist.', 'woocommerce_wishlist' ),
				get_permalink( woocommerce_get_page_id( 'shop' ) ),
				__( 'Add some now.', 'woocommerce_wishlist' )
				);
			return apply_filters( 'woocommerce_wishlist_template_not_found', $message );
		}
	}
}
