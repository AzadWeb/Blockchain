<?php
/**
 * Plugin Name:       User Referral Coupons
 * Plugin URI:        https://example.com/plugins/user-referral-coupons/
 * Description:       Generates referral coupon codes for users and integrates with Tera Wallet for commissions.
 * Version:           1.0.0
 * Author:            Jules AI Assistant
 * Author URI:        https://example.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       user-referral-coupons
 * Domain Path:       /languages
 * WC requires at least: 3.0
 * WC tested up to: 8.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'URC_PLUGIN_VERSION', '1.0.0' );
define( 'URC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'URC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'URC_COUPON_PREFIX', 'imm-' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
class User_Referral_Coupons {

	private static $instance;

	/**
	 * Ensures only one instance of the class is loaded or can be loaded.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->includes();
		$this->init_hooks();
	}

	/**
	 * Include required core files used in admin and public side.
	 */
	private function includes() {
		require_once URC_PLUGIN_DIR . 'admin/class-urc-admin.php';
		require_once URC_PLUGIN_DIR . 'public/class-urc-public.php';
		require_once URC_PLUGIN_DIR . 'includes/class-urc-coupon-manager.php';
		require_once URC_PLUGIN_DIR . 'includes/class-urc-wallet-manager.php';
	}

	/**
	 * Initialize hooks.
	 */
	private function init_hooks() {
		add_action( 'plugins_loaded', array( $this, 'load_plugin_textdomain' ) );

		// Activation and deactivation hooks
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

		// Initialize admin class
		if ( is_admin() ) {
			new URC_Admin( 'user-referral-coupons', URC_PLUGIN_VERSION );
		}

		// Hook for new user registration
		add_action( 'user_register', array( $this, 'handle_new_user_registration' ), 10, 1 );

		// Initialize public class for non-admin hooks
		if ( ! is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
            // Also load for AJAX requests as they might be frontend related
			new URC_Public( 'user-referral-coupons', URC_PLUGIN_VERSION );
		}
		// Add other hooks here
	}

	/**
	 * Load plugin textdomain.
	 */
	public function load_plugin_textdomain() {
		load_plugin_textdomain(
			'user-referral-coupons',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages/'
		);
	}

	/**
	 * Plugin activation.
	 */
	public function activate() {
		// Check for WooCommerce
		if ( ! class_exists( 'WooCommerce' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die(
				esc_html__( 'User Referral Coupons plugin requires WooCommerce to be installed and active. The plugin has been deactivated.', 'user-referral-coupons' ),
				esc_html__( 'Plugin Activation Error', 'user-referral-coupons' ),
				array( 'back_link' => true )
			);
		}
		// Add activation code here (e.g., set default options, schedule cron jobs)
		// For now, we can add a transient to show an admin notice if WooCommerce is not active.
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', array( $this, 'missing_woocommerce_notice' ) );
            // Don't run activation code if WooCommerce is not present.
            return;
        }

        // Generate coupons for existing users (optional, can be a setting)
        $this->generate_missing_coupons_for_all_users();
	}

    /**
     * Admin notice for missing WooCommerce.
     */
    public function missing_woocommerce_notice() {
        ?>
        <div class="notice notice-error is-dismissible">
            <p><?php esc_html_e( 'User Referral Coupons requires WooCommerce to be active. Please activate WooCommerce.', 'user-referral-coupons' ); ?></p>
        </div>
        <?php
    }

	/**
	 * Plugin deactivation.
	 */
	public function deactivate() {
		// Add deactivation code here (e.g., remove options, unschedule cron jobs)
	}

    // Placeholder for future methods related to coupon generation and management
    // We will move these to dedicated classes later as per the plan.

    /**
     * Handle new user registration to generate a coupon.
     *
     * @param int $user_id The ID of the newly registered user.
     */
    public function handle_new_user_registration( $user_id ) {
        if ( ! $user_id ) {
            return;
        }

        // Ensure WooCommerce and our coupon manager are available
        if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'URC_Coupon_Manager' ) ) {
            error_log( 'User Referral Coupons: WooCommerce or URC_Coupon_Manager not found during user registration for user ID ' . $user_id );
            return;
        }

        // Get current settings for coupon creation
        $settings = URC_Admin::get_urc_options(); // Ensure URC_Admin is loaded or provide a direct way to get options

        $result = URC_Coupon_Manager::create_user_coupon( $user_id, $settings );

        if ( is_wp_error( $result ) ) {
            // Log the error, but don't break the registration process
            error_log( sprintf( 'User Referral Coupons: Error creating coupon for new user ID %d: %s', $user_id, $result->get_error_message() ) );
        } else {
            // Optionally, do something on successful coupon creation for new user
            // e.g., send an email, add user meta, etc.
            // For now, just logging success for debugging.
            // error_log( sprintf( 'User Referral Coupons: Successfully created coupon for new user ID %d. Coupon ID: %d', $user_id, $result ) );
        }
    }

    /**
     * Generate missing coupons for all existing users.
     * Typically called on activation.
     *
     * @since 1.0.0
     */
    public function generate_missing_coupons_for_all_users() {
        if ( ! class_exists( 'URC_Coupon_Manager' ) || ! class_exists( 'URC_Admin' ) ) {
            error_log('User Referral Coupons: URC_Coupon_Manager or URC_Admin not available for generating all user coupons on activation.');
            return;
        }

        $users = get_users( array( 'fields' => 'ID' ) );
        if ( empty( $users ) ) {
            return;
        }

        $settings = URC_Admin::get_urc_options(); // Get default/saved settings
        $created_count = 0;
        $skipped_count = 0;
        $error_count = 0;

        foreach ( $users as $user_id ) {
            $coupon_code = URC_Coupon_Manager::generate_user_coupon_code( $user_id );
            $existing_coupon_id = URC_Coupon_Manager::coupon_exists( $coupon_code );

            if ( $existing_coupon_id ) {
                 // Further check if it's OUR coupon
                if( get_post_meta( $existing_coupon_id, '_urc_coupon', true) === 'yes' &&
                    (int) get_post_meta( $existing_coupon_id, '_urc_user_id', true ) === (int) $user_id ) {
                    $skipped_count++;
                    continue;
                }
                // If a coupon with the same code exists but isn't our URC coupon, we might log this or decide to skip.
                // For activation, it's safer to skip to prevent conflicts.
                // error_log("URC Activation: Conflicting coupon code {$coupon_code} for user {$user_id}. Skipping.");
                $skipped_count++; // Counting as skipped to avoid issues.
                continue;
            }

            $result = URC_Coupon_Manager::create_user_coupon( $user_id, $settings );

            if ( is_wp_error( $result ) ) {
                if ( $result->get_error_code() === 'coupon_already_exists' ) {
                    $skipped_count++;
                } else {
                    $error_count++;
                    error_log( sprintf( 'User Referral Coupons Activation: Error creating coupon for user ID %d: %s', $user_id, $result->get_error_message() ) );
                }
            } else {
                $created_count++;
            }
        }

        // Optional: Store a summary in an option or transient if you want to show a notice after activation.
        // For now, logging is sufficient for background activation task.
        if ($created_count > 0 || $skipped_count > 0 || $error_count > 0) {
             error_log(sprintf(
                'User Referral Coupons Activation: Coupons processed for existing users. Created: %d, Skipped (already existing URC or conflict): %d, Errors: %d',
                $created_count,
                $skipped_count,
                $error_count
            ));
        }
    }
}

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_user_referral_coupons() {
	return User_Referral_Coupons::get_instance();
}

