<?php
/**
 * Client puro PHP per Google Drive API v3 e Google Sheets API v4 tramite Service Account JWT RS256.
 *
 * @package NK_Report_Analytics
 */

defined( 'ABSPATH' ) || exit;

class NK_Google_Sheets_Client {

	/**
	 * Scope OAuth2 in sola lettura richiesti nel JWT.
	 */
	const OAUTH_SCOPES = 'https://www.googleapis.com/auth/spreadsheets.readonly https://www.googleapis.com/auth/drive.readonly';

	/**
	 * Endpoint OAuth2 di default.
	 */
	const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

	/**
	 * Legge e valida le credenziali del Service Account da wp-config.php.
	 *
	 * @return array|WP_Error
	 */
	public function get_credentials() {
		if ( ! function_exists( 'openssl_sign' ) ) {
			return new WP_Error(
				'nk_openssl_missing',
				__( 'Estensione PHP OpenSSL non disponibile sul server: impossibile firmare il JWT per Google API.', 'nk-report-analytics' )
			);
		}

		$raw_json = '';

		if ( defined( 'NK_GOOGLE_SA_PATH' ) && '' !== trim( (string) NK_GOOGLE_SA_PATH ) ) {
			$path = trim( (string) NK_GOOGLE_SA_PATH );
			if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
				return new WP_Error(
					'nk_sa_file_unreadable',
					sprintf(
						/* translators: %s: percorso file configurato */
						__( 'Il file Service Account definito in NK_GOOGLE_SA_PATH non esiste o non è leggibile: %s', 'nk-report-analytics' ),
						$path
					)
				);
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$raw_json = file_get_contents( $path );
		} elseif ( defined( 'NK_GOOGLE_SA_B64' ) && '' !== trim( (string) NK_GOOGLE_SA_B64 ) ) {
			$decoded = base64_decode( trim( (string) NK_GOOGLE_SA_B64 ), true );
			if ( false === $decoded ) {
				return new WP_Error(
					'nk_sa_b64_invalid',
					__( 'La costante NK_GOOGLE_SA_B64 in wp-config.php non contiene una stringa Base64 valida.', 'nk-report-analytics' )
				);
			}
			$raw_json = $decoded;
		} else {
			return new WP_Error(
				'nk_missing_credentials',
				__( 'Credenziali Service Account mancanti. Definisci NK_GOOGLE_SA_PATH oppure NK_GOOGLE_SA_B64 nel file wp-config.php.', 'nk-report-analytics' )
			);
		}

		$creds = json_decode( (string) $raw_json, true );
		if ( ! is_array( $creds ) ) {
			return new WP_Error(
				'nk_sa_json_invalid',
				__( 'Il file JSON del Service Account non è valido o è corrotto.', 'nk-report-analytics' )
			);
		}

		if ( empty( $creds['client_email'] ) || empty( $creds['private_key'] ) ) {
			return new WP_Error(
				'nk_sa_fields_missing',
				__( 'Il JSON del Service Account non contiene i campi obbligatori client_email o private_key.', 'nk-report-analytics' )
			);
		}

		$pkey = openssl_pkey_get_private( $creds['private_key'] );
		if ( false === $pkey ) {
			return new WP_Error(
				'nk_sa_private_key_invalid',
				__( 'La chiave privata (private_key) nel JSON del Service Account non è valida.', 'nk-report-analytics' )
			);
		}

		if ( PHP_VERSION_ID < 80000 ) {
			openssl_free_key( $pkey );
		}

		if ( empty( $creds['token_uri'] ) ) {
			$creds['token_uri'] = self::DEFAULT_TOKEN_URI;
		}

		return $creds;
	}

	/**
	 * Restituisce la sola client_email del Service Account per visualizzazione in admin.
	 *
	 * @return string|WP_Error
	 */
	public function get_service_account_email() {
		$creds = $this->get_credentials();
		if ( is_wp_error( $creds ) ) {
			return $creds;
		}
		return (string) $creds['client_email'];
	}

	/**
	 * Codifica Base64URL (RFC 7515).
	 *
	 * @param string $data Dati grezzi.
	 * @return string
	 */
	private function base64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Ottiene l'access token OAuth2 utilizzando la cache Transient (50 minuti).
	 *
	 * @param bool $force_refresh Se true ignora il transient e rigenera il token.
	 * @return string|WP_Error
	 */
	public function get_access_token( $force_refresh = false ) {
		if ( ! $force_refresh ) {
			$cached_token = get_transient( NK_REPORT_TOKEN_TRANSIENT );
			if ( is_string( $cached_token ) && '' !== $cached_token ) {
				return $cached_token;
			}
		}

		$creds = $this->get_credentials();
		if ( is_wp_error( $creds ) ) {
			return $creds;
		}

		$now       = time();
		$token_uri = $creds['token_uri'];

		$header = array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		);

