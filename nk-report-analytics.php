<?php
/**
 * Plugin Name:       Netweek Report Analytics
 * Plugin URI:        https://netweek.it
 * Description:       Dashboard editoriale interattiva basata su Google Sheets e Service Account, con selezione dinamica di fogli e tab dal backend WordPress.
 * Version:           1.7.2
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Netweek
 * License:           GPL-2.0-or-later
 * Text Domain:       nk-report-analytics
 */

defined( 'ABSPATH' ) || exit;

define( 'NK_REPORT_VERSION', '1.7.2' );
define( 'NK_REPORT_PLUGIN_FILE', __FILE__ );
define( 'NK_REPORT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NK_REPORT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

define( 'NK_REPORT_DEFAULT_SPREADSHEET_ID', '1H66ADbqv_wi81_kIUFMpGZVHwQHdmuyImFTk3_8PIUQ' );
define( 'NK_REPORT_DEFAULT_TAB_NAME', 'Dati_Giornalieri' );

define( 'NK_REPORT_OPTION_KEY', 'nk_report_analytics_settings' );
define( 'NK_REPORT_TOKEN_TRANSIENT', 'nk_report_google_oauth_token' );
define( 'NK_REPORT_DATA_TRANSIENT_PREFIX', 'nk_report_sheet_data_' );

/**
 * Restituisce le impostazioni del plugin unite ai valori di default.
 *
 * @return array
 */
function nk_report_get_settings() {
	$defaults = array(
		'spreadsheet_id'          => NK_REPORT_DEFAULT_SPREADSHEET_ID,
		'tab_name'                => NK_REPORT_DEFAULT_TAB_NAME,
		'spreadsheet_name'        => '',
		'cache_hours'             => 6,
		'required_capability'     => 'edit_posts',
		'manual_mode'             => false,
		'public_password_enabled' => false,
		'access_password_hash'    => '',
	);

	$saved = get_option( NK_REPORT_OPTION_KEY, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}

	$settings = wp_parse_args( $saved, $defaults );

	$settings['cache_hours']             = max( 1, min( 168, absint( $settings['cache_hours'] ) ) );
	$settings['required_capability']     = ! empty( $settings['required_capability'] ) ? sanitize_key( $settings['required_capability'] ) : 'edit_posts';
	$settings['spreadsheet_id']          = trim( (string) $settings['spreadsheet_id'] );
	$settings['tab_name']                = trim( (string) $settings['tab_name'] );
	$settings['spreadsheet_name']        = trim( (string) $settings['spreadsheet_name'] );
	$settings['manual_mode']             = ! empty( $settings['manual_mode'] );
	$settings['public_password_enabled'] = ! empty( $settings['public_password_enabled'] );
	$settings['access_password_hash']    = (string) $settings['access_password_hash'];

	return $settings;
}

/**
 * Verifica se il visitatore non autenticato possiede un cookie di accesso valido firmato HMAC.
 *
 * @param array|null $settings Impostazioni opzionali.
 * @return bool
 */
function nk_report_is_visitor_authorized( $settings = null ) {
	if ( null === $settings ) {
		$settings = nk_report_get_settings();
	}

	if ( empty( $settings['public_password_enabled'] ) || empty( $settings['access_password_hash'] ) ) {
		return false;
	}

	$cookie_name = 'wp-postpass_nk_report';
	if ( empty( $_COOKIE[ $cookie_name ] ) && empty( $_COOKIE['nk_report_access'] ) ) {
		return false;
	}

	$raw_raw    = ! empty( $_COOKIE[ $cookie_name ] ) ? $_COOKIE[ $cookie_name ] : $_COOKIE['nk_report_access'];
	$raw_cookie = sanitize_text_field( wp_unslash( $raw_raw ) );
	$parts      = explode( '|', $raw_cookie, 2 );
	if ( 2 !== count( $parts ) ) {
		return false;
	}

	$expires = (int) $parts[0];
	$sig     = (string) $parts[1];

	if ( $expires < time() ) {
		return false;
	}

	$expected = hash_hmac( 'sha256', $expires . '|' . $settings['access_password_hash'], wp_salt( 'auth' ) );

	return hash_equals( $expected, $sig );
}

/**
 * Verifica se l'utente corrente (autenticato o visitatore con password) può accedere alla dashboard.
 *
 * @return bool
 */
function nk_report_can_view_dashboard() {
	$settings = nk_report_get_settings();

	if ( is_user_logged_in() && current_user_can( $settings['required_capability'] ) ) {
		return true;
	}

	return nk_report_is_visitor_authorized( $settings );
}

/**
 * Invalida tutti i transient della cache dati letti da Google Sheets.
 *
 * @return void
 */
function nk_report_clear_data_cache() {
	global $wpdb;

	$settings = nk_report_get_settings();
	if ( ! empty( $settings['spreadsheet_id'] ) && ! empty( $settings['tab_name'] ) ) {
		$active_key = NK_REPORT_DATA_TRANSIENT_PREFIX . md5( $settings['spreadsheet_id'] . '|' . $settings['tab_name'] );
		delete_transient( $active_key );
	}

	// Pulizia di eventuali transient precedenti salvati per altri fogli o tab.
	$like_transient = $wpdb->esc_like( '_transient_' . NK_REPORT_DATA_TRANSIENT_PREFIX ) . '%';
	$like_timeout   = $wpdb->esc_like( '_transient_timeout_' . NK_REPORT_DATA_TRANSIENT_PREFIX ) . '%';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$like_transient,
			$like_timeout
		)
	);
}

/**
 * Attivazione del plugin: inizializza le opzioni di default se assenti.
 *
 * @return void
 */
function nk_report_activate() {
	if ( false === get_option( NK_REPORT_OPTION_KEY, false ) ) {
		add_option(
			NK_REPORT_OPTION_KEY,
			array(
				'spreadsheet_id'      => NK_REPORT_DEFAULT_SPREADSHEET_ID,
				'tab_name'            => NK_REPORT_DEFAULT_TAB_NAME,
				'spreadsheet_name'    => '',
				'cache_hours'         => 6,
				'required_capability' => 'edit_posts',
				'manual_mode'         => false,
			)
		);
	}
}
register_activation_hook( __FILE__, 'nk_report_activate' );

/**
 * Disattivazione del plugin: rimuove i transient temporanei.
 *
 * @return void
 */
function nk_report_deactivate() {
	delete_transient( NK_REPORT_TOKEN_TRANSIENT );
	nk_report_clear_data_cache();
}
register_deactivation_hook( __FILE__, 'nk_report_deactivate' );

/**
 * Bootstrap del plugin.
 *
 * @return void
 */
function nk_report_init() {
	require_once NK_REPORT_PLUGIN_DIR . 'includes/class-google-sheets-client.php';
	require_once NK_REPORT_PLUGIN_DIR . 'includes/class-admin-settings.php';
	require_once NK_REPORT_PLUGIN_DIR . 'includes/class-ajax-handler.php';
	require_once NK_REPORT_PLUGIN_DIR . 'includes/class-shortcode.php';

	$sheets_client = new NK_Google_Sheets_Client();

	if ( is_admin() ) {
		$admin_settings = new NK_Admin_Settings( $sheets_client );
		$admin_settings->register_hooks();
	}

	$ajax_handler = new NK_Ajax_Handler( $sheets_client );
	$ajax_handler->register_hooks();

	$shortcode = new NK_Shortcode();
	$shortcode->register_hooks();
}
add_action( 'plugins_loaded', 'nk_report_init' );
