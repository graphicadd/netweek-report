<?php
/**
 * Pannello Impostazioni in WordPress: Impostazioni > Netweek Analytics.
 *
 * @package NK_Report_Analytics
 */

defined( 'ABSPATH' ) || exit;

class NK_Admin_Settings {

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
	 * Registra gli hook WordPress per la pagina Impostazioni.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Aggiunge la voce di menu sotto Impostazioni.
	 *
	 * @return void
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'Netweek Report', 'nk-report-analytics' ),
			__( 'Netweek Report', 'nk-report-analytics' ),
			'manage_options',
			'nk-report-analytics',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Registra le opzioni del plugin con sanitizzazione.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'nk_report_analytics_group',
			NK_REPORT_OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);
	}

	/**
	 * Sanitizza le impostazioni salvate e invalida automaticamente la cache dati.
	 *
	 * @param array $input Valori inviati dal form.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$current = nk_report_get_settings();

		$spreadsheet_id = isset( $input['spreadsheet_id'] ) ? sanitize_text_field( wp_unslash( $input['spreadsheet_id'] ) ) : $current['spreadsheet_id'];
		$spreadsheet_id = preg_replace( '/[^a-zA-Z0-9_-]/', '', trim( $spreadsheet_id ) );

		$tab_name = isset( $input['tab_name'] ) ? sanitize_text_field( wp_unslash( $input['tab_name'] ) ) : $current['tab_name'];
		$tab_name = trim( $tab_name );

		$spreadsheet_name = isset( $input['spreadsheet_name'] ) ? sanitize_text_field( wp_unslash( $input['spreadsheet_name'] ) ) : '';
		$cache_hours      = isset( $input['cache_hours'] ) ? max( 1, min( 168, absint( $input['cache_hours'] ) ) ) : 6;

		$allowed_caps = array( 'edit_posts', 'publish_posts', 'edit_pages', 'edit_others_posts', 'manage_options', 'read' );
		$req_cap      = isset( $input['required_capability'] ) ? sanitize_key( wp_unslash( $input['required_capability'] ) ) : 'edit_posts';
		if ( ! in_array( $req_cap, $allowed_caps, true ) ) {
			$req_cap = 'edit_posts';
		}

		$manual_mode             = ! empty( $input['manual_mode'] );
		$public_password_enabled = ! empty( $input['public_password_enabled'] );

		$password_hash = $current['access_password_hash'];
		if ( isset( $input['access_password'] ) && '' !== trim( (string) $input['access_password'] ) ) {
			$raw_pass      = (string) wp_unslash( $input['access_password'] );
			$password_hash = wp_hash_password( $raw_pass );
		}

		// Invalida automaticamente la cache dati al salvataggio delle impostazioni.
		nk_report_clear_data_cache();

		return array(
			'spreadsheet_id'          => $spreadsheet_id,
			'tab_name'                => $tab_name,
			'spreadsheet_name'        => $spreadsheet_name,
			'cache_hours'             => $cache_hours,
			'required_capability'     => $req_cap,
			'manual_mode'             => $manual_mode,
			'public_password_enabled' => $public_password_enabled,
			'access_password_hash'    => $password_hash,
		);
	}

	/**
	 * Carica gli script JS solo nella pagina Impostazioni del plugin.
	 *
	 * @param string $hook_suffix Suffisso della pagina admin corrente.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		if ( 'settings_page_nk-report-analytics' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_script(
			'nk-report-admin-settings',
			NK_REPORT_PLUGIN_URL . 'assets/js/admin-settings.js',
			array(),
			NK_REPORT_VERSION,
			true
		);

		$settings   = nk_report_get_settings();
		$sa_email   = $this->client->get_service_account_email();
		$has_creds  = ! is_wp_error( $sa_email );

		wp_localize_script(
			'nk-report-admin-settings',
			'nkAdminConfig',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'nk_report_admin_nonce' ),
				'hasCredentials'  => $has_creds,
				'savedSheetId'    => $settings['spreadsheet_id'],
				'savedTabName'    => $settings['tab_name'],
				'savedSheetName'  => $settings['spreadsheet_name'],
				'manualMode'      => $settings['manual_mode'],
				'saEmail'         => $has_creds ? $sa_email : '',
			)
		);
	}

	/**
	 * Renderizza la pagina Impostazioni.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Non disponi dei permessi necessari per accedere a questa pagina.', 'nk-report-analytics' ) );
		}

		$settings = nk_report_get_settings();
		$sa_email = $this->client->get_service_account_email();
		$is_valid = ! is_wp_error( $sa_email );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Netweek Report Analytics — Impostazioni', 'nk-report-analytics' ); ?></h1>

			<style>
				.nk-admin-card {
					background: #fff;
					border: 1px solid #c3c4c7;
					border-radius: 6px;
					padding: 18px 22px;
					margin-top: 18px;
					max-width: 960px;
				}
				.nk-admin-card h2 {
					margin-top: 0;
					padding-bottom: 10px;
					border-bottom: 1px solid #f0f0f1;
				}
				.nk-badge {
					display: inline-block;
					padding: 3px 10px;
					border-radius: 12px;
					font-size: 12px;
					font-weight: 600;
				}
				.nk-badge-ok { background: #edfaef; color: #0a6b24; border: 1px solid #b8e6bf; }
				.nk-badge-err { background: #fcf0f1; color: #b32d2e; border: 1px solid #f1b6b7; }
				.nk-sa-email-row {
					display: flex;
					align-items: center;
					gap: 10px;
					flex-wrap: wrap;
					margin-top: 10px;
				}
				.nk-sa-email-code {
					font-size: 13px;
					padding: 6px 10px;
					background: #f6f7f7;
					border: 1px solid #dcdcde;
					border-radius: 4px;
				}
				.nk-inline-row {
					display: flex;
					align-items: center;
					gap: 10px;
					flex-wrap: wrap;
				}
				.nk-status-box {
					margin-top: 12px;
					padding: 10px 14px;
					border-radius: 4px;
					display: none;
				}
				.nk-status-box.is-visible { display: block; }
				.nk-status-error { background: #fcf0f1; border-left: 4px solid #d63638; color: #5c191a; }
				.nk-status-info { background: #f0f6fc; border-left: 4px solid #2271b1; color: #1d2327; }
				.nk-status-success { background: #edfaef; border-left: 4px solid #00a32a; color: #0a4b1a; }
				.nk-preview-table {
					width: 100%;
					border-collapse: collapse;
					margin-top: 12px;
					font-size: 13px;
				}
				.nk-preview-table th, .nk-preview-table td {
					border: 1px solid #dcdcde;
					padding: 7px 10px;
					text-align: left;
				}
				.nk-preview-table th { background: #f6f7f7; font-weight: 600; }
				.nk-hidden { display: none !important; }
			</style>

			<!-- Box Stato Service Account -->
			<div class="nk-admin-card">
				<h2><?php esc_html_e( 'Stato Google Service Account', 'nk-report-analytics' ); ?></h2>
				<?php if ( $is_valid ) : ?>
					<p>
						<span class="nk-badge nk-badge-ok"><?php esc_html_e( 'Credenziali valide rilevate', 'nk-report-analytics' ); ?></span>
					</p>
					<p class="description">
						<?php esc_html_e( 'Condividi ogni Google Sheet che vuoi analizzare con questa email (permesso minimo: Visualizzatore):', 'nk-report-analytics' ); ?>
					</p>
					<div class="nk-sa-email-row">
						<code class="nk-sa-email-code" id="nk-sa-email-text"><?php echo esc_html( $sa_email ); ?></code>
						<button type="button" class="button button-secondary" id="nk-copy-email-btn">
							<?php esc_html_e( 'Copia email', 'nk-report-analytics' ); ?>
						</button>
						<span id="nk-copy-feedback" class="description"></span>
					</div>
				<?php else : ?>
					<p>
						<span class="nk-badge nk-badge-err"><?php esc_html_e( 'Credenziali non configurate o non valide', 'nk-report-analytics' ); ?></span>
					</p>
					<div class="notice notice-error inline">
						<p><?php echo esc_html( $sa_email->get_error_message() ); ?></p>
					</div>
					<p class="description">
						<?php
						echo wp_kses_post(
							__( 'Aggiungi in <code>wp-config.php</code> una delle due costanti:<br><code>define( \'NK_GOOGLE_SA_PATH\', \'/percorso/fuori/webroot/service-account.json\' );</code><br>oppure:<br><code>define( \'NK_GOOGLE_SA_B64\', \'BASE64_DEL_JSON\' );</code>', 'nk-report-analytics' )
						);
						?>
					</p>
				<?php endif; ?>
			</div>

			<!-- Form Impostazioni Principali -->
			<form method="post" action="options.php" id="nk-settings-form">
				<?php settings_fields( 'nk_report_analytics_group' ); ?>

				<div class="nk-admin-card">
					<h2><?php esc_html_e( 'Sorgente Dati Google Sheets', 'nk-report-analytics' ); ?></h2>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Modalità selezione', 'nk-report-analytics' ); ?></th>
							<td>
								<label>
									<input type="checkbox" id="nk-manual-mode-toggle" name="<?php echo esc_attr( NK_REPORT_OPTION_KEY ); ?>[manual_mode]" value="1" <?php checked( $settings['manual_mode'] ); ?>>
									<?php esc_html_e( 'Inserimento manuale ID / Nome Tab (usa se Google Drive API non è ancora attiva)', 'nk-report-analytics' ); ?>
								</label>
							</td>
						</tr>

						<!-- Selettore Dinamico da Google Drive -->
						<tr class="nk-dynamic-row <?php echo $settings['manual_mode'] ? 'nk-hidden' : ''; ?>">
							<th scope="row">
								<label for="nk-sheet-select"><?php esc_html_e( 'Google Sheet (da Drive)', 'nk-report-analytics' ); ?></label>
							</th>
							<td>
								<div class="nk-inline-row">
									<select id="nk-sheet-select" class="regular-text" <?php disabled( ! $is_valid ); ?>>
										<option value=""><?php esc_html_e( '-- Caricamento fogli da Google Drive... --', 'nk-report-analytics' ); ?></option>
									</select>
									<button type="button" class="button button-secondary" id="nk-reload-drive-btn" <?php disabled( ! $is_valid ); ?>>
										<?php esc_html_e( 'Ricarica elenco da Drive', 'nk-report-analytics' ); ?>
									</button>
								</div>
								<p class="description">
									<?php esc_html_e( 'Mostra i Google Sheet condivisi con il Service Account, ordinati per ultima modifica.', 'nk-report-analytics' ); ?>
								</p>
							</td>
						</tr>

						<tr class="nk-dynamic-row <?php echo $settings['manual_mode'] ? 'nk-hidden' : ''; ?>">
							<th scope="row">
								<label for="nk-tab-select"><?php esc_html_e( 'Foglio / Tab interno', 'nk-report-analytics' ); ?></label>
							</th>
							<td>
								<select id="nk-tab-select" class="regular-text" <?php disabled( ! $is_valid ); ?>>
									<option value="<?php echo esc_attr( $settings['tab_name'] ); ?>">
										<?php echo esc_html( $settings['tab_name'] ? $settings['tab_name'] : __( '-- Seleziona prima un Google Sheet --', 'nk-report-analytics' ) ); ?>
									</option>
								</select>
								<p class="description">
									<?php esc_html_e( 'Popolato automaticamente in base al Google Sheet scelto sopra.', 'nk-report-analytics' ); ?>
								</p>
							</td>
						</tr>

						<!-- Campi Manuali / Sincronizzati -->
						<tr class="nk-manual-row <?php echo $settings['manual_mode'] ? '' : 'nk-hidden'; ?>">
							<th scope="row">
								<label for="nk-spreadsheet-id-input"><?php esc_html_e( 'Spreadsheet ID', 'nk-report-analytics' ); ?></label>
							</th>
							<td>
								<input type="text"
									id="nk-spreadsheet-id-input"
									name="<?php echo esc_attr( NK_REPORT_OPTION_KEY ); ?>[spreadsheet_id]"
									value="<?php echo esc_attr( $settings['spreadsheet_id'] ); ?>"
									class="regular-text code"
									required>
								<p class="description">
									<?php esc_html_e( 'ID contenuto nell\'URL del Google Sheet: https://docs.google.com/spreadsheets/d/<ID>/edit', 'nk-report-analytics' ); ?>
								</p>
							</td>
						</tr>

						<tr class="nk-manual-row <?php echo $settings['manual_mode'] ? '' : 'nk-hidden'; ?>">
							<th scope="row">
								<label for="nk-tab-name-input"><?php esc_html_e( 'Nome Tab', 'nk-report-analytics' ); ?></label>
							</th>
							<td>
								<input type="text"
									id="nk-tab-name-input"
									name="<?php echo esc_attr( NK_REPORT_OPTION_KEY ); ?>[tab_name]"
									value="<?php echo esc_attr( $settings['tab_name'] ); ?>"
									class="regular-text"
									required>
								<p class="description">
									<?php esc_html_e( 'Nome esatto del foglio/scheda interna (es. Dati_Giornalieri).', 'nk-report-analytics' ); ?>
								</p>
							</td>
						</tr>
					</table>

					<input type="hidden"
						id="nk-spreadsheet-name-input"
						name="<?php echo esc_attr( NK_REPORT_OPTION_KEY ); ?>[spreadsheet_name]"
						value="<?php echo esc_attr( $settings['spreadsheet_name'] ); ?>">

					<div id="nk-drive-status" class="nk-status-box"></div>

					<p style="margin-top: 14px;">
						<button type="button" class="button button-secondary" id="nk-test-connection-btn" <?php disabled( ! $is_valid ); ?>>
							<?php esc_html_e( 'Testa connessione e anteprima', 'nk-report-analytics' ); ?>
						</button>
					</p>

					<div id="nk-test-result" class="nk-status-box"></div>
				</div>

				<div class="nk-admin-card">
					<h2><?php esc_html_e( 'Cache e Permessi Dashboard', 'nk-report-analytics' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="nk-cache-hours"><?php esc_html_e( 'Durata cache dati (ore)', 'nk-report-analytics' ); ?></label>
							</th>
							<td>
								<input type="number"
									id="nk-cache-hours"
									name="<?php echo esc_attr( NK_REPORT_OPTION_KEY ); ?>[cache_hours]"
									value="<?php echo esc_attr( $settings['cache_hours'] ); ?>"
									min="1"
									max="168"
									class="small-text">
								<p class="description">
									<?php esc_html_e( 'Default: 6 ore. La cache viene invalidata automaticamente al salvataggio o tramite il pulsante "Aggiorna dati ora" in dashboard.', 'nk-report-analytics' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="nk-required-cap"><?php esc_html_e( 'Capability richiesta per la dashboard', 'nk-report-analytics' ); ?></label>
							</th>
							<td>
								<?php
								$caps = array(
									'edit_posts'        => __( 'edit_posts (Collaboratori, Autori, Editori, Admin)', 'nk-report-analytics' ),
									'publish_posts'     => __( 'publish_posts (Autori, Editori, Admin)', 'nk-report-analytics' ),
									'edit_pages'        => __( 'edit_pages (Editori, Admin)', 'nk-report-analytics' ),
									'edit_others_posts' => __( 'edit_others_posts (Editori, Admin)', 'nk-report-analytics' ),
									'manage_options'    => __( 'manage_options (Solo Amministratori)', 'nk-report-analytics' ),
									'read'              => __( 'read (Tutti gli utenti autenticati)', 'nk-report-analytics' ),
								);
								?>
								<select id="nk-required-cap" name="<?php echo esc_attr( NK_REPORT_OPTION_KEY ); ?>[required_capability]">
									<?php foreach ( $caps as $cap_key => $cap_label ) : ?>
										<option value="<?php echo esc_attr( $cap_key ); ?>" <?php selected( $settings['required_capability'], $cap_key ); ?>>
											<?php echo esc_html( $cap_label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Accesso visitatori con password', 'nk-report-analytics' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( NK_REPORT_OPTION_KEY ); ?>[public_password_enabled]" value="1" <?php checked( $settings['public_password_enabled'] ); ?>>
									<?php esc_html_e( 'Consenti anche ai visitatori non autenticati di visualizzare la dashboard inserendo una password', 'nk-report-analytics' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'Gli utenti WordPress loggati con la capability richiesta sopra continuano ad accedere direttamente senza password.', 'nk-report-analytics' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="nk-access-password"><?php esc_html_e( 'Password pagina pubblica', 'nk-report-analytics' ); ?></label>
							</th>
							<td>
								<input type="password"
									id="nk-access-password"
									name="<?php echo esc_attr( NK_REPORT_OPTION_KEY ); ?>[access_password]"
									value=""
									autocomplete="new-password"
									class="regular-text"
									placeholder="<?php echo ! empty( $settings['access_password_hash'] ) ? esc_attr__( 'Password già impostata (lascia vuoto per mantenerla)', 'nk-report-analytics' ) : esc_attr__( 'Inserisci una password...', 'nk-report-analytics' ); ?>">
								<?php if ( ! empty( $settings['access_password_hash'] ) ) : ?>
									<span class="nk-badge nk-badge-ok" style="margin-left:8px;"><?php esc_html_e( 'Password configurata', 'nk-report-analytics' ); ?></span>
								<?php else : ?>
									<span class="nk-badge nk-badge-err" style="margin-left:8px;"><?php esc_html_e( 'Nessuna password impostata', 'nk-report-analytics' ); ?></span>
								<?php endif; ?>
								<p class="description">
									<?php esc_html_e( 'La password viene salvata cifrata (hash WordPress). Inseriscine una nuova solo se vuoi impostarla o cambiarla.', 'nk-report-analytics' ); ?>
								</p>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Salva impostazioni', 'nk-report-analytics' ) ); ?>
				</div>

				<div class="nk-admin-card">
					<h2><?php esc_html_e( 'Shortcode Dashboard', 'nk-report-analytics' ); ?></h2>
					<p>
						<?php
						echo wp_kses_post(
							__( 'Inserisci lo shortcode <code>[nk_report_dashboard]</code> all\'interno di una pagina WordPress privata (visibile solo agli utenti autorizzati).', 'nk-report-analytics' )
						);
						?>
					</p>
				</div>
			</form>
		</div>
		<?php
	}
}
