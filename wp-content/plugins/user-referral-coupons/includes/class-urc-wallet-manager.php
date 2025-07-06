<?php
/**
 * Handles Tera Wallet integration for commissions.
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

class URC_Wallet_Manager {

	/**
	 * Initialize wallet hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'handle_commission_on_order_completion' ), 10, 1 );
	}

	/**
	 * Handle commission processing when an order is completed.
	 *
	 * @since 1.0.0
	 * @param int $order_id The ID of the completed order.
	 */
	public static function handle_commission_on_order_completion( $order_id ) {
		// Check if Tera Wallet is active
		if ( ! class_exists( 'WOO_Wallet' ) || ! function_exists( 'woo_wallet' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

        // Prevent processing for refunded or failed orders, though hook is on 'completed'
        if ( $order->get_status() !== 'completed' ) {
            return;
        }

        // Prevent duplicate commission processing
        if ( get_post_meta( $order_id, '_urc_commission_processed', true ) ) {
            return;
        }

		$used_coupons = $order->get_coupon_codes();
		if ( empty( $used_coupons ) ) {
			return;
		}

		$settings = URC_Admin::get_urc_options();

		// Check if commission is enabled
		if ( empty( $settings['enable_commission'] ) || ! $settings['enable_commission'] ) {
			return;
		}

		foreach ( $used_coupons as $coupon_code ) {
			$coupon = new WC_Coupon( $coupon_code );
			if ( ! $coupon->get_id() ) {
				continue;
			}

			// Check if this is one of our referral coupons
			$referrer_user_id = get_post_meta( $coupon->get_id(), '_urc_user_id', true );
			if ( ! $referrer_user_id || get_post_meta( $coupon->get_id(), '_urc_coupon', true ) !== 'yes' ) {
				continue; // Not a URC coupon or no associated user ID
			}

            $referrer_user_id = (int) $referrer_user_id;
            $customer_user_id = $order->get_user_id();

            // Optional: Prevent self-referral commission
            // if ( $referrer_user_id === $customer_user_id ) {
            //    $order->add_order_note( sprintf( __( 'Referral commission for coupon %s skipped: Self-referral by user ID %d.', 'user-referral-coupons' ), $coupon_code, $referrer_user_id ) );
            //    continue;
            // }


			// Calculate commission
			$commission_amount = 0;
			$commission_type = isset( $settings['commission_type'] ) ? $settings['commission_type'] : 'percent';
			$commission_value = isset( $settings['commission_value'] ) ? floatval( $settings['commission_value'] ) : 0;

			if ( $commission_value <= 0 ) {
				continue; // No commission value configured
			}

			$order_total_for_commission = $order->get_subtotal() - $order->get_total_discount(); // Or $order->get_total()
            // Consider if commission should be on total after discount, or subtotal, or total.
            // Using subtotal - discount to represent the amount the customer actually paid before shipping/taxes.

			if ( 'percent' === $commission_type ) {
				$commission_amount = ( $commission_value / 100 ) * $order_total_for_commission;
			} elseif ( 'fixed' === $commission_type ) {
				$commission_amount = $commission_value;
			}

			$commission_amount = round( $commission_amount, wc_get_price_decimals() );

			if ( $commission_amount > 0 ) {
				$referrer_wallet = woo_wallet()->wallet->get_wallet_by_user_id( $referrer_user_id );
                if ( ! $referrer_wallet ) {
                     // This case might happen if the user was deleted or wallet system has issues.
                    error_log("URC: Could not find wallet for referrer user ID: $referrer_user_id for order ID: $order_id");
                    $order->add_order_note( sprintf( __( 'Referral commission for coupon %s failed: Could not find wallet for referrer user ID %d.', 'user-referral-coupons' ), $coupon_code, $referrer_user_id ) );
                    continue;
                }

				$result = woo_wallet()->wallet->credit(
					$referrer_user_id,
					$commission_amount,
					sprintf( __( 'Referral commission from order #%s (coupon %s)', 'user-referral-coupons' ), $order->get_order_number(), $coupon_code )
				);

				if ( ! is_wp_error( $result ) ) {
					$order->add_order_note( sprintf( __( 'Referral commission of %s awarded to user ID %d (wallet) for coupon %s.', 'user-referral-coupons' ), wc_price( $commission_amount ), $referrer_user_id, $coupon_code ) );
                    update_post_meta( $order_id, '_urc_commission_processed', true ); // Mark as processed for this coupon/order
                    update_post_meta( $order_id, '_urc_commission_referrer_id_' . $coupon_code, $referrer_user_id );
                    update_post_meta( $order_id, '_urc_commission_amount_' . $coupon_code, $commission_amount );

                    do_action('urc_commission_awarded', $referrer_user_id, $commission_amount, $order_id, $coupon_code);
					break; // Process only the first valid URC coupon for commission to avoid multiple commissions on one order.
                           // Or adjust if multiple referrers' coupons can be used and all should get commission.
				} else {
					$order->add_order_note( sprintf( __( 'Failed to award referral commission to user ID %d for coupon %s. Error: %s', 'user-referral-coupons' ), $referrer_user_id, $coupon_code, $result->get_error_message() ) );
                    error_log("URC: Failed to credit wallet for User ID $referrer_user_id, Amount: $commission_amount, Order: $order_id. Error: " . $result->get_error_message());
				}
			}
		}
	}
}

// Initialize the wallet manager hooks
URC_Wallet_Manager::init();
?>
