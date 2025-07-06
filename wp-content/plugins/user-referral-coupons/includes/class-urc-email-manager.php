<?php
/**
 * Handles email sending functionalities.
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

class URC_Email_Manager {

    /**
     * Get email settings from options.
     * @return array Email settings.
     */
    public static function get_email_settings() {
        $defaults = URC_Admin::get_static_default_email_options(); // Leverages defaults from URC_Admin
        $options = get_option( 'user-referral-coupons_email_options', $defaults );
        return wp_parse_args( $options, $defaults );
    }

    /**
     * Get a human-readable summary of the coupon's offer.
     * @param WC_Coupon $coupon WC_Coupon object.
     * @return string Coupon details summary.
     */
    public static function get_coupon_details_summary( WC_Coupon $coupon ) {
        if ( ! $coupon || ! $coupon->get_id() ) {
            return __( 'N/A', 'user-referral-coupons' );
        }

        $discount_type = $coupon->get_discount_type();
        $amount = $coupon->get_amount();
        $details = '';

        if ( 'percent' === $discount_type ) {
            $details = sprintf( esc_html__( '%s%% off your purchase.', 'user-referral-coupons' ), $amount );
        } elseif ( 'fixed_cart' === $discount_type ) {
            $details = sprintf( esc_html__( 'a %s discount on the cart total.', 'user-referral-coupons' ), wc_price( $amount ) );
        } elseif ( 'fixed_product' === $discount_type ) {
            $details = sprintf( esc_html__( 'a %s discount on applicable products.', 'user-referral-coupons' ), wc_price( $amount ) );
        } else {
            $details = sprintf( esc_html__( 'a discount of %s.', 'user-referral-coupons' ), $amount );
        }

        $expiry_date = $coupon->get_date_expires();
        if ( $expiry_date ) {
            $details .= ' ' . sprintf( esc_html__( 'Expires on: %s.', 'user-referral-coupons' ), esc_html( $expiry_date->date_i18n( wc_date_format() ) ) );
        }


        return $details;
    }

    /**
     * Replace placeholders in email content.
     * @param string $content The email content (subject or body).
     * @param WP_User $user WP_User object.
     * @param string $coupon_code The coupon code string.
     * @param WC_Coupon $coupon_obj WC_Coupon object.
     * @return string Content with placeholders replaced.
     */
    public static function replace_placeholders( $content, WP_User $user, $coupon_code, WC_Coupon $coupon_obj ) {
        $replacements = array(
            '{user_first_name}'   => $user->first_name,
            '{user_last_name}'    => $user->last_name,
            '{user_display_name}' => $user->display_name,
            '{user_email}'        => $user->user_email,
            '{coupon_code}'       => $coupon_code,
            '{coupon_details}'    => self::get_coupon_details_summary( $coupon_obj ),
            '{site_name}'         => get_bloginfo( 'name' ),
            '{site_url}'          => home_url(),
        );

        // For first/last name, if empty, use display_name as a fallback for greetings
        if ( empty( $replacements['{user_first_name}'] ) && empty( $replacements['{user_last_name}'] ) ) {
             if (strpos($content, '{user_first_name}') !== false && empty($user->first_name)) {
                // If content specifically uses first_name and it's empty, fallback to display_name for that part.
             }
        }
        // A simple approach for greeting if first name is empty:
        $displayName = $user->display_name;
        if(!empty($user->first_name)){
            $displayName = $user->first_name;
        }


        $content = str_replace( '{user_first_name}', $user->first_name ?: $user->display_name, $content ); // Fallback to display_name if first_name is empty
        $content = str_replace( '{user_last_name}', $user->last_name, $content ); // No direct fallback, can be empty

        // The rest of the replacements
        unset($replacements['{user_first_name}'], $replacements['{user_last_name}']); // remove already processed ones
        foreach ( $replacements as $placeholder => $value ) {
            $content = str_replace( $placeholder, $value, $content );
        }

        return $content;
    }

    /**
     * Send the coupon email to a specific user.
     * @param int $user_id User ID.
     * @return bool True on success, false on failure.
     */
    public static function send_coupon_email( $user_id ) {
        $user = get_user_by( 'id', $user_id );
        if ( ! $user ) {
            error_log( "URC Email: User ID {$user_id} not found." );
            return false;
        }

        $coupon_code = URC_Coupon_Manager::get_user_referral_coupon( $user_id );
        if ( ! $coupon_code ) {
            error_log( "URC Email: No coupon code found for user ID {$user_id} ({$user->user_email})." );
            return false; // Or perhaps generate one if policy allows? For now, skip.
        }

        $coupon_obj = new WC_Coupon( $coupon_code );
        if ( ! $coupon_obj->get_id() || ! $coupon_obj->is_valid() ) {
            error_log( "URC Email: Coupon code {$coupon_code} for user ID {$user_id} is invalid or not found." );
            return false;
        }

        $email_settings = self::get_email_settings();
        $subject = $email_settings['email_subject'];
        $body = $email_settings['email_body'];

        $subject = self::replace_placeholders( $subject, $user, $coupon_code, $coupon_obj );
        $body = self::replace_placeholders( $body, $user, $coupon_code, $coupon_obj );

        // Ensure body is processed by wpautop for proper paragraph formatting if it's plain text with newlines
        // but since we use wp_editor, it should already have HTML.
        // $body = wpautop($body); // Might be useful if users enter plain text. wp_editor output is usually HTML.

        $headers = array( 'Content-Type: text/html; charset=UTF-8' );
        // Optional: From header, though wp_mail defaults are usually fine if site email is set.
        // $site_name = get_bloginfo('name');
        // $admin_email = get_option('admin_email');
        // $headers[] = "From: {$site_name} <{$admin_email}>";


        // Temporarily set content type to HTML
        add_filter( 'wp_mail_content_type', array( __CLASS__, 'set_html_content_type' ) );

        $sent = wp_mail( $user->user_email, $subject, $body, $headers );

        // Remove the filter immediately
        remove_filter( 'wp_mail_content_type', array( __CLASS__, 'set_html_content_type' ) );

        if ( ! $sent ) {
            error_log( "URC Email: Failed to send coupon email to {$user->user_email} (User ID {$user_id})." );
            return false;
        }

        // error_log("URC Email: Successfully sent coupon email to {$user->user_email} (User ID {$user_id}).");
        return true;
    }

    /**
     * Sets the email content type to HTML.
     * Hooked by wp_mail_content_type filter.
     * @return string 'text/html'
     */
    public static function set_html_content_type() {
        return 'text/html';
    }
}
?>
