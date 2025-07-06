<?php
/**
 * The public-facing functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for actions and filters.
 *
 * @package    User_Referral_Coupons
 * @subpackage User_Referral_Coupons/public
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class URC_Public {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param    string    $plugin_name       The name of the plugin.
	 * @param    string    $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version = $version;

		add_action( 'woocommerce_account_dashboard', array( $this, 'display_referral_coupon_on_dashboard' ), 10 );
		add_shortcode( 'user_referral_coupon', array( $this, 'render_referral_coupon_shortcode' ) );
	}

	/**
	 * Display the user's referral coupon on the WooCommerce My Account dashboard.
	 *
	 * @since 1.0.0
	 */
	public function display_referral_coupon_on_dashboard() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		// Ensure URC_Coupon_Manager is available
		if ( ! class_exists( 'URC_Coupon_Manager' ) ) {
			return;
		}

		$coupon_code = URC_Coupon_Manager::get_user_referral_coupon( $user_id );

		if ( $coupon_code ) {
			// Check if the coupon is still valid (e.g., not expired, usage limits not reached for the coupon itself)
            $coupon = new WC_Coupon( $coupon_code );
            if ( ! $coupon->get_id() || ! $coupon->is_valid() ) {
                 // Coupon might exist by code but is invalid (e.g. expired, or an admin manually deleted/invalidated it)
                echo '<div class="urc-coupon-display-error">';
                echo '<p>' . esc_html__( 'Your referral coupon is currently unavailable or invalid. Please contact support if you believe this is an error.', 'user-referral-coupons' ) . '</p>';
                echo '</div>';
                return;
            }

			echo '<div class="urc-coupon-display woocommerce-message">';
			echo '<h3>' . esc_html__( 'Your Referral Coupon', 'user-referral-coupons' ) . '</h3>';
			echo '<p>' . esc_html__( 'Share this coupon code with your friends! They get a discount, and you can earn rewards.', 'user-referral-coupons' ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Your Coupon Code:', 'user-referral-coupons' ) . '</strong> <code class="urc-coupon-code">' . esc_html( $coupon_code ) . '</code></p>';

            // Display coupon details
            $discount_type = $coupon->get_discount_type();
            $coupon_amount = $coupon->get_amount();
            $details = '';

            if ( 'percent' === $discount_type ) {
                $details = sprintf( esc_html__( 'This coupon gives a %s%% discount.', 'user-referral-coupons' ), $coupon_amount );
            } elseif ( 'fixed_cart' === $discount_type ) {
                $details = sprintf( esc_html__( 'This coupon gives a %s discount on the cart total.', 'user-referral-coupons' ), wc_price( $coupon_amount ) );
            } elseif ( 'fixed_product' === $discount_type ) {
                 $details = sprintf( esc_html__( 'This coupon gives a %s discount on applicable products.', 'user-referral-coupons' ), wc_price( $coupon_amount ) );
            }

            if($details){
                echo '<p>' . $details . '</p>';
            }

            $expiry_date = $coupon->get_date_expires();
            if ( $expiry_date ) {
                echo '<p>' . sprintf( esc_html__( 'This coupon expires on: %s', 'user-referral-coupons' ), esc_html( $expiry_date->date_i18n( wc_date_format() ) ) ) . '</p>';
            } else {
                echo '<p>' . esc_html__( 'This coupon does not expire.', 'user-referral-coupons' ) . '</p>';
            }

			// Add a button to copy the coupon code
            // Simple copy to clipboard (requires user interaction for security reasons)
            echo '<p><button type="button" class="button urc-copy-coupon-button" data-coupon="' . esc_attr($coupon_code) . '">' . esc_html__('Copy Code', 'user-referral-coupons') . '</button></p>';
            wc_enqueue_js("
                jQuery(document).ready(function($) {
                    $('.urc-copy-coupon-button').on('click', function() {
                        var couponCode = $(this).data('coupon');
                        var tempInput = $('<input>');
                        $('body').append(tempInput);
                        tempInput.val(couponCode).select();
                        try {
                            document.execCommand('copy');
                            $(this).text('" . esc_js(__('Copied!', 'user-referral-coupons')) . "');
                            setTimeout(() => { $(this).text('" . esc_js(__('Copy Code', 'user-referral-coupons')) . "'); }, 2000);
                        } catch (e) {
                            alert('" . esc_js(__('Failed to copy. Please select and copy manually.', 'user-referral-coupons')) . "');
                        }
                        tempInput.remove();
                    });
                });
            ");

			echo '</div>';

		} else {
            // This case means URC_Coupon_Manager::get_user_referral_coupon returned false.
            // It could be that the coupon was never generated, or it's not identifiable as a URC coupon.
            // If a coupon is expected, this might indicate an issue or a user registered before the plugin was fully active for them.
            // An admin could use the "Generate for all users" button to fix this for existing users.
			echo '<div class="urc-coupon-display-notice woocommerce-info">';
			echo '<p>' . esc_html__( 'Your personal referral coupon is not yet available. It might be generated soon. If you believe this is an error, please contact support.', 'user-referral-coupons' ) . '</p>';
			echo '</div>';
		}
	}

	/**
	 * Render the [user_referral_coupon] shortcode.
	 *
	 * @since 1.0.0
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output for the shortcode.
	 */
	public function render_referral_coupon_shortcode( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Please log in to view your referral coupon.', 'user-referral-coupons' ) . '</p>';
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return ''; // Should not happen if logged in
		}

		if ( ! class_exists( 'URC_Coupon_Manager' ) ) {
			return '<p>' . esc_html__( 'Coupon system is currently unavailable.', 'user-referral-coupons' ) . '</p>';
		}

		$coupon_code = URC_Coupon_Manager::get_user_referral_coupon( $user_id );
		$output = '';

		if ( $coupon_code ) {
            $coupon = new WC_Coupon( $coupon_code );
            if ( ! $coupon->get_id() || ! $coupon->is_valid() ) {
                return '<div class="urc-coupon-display-error"><p>' . esc_html__( 'Your referral coupon is currently unavailable or invalid.', 'user-referral-coupons' ) . '</p></div>';
            }

			$output .= '<div class="urc-coupon-display urc-shortcode-coupon-display">'; // Added woocommerce-message like class if needed for styling
			$output .= '<h3>' . esc_html__( 'Your Referral Coupon', 'user-referral-coupons' ) . '</h3>';
			$output .= '<p>' . esc_html__( 'Share this coupon code with your friends!', 'user-referral-coupons' ) . '</p>'; // Removed: "They get a discount, and you can earn rewards." to be more concise for shortcode
			$output .= '<p><strong>' . esc_html__( 'Your Coupon Code:', 'user-referral-coupons' ) . '</strong> <code class="urc-coupon-code">' . esc_html( $coupon_code ) . '</code></p>';

            $discount_type = $coupon->get_discount_type();
            $coupon_amount = $coupon->get_amount();
            $details = '';
            if ( 'percent' === $discount_type ) {
                $details = sprintf( esc_html__( 'This coupon gives a %s%% discount.', 'user-referral-coupons' ), $coupon_amount );
            } elseif ( 'fixed_cart' === $discount_type ) {
                $details = sprintf( esc_html__( 'This coupon gives a %s discount on the cart total.', 'user-referral-coupons' ), wc_price( $coupon_amount ) );
            } elseif ( 'fixed_product' === $discount_type ) {
                 $details = sprintf( esc_html__( 'This coupon gives a %s discount on applicable products.', 'user-referral-coupons' ), wc_price( $coupon_amount ) );
            }
            if($details){
                $output .= '<p>' . $details . '</p>';
            }

            $expiry_date = $coupon->get_date_expires();
            if ( $expiry_date ) {
                $output .= '<p>' . sprintf( esc_html__( 'Expires on: %s', 'user-referral-coupons' ), esc_html( $expiry_date->date_i18n( wc_date_format() ) ) ) . '</p>';
            } else {
                $output .= '<p>' . esc_html__( 'This coupon does not expire.', 'user-referral-coupons' ) . '</p>';
            }

            // Enqueue script for copy button if not already enqueued by dashboard widget
            // Note: Shortcodes might render before or after wp_head/wp_footer, so direct script output is more reliable here,
            // but it's better practice to enqueue. For simplicity here, it's similar to the dashboard.
            // A more robust solution would use a flag to ensure the script is only added once per page.
            static $shortcode_script_added = false;
            if(!$shortcode_script_added){
                 wc_enqueue_js("
                    jQuery(document).ready(function($) {
                        // Ensure this handler is specific enough if multiple copy buttons exist
                        $('body').on('click', '.urc-copy-coupon-button-shortcode', function() {
                            var couponCode = $(this).data('coupon');
                            var tempInput = $('<input>');
                            $('body').append(tempInput);
                            tempInput.val(couponCode).select();
                            try {
                                document.execCommand('copy');
                                $(this).text('" . esc_js(__('Copied!', 'user-referral-coupons')) . "');
                                var originalButton = $(this);
                                setTimeout(function() { originalButton.text('" . esc_js(__('Copy Code', 'user-referral-coupons')) . "'); }, 2000);
                            } catch (e) {
                                alert('" . esc_js(__('Failed to copy. Please select and copy manually.', 'user-referral-coupons')) . "');
                            }
                            tempInput.remove();
                        });
                    });
                ");
                $shortcode_script_added = true;
            }

			$output .= '<p><button type="button" class="button urc-copy-coupon-button-shortcode" data-coupon="' . esc_attr($coupon_code) . '">' . esc_html__('Copy Code', 'user-referral-coupons') . '</button></p>';
			$output .= '</div>';

		} else {
			$output .= '<div class="urc-coupon-display-notice"><p>' . esc_html__( 'Your personal referral coupon is not yet available.', 'user-referral-coupons' ) . '</p></div>';
		}
		return $output;
	}
}
?>