// Check if WooCommerce is active before running the plugin
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true ) ) {
    run_user_referral_coupons();
} else {
    // Fallback action to ensure the activation hook's WooCommerce check runs
    // and the admin notice can be displayed if the plugin is activated without WC.
    add_action( 'admin_notices', function() {
        if ( ! class_exists( 'WooCommerce' ) && current_user_can( 'activate_plugins' ) ) {
            $plugin_data = get_plugin_data( __FILE__ );
            echo '<div class="notice notice-error"><p>' .
                sprintf(
                    /* translators: %s: Plugin name */
                    esc_html__( '%s requires WooCommerce to be installed and active. Please install and activate WooCommerce.', 'user-referral-coupons' ),
                    '<strong>' . esc_html( $plugin_data['Name'] ) . '</strong>'
                ) .
            '</p></div>';
        }
    });

    // Ensure activation hook is registered to perform WC check even if plugin doesn't fully load
    // This is a bit redundant with the class constructor but ensures the check occurs.
    register_activation_hook( __FILE__, function() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            deactivate_plugins( plugin_basename( __FILE__ ) );
            wp_die(
                esc_html__( 'User Referral Coupons plugin requires WooCommerce to be installed and active. The plugin has been deactivated.', 'user-referral-coupons' ),
                esc_html__( 'Plugin Activation Error', 'user-referral-coupons' ),
                array( 'back_link' => true )
            );
        }
    } );
}
