<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://example.com
 * @since      1.0.0
 *
 * @package    User_Referral_Coupons
 * @subpackage User_Referral_Coupons/admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class URC_Admin {

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
	 * @param      string    $plugin_name       The name of this plugin.
	 * @param      string    $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version = $version;

		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_urc_generate_all_coupons', array( $this, 'handle_generate_all_coupons' ) );
		add_action( 'admin_post_urc_reset_email_sent_status', array( $this, 'handle_reset_email_sent_status' ) );
		add_action( 'admin_notices', array( $this, 'display_admin_notices' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

        // AJAX handlers
        add_action( 'wp_ajax_urc_initiate_bulk_email', array( $this, 'ajax_initiate_bulk_email' ) );
        add_action( 'wp_ajax_urc_process_bulk_email_batch', array( $this, 'ajax_process_bulk_email_batch' ) );
	}

    /**
     * Enqueue admin scripts and styles.
     *
     * @since 1.0.0
     * @param string $hook_suffix The current admin page.
     */
    public function enqueue_admin_scripts( $hook_suffix ) {
        // Only load on our plugin's admin page.
        // The hook_suffix for a top-level menu page is 'toplevel_page_{menu_slug}'.
        if ( 'toplevel_page_' . $this->plugin_name !== $hook_suffix ) {
            return;
        }
        wp_enqueue_script(
            $this->plugin_name . '-admin-script',
            URC_PLUGIN_URL . 'admin/js/urc-admin.js',
            array( 'jquery' ),
            URC_PLUGIN_VERSION,
            true // In footer
        );
        wp_localize_script(
            $this->plugin_name . '-admin-script',
            'urcAdminAjax',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'urc_admin_ajax_nonce' ),
                'i18n'     => array(
                    'processing'          => __( 'Processing...', 'user-referral-coupons' ),
                    'error_please_try'    => __( 'An error occurred. Please try again.', 'user-referral-coupons' ),
                    'complete'            => __( 'Bulk email process complete.', 'user-referral-coupons' ),
                    'processed_users'     => __( 'Processed users', 'user-referral-coupons' ),
                    'successfully_sent_to' => __( 'Successfully sent to:', 'user-referral-coupons' ),
                    'failed_for'          => __( 'Failed for:', 'user-referral-coupons' ),
                    'no_users_to_email'   => __( 'No users eligible for email.', 'user-referral-coupons' ),
                )
            )
        );
    }

	/**
	 * Add admin menu page.
	 *
	 * @since    1.0.0
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'User Referral Coupons', 'user-referral-coupons' ), // Page title
			__( 'Referral Coupons', 'user-referral-coupons' ),    // Menu title
			'manage_options',                                     // Capability
			$this->plugin_name,                                   // Menu slug
			array( $this, 'display_settings_page' ),              // Function to display the page
			'dashicons-tickets-alt',                              // Icon URL
			75                                                    // Position
		);
	}

	/**
	 * Display the settings page.
	 *
	 * @since    1.0.0
	 */
	public function display_settings_page() {
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( $this->plugin_name . '_settings' ); // Group name for general coupon and commission settings
                settings_fields( $this->plugin_name . '_email_settings_group' ); // Group name for email settings
				do_settings_sections( $this->plugin_name );          // Page slug (common for all sections on this page)
				submit_button( __( 'Save Settings', 'user-referral-coupons' ) );
				?>
			</form>

            <hr>
            <!-- Bulk Email Sender UI will go here later -->
            <div id="urc-bulk-email-sender-section">
                <h2><?php esc_html_e( 'Bulk Coupon Email Sender', 'user-referral-coupons' ); ?></h2>

                <?php
                $user_query = new WP_User_Query( array(
                    'count_total' => true,
                ) );
                $total_users = $user_query->get_total();

                $users_emailed_query = new WP_User_Query( array(
                    'meta_key'    => '_urc_coupon_email_sent_v1',
                    'meta_compare' => 'EXISTS',
                    'count_total' => true,
                ) );
                $users_emailed_count = $users_emailed_query->get_total();
                $users_eligible_count = $total_users - $users_emailed_count;
                ?>
                <p>
                    <?php printf( esc_html__( 'Total Users: %d', 'user-referral-coupons' ), (int) $total_users ); ?><br>
                    <?php printf( esc_html__( 'Users Already Emailed (this campaign): %d', 'user-referral-coupons' ), (int) $users_emailed_count ); ?><br>
                    <?php printf( esc_html__( 'Users Eligible for this Email Campaign: %d', 'user-referral-coupons' ), (int) $users_eligible_count ); ?>
                </p>

                <div id="urc-bulk-email-feedback"></div>
                <p>
                    <button type="button" id="urc-initiate-bulk-email" class="button button-primary" <?php echo $users_eligible_count === 0 ? 'disabled' : ''; ?>>
                        <?php esc_html_e( 'Process and Send Coupon Emails to Eligible Users', 'user-referral-coupons' ); ?>
                    </button>
                </p>
                <p>
                    <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" style="display: inline;">
                        <input type="hidden" name="action" value="urc_reset_email_sent_status">
                        <?php wp_nonce_field( 'urc_reset_email_sent_status_action', 'urc_reset_email_sent_status_nonce' ); ?>
                        <button type="submit" id="urc-reset-email-status" class="button button-caution" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to reset the email sent status for all users? This will allow the bulk sender to email them again.', 'user-referral-coupons' ); ?>');">
                            <?php esc_html_e( 'Reset Email Sent Status for All Users', 'user-referral-coupons' ); ?>
                        </button>
                    </form>
                </p>
                 <div id="urc-bulk-email-progress-bar-container" style="width: 100%; background-color: #f3f3f3; border: 1px solid #ccc; margin-top:10px; display:none;">
                    <div id="urc-bulk-email-progress-bar" style="width: 0%; height: 20px; background-color: #4caf50; text-align: center; line-height: 20px; color: white;">0%</div>
                </div>
                <div id="urc-bulk-email-log" style="max-height: 200px; overflow-y: auto; border: 1px solid #eee; padding: 5px; margin-top:10px; display:none;"></div>

            </div>
            <hr>
            <h2><?php esc_html_e( 'Manual Coupon Generation', 'user-referral-coupons' ); ?></h2>
            <p><?php esc_html_e( 'Use the button below to generate referral coupons for all existing users who do not currently have one.', 'user-referral-coupons' ); ?></p>
            <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
                <input type="hidden" name="action" value="urc_generate_all_coupons">
                <?php wp_nonce_field( 'urc_generate_all_coupons_action', 'urc_generate_all_coupons_nonce' ); ?>
                <?php submit_button( __( 'Generate Coupons for All Users', 'user-referral-coupons' ), 'secondary', 'urc_generate_all_coupons_submit' ); ?>
            </form>
		</div>
		<?php
	}

	/**
	 * Register plugin settings.
	 *
	 * @since    1.0.0
	 */
	public function register_settings() {
		register_setting(
			$this->plugin_name . '_settings', // Option group
			$this->plugin_name . '_options',  // Option name
			array( $this, 'sanitize_settings' ) // Sanitize callback
		);

		// General Settings Section
		add_settings_section(
			$this->plugin_name . '_general_section', // ID
			__( 'General Coupon Settings', 'user-referral-coupons' ), // Title
			array( $this, 'general_section_callback' ), // Callback
			$this->plugin_name // Page slug
		);

		add_settings_field(
			'coupon_discount_type', // ID
			__( 'Discount Type', 'user-referral-coupons' ), // Title
			array( $this, 'render_discount_type_field' ), // Callback to render the field
			$this->plugin_name, // Page slug
			$this->plugin_name . '_general_section', // Section ID
			array( 'label_for' => 'coupon_discount_type' ) // Args
		);

		add_settings_field(
			'coupon_amount', // ID
			__( 'Coupon Amount', 'user-referral-coupons' ), // Title
			array( $this, 'render_coupon_amount_field' ), // Callback
			$this->plugin_name, // Page slug
			$this->plugin_name . '_general_section', // Section
			array( 'label_for' => 'coupon_amount' )
		);

        add_settings_field(
			'coupon_expiry_days', // ID
			__( 'Coupon Expiry (Days)', 'user-referral-coupons' ), // Title
			array( $this, 'render_coupon_expiry_days_field' ), // Callback
			$this->plugin_name, // Page slug
			$this->plugin_name . '_general_section', // Section
			array( 'label_for' => 'coupon_expiry_days', 'description' => __( 'Number of days until the coupon expires. Leave 0 or empty for no expiration.', 'user-referral-coupons') )
		);


		// Commission Settings Section
		add_settings_section(
			$this->plugin_name . '_commission_section', // ID
			__( 'Commission Settings (Tera Wallet)', 'user-referral-coupons' ), // Title
			array( $this, 'commission_section_callback' ), // Callback
			$this->plugin_name // Page slug
		);

		add_settings_field(
			'enable_commission', // ID
			__( 'Enable Commission', 'user-referral-coupons' ), // Title
			array( $this, 'render_enable_commission_field' ), // Callback
			$this->plugin_name, // Page slug
			$this->plugin_name . '_commission_section', // Section
			array( 'label_for' => 'enable_commission' )
		);

		add_settings_field(
			'commission_type', // ID
			__( 'Commission Type', 'user-referral-coupons' ), // Title
			array( $this, 'render_commission_type_field' ), // Callback
			$this->plugin_name, // Page slug
			$this->plugin_name . '_commission_section', // Section
			array( 'label_for' => 'commission_type' )
		);

		add_settings_field(
			'commission_value', // ID
			__( 'Commission Value', 'user-referral-coupons' ), // Title
			array( $this, 'render_commission_value_field' ), // Callback
			$this->plugin_name, // Page slug
			$this->plugin_name . '_commission_section', // Section
			array( 'label_for' => 'commission_value' )
		);

		// Email Settings Section
		add_settings_section(
			$this->plugin_name . '_email_section', // ID
			__( 'Coupon Email Sender Settings', 'user-referral-coupons' ), // Title
			array( $this, 'email_section_callback' ), // Callback
			$this->plugin_name // Page slug
		);

		add_settings_field(
			'email_subject', // ID
			__( 'Email Subject', 'user-referral-coupons' ), // Title
			array( $this, 'render_email_subject_field' ), // Callback
			$this->plugin_name, // Page slug
			$this->plugin_name . '_email_section' // Section ID
		);

		add_settings_field(
			'email_body', // ID
			__( 'Email Body', 'user-referral-coupons' ), // Title
			array( $this, 'render_email_body_field' ), // Callback
			$this->plugin_name, // Page slug
			$this->plugin_name . '_email_section' // Section ID
		);

        add_settings_field(
			'send_on_new_registration', // ID
			__( 'New User Email', 'user-referral-coupons' ), // Title
			array( $this, 'render_send_on_new_registration_field' ), // Callback
			$this->plugin_name, // Page slug
			$this->plugin_name . '_email_section' // Section ID
		);

        register_setting(
			$this->plugin_name . '_email_settings_group', // Option group for email settings
			'user-referral-coupons_email_options',  // Option name
			array( $this, 'sanitize_email_settings' ) // Sanitize callback
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @since    1.0.0
	 * @param    array    $input    The settings array.
	 * @return   array    Sanitized settings array.
	 */
	public function sanitize_settings( $input ) {
		$new_input = array();
        $defaults = $this->get_default_options(); // Get defaults to ensure all keys are present

		// General Coupon Settings
		$new_input['coupon_discount_type'] = isset( $input['coupon_discount_type'] ) ? sanitize_text_field( $input['coupon_discount_type'] ) : $defaults['coupon_discount_type'];
		$new_input['coupon_amount']        = isset( $input['coupon_amount'] ) ? sanitize_text_field( $input['coupon_amount'] ) : $defaults['coupon_amount'];
		// Ensure coupon_amount is a string, WooCommerce expects it this way, validation for numeric can be added.
        // For 'percent' type, WC_Coupon expects '10' for 10%. For fixed types, '10' for $10.
        // We should ensure it's a format suitable for floatval() or direct use by WC.
        // sanitize_text_field is okay, but wc_format_decimal() might be better if we need to enforce a numeric format.
        // For now, sanitize_text_field is fine as WC_Coupon handles various inputs.

		$new_input['coupon_expiry_days']   = isset( $input['coupon_expiry_days'] ) ? absint( $input['coupon_expiry_days'] ) : $defaults['coupon_expiry_days'];

		// Commission Settings
		// For a checkbox, if it's not in $input, it means it was unchecked.
		$new_input['enable_commission']    = isset( $input['enable_commission'] ) ? 1 : 0;
		$new_input['commission_type']      = isset( $input['commission_type'] ) ? sanitize_text_field( $input['commission_type'] ) : $defaults['commission_type'];
		$new_input['commission_value']     = isset( $input['commission_value'] ) ? sanitize_text_field( $input['commission_value'] ) : $defaults['commission_value'];
        // Similar to coupon_amount, ensure commission_value is a string.

		return $new_input;
	}

    /**
     * Get default plugin options (static version).
     * Used for sanitization and initial setup.
     * @return array Default options.
     */
    public static function get_static_default_options() {
        return array(
            'coupon_discount_type' => 'percent',
            'coupon_amount'        => '10',
            'coupon_expiry_days'   => 0,
            'enable_commission'    => 0,
            'commission_type'      => 'percent',
            'commission_value'     => '5',
        );
    }

    /**
     * Get default plugin options (instance version).
     * Used by instance methods like sanitize_settings.
     * @return array Default options.
     */
    private function get_default_options() {
        return self::get_static_default_options();
    }

    /**
     * Get default email plugin options (static version).
     * @return array Default email options.
     */
    public static function get_static_default_email_options() {
        return array(
            'email_subject'            => __( 'Your Referral Coupon from {site_name}!', 'user-referral-coupons' ),
            'email_body'               => self::get_default_email_body(),
            'send_on_new_registration' => 0, // Default to off
        );
    }

    /**
     * Get default email body content.
     * @return string Default email body.
     */
    private static function get_default_email_body() {
        $body = "<p>" . __( "Hi {user_display_name},", 'user-referral-coupons' ) . "</p>\n\n";
        $body .= "<p>" . __( "Thanks for being a valued member of {site_name}!", 'user-referral-coupons' ) . "</p>\n\n";
        $body .= "<p>" . __( "You can invite your friends to make a purchase with your exclusive referral coupon code:", 'user-referral-coupons' ) . " <strong>{coupon_code}</strong></p>\n\n";
        $body .= "<p>" . __( "This coupon gives: {coupon_details}.", 'user-referral-coupons' ) . "</p>\n\n";
        $body .= "<p>" . sprintf(__( 'Share it with your friends and let them enjoy the benefits! Visit us at <a href="%s">%s</a>.', 'user-referral-coupons' ), '{site_url}', '{site_name}') . "</p>\n\n";
        $body .= "<p>" . __( "Thanks,", 'user-referral-coupons' ) . "<br>\n";
        $body .= "{site_name}</p>";
        return $body;
    }


	/**
	 * Sanitize email settings.
	 *
	 * @since    1.0.0
	 * @param    array    $input    The settings array.
	 * @return   array    Sanitized settings array.
	 */
	public function sanitize_email_settings( $input ) {
		$new_input = array();
		$defaults = self::get_static_default_email_options();

		$new_input['email_subject'] = isset( $input['email_subject'] ) ? sanitize_text_field( $input['email_subject'] ) : $defaults['email_subject'];

        if ( isset( $input['email_body'] ) ) {
            // Allow most HTML, similar to post content.
            // wp_kses_post is good for display, but for saving, we might want a bit more control or trust admin input.
            // For now, use wp_kses_post to ensure safe HTML.
             $new_input['email_body'] = wp_kses_post( $input['email_body'] );
        } else {
            $new_input['email_body'] = $defaults['email_body'];
        }
		$new_input['send_on_new_registration'] = isset( $input['send_on_new_registration'] ) ? 1 : 0;

		return $new_input;
	}

	/**
	 * Callback for the general settings section.
	 *
	 * @since    1.0.0
	 */
	public function general_section_callback() {
		echo '<p>' . esc_html__( 'Configure the general settings for referral coupons.', 'user-referral-coupons' ) . '</p>';
	}

	/**
	 * Callback for the commission settings section.
	 *
	 * @since    1.0.0
	 */
	public function commission_section_callback() {
		echo '<p>' . esc_html__( 'Configure commission settings for Tera Wallet integration.', 'user-referral-coupons' ) . '</p>';
        if ( ! class_exists( 'WOO_Wallet' ) ) {
            echo '<p style="color:red;">' . esc_html__( 'Tera Wallet plugin is not active. Commission features will not work.', 'user-referral-coupons' ) . '</p>';
        }
	}

	/**
	 * Render discount type field.
	 */
	public function render_discount_type_field() {
		$options = get_option( $this->plugin_name . '_options' );
		$value = isset( $options['coupon_discount_type'] ) ? $options['coupon_discount_type'] : 'percent';
		?>
		<select id="coupon_discount_type" name="<?php echo $this->plugin_name . '_options[coupon_discount_type]'; ?>">
			<option value="percent" <?php selected( $value, 'percent' ); ?>><?php esc_html_e( 'Percentage discount', 'user-referral-coupons' ); ?></option>
			<option value="fixed_cart" <?php selected( $value, 'fixed_cart' ); ?>><?php esc_html_e( 'Fixed cart discount', 'user-referral-coupons' ); ?></option>
			<option value="fixed_product" <?php selected( $value, 'fixed_product' ); ?>><?php esc_html_e( 'Fixed product discount', 'user-referral-coupons' ); ?></option>
		</select>
		<?php
	}

	/**
	 * Render coupon amount field.
	 */
	public function render_coupon_amount_field() {
		$options = get_option( $this->plugin_name . '_options' );
		$value = isset( $options['coupon_amount'] ) ? $options['coupon_amount'] : '10';
		?>
		<input type="number" step="any" min="0" id="coupon_amount" name="<?php echo $this->plugin_name . '_options[coupon_amount]'; ?>" value="<?php echo esc_attr( $value ); ?>" />
        <p class="description"><?php esc_html_e( 'Enter the discount value. For percentage, 10 means 10%. For fixed, it is the monetary value.', 'user-referral-coupons' ); ?></p>
		<?php
	}

    /**
	 * Render coupon expiry days field.
	 */
	public function render_coupon_expiry_days_field( $args ) {
		$options = get_option( $this->plugin_name . '_options' );
		$value = isset( $options['coupon_expiry_days'] ) ? $options['coupon_expiry_days'] : '0';
		?>
		<input type="number" step="1" min="0" id="coupon_expiry_days" name="<?php echo $this->plugin_name . '_options[coupon_expiry_days]'; ?>" value="<?php echo esc_attr( $value ); ?>" />
        <p class="description"><?php echo wp_kses_post( $args['description'] ?? '' ); ?></p>
		<?php
	}


	/**
	 * Render enable commission field.
	 */
	public function render_enable_commission_field() {
		$options = get_option( $this->plugin_name . '_options' );
		$value = isset( $options['enable_commission'] ) ? $options['enable_commission'] : 0;
		?>
		<input type="checkbox" id="enable_commission" name="<?php echo $this->plugin_name . '_options[enable_commission]'; ?>" value="1" <?php checked( $value, 1 ); ?> />
		<label for="enable_commission"><?php esc_html_e( 'Enable commission for referrers', 'user-referral-coupons' ); ?></label>
		<?php
	}

	/**
	 * Render commission type field.
	 */
	public function render_commission_type_field() {
		$options = get_option( $this->plugin_name . '_options' );
		$value = isset( $options['commission_type'] ) ? $options['commission_type'] : 'percent';
		?>
		<select id="commission_type" name="<?php echo $this->plugin_name . '_options[commission_type]'; ?>">
			<option value="percent" <?php selected( $value, 'percent' ); ?>><?php esc_html_e( 'Percentage of cart total', 'user-referral-coupons' ); ?></option>
			<option value="fixed" <?php selected( $value, 'fixed' ); ?>><?php esc_html_e( 'Fixed amount', 'user-referral-coupons' ); ?></option>
		</select>
		<?php
	}

	/**
	 * Render commission value field.
	 */
	public function render_commission_value_field() {
		$options = get_option( $this->plugin_name . '_options' );
		$value = isset( $options['commission_value'] ) ? $options['commission_value'] : '5';
		?>
		<input type="number" step="any" min="0" id="commission_value" name="<?php echo $this->plugin_name . '_options[commission_value]'; ?>" value="<?php echo esc_attr( $value ); ?>" />
        <p class="description"><?php esc_html_e( 'Enter the commission value. For percentage, 5 means 5%. For fixed, it is the monetary value.', 'user-referral-coupons' ); ?></p>
		<?php
	}

    /**
     * Helper function to get plugin options with defaults.
     */
    public static function get_urc_options() {
        $defaults = self::get_static_default_options(); // Use the static method for defaults
        $options = get_option( 'user-referral-coupons_options' ); // Get saved options

        // If options are not set in the database, $options will be false.
        // In this case, wp_parse_args will correctly use $defaults.
        // If options are set, they will be merged over $defaults.
        // It's good to ensure $options is an array if it's not false, for robustness.
        if ( false === $options ) {
            $options = array(); // Ensure it's an array for wp_parse_args if it wasn't found
        }

        return wp_parse_args( (array) $options, $defaults );
    }

    /**
     * Callback for the email settings section.
     *
     * @since    1.0.0
     */
    public function email_section_callback() {
        echo '<p>' . esc_html__( 'Configure the email template sent to users with their coupon code.', 'user-referral-coupons' ) . '</p>';
        echo '<p><strong>' . esc_html__( 'Available placeholders for Subject and Body:', 'user-referral-coupons') . '</strong><br>';
        echo '<code>{user_first_name}</code>, <code>{user_last_name}</code>, <code>{user_display_name}</code>, <code>{user_email}</code>, ';
        echo '<code>{coupon_code}</code>, <code>{coupon_details}</code>, <code>{site_name}</code>, <code>{site_url}</code></p>';
    }

    /**
     * Render email subject field.
     * @since 1.0.0
     */
    public function render_email_subject_field() {
        $options = get_option( 'user-referral-coupons_email_options', self::get_static_default_email_options() );
        $value = $options['email_subject'];
        ?>
        <input type="text" id="email_subject" name="user-referral-coupons_email_options[email_subject]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
        <?php
    }

    /**
     * Render email body field.
     * @since 1.0.0
     */
    public function render_email_body_field() {
        $options = get_option( 'user-referral-coupons_email_options', self::get_static_default_email_options() );
        $value = $options['email_body'];
        wp_editor( $value, 'email_body_editor', array(
            'textarea_name' => 'user-referral-coupons_email_options[email_body]',
            'textarea_rows' => 10,
            'media_buttons' => false, // No media buttons in email editor
            'tinymce'       => true, // Use TinyMCE
            'quicktags'     => true
        ) );
    }

    /**
     * Render 'Send on new registration' field.
     * @since 1.0.0
     */
    public function render_send_on_new_registration_field() {
        $options = get_option( 'user-referral-coupons_email_options', self::get_static_default_email_options() );
        $value = isset( $options['send_on_new_registration'] ) ? $options['send_on_new_registration'] : 0;
		?>
		<input type="checkbox" id="send_on_new_registration" name="user-referral-coupons_email_options[send_on_new_registration]" value="1" <?php checked( $value, 1 ); ?> />
		<label for="send_on_new_registration"><?php esc_html_e( 'Send coupon welcome email to new users upon their registration.', 'user-referral-coupons' ); ?></label>
		<?php
    }

    /**
     * Handle resetting the email sent status for all users.
     *
     * @since 1.0.0
     */
    public function handle_reset_email_sent_status() {
        if ( ! isset( $_POST['urc_reset_email_sent_status_nonce'] ) || ! wp_verify_nonce( sanitize_key($_POST['urc_reset_email_sent_status_nonce']), 'urc_reset_email_sent_status_action' ) ) {
            wp_die( esc_html__( 'Nonce verification failed.', 'user-referral-coupons' ), 'Error', array( 'response' => 403 ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'user-referral-coupons' ), 'Error', array( 'response' => 403 ) );
        }

        global $wpdb;
        // Directly delete user meta for all users. This is more efficient than looping through get_users.
        $deleted_count = $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->usermeta WHERE meta_key = %s", '_urc_coupon_email_sent_v1' ) );

        // $deleted_count might be number of rows affected, or false on error.
        // For a more accurate count of users affected, one might need to query users who had the meta first.
        // However, for this purpose, confirming the action was run is often sufficient.

        $notices = array(array(
            'type'    => 'success',
            // Message can be generic as $deleted_count from $wpdb->query might not be the user count.
            'message' => __( 'Email sent status has been reset for all users.', 'user-referral-coupons' ),
        ));
        if (false === $deleted_count) {
             $notices = array(array(
                'type'    => 'error',
                'message' => __( 'Failed to reset email status. Please check logs.', 'user-referral-coupons' ),
            ));
        }

        set_transient( $this->plugin_name . '_admin_notices', $notices, 30 );
        wp_safe_redirect( admin_url( 'admin.php?page=' . $this->plugin_name ) );
        exit;
    }

    /**
     * AJAX handler to initiate bulk email sending.
     * Prepares a list of eligible users and stores it in a transient.
     */
    public function ajax_initiate_bulk_email() {
        check_ajax_referer( 'urc_admin_ajax_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'user-referral-coupons' ) ) );
        }

        $all_user_ids = get_users( array( 'fields' => 'ID', 'orderby' => 'ID', 'order' => 'ASC' ) );
        $eligible_user_ids = array();

        foreach ( $all_user_ids as $user_id ) {
            if ( ! get_user_meta( $user_id, '_urc_coupon_email_sent_v1', true ) ) {
                // Also check if user has a coupon
                if ( URC_Coupon_Manager::get_user_referral_coupon( $user_id ) ) {
                    $eligible_user_ids[] = $user_id;
                }
            }
        }

        $batch_size = apply_filters('urc_bulk_email_batch_size', 20);
        set_transient( 'urc_bulk_email_user_ids_queue', $eligible_user_ids, HOUR_IN_SECONDS ); // Store for 1 hour
        set_transient( 'urc_bulk_email_batch_size', $batch_size, HOUR_IN_SECONDS);


        wp_send_json_success( array(
            'total_eligible' => count( $eligible_user_ids ),
            'batch_size'     => $batch_size,
        ) );
    }

    /**
     * AJAX handler to process a batch of emails.
     */
    public function ajax_process_bulk_email_batch() {
        check_ajax_referer( 'urc_admin_ajax_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'user-referral-coupons' ) ) );
        }

        $eligible_user_ids = get_transient( 'urc_bulk_email_user_ids_queue' );
        $batch_size = get_transient( 'urc_bulk_email_batch_size' );

        if ( false === $eligible_user_ids || false === $batch_size) {
            wp_send_json_error( array( 'message' => __( 'Email queue not found or expired. Please initiate again.', 'user-referral-coupons' ) ) );
        }
        if (!is_array($eligible_user_ids)) {
             $eligible_user_ids = array(); // ensure it's an array
        }


        $offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
        // The JS sends offset based on total processed. We need to pick from the $eligible_user_ids array.
        // The JS offset is the number of items already processed from the eligible list.

        $current_batch_user_ids = array_slice( $eligible_user_ids, $offset, $batch_size );

        $success_count = 0;
        $failure_count = 0;
        $processed_in_this_batch = 0;
        $log_messages = array();

        if ( empty( $current_batch_user_ids ) ) {
             wp_send_json_success( array(
                'processed_in_batch' => 0,
                'successes'          => 0,
                'failures'           => 0,
                'log_messages'       => array(__('No users left in this batch from queue.', 'user-referral-coupons')),
                'current_offset'     => $offset, // current offset + processed
                'total_eligible'     => count($eligible_user_ids)
            ) );
            return;
        }


        foreach ( $current_batch_user_ids as $user_id ) {
            $processed_in_this_batch++;
            $user = get_user_by('id', $user_id);
            if (!$user) {
                $failure_count++;
                $log_messages[] = sprintf(__('User ID %d not found. Skipped.', 'user-referral-coupons'), $user_id);
                continue;
            }

            // Double check meta, though initial list should be eligible
            if ( get_user_meta( $user_id, '_urc_coupon_email_sent_v1', true ) ) {
                // $log_messages[] = sprintf(__('User ID %d (%s) already marked as emailed. Skipped.', 'user-referral-coupons'), $user_id, $user->user_email);
                // This case should ideally not happen if the initial eligible list is correct.
                // If it does, it means something else marked them, or the list is stale.
                // For simplicity, we don't count this as a failure or success for this batch's purpose,
                // as it wasn't an "attempt" for this batch.
                // Or, more accurately, the definition of "processed_in_this_batch" should be "attempted".
                continue;
            }


            if ( URC_Email_Manager::send_coupon_email( $user_id ) ) {
                update_user_meta( $user_id, '_urc_coupon_email_sent_v1', time() );
                $success_count++;
                // $log_messages[] = sprintf(__('Email sent to %s.', 'user-referral-coupons'), $user->user_email);
            } else {
                $failure_count++;
                $log_messages[] = sprintf(__('Failed to send email to %s (User ID %d).', 'user-referral-coupons'), $user->user_email, $user_id);
            }
        }

        // No need to update the transient queue here, as we just process a slice.
        // The offset for next batch will be current_offset + $processed_in_this_batch

        wp_send_json_success( array(
            'processed_in_batch' => $processed_in_this_batch, // Number of users attempted in this specific AJAX call
            'successes'          => $success_count,
            'failures'           => $failure_count,
            'log_messages'       => $log_messages,
            'current_offset'     => $offset + $processed_in_this_batch, // JS uses this to know the new global offset
            'total_eligible'     => count($eligible_user_ids) // Total number of users in the transient queue
        ) );
    }


    /**
     * Handle the manual generation of coupons for all users.
     *
     * @since 1.0.0
     */
    public function handle_generate_all_coupons() {
        if ( ! isset( $_POST['urc_generate_all_coupons_nonce'] ) || ! wp_verify_nonce( sanitize_key($_POST['urc_generate_all_coupons_nonce']), 'urc_generate_all_coupons_action' ) ) {
            wp_die( esc_html__( 'Nonce verification failed.', 'user-referral-coupons' ), 'Error', array( 'response' => 403 ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'user-referral-coupons' ), 'Error', array( 'response' => 403 ) );
        }

        $users = get_users( array( 'fields' => 'ID' ) );
        $created_count = 0;
        $skipped_count = 0;
        $error_count = 0;
        $error_messages = array();

        $settings = self::get_urc_options();

        foreach ( $users as $user_id ) {
            // Check if coupon code already exists (using the specific format)
            $coupon_code = URC_Coupon_Manager::generate_user_coupon_code( $user_id );
            if ( URC_Coupon_Manager::coupon_exists( $coupon_code ) ) {
                // Check if it's OUR coupon by checking metadata
                $existing_coupon_id = URC_Coupon_Manager::coupon_exists( $coupon_code );
                if( get_post_meta( $existing_coupon_id, '_urc_coupon', true) === 'yes' &&
                    (int) get_post_meta( $existing_coupon_id, '_urc_user_id', true ) === (int) $user_id ) {
                    $skipped_count++;
                    continue;
                }
                // If a coupon with the same code exists but isn't our specific URC coupon, it's an edge case.
                // For now, we'll treat it as a skip/conflict to avoid overwriting unrelated coupons.
                // A more advanced version might append a suffix or handle this differently.
                // error_log("Conflicting coupon code found for user {$user_id}: {$coupon_code} but it's not a URC coupon for this user.");
                // $error_messages[] = sprintf( __( 'Conflicting coupon code %s found for user ID %d (not a URC coupon or different user). Skipped.', 'user-referral-coupons' ), $coupon_code, $user_id );
                // $error_count++;
                // continue;
                // For simplicity now, if a coupon with that code exists, just skip.
                $skipped_count++;
                continue;

            }

            $result = URC_Coupon_Manager::create_user_coupon( $user_id, $settings );

            if ( is_wp_error( $result ) ) {
                if ( $result->get_error_code() === 'coupon_already_exists' ) {
                    // This case should ideally be caught by the check above, but as a fallback:
                    $skipped_count++;
                } else {
                    $error_count++;
                    $error_messages[] = sprintf( __( 'Error creating coupon for user ID %d: %s', 'user-referral-coupons' ), $user_id, $result->get_error_message() );
                }
            } else {
                $created_count++;
            }
        }

        $notices = array();
        if ( $created_count > 0 ) {
            $notices[] = array(
                'type'    => 'success',
                'message' => sprintf( _n( '%d new referral coupon created.', '%d new referral coupons created.', $created_count, 'user-referral-coupons' ), $created_count ),
            );
        }
        if ( $skipped_count > 0 ) {
            $notices[] = array(
                'type'    => 'info',
                'message' => sprintf( _n( '%d user already had a referral coupon and was skipped.', '%d users already had referral coupons and were skipped.', $skipped_count, 'user-referral-coupons' ), $skipped_count ),
            );
        }
        if ( $error_count > 0 ) {
            $notices[] = array(
                'type'    => 'error',
                'message' => sprintf( _n( 'There was %d error during coupon generation.', 'There were %d errors during coupon generation.', $error_count, 'user-referral-coupons' ), $error_count ) . '<br>' . implode( '<br>', $error_messages ),
            );
        }
        if ( empty( $notices ) ) {
             $notices[] = array(
                'type'    => 'info',
                'message' => __( 'No users found or no coupons needed to be generated.', 'user-referral-coupons' ),
            );
        }

        set_transient( $this->plugin_name . '_admin_notices', $notices, 30 );
        wp_safe_redirect( admin_url( 'admin.php?page=' . $this->plugin_name ) );
        exit;
    }

    /**
     * Display admin notices stored in a transient.
     *
     * @since 1.0.0
     */
    public function display_admin_notices() {
        $notices = get_transient( $this->plugin_name . '_admin_notices' );

        if ( $notices && is_array( $notices ) ) {
            foreach ( $notices as $notice ) {
                $type = isset( $notice['type'] ) ? $notice['type'] : 'info'; // success, error, warning, info
                $message = isset( $notice['message'] ) ? $notice['message'] : '';
                if ( ! empty( $message ) ) {
                    printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), wp_kses_post( $message ) );
                }
            }
            delete_transient( $this->plugin_name . '_admin_notices' );
        }
    }
}
?>
