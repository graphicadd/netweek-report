<?php
/**
 * Shortcode [nk_report_dashboard] con caricamento condizionale degli asset.
 *
 * @package NK_Report_Analytics
 */

defined( 'ABSPATH' ) || exit;

class NK_Shortcode {

	/**
	 * Registra lo shortcode.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_shortcode( 'nk_report_dashboard', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Renderizza la dashboard per utenti autorizzati o visitatori sbloccati via password.
	 *
	 * @return string
	 */
	public function render_shortcode() {
		$settings = nk_report_get_settings();

		if ( ! nk_report_can_view_dashboard() ) {
			if ( ! empty( $settings['public_password_enabled'] ) && ! empty( $settings['access_password_hash'] ) ) {
				return $this->render_password_gate();
			}

			return '<div class="nk-access-denied" style="padding:16px 20px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;color:#991b1b;font-family:sans-serif;">'
				. esc_html__( 'Accesso negato. Devi effettuare il login con un account autorizzato per visualizzare la dashboard analytics.', 'nk-report-analytics' )
				. '</div>';
		}

		$this->enqueue_assets( $settings );

		ob_start();
		?>
		<div class="nk-dashboard-wrap" id="nk-dashboard-root">
			<!-- Header -->
			<header class="nk-header">
				<div class="nk-header-title-group">
					<h2 class="nk-title"><?php esc_html_e( 'Netweek Report', 'nk-report-analytics' ); ?></h2>
					<div class="nk-meta-badges">
						<span class="nk-badge nk-badge-time" id="nk-updated-badge">
							<span class="nk-status-dot" aria-hidden="true"></span>
							<span class="nk-updated-text"><?php esc_html_e( 'Sincronizzazione...', 'nk-report-analytics' ); ?></span>
						</span>
					</div>
				</div>
				<div class="nk-header-actions">
					<button type="button" class="nk-btn nk-btn-primary" id="nk-refresh-btn">
						<span class="nk-spinner" aria-hidden="true"></span>
						<svg class="nk-btn-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>
						<span class="nk-btn-label"><?php esc_html_e( 'Aggiorna dati', 'nk-report-analytics' ); ?></span>
					</button>
				</div>
			</header>

			<!-- Messaggio errore -->
			<div class="nk-alert nk-alert-error nk-hidden" id="nk-error-banner" role="alert"></div>

			<!-- Tab Viste -->
			<nav class="nk-tabs" aria-label="<?php esc_attr_e( 'Viste report', 'nk-report-analytics' ); ?>">
				<span class="nk-tab-indicator" aria-hidden="true"></span>
				<button type="button" class="nk-tab is-active" data-view="province"><?php esc_html_e( 'Province', 'nk-report-analytics' ); ?></button>
				<button type="button" class="nk-tab" data-view="regioni"><?php esc_html_e( 'Regioni', 'nk-report-analytics' ); ?></button>
				<button type="button" class="nk-tab" data-view="nazionale"><?php esc_html_e( 'Nazionale', 'nk-report-analytics' ); ?></button>
				<button type="button" class="nk-tab" data-view="verticali"><?php esc_html_e( 'Verticali', 'nk-report-analytics' ); ?></button>
				<button type="button" class="nk-tab" data-view="tutte"><?php esc_html_e( 'Tutte le pubblicate', 'nk-report-analytics' ); ?></button>
			</nav>

			<!-- Barra Filtri -->
			<section class="nk-filters-bar">
				<div class="nk-filter-group">
					<span class="nk-filter-label"><?php esc_html_e( 'Periodo rapido:', 'nk-report-analytics' ); ?></span>
					<div class="nk-presets" id="nk-date-presets">
						<button type="button" class="nk-preset-btn" data-preset="1d"><?php esc_html_e( 'Ultimo giorno', 'nk-report-analytics' ); ?></button>
						<button type="button" class="nk-preset-btn" data-preset="7d"><?php esc_html_e( 'Ultimi 7 gg', 'nk-report-analytics' ); ?></button>
						<button type="button" class="nk-preset-btn is-active" data-preset="30d"><?php esc_html_e( 'Ultimi 30 gg', 'nk-report-analytics' ); ?></button>
						<button type="button" class="nk-preset-btn" data-preset="month"><?php esc_html_e( 'Mese corrente', 'nk-report-analytics' ); ?></button>
						<button type="button" class="nk-preset-btn" data-preset="all"><?php esc_html_e( 'Tutto lo storico', 'nk-report-analytics' ); ?></button>
					</div>
				</div>

				<div class="nk-filter-group nk-date-inputs">
					<label class="nk-field">
						<span><?php esc_html_e( 'Da', 'nk-report-analytics' ); ?></span>
						<input type="text" id="nk-date-from" class="nk-date-picker" placeholder="gg/mm/aaaa" readonly>
					</label>
					<label class="nk-field">
						<span><?php esc_html_e( 'A', 'nk-report-analytics' ); ?></span>
						<input type="text" id="nk-date-to" class="nk-date-picker" placeholder="gg/mm/aaaa" readonly>
					</label>
				</div>

				<div class="nk-filter-group nk-dim-filter">
					<label class="nk-field" for="nk-dim-search">
						<span id="nk-dim-label"><?php esc_html_e( 'Provincia / Elemento', 'nk-report-analytics' ); ?></span>
						<div class="nk-dim-input-wrap">
							<input type="text"
								id="nk-dim-search"
								list="nk-dim-datalist"
								placeholder="<?php esc_attr_e( 'Tutte (scrivi per cercare...)', 'nk-report-analytics' ); ?>"
								autocomplete="off">
							<button type="button" class="nk-clear-dim nk-hidden" id="nk-clear-dim-btn" title="<?php esc_attr_e( 'Mostra tutte', 'nk-report-analytics' ); ?>">&times;</button>
						</div>
						<datalist id="nk-dim-datalist"></datalist>
					</label>
				</div>
			</section>

			<!-- KPI Cards -->
			<section class="nk-kpi-grid">
				<article class="nk-kpi-card">
					<span class="nk-kpi-label"><?php esc_html_e( 'Totale nel periodo', 'nk-report-analytics' ); ?></span>
					<strong class="nk-kpi-value" id="nk-kpi-total">-</strong>
					<span class="nk-kpi-sub" id="nk-kpi-total-sub"></span>
				</article>
				<article class="nk-kpi-card">
					<span class="nk-kpi-label"><?php esc_html_e( 'Media giornaliera', 'nk-report-analytics' ); ?></span>
					<strong class="nk-kpi-value" id="nk-kpi-avg">-</strong>
					<span class="nk-kpi-sub" id="nk-kpi-avg-sub"></span>
				</article>
				<article class="nk-kpi-card">
					<span class="nk-kpi-label"><?php esc_html_e( 'Ultimo giorno disponibile', 'nk-report-analytics' ); ?></span>
					<strong class="nk-kpi-value" id="nk-kpi-last">-</strong>
					<span class="nk-kpi-sub" id="nk-kpi-last-sub"></span>
				</article>
				<article class="nk-kpi-card">
					<span class="nk-kpi-label" id="nk-kpi-top-label"><?php esc_html_e( 'Top Provincia / Elemento', 'nk-report-analytics' ); ?></span>
					<strong class="nk-kpi-value" id="nk-kpi-top">-</strong>
					<span class="nk-kpi-sub" id="nk-kpi-top-sub"></span>
				</article>
			</section>

			<div class="nk-info-notice nk-hidden" id="nk-nazionale-notice" role="note">
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
				<span><strong><?php esc_html_e( 'Nota sulle metriche:', 'nk-report-analytics' ); ?></strong> <?php esc_html_e( 'i dati mostrati si riferiscono alle notizie generate dal sistema di Intelligenza Artificiale; il numero effettivo di articoli pubblicati può variare in base alle scelte editoriali della redazione.', 'nk-report-analytics' ); ?></span>
			</div>

			<!-- Grafico Andamento Giornaliero -->
			<section class="nk-panel">
				<div class="nk-panel-header">
					<div>
						<h3 class="nk-panel-title" id="nk-chart-title"><?php esc_html_e( 'Andamento giornaliero', 'nk-report-analytics' ); ?></h3>
						<p class="nk-panel-subtitle"><?php esc_html_e( 'Clicca su una riga della tabella sottostante per isolare lo storico di un singolo elemento.', 'nk-report-analytics' ); ?></p>
					</div>
					<div class="nk-active-chip nk-hidden" id="nk-active-chip">
						<span><?php esc_html_e( 'Filtro attivo:', 'nk-report-analytics' ); ?> <strong id="nk-chip-name"></strong></span>
						<button type="button" id="nk-chip-reset"><?php esc_html_e( 'Mostra tutte', 'nk-report-analytics' ); ?></button>
					</div>
				</div>
				<div class="nk-chart-box">
					<canvas id="nk-daily-chart"></canvas>
				</div>
			</section>

			<!-- Tabella Analitica Tabulator -->
			<section class="nk-panel">
				<div class="nk-panel-header nk-table-toolbar">
					<div class="nk-table-mode-switch">
						<button type="button" class="nk-mode-btn is-active" data-table-mode="summary"><?php esc_html_e( 'Riepilogo per Dimensione', 'nk-report-analytics' ); ?></button>
						<button type="button" class="nk-mode-btn" data-table-mode="detail"><?php esc_html_e( 'Dettaglio giornaliero', 'nk-report-analytics' ); ?></button>
					</div>
					<div class="nk-table-actions">
						<input type="search" id="nk-table-search" class="nk-table-search" placeholder="<?php esc_attr_e( 'Cerca nella tabella...', 'nk-report-analytics' ); ?>">
						<button type="button" class="nk-btn nk-btn-secondary" id="nk-export-csv-btn">
							<?php esc_html_e( 'Esporta CSV', 'nk-report-analytics' ); ?>
						</button>
					</div>
				</div>
				<div id="nk-tabulator-table"></div>
			</section>

			<!-- Loading Overlay -->
			<div class="nk-loading-overlay" id="nk-loading-overlay">
				<div class="nk-loader-box">
					<span class="nk-loader-ring"></span>
					<span><?php esc_html_e( 'Caricamento dati da Google Sheets...', 'nk-report-analytics' ); ?></span>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Renderizza il form di sblocco con password per i visitatori non autenticati (senza caricare script della dashboard).
	 *
	 * @return string
	 */
	private function render_password_gate() {
		$ajax_url = admin_url( 'admin-ajax.php' );
		$nonce    = wp_create_nonce( 'nk_report_password_nonce' );

		ob_start();
		?>
		<div class="nk-gate-wrap" style="max-width:420px;margin:48px auto;padding:32px 28px;background:rgba(255,255,255,0.88);backdrop-filter:blur(12px);border:1px solid rgba(226,232,240,0.9);border-radius:16px;box-shadow:0 12px 32px -8px rgba(0,89,156,0.14);font-family:Roboto,-apple-system,sans-serif;color:#0f172a;">
			<div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
				<span style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:10px;background:rgba(0,89,156,0.10);color:#00599C;">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
				</span>
				<h2 style="margin:0;font-size:20px;font-weight:700;color:#0f172a;"><?php esc_html_e( 'Netweek Report', 'nk-report-analytics' ); ?></h2>
			</div>
			<p style="margin:0 0 18px 0;font-size:13.5px;color:#475569;line-height:1.5;">
				<?php esc_html_e( 'Inserisci la password di accesso per visualizzare la dashboard.', 'nk-report-analytics' ); ?>
			</p>
			<form id="nk-password-gate-form" style="display:flex;flex-direction:column;gap:12px;">
				<input type="password"
					id="nk-gate-password"
					placeholder="<?php esc_attr_e( 'Password...', 'nk-report-analytics' ); ?>"
					required
					autocomplete="current-password"
					style="width:100%;height:40px;padding:0 12px;border:1px solid #cbd5e1;border-radius:9px;font-size:14px;color:#0f172a;background:#fff;box-sizing:border-box;">
				<div id="nk-gate-error" style="display:none;padding:9px 12px;border-radius:8px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;font-size:13px;"></div>
				<button type="submit"
					id="nk-gate-submit"
					style="height:40px;border:none;border-radius:999px;background:#00599C;color:#fff;font-size:14px;font-weight:600;cursor:pointer;">
					<?php esc_html_e( 'Accedi al report', 'nk-report-analytics' ); ?>
				</button>
			</form>
		</div>
		<script>
		(function(){
			var form = document.getElementById('nk-password-gate-form');
			if (!form) return;
			var input = document.getElementById('nk-gate-password');
			var errBox = document.getElementById('nk-gate-error');
			var btn = document.getElementById('nk-gate-submit');

			form.addEventListener('submit', function(e){
				e.preventDefault();
				errBox.style.display = 'none';
				btn.disabled = true;
				btn.textContent = 'Verifica...';

				var body = new URLSearchParams();
				body.append('action', 'nk_verify_dashboard_password');
				body.append('nonce', <?php echo wp_json_encode( $nonce ); ?>);
				body.append('password', input.value);

				fetch(<?php echo wp_json_encode( $ajax_url ); ?>, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
					body: body.toString()
				})
				.then(function(r){ return r.json(); })
				.then(function(res){
					if (res && res.success) {
						window.location.reload();
						return;
					}
					btn.disabled = false;
					btn.textContent = 'Accedi al report';
					errBox.textContent = (res && res.data && res.data.message) ? res.data.message : 'Password non corretta.';
					errBox.style.display = 'block';
					input.focus();
				})
				.catch(function(){
					btn.disabled = false;
					btn.textContent = 'Accedi al report';
					errBox.textContent = 'Errore di rete durante la verifica.';
					errBox.style.display = 'block';
				});
			});
		})();
		</script>
		<?php
		return ob_get_clean();
	}

