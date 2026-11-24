<?php
/**
 * Gestione endpoint AJAX admin e frontend.
 *
 * @package NK_Report_Analytics
 */

defined( 'ABSPATH' ) || exit;

class NK_Ajax_Handler {

	/**
	 * Client Google Sheets.
	 *
	 * @var NK_Google_Sheets_Client
	 */
	private $client;

	/**
	 * Costruttore.
	 *
	 * @param NK_Google_Sheets_Client $client Istanza del client Google Sheets.
	 */
	public function __construct( NK_Google_Sheets_Client $client ) {
		$this->client = $client;
	}

	/**
	 * Registra gli action hook AJAX solo per utenti autenticati.
	 *
	 * @return void
	 */
	public function register_hooks() {
		// Endpoint Admin (manage_options).
		add_action( 'wp_ajax_nk_admin_list_spreadsheets', array( $this, 'admin_list_spreadsheets' ) );
		add_action( 'wp_ajax_nk_admin_list_tabs', array( $this, 'admin_list_tabs' ) );
		add_action( 'wp_ajax_nk_admin_test_connection', array( $this, 'admin_test_connection' ) );

		// Endpoint Frontend (utenti autorizzati o visitatori con cookie password valido).
		add_action( 'wp_ajax_nk_get_report_data', array( $this, 'frontend_get_report_data' ) );
		add_action( 'wp_ajax_nopriv_nk_get_report_data', array( $this, 'frontend_get_report_data' ) );

		add_action( 'wp_ajax_nk_refresh_report_data', array( $this, 'frontend_refresh_report_data' ) );
		add_action( 'wp_ajax_nopriv_nk_refresh_report_data', array( $this, 'frontend_refresh_report_data' ) );

		// Verifica password visitatori.
		add_action( 'wp_ajax_nk_verify_dashboard_password', array( $this, 'verify_dashboard_password' ) );
		add_action( 'wp_ajax_nopriv_nk_verify_dashboard_password', array( $this, 'verify_dashboard_password' ) );
	}