		$claims = array(
			'iss'   => $creds['client_email'],
			'scope' => self::OAUTH_SCOPES,
			'aud'   => $token_uri,
			'exp'   => $now + 3600,
			'iat'   => $now,
		);

		$signing_input = $this->base64url_encode( wp_json_encode( $header ) ) . '.' . $this->base64url_encode( wp_json_encode( $claims ) );

		$signature = '';
		$signed    = openssl_sign( $signing_input, $signature, $creds['private_key'], OPENSSL_ALGO_SHA256 );
		if ( ! $signed ) {
			return new WP_Error(
				'nk_jwt_sign_failed',
				__( 'Errore durante la firma RS256 del JWT con openssl_sign().', 'nk-report-analytics' )
			);
		}

		$jwt = $signing_input . '.' . $this->base64url_encode( $signature );

		$response = wp_remote_post(
			$token_uri,
			array(
				'timeout' => 20,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'nk_oauth_http_error',
				sprintf(
					/* translators: %s: messaggio errore HTTP */
					__( 'Errore di rete durante la richiesta del token OAuth2 a Google: %s', 'nk-report-analytics' ),
					$response->get_error_message()
				)
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $code || empty( $body['access_token'] ) ) {
			$err_desc = isset( $body['error_description'] ) ? $body['error_description'] : ( isset( $body['error'] ) ? $body['error'] : wp_remote_retrieve_body( $response ) );
			return new WP_Error(
				'nk_oauth_token_error',
				sprintf(
					/* translators: 1: HTTP code, 2: dettagli errore */
					__( 'Google OAuth2 ha rifiutato l\'autenticazione (HTTP %1$d): %2$s', 'nk-report-analytics' ),
					(int) $code,
					(string) $err_desc
				)
			);
		}

		$access_token = (string) $body['access_token'];
		set_transient( NK_REPORT_TOKEN_TRANSIENT, $access_token, 50 * MINUTE_IN_SECONDS );

		return $access_token;
	}