	/**
	 * Accoda Chart.js, Tabulator.js e gli asset della dashboard solo quando lo shortcode è presente.
	 *
	 * @param array $settings Impostazioni correnti.
	 * @return void
	 */
	private function enqueue_assets( $settings ) {
		wp_enqueue_style(
			'nk-roboto-font',
			'https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap',
			array(),
			null
		);

		wp_enqueue_style(
			'nk-tabulator-css',
			'https://cdn.jsdelivr.net/npm/tabulator-tables@6.3.0/dist/css/tabulator.min.css',
			array(),
			'6.3.0'
		);

		wp_enqueue_style(
			'nk-flatpickr-css',
			'https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css',
			array(),
			'4.6.13'
		);

		wp_enqueue_style(
			'nk-report-dashboard-css',
			NK_REPORT_PLUGIN_URL . 'assets/css/dashboard.css',
			array( 'nk-tabulator-css', 'nk-roboto-font', 'nk-flatpickr-css' ),
			NK_REPORT_VERSION
		);

		wp_enqueue_script(
			'nk-chartjs',
			'https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js',
			array(),
			'4.4.6',
			true
		);

		wp_enqueue_script(
			'nk-tabulator-js',
			'https://cdn.jsdelivr.net/npm/tabulator-tables@6.3.0/dist/js/tabulator.min.js',
			array(),
			'6.3.0',
			true
		);

		wp_enqueue_script(
			'nk-flatpickr-js',
			'https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js',
			array(),
			'4.6.13',
			true
		);

		wp_enqueue_script(
			'nk-flatpickr-it',
			'https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/it.js',
			array( 'nk-flatpickr-js' ),
			'4.6.13',
			true
		);

		wp_enqueue_script(
			'nk-report-dashboard-js',
			NK_REPORT_PLUGIN_URL . 'assets/js/dashboard.js',
			array( 'nk-chartjs', 'nk-tabulator-js', 'nk-flatpickr-it' ),
			NK_REPORT_VERSION,
			true
		);

		wp_localize_script(
			'nk-report-dashboard-js',
			'nkReportConfig',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'nk_report_frontend_nonce' ),
				'spreadsheetName' => $settings['spreadsheet_name'] ? $settings['spreadsheet_name'] : $settings['spreadsheet_id'],
				'tabName'         => $settings['tab_name'],
			)
		);
	}
}