	/**
	 * Verifica permessi admin e nonce.
	 *
	 * @return void
	 */
	private function verify_admin_request() {
		check_ajax_referer( 'nk_report_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Permessi insufficienti per eseguire questa operazione.', 'nk-report-analytics' ) ),
				403
			);
		}
	}

	/**
	 * Verifica permessi frontend e nonce.
	 *
	 * @return array Impostazioni del plugin.
	 */
	private function verify_frontend_request() {
		check_ajax_referer( 'nk_report_frontend_nonce', 'nonce' );

		$settings = nk_report_get_settings();
		if ( ! nk_report_can_view_dashboard() ) {
			wp_send_json_error(
				array( 'message' => __( 'Accesso negato: non disponi dei permessi necessari.', 'nk-report-analytics' ) ),
				403
			);
		}

		return $settings;
	}

	/**
	 * AJAX Pubblico: verifica la password inserita dal visitatore e imposta il cookie firmato HMAC.
	 *
	 * @return void
	 */
	public function verify_dashboard_password() {
		check_ajax_referer( 'nk_report_password_nonce', 'nonce' );

		$settings = nk_report_get_settings();
		if ( empty( $settings['public_password_enabled'] ) || empty( $settings['access_password_hash'] ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Accesso con password non abilitato.', 'nk-report-analytics' ) ),
				403
			);
		}

		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
		if ( '' === $password || ! wp_check_password( $password, $settings['access_password_hash'] ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Password non corretta. Riprova.', 'nk-report-analytics' ) ),
				401
			);
		}

		$expires = time() + DAY_IN_SECONDS;
		$sig     = hash_hmac( 'sha256', $expires . '|' . $settings['access_password_hash'], wp_salt( 'auth' ) );
		$value   = $expires . '|' . $sig;

		setcookie(
			'nk_report_access',
			$value,
			array(
				'expires'  => $expires,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);

		wp_send_json_success( array( 'authorized' => true ) );
	}

	/**
	 * AJAX Admin: elenco dei Google Sheet accessibili al Service Account.
	 *
	 * @return void
	 */
	public function admin_list_spreadsheets() {
		$this->verify_admin_request();

		$files = $this->client->list_spreadsheets();
		if ( is_wp_error( $files ) ) {
			wp_send_json_error(
				array(
					'message' => $files->get_error_message(),
					'code'    => $files->get_error_code(),
				),
				400
			);
		}

		$sa_email = $this->client->get_service_account_email();

		wp_send_json_success(
			array(
				'files'    => $files,
				'sa_email' => is_wp_error( $sa_email ) ? '' : $sa_email,
			)
		);
	}

	/**
	 * AJAX Admin: elenco dei Tab interni di un Google Sheet.
	 *
	 * @return void
	 */
	public function admin_list_tabs() {
		$this->verify_admin_request();

		$spreadsheet_id = isset( $_POST['spreadsheet_id'] ) ? sanitize_text_field( wp_unslash( $_POST['spreadsheet_id'] ) ) : '';
		$result         = $this->client->list_sheet_tabs( $spreadsheet_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
				),
				400
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX Admin: testa la lettura del file/tab selezionato e restituisce anteprima prime 5 righe.
	 *
	 * @return void
	 */
	public function admin_test_connection() {
		$this->verify_admin_request();

		$spreadsheet_id = isset( $_POST['spreadsheet_id'] ) ? sanitize_text_field( wp_unslash( $_POST['spreadsheet_id'] ) ) : '';
		$tab_name       = isset( $_POST['tab_name'] ) ? sanitize_text_field( wp_unslash( $_POST['tab_name'] ) ) : '';

		$data = $this->client->get_sheet_data( $spreadsheet_id, $tab_name, true );
		if ( is_wp_error( $data ) ) {
			wp_send_json_error(
				array(
					'message' => $data->get_error_message(),
					'code'    => $data->get_error_code(),
				),
				400
			);
		}

		$preview = array_slice( $data['rows'], 0, 5 );

		wp_send_json_success(
			array(
				'total_rows'   => $data['total_rows'],
				'last_updated' => $data['last_updated'],
				'preview'      => $preview,
			)
		);
	}

	/**
	 * AJAX Frontend: restituisce i dati (da cache se valida).
	 *
	 * @return void
	 */
	public function frontend_get_report_data() {
		$settings = $this->verify_frontend_request();
		$this->send_report_payload( $settings, false );
	}

	/**
	 * AJAX Frontend: invalida cache e rilegge subito dallo Sheet.
	 *
	 * @return void
	 */
	public function frontend_refresh_report_data() {
		$settings = $this->verify_frontend_request();
		$this->send_report_payload( $settings, true );
	}

	/**
	 * Carica i dati dallo Sheet e invia la risposta JSON alla dashboard.
	 *
	 * @param array $settings      Impostazioni attive.
	 * @param bool  $force_refresh Forza aggiornamento da Google API.
	 * @return void
	 */
	private function send_report_payload( $settings, $force_refresh ) {
		$data = $this->client->get_sheet_data( $settings['spreadsheet_id'], $settings['tab_name'], $force_refresh );

		if ( is_wp_error( $data ) ) {
			wp_send_json_error(
				array(
					'message' => $data->get_error_message(),
					'code'    => $data->get_error_code(),
				),
				400
			);
		}

		$sheet_label = $settings['spreadsheet_name'] ? $settings['spreadsheet_name'] : $settings['spreadsheet_id'];

		wp_send_json_success(
			array(
				'rows'             => $data['rows'],
				'total_rows'       => $data['total_rows'],
				'last_updated'     => $data['last_updated'],
				'spreadsheet_id'   => $settings['spreadsheet_id'],
				'spreadsheet_name' => $sheet_label,
				'tab_name'         => $settings['tab_name'],
			)
		);
	}
}
