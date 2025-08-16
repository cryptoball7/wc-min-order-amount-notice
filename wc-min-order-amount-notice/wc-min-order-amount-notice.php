<?php
/**
 * Plugin Name: WooCommerce Minimum Order Amount Notification
 * Description: Prevents checkout when the cart subtotal is below a configurable minimum and shows a helpful notice. Includes an admin settings page to set the minimum.
 * Author: Cryptoball cryptoball7@gmail.com
 * Version: 1.0.0
 * License: GPLv3
 * Text Domain: wc-min-order-notice
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'WC_Min_Order_Amount_Notification' ) ) {
	class WC_Min_Order_Amount_Notification {
		const OPTION_KEY = 'wc_moan_minimum_amount';
		const NONCE_KEY  = 'wc_moan_save_settings';

		public function __construct() {
			// Defer WooCommerce-dependent hooks until plugins are loaded
			add_action( 'plugins_loaded', [ $this, 'init' ] );

			// Admin: settings page
			add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
			add_action( 'admin_init', [ $this, 'register_setting' ] );
		}

		public function init() {
			if ( ! class_exists( 'WooCommerce' ) ) {
				add_action( 'admin_notices', [ $this, 'woo_missing_notice' ] );
				return;
			}

			// Front-end hooks for validation and notices
			add_action( 'woocommerce_check_cart_items', [ $this, 'validate_minimum_in_cart' ] );
			add_action( 'woocommerce_checkout_process', [ $this, 'validate_minimum_in_checkout' ] );
			add_action( 'woocommerce_before_cart', [ $this, 'maybe_show_cart_notice' ] );
		}

		/**
		 * Default minimum amount (can be filtered)
		 */
		public function get_minimum_amount() {
			$default = 50.0; // Default if not set yet
			$min = get_option( self::OPTION_KEY, $default );
			$min = is_numeric( $min ) ? (float) $min : $default;
			/**
			 * Filter: wc_moan_minimum_amount
			 * Allows developers to override the minimum via code.
			 */
			return (float) apply_filters( 'wc_moan_minimum_amount', $min );
		}

		/**
		 * Calculate the relevant cart amount (subtotal, before shipping/taxes).
		 */
		protected function get_cart_subtotal() {
			if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
				return 0.0;
			}
			// Use subtotal before shipping/taxes, after discounts
			$subtotal = (float) WC()->cart->get_subtotal();
			return $subtotal;
		}

		/**
		 * Add an error on the Cart screen if below minimum to block checkout from cart.
		 */
		public function validate_minimum_in_cart() {
			$min = $this->get_minimum_amount();
			$subtotal = $this->get_cart_subtotal();

			if ( $subtotal > 0 && $subtotal < $min ) {
				$formatted_min = wc_price( $min );
				/* translators: %s: minimum order amount */
				$message = sprintf( __( 'A minimum order amount of %s is required to checkout.', 'wc-min-order-notice' ), $formatted_min );
				wc_add_notice( $message, 'error' );
			}
		}

		/**
		 * Add an error during checkout processing as a second line of defense.
		 */
		public function validate_minimum_in_checkout() {
			$min = $this->get_minimum_amount();
			$subtotal = $this->get_cart_subtotal();

			if ( $subtotal > 0 && $subtotal < $min ) {
				$formatted_min = wc_price( $min );
				/* translators: %s: minimum order amount */
				$message = sprintf( __( 'A minimum order amount of %s is required to checkout.', 'wc-min-order-notice' ), $formatted_min );
				wc_add_notice( $message, 'error' );
			}
		}

		/**
		 * Show an informational notice on the Cart page to encourage adding more items.
		 */
		public function maybe_show_cart_notice() {
			$min = $this->get_minimum_amount();
			$subtotal = $this->get_cart_subtotal();

			if ( $subtotal > 0 && $subtotal < $min ) {
				$formatted_min = wc_price( $min );
				$remaining = wc_price( $min - $subtotal );
				/* translators: 1: minimum order amount, 2: amount remaining */
				$message = sprintf( __( 'Add %2$s more to reach the minimum order amount of %1$s to checkout.', 'wc-min-order-notice' ), $formatted_min, $remaining );
				wc_print_notice( $message, 'notice' );
			}
		}

		/**
		 * Admin: add submenu under WooCommerce
		 */
		public function add_settings_page() {
			add_submenu_page(
				'woocommerce',
				__( 'Minimum Order Amount', 'wc-min-order-notice' ),
				__( 'Minimum Order Amount', 'wc-min-order-notice' ),
				'manage_woocommerce',
				'wc-min-order-amount',
				[ $this, 'render_settings_page' ]
			);
		}

		public function register_setting() {
			register_setting( 'wc_moan_settings', self::OPTION_KEY, [
				'type'              => 'number',
				'sanitize_callback' => function ( $value ) {
					$value = is_numeric( $value ) ? (float) $value : 0.0;
					return $value < 0 ? 0.0 : $value; // No negative minimums
				},
				'default'           => 50.0,
			] );
		}

		public function render_settings_page() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}

			// Handle manual nonce check if form posted (Settings API also handles when using settings_fields)
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'WooCommerce Minimum Order Amount', 'wc-min-order-notice' ); ?></h1>
				<form method="post" action="options.php">
					<?php settings_fields( 'wc_moan_settings' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr( self::OPTION_KEY ); ?>">
									<?php esc_html_e( 'Minimum order amount', 'wc-min-order-notice' ); ?>
								</label>
							</th>
							<td>
								<input name="<?php echo esc_attr( self::OPTION_KEY ); ?>" id="<?php echo esc_attr( self::OPTION_KEY ); ?>" type="number" step="0.01" min="0" value="<?php echo esc_attr( $this->get_minimum_amount() ); ?>" class="regular-text" />
								<p class="description">
									<?php esc_html_e( 'Orders with a subtotal below this amount cannot proceed to checkout. Shipping and taxes are not counted.', 'wc-min-order-notice' ); ?>
								</p>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Save changes', 'wc-min-order-notice' ) ); ?>
				</form>
				<hr />
				<p>
					<em><?php esc_html_e( 'Tip: Developers can filter the minimum via the wc_moan_minimum_amount filter.', 'wc-min-order-notice' ); ?></em>
				</p>
			</div>
			<?php
		}

		public function woo_missing_notice() {
			if ( current_user_can( 'activate_plugins' ) ) {
				$class   = 'notice notice-error';
				$message = __( 'WooCommerce Minimum Order Amount Notification requires WooCommerce to be installed and active.', 'wc-min-order-notice' );
				echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';
			}
		}
	}
}

// Bootstrap the plugin
add_action( 'plugins_loaded', function () {
	if ( class_exists( 'WC_Min_Order_Amount_Notification' ) ) {
		new WC_Min_Order_Amount_Notification();
	}
} );