	/**
	 * Esegue una richiesta GET autenticata verso le Google API e gestisce gli errori diagnostici.
	 *
	 * @param string $url     URL completo API Google.
	 * @param string $context Contesto ('drive' o 'sheets').
	 * @return array|WP_Error
	 */
	private function api_get( $url, $context = 'sheets' ) {
		$token = $this->get_access_token( false );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 25,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'nk_api_network_error',
				sprintf(
					/* translators: %s: errore rete */
					__( 'Errore di connessione verso Google API: %s', 'nk-report-analytics' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		// Se il token fosse scaduto o revocato anticipatamente, rigenera una volta sola.
		if ( 401 === $code ) {
			$token = $this->get_access_token( true );
			if ( is_wp_error( $token ) ) {
				return $token;
			}

			$response = wp_remote_get(
				$url,
				array(
					'timeout' => 25,
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
						'Accept'        => 'application/json',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
		}

		$raw_body = wp_remote_retrieve_body( $response );
		$data     = json_decode( $raw_body, true );

		if ( $code >= 200 && $code < 300 && is_array( $data ) ) {
			return $data;
		}

		return $this->parse_google_api_error( $code, $data, $raw_body, $context );
	}

	/**
	 * Traduce gli errori delle Google API in messaggi diagnostici chiari.
	 *
	 * @param int        $http_code Codice HTTP.
	 * @param array|null $data      Body decodificato.
	 * @param string     $raw_body  Body grezzo.
	 * @param string     $context   Contesto ('drive' o 'sheets').
	 * @return WP_Error
	 */
	private function parse_google_api_error( $http_code, $data, $raw_body, $context ) {
		$api_message = '';
		$reason      = '';

		if ( is_array( $data ) && isset( $data['error'] ) ) {
			$err         = $data['error'];
			$api_message = isset( $err['message'] ) ? (string) $err['message'] : '';
			if ( ! empty( $err['errors'][0]['reason'] ) ) {
				$reason = (string) $err['errors'][0]['reason'];
			}
		}

		// Google Drive API non abilitata sul progetto Google Cloud.
		if ( 'drive' === $context && 403 === $http_code && ( 'accessNotConfigured' === $reason || false !== stripos( $api_message, 'Google Drive API' ) || false !== stripos( $api_message, 'drive.googleapis.com' ) ) ) {
			return new WP_Error(
				'nk_drive_api_disabled',
				__( 'Google Drive API non è abilitata sul progetto Google Cloud del Service Account. Attivala nella Google Cloud Console (API e servizi > Libreria > Google Drive API: https://console.cloud.google.com/apis/library/drive.googleapis.com) oppure usa la modalità "Inserimento manuale ID / Nome Tab".', 'nk-report-analytics' )
			);
		}

		// Google Sheets API non abilitata sul progetto Google Cloud.
		if ( 'sheets' === $context && 403 === $http_code && ( 'accessNotConfigured' === $reason || false !== stripos( $api_message, 'Google Sheets API' ) || false !== stripos( $api_message, 'sheets.googleapis.com' ) ) ) {
			return new WP_Error(
				'nk_sheets_api_disabled',
				__( 'Google Sheets API non è abilitata sul progetto Google Cloud. Attivala nella Google Cloud Console (https://console.cloud.google.com/apis/library/sheets.googleapis.com).', 'nk-report-analytics' )
			);
		}

		// Foglio non condiviso con il Service Account o ID errato.
		if ( 403 === $http_code || 404 === $http_code ) {
			if ( false !== stripos( $api_message, 'Unable to parse range' ) ) {
				return new WP_Error(
					'nk_tab_not_found',
					sprintf(
						/* translators: %s: messaggio Google */
						__( 'Il Tab specificato non esiste nel Google Sheet selezionato (%s).', 'nk-report-analytics' ),
						$api_message
					)
				);
			}

			$sa_email = $this->get_service_account_email();
			$email    = is_wp_error( $sa_email ) ? 'client_email' : $sa_email;

			return new WP_Error(
				'nk_sheet_access_denied',
				sprintf(
					/* translators: 1: email service account, 2: dettagli Google */
					__( 'Impossibile accedere al Google Sheet (HTTP %1$d). Verifica che l\'ID sia corretto e che il file sia stato condiviso con il Service Account (%2$s) con permesso di almeno Visualizzatore. Dettagli: %3$s', 'nk-report-analytics' ),
					$http_code,
					$email,
					$api_message ? $api_message : $raw_body
				)
			);
		}

		if ( 400 === $http_code && false !== stripos( $api_message, 'Unable to parse range' ) ) {
			return new WP_Error(
				'nk_tab_not_found',
				sprintf(
					/* translators: %s: dettagli Google */
					__( 'Nome del Tab non trovato nel Google Sheet: %s', 'nk-report-analytics' ),
					$api_message
				)
			);
		}

		return new WP_Error(
			'nk_google_api_error',
			sprintf(
				/* translators: 1: HTTP code, 2: dettagli */
				__( 'Errore Google API (HTTP %1$d): %2$s', 'nk-report-analytics' ),
				$http_code,
				$api_message ? $api_message : substr( (string) $raw_body, 0, 300 )
			)
		);
	}

	/**
	 * Restituisce l'elenco dei Google Sheet accessibili al Service Account tramite Google Drive API v3.
	 *
	 * @return array|WP_Error
	 */
	public function list_spreadsheets() {
		$query = "mimeType='application/vnd.google-apps.spreadsheet' and trashed=false";
		$url   = 'https://www.googleapis.com/drive/v3/files'
			. '?q=' . rawurlencode( $query )
			. '&fields=' . rawurlencode( 'files(id,name,modifiedTime)' )
			. '&orderBy=' . rawurlencode( 'modifiedTime desc' )
			. '&pageSize=100'
			. '&supportsAllDrives=true'
			. '&includeItemsFromAllDrives=true';

		$data = $this->api_get( $url, 'drive' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$files = isset( $data['files'] ) && is_array( $data['files'] ) ? $data['files'] : array();
		$out   = array();

		foreach ( $files as $file ) {
			if ( empty( $file['id'] ) ) {
				continue;
			}
			$out[] = array(
				'id'           => (string) $file['id'],
				'name'         => isset( $file['name'] ) ? (string) $file['name'] : (string) $file['id'],
				'modifiedTime' => isset( $file['modifiedTime'] ) ? (string) $file['modifiedTime'] : '',
			);
		}

		return $out;
	}

	/**
	 * Restituisce titolo del file e lista dei Tab interni di un Google Sheet.
	 *
	 * @param string $spreadsheet_id ID del Google Sheet.
	 * @return array|WP_Error
	 */
	public function list_sheet_tabs( $spreadsheet_id ) {
		$spreadsheet_id = trim( (string) $spreadsheet_id );
		if ( '' === $spreadsheet_id || ! preg_match( '/^[a-zA-Z0-9_-]+$/', $spreadsheet_id ) ) {
			return new WP_Error(
				'nk_invalid_spreadsheet_id',
				__( 'ID Google Sheet non valido.', 'nk-report-analytics' )
			);
		}

		$url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode( $spreadsheet_id )
			. '?fields=' . rawurlencode( 'properties.title,sheets.properties(sheetId,title)' );

		$data = $this->api_get( $url, 'sheets' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$title = isset( $data['properties']['title'] ) ? (string) $data['properties']['title'] : $spreadsheet_id;
		$tabs  = array();

		if ( ! empty( $data['sheets'] ) && is_array( $data['sheets'] ) ) {
			foreach ( $data['sheets'] as $sheet ) {
				if ( isset( $sheet['properties']['title'] ) ) {
					$tabs[] = array(
						'sheetId' => isset( $sheet['properties']['sheetId'] ) ? (int) $sheet['properties']['sheetId'] : 0,
						'title'   => (string) $sheet['properties']['title'],
					);
				}
			}
		}

		return array(
			'title' => $title,
			'tabs'  => $tabs,
		);
	}

	/**
	 * Legge i dati dal range <tab_name>!A:E, normalizza in formato compatto e gestisce la cache Transient.
	 *
	 * @param string $spreadsheet_id ID del Google Sheet.
	 * @param string $tab_name       Nome del Tab.
	 * @param bool   $force_refresh  Se true forza la rilettura da Google API.
	 * @return array|WP_Error
	 */
	public function get_sheet_data( $spreadsheet_id, $tab_name, $force_refresh = false ) {
		$spreadsheet_id = trim( (string) $spreadsheet_id );
		$tab_name       = trim( (string) $tab_name );

		if ( '' === $spreadsheet_id || ! preg_match( '/^[a-zA-Z0-9_-]+$/', $spreadsheet_id ) ) {
			return new WP_Error(
				'nk_invalid_spreadsheet_id',
				__( 'ID Google Sheet non configurato o non valido.', 'nk-report-analytics' )
			);
		}

		if ( '' === $tab_name ) {
			return new WP_Error(
				'nk_invalid_tab_name',
				__( 'Nome del Tab non configurato.', 'nk-report-analytics' )
			);
		}

		$cache_key = NK_REPORT_DATA_TRANSIENT_PREFIX . md5( $spreadsheet_id . '|' . $tab_name );

		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && isset( $cached['rows'] ) ) {
				return $cached;
			}
		}

		// Escaping sicuro per nomi tab con spazi o apostrofi nella sintassi A1 di Google Sheets.
		$escaped_tab = str_replace( "'", "''", $tab_name );
		$range       = "'{$escaped_tab}'!A:E";

		$url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode( $spreadsheet_id )
			. '/values/' . rawurlencode( $range )
			. '?valueRenderOption=UNFORMATTED_VALUE&dateTimeRenderOption=FORMATTED_STRING';

		$data = $this->api_get( $url, 'sheets' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$values     = isset( $data['values'] ) && is_array( $data['values'] ) ? $data['values'] : array();
		$normalized = array();

		foreach ( $values as $index => $row ) {
			// Salta l'intestazione nella riga 1.
			if ( 0 === (int) $index ) {
				continue;
			}

			if ( ! is_array( $row ) ) {
				continue;
			}

			$d   = isset( $row[0] ) ? trim( (string) $row[0] ) : '';
			$c   = isset( $row[1] ) ? trim( (string) $row[1] ) : '';
			$dim = isset( $row[2] ) ? trim( (string) $row[2] ) : '';
			$m   = isset( $row[3] ) ? trim( (string) $row[3] ) : '';
			$n   = isset( $row[4] ) ? (int) $row[4] : 0;

			// Salta righe vuote.
			if ( '' === $d && '' === $c && '' === $dim && '' === $m ) {
				continue;
			}

			$normalized[] = array(
				'd'   => $d,
				'c'   => $c,
				'dim' => $dim,
				'm'   => $m,
				'n'   => $n,
			);
		}

		$settings    = nk_report_get_settings();
		$cache_hours = max( 1, (int) $settings['cache_hours'] );

		$payload = array(
			'rows'           => $normalized,
			'total_rows'     => count( $normalized ),
			'last_updated'   => wp_date( 'd/m/Y H:i:s' ),
			'timestamp'      => time(),
			'spreadsheet_id' => $spreadsheet_id,
			'tab_name'       => $tab_name,
		);

		set_transient( $cache_key, $payload, $cache_hours * HOUR_IN_SECONDS );

		return $payload;
	}
}
