<?php
/**
 * Handles coupon generation and management.
 *
 * @link       https://example.com
 * @since      1.0.0
 *
 * @package    User_Referral_Coupons
 * @subpackage User_Referral_Coupons/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class URC_Coupon_Manager {

	/**
	 * Generates a unique coupon code for a given user ID.
	 *
	 * @since 1.0.0
	 * @param int $user_id The ID of the user.
	 * @return string The generated coupon code.
	 */
	public static function generate_user_coupon_code( $user_id ) {
		return strtoupper( URC_COUPON_PREFIX . $user_id );
	}

	/**
	 * Checks if a coupon code already exists in WooCommerce.
	 *
	 * @since 1.0.0
	 * @param string $coupon_code The coupon code to check.
	 * @return int|false The coupon ID if it exists, false otherwise.
	 */
	public static function coupon_exists( $coupon_code ) {
		global $wpdb;
		$coupon_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_title = %s AND post_type = 'shop_coupon' AND post_status = 'publish'", $coupon_code ) );
		return $coupon_id ? (int) $coupon_id : false;
	}

	/**
	 * Creates a WooCommerce coupon for a specific user.
	 *
	 * @since 1.0.0
	 * @param int $user_id The user ID for whom the coupon is being created.
	 * @param array $settings Plugin settings (optional, uses defaults if not provided).
	 * @return int|WP_Error The post ID of the created coupon, or WP_Error on failure.
	 */
	public static function create_user_coupon( $user_id, $settings = array() ) {
		if ( ! $user_id ) {
			return new WP_Error( 'missing_user_id', __( 'User ID is required to create a coupon.', 'user-referral-coupons' ) );
		}

		$coupon_code = self::generate_user_coupon_code( $user_id );

		// Check if coupon already exists
		if ( self::coupon_exists( $coupon_code ) ) {
			return new WP_Error( 'coupon_already_exists', sprintf( __( 'Coupon "%s" already exists.', 'user-referral-coupons' ), $coupon_code ) );
		}

		$user_info = get_userdata( $user_id );
		if ( ! $user_info ) {
			return new WP_Error( 'invalid_user_id', sprintf( __( 'User with ID %d not found.', 'user-referral-coupons' ), $user_id ) );
		}

        // $settings are expected to be pre-loaded by the caller using URC_Admin::get_urc_options()
        // Ensure $settings is an array and provide defaults if it's not (though callers should ensure this)
        if ( !is_array($settings) || empty($settings) ) {
            // Fallback if $settings somehow not provided correctly by caller.
            // This indicates an issue with the calling code if this branch is hit.
            error_log('URC_Coupon_Manager::create_user_coupon was called with invalid or empty $settings.');
            $settings = URC_Admin::get_urc_options();
        }

		$discount_type = $settings['coupon_discount_type']; // No fallback needed, get_urc_options ensures keys exist
		$coupon_amount = $settings['coupon_amount'];
        $expiry_days   = (int) $settings['coupon_expiry_days'];

		$coupon_data = array(
			'post_title'   => $coupon_code,
			'post_content' => sprintf(__( 'Referral coupon for user %s (%s).', 'user-referral-coupons' ), $user_info->user_login, $user_info->user_email ),
			'post_status'  => 'publish',
			'post_author'  => 1, // Or current admin user
			'post_type'    => 'shop_coupon',
		);

		$coupon_id = wp_insert_post( $coupon_data );

		if ( is_wp_error( $coupon_id ) ) {
			return $coupon_id;
		}

		// Add coupon meta data
		update_post_meta( $coupon_id, 'discount_type', $discount_type );
		update_post_meta( $coupon_id, 'coupon_amount', $coupon_amount );
		update_post_meta( $coupon_id, 'individual_use', 'yes' ); // 'yes' or 'no'
		update_post_meta( $coupon_id, 'product_ids', '' ); // Comma separated string of product IDs
		update_post_meta( $coupon_id, 'exclude_product_ids', '' );
		update_post_meta( $coupon_id, 'usage_limit', '' ); // Usage limit per coupon
		update_post_meta( $coupon_id, 'usage_limit_per_user', '1' ); // Usage limit per user (for the customer using it, not the referrer)
		update_post_meta( $coupon_id, 'limit_usage_to_x_items', '' );
		update_post_meta( $coupon_id, 'free_shipping', 'no' ); // 'yes' or 'no'
		update_post_meta( $coupon_id, 'exclude_sale_items', 'no' ); // 'yes' or 'no'
		update_post_meta( $coupon_id, 'product_categories', array() );
		update_post_meta( $coupon_id, 'exclude_product_categories', array() );
		update_post_meta( $coupon_id, 'minimum_amount', '' );
		update_post_meta( $coupon_id, 'maximum_amount', '' );
		update_post_meta( $coupon_id, 'customer_email', '' ); // Not restricted to specific emails for general referral

        // Set expiry date
        if ( $expiry_days > 0 ) {
            $expiry_date = date( 'Y-m-d', strtotime( "+{$expiry_days} days" ) );
            update_post_meta( $coupon_id, 'date_expires', $expiry_date );
        } else {
            update_post_meta( $coupon_id, 'date_expires', '' ); // No expiry
        }

        // Meta to identify this as a URC coupon and link to user
        update_post_meta( $coupon_id, '_urc_coupon', 'yes' );
        update_post_meta( $coupon_id, '_urc_user_id', $user_id );


		// Allow plugins to hook and modify coupon settings
		do_action( 'urc_after_create_user_coupon', $coupon_id, $user_id, $settings );

		return $coupon_id;
	}

    /**
     * Get a user's referral coupon code if it exists.
     *
     * @param int $user_id The user ID.
     * @return string|false The coupon code if found and valid, false otherwise.
     */
    public static function get_user_referral_coupon( $user_id ) {
        if ( ! $user_id ) {
            return false;
        }

        $coupon_code = self::generate_user_coupon_code( $user_id );
        $coupon_id = self::coupon_exists( $coupon_code );

        if ( ! $coupon_id ) {
            return false;
        }

        // Optional: Add more validation like checking if the coupon is still valid (not expired, etc.)
        $coupon = new WC_Coupon( $coupon_id );
        if ( ! $coupon->get_id() ) { // Check if coupon object is valid
             return false;
        }

        // Check if it's actually a URC coupon and belongs to this user
        if ( get_post_meta( $coupon_id, '_urc_coupon', true ) !== 'yes' || (int) get_post_meta( $coupon_id, '_urc_user_id', true ) !== (int) $user_id ) {
            return false;
        }

        return $coupon_code;
    }
}
?>
