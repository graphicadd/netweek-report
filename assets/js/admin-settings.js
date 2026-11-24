(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		if (typeof nkAdminConfig === 'undefined') {
			return;
		}

		var copyBtn = document.getElementById('nk-copy-email-btn');
		var copyFeedback = document.getElementById('nk-copy-feedback');
		var emailText = document.getElementById('nk-sa-email-text');

		var manualToggle = document.getElementById('nk-manual-mode-toggle');
		var dynamicRows = document.querySelectorAll('.nk-dynamic-row');
		var manualRows = document.querySelectorAll('.nk-manual-row');

		var sheetSelect = document.getElementById('nk-sheet-select');
		var tabSelect = document.getElementById('nk-tab-select');
		var reloadDriveBtn = document.getElementById('nk-reload-drive-btn');

		var sheetIdInput = document.getElementById('nk-spreadsheet-id-input');
		var tabNameInput = document.getElementById('nk-tab-name-input');
		var sheetNameInput = document.getElementById('nk-spreadsheet-name-input');

		var driveStatus = document.getElementById('nk-drive-status');
		var testBtn = document.getElementById('nk-test-connection-btn');
		var testResult = document.getElementById('nk-test-result');

		function escapeHtml(str) {
			return String(str)
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;')
				.replace(/'/g, '&#039;');
		}

		function showStatus(el, type, html) {
			if (!el) return;
			el.className = 'nk-status-box is-visible nk-status-' + type;
			el.innerHTML = html;
		}

		function hideStatus(el) {
			if (!el) return;
			el.className = 'nk-status-box';
			el.innerHTML = '';
		}

		function formatIsoDate(iso) {
			if (!iso) return '';
			var d = new Date(iso);
			if (isNaN(d.getTime())) return iso;
			return d.toLocaleDateString('it-IT', {
				day: '2-digit',
				month: '2-digit',
				year: 'numeric',
				hour: '2-digit',
				minute: '2-digit'
			});
		}

		function postAjax(action, params) {
			var body = new URLSearchParams();
			body.append('action', action);
			body.append('nonce', nkAdminConfig.nonce);
			if (params) {
				Object.keys(params).forEach(function (key) {
					body.append(key, params[key]);
				});
			}

			return fetch(nkAdminConfig.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			}).then(function (res) {
				return res.json();
			});
		}

		// Copia email Service Account.
		if (copyBtn && emailText) {
			copyBtn.addEventListener('click', function () {
				var text = emailText.textContent.trim();
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(text).then(function () {
						copyFeedback.textContent = 'Email copiata negli appunti!';
						setTimeout(function () { copyFeedback.textContent = ''; }, 2500);
					});
				} else {
					var tmp = document.createElement('textarea');
					tmp.value = text;
					document.body.appendChild(tmp);
					tmp.select();
					document.execCommand('copy');
					document.body.removeChild(tmp);
					copyFeedback.textContent = 'Email copiata!';
					setTimeout(function () { copyFeedback.textContent = ''; }, 2500);
				}
			});
		}

		// Toggle modalità manuale / dinamica.
		function syncModeVisibility() {
			var isManual = manualToggle && manualToggle.checked;
			dynamicRows.forEach(function (row) {
				row.classList.toggle('nk-hidden', isManual);
			});
			manualRows.forEach(function (row) {
				row.classList.toggle('nk-hidden', !isManual);
			});
			if (!isManual && nkAdminConfig.hasCredentials && sheetSelect && sheetSelect.options.length <= 1) {
				loadSpreadsheets();
			}
		}

		if (manualToggle) {
			manualToggle.addEventListener('change', syncModeVisibility);
		}

		// Carica elenco Google Sheet da Drive API.
		function loadSpreadsheets() {
			if (!sheetSelect || !nkAdminConfig.hasCredentials) return;

			hideStatus(driveStatus);
			sheetSelect.disabled = true;
			sheetSelect.innerHTML = '<option value="">Caricamento fogli da Google Drive...</option>';
			if (reloadDriveBtn) reloadDriveBtn.disabled = true;

			postAjax('nk_admin_list_spreadsheets').then(function (res) {
				sheetSelect.disabled = false;
				if (reloadDriveBtn) reloadDriveBtn.disabled = false;

				if (!res || !res.success) {
					var errMsg = (res && res.data && res.data.message) ? res.data.message : 'Errore sconosciuto durante il caricamento da Google Drive.';
					sheetSelect.innerHTML = '<option value="">-- Errore caricamento --</option>';
					showStatus(driveStatus, 'error', '<strong>Errore Google Drive API:</strong> ' + escapeHtml(errMsg));
					return;
				}

				var files = res.data.files || [];
				if (files.length === 0) {
					sheetSelect.innerHTML = '<option value="">-- Nessun Google Sheet trovato --</option>';
					showStatus(
						driveStatus,
						'info',
						'Nessun Google Sheet condiviso con <code>' + escapeHtml(res.data.sa_email || nkAdminConfig.saEmail) + '</code>. Condividi almeno un foglio su Google Drive e clicca "Ricarica elenco da Drive".'
					);
					return;
				}

				var currentId = sheetIdInput ? sheetIdInput.value.trim() : nkAdminConfig.savedSheetId;
				var foundCurrent = false;
				sheetSelect.innerHTML = '<option value="">-- Seleziona un Google Sheet --</option>';

				files.forEach(function (file) {
					var opt = document.createElement('option');
					opt.value = file.id;
					opt.dataset.name = file.name;
					var modStr = file.modifiedTime ? ' (mod. ' + formatIsoDate(file.modifiedTime) + ')' : '';
					opt.textContent = file.name + modStr;
					if (file.id === currentId) {
						opt.selected = true;
						foundCurrent = true;
						if (sheetNameInput) sheetNameInput.value = file.name;
					}
					sheetSelect.appendChild(opt);
				});

				if (foundCurrent) {
					loadTabs(currentId);
				} else if (currentId) {
					showStatus(
						driveStatus,
						'info',
						'Lo Spreadsheet ID salvato (<code>' + escapeHtml(currentId) + '</code>) non compare tra i file condivisi su Drive. Seleziona un file dall\'elenco o usa la modalità manuale.'
					);
				}
			}).catch(function (err) {
				sheetSelect.disabled = false;
				if (reloadDriveBtn) reloadDriveBtn.disabled = false;
				showStatus(driveStatus, 'error', 'Errore di rete durante la chiamata AJAX: ' + escapeHtml(err.message));
			});
		}

		// Carica elenco Tab interni di un Google Sheet.
		function loadTabs(spreadsheetId) {
			if (!tabSelect || !spreadsheetId) return;

			tabSelect.disabled = true;
			tabSelect.innerHTML = '<option value="">Caricamento tab...</option>';

			postAjax('nk_admin_list_tabs', { spreadsheet_id: spreadsheetId }).then(function (res) {
				tabSelect.disabled = false;

				if (!res || !res.success) {
					var errMsg = (res && res.data && res.data.message) ? res.data.message : 'Impossibile leggere i tab del foglio.';
					tabSelect.innerHTML = '<option value="">-- Errore caricamento tab --</option>';
					showStatus(driveStatus, 'error', escapeHtml(errMsg));
					return;
				}

				hideStatus(driveStatus);
				var tabs = res.data.tabs || [];
				if (res.data.title && sheetNameInput) {
					sheetNameInput.value = res.data.title;
				}

				if (tabs.length === 0) {
					tabSelect.innerHTML = '<option value="">-- Nessun tab trovato --</option>';
					return;
				}

				var targetTab = tabNameInput ? tabNameInput.value.trim() : nkAdminConfig.savedTabName;
				var matched = false;
				tabSelect.innerHTML = '';

				tabs.forEach(function (tab) {
					var opt = document.createElement('option');
					opt.value = tab.title;
					opt.textContent = tab.title;
					if (tab.title === targetTab) {
						opt.selected = true;
						matched = true;
					}
					tabSelect.appendChild(opt);
				});

				if (!matched && tabs[0]) {
					tabSelect.value = tabs[0].title;
					if (tabNameInput) tabNameInput.value = tabs[0].title;
				}
			}).catch(function (err) {
				tabSelect.disabled = false;
				showStatus(driveStatus, 'error', 'Errore di rete: ' + escapeHtml(err.message));
			});
		}

		if (sheetSelect) {
			sheetSelect.addEventListener('change', function () {
				var selectedId = sheetSelect.value;
				var selectedOpt = sheetSelect.options[sheetSelect.selectedIndex];
				if (sheetIdInput) sheetIdInput.value = selectedId;
				if (sheetNameInput && selectedOpt) {
					sheetNameInput.value = selectedOpt.dataset.name || '';
				}
				if (selectedId) {
					loadTabs(selectedId);
				} else if (tabSelect) {
					tabSelect.innerHTML = '<option value="">-- Seleziona prima un Google Sheet --</option>';
				}
			});
		}

		if (tabSelect) {
			tabSelect.addEventListener('change', function () {
				if (tabNameInput) {
					tabNameInput.value = tabSelect.value;
				}
			});
		}

		if (reloadDriveBtn) {
			reloadDriveBtn.addEventListener('click', loadSpreadsheets);
		}

		// Testa connessione e mostra anteprima prime 5 righe.
		if (testBtn) {
			testBtn.addEventListener('click', function () {
				var sid = sheetIdInput ? sheetIdInput.value.trim() : '';
				var tname = tabNameInput ? tabNameInput.value.trim() : '';

				if (!sid || !tname) {
					showStatus(testResult, 'error', 'Specifica sia lo Spreadsheet ID che il Nome Tab prima di eseguire il test.');
					return;
				}

				testBtn.disabled = true;
				showStatus(testResult, 'info', 'Connessione a Google Sheets in corso...');

				postAjax('nk_admin_test_connection', {
					spreadsheet_id: sid,
					tab_name: tname
				}).then(function (res) {
					testBtn.disabled = false;

					if (!res || !res.success) {
						var errMsg = (res && res.data && res.data.message) ? res.data.message : 'Test di connessione fallito.';
						showStatus(testResult, 'error', '<strong>Test fallito:</strong> ' + escapeHtml(errMsg));
						return;
					}

					var total = res.data.total_rows || 0;
					var preview = res.data.preview || [];
					var html = '<strong>Connessione riuscita!</strong> Righe valide rilevate: <strong>' + escapeHtml(total) + '</strong> (aggiornato: ' + escapeHtml(res.data.last_updated || '') + ').';

					if (preview.length > 0) {
						html += '<table class="nk-preview-table"><thead><tr>'
							+ '<th>data (A)</th><th>canale (B)</th><th>dimensione (C)</th><th>tipo_metrica (D)</th><th>conteggio (E)</th>'
							+ '</tr></thead><tbody>';
						preview.forEach(function (r) {
							html += '<tr>'
								+ '<td>' + escapeHtml(r.d) + '</td>'
								+ '<td>' + escapeHtml(r.c) + '</td>'
								+ '<td>' + escapeHtml(r.dim) + '</td>'
								+ '<td>' + escapeHtml(r.m) + '</td>'
								+ '<td>' + escapeHtml(r.n) + '</td>'
								+ '</tr>';
						});
						html += '</tbody></table>';
					} else {
						html += '<p>Il tab è accessibile ma non contiene righe dati oltre all\'intestazione.</p>';
					}

					showStatus(testResult, 'success', html);
				}).catch(function (err) {
					testBtn.disabled = false;
					showStatus(testResult, 'error', 'Errore di rete durante il test: ' + escapeHtml(err.message));
				});
			});
		}

		// Avvio iniziale se in modalità dinamica con credenziali valide.
		if (nkAdminConfig.hasCredentials && !nkAdminConfig.manualMode) {
			loadSpreadsheets();
		}
	});
})();
