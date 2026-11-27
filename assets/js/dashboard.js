(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		if (typeof nkReportConfig === 'undefined') {
			return;
		}

		var root = document.getElementById('nk-dashboard-root');
		if (!root) {
			return;
		}

		if (typeof Chart !== 'undefined' && Chart.defaults && Chart.defaults.font) {
			Chart.defaults.font.family = '"Roboto", sans-serif';
		}

		// Elementi UI.
		var updatedBadgeText = root.querySelector('#nk-updated-badge .nk-updated-text');
		var refreshBtn = document.getElementById('nk-refresh-btn');
		var errorBanner = document.getElementById('nk-error-banner');
		var loadingOverlay = document.getElementById('nk-loading-overlay');

		var tabsContainer = root.querySelector('.nk-tabs');
		var tabIndicator = root.querySelector('.nk-tab-indicator');
		var tabButtons = root.querySelectorAll('.nk-tab');
		var presetButtons = root.querySelectorAll('.nk-preset-btn');
		var dateFromInput = document.getElementById('nk-date-from');
		var dateToInput = document.getElementById('nk-date-to');
		var dimSearchInput = document.getElementById('nk-dim-search');
		var dimLabel = document.getElementById('nk-dim-label');
		var dimDatalist = document.getElementById('nk-dim-datalist');
		var clearDimBtn = document.getElementById('nk-clear-dim-btn');

		var kpiTotal = document.getElementById('nk-kpi-total');
		var kpiTotalSub = document.getElementById('nk-kpi-total-sub');
		var kpiAvg = document.getElementById('nk-kpi-avg');
		var kpiAvgSub = document.getElementById('nk-kpi-avg-sub');
		var kpiLast = document.getElementById('nk-kpi-last');
		var kpiLastSub = document.getElementById('nk-kpi-last-sub');
		var kpiTopLabel = document.getElementById('nk-kpi-top-label');
		var kpiTop = document.getElementById('nk-kpi-top');
		var kpiTopSub = document.getElementById('nk-kpi-top-sub');

		var chartTitle = document.getElementById('nk-chart-title');
		var activeChip = document.getElementById('nk-active-chip');
		var chipName = document.getElementById('nk-chip-name');
		var chipReset = document.getElementById('nk-chip-reset');
		var chartCanvas = document.getElementById('nk-daily-chart');

		var modeButtons = root.querySelectorAll('.nk-mode-btn');
		var nazionaleNotice = document.getElementById('nk-nazionale-notice');
		var tableSearchInput = document.getElementById('nk-table-search');
		var exportCsvBtn = document.getElementById('nk-export-csv-btn');

		var mapPanel = document.getElementById('nk-map-panel');
		var chartPanel = document.getElementById('nk-chart-panel');
		var tablePanel = document.getElementById('nk-table-panel');
		var mapContainer = document.getElementById('nk-italy-map');
		var mapSidebarTitle = document.getElementById('nk-map-sidebar-title');
		var mapSidebarSub = document.getElementById('nk-map-sidebar-sub');
		var mapBackBtn = document.getElementById('nk-map-back-btn');
		var mapDetailList = document.getElementById('nk-map-detail-list');

		// Stato applicativo.
		var state = {
			rawRows: [],
			activeView: 'province',
			activePreset: '30d',
			dateFrom: '',
			dateTo: '',
			selectedDim: '',
			tableMode: 'summary',
			chart: null,
			table: null,
			fpFrom: null,
			fpTo: null,
			mapChart: null,
			italyGeoPromise: null
		};

		if (typeof flatpickr !== 'undefined') {
			var fpConfig = {
				locale: (flatpickr.l10ns && flatpickr.l10ns.it) ? 'it' : 'default',
				dateFormat: 'Y-m-d',
				altInput: true,
				altFormat: 'd/m/Y',
				altInputClass: 'nk-date-picker',
				disableMobile: true,
				allowInput: false,
				onChange: function () {
					onCustomDateChange();
				}
			};
			state.fpFrom = flatpickr(dateFromInput, fpConfig);
			state.fpTo = flatpickr(dateToInput, fpConfig);
		}

		function formatInt(val) {
			return Math.round(Number(val) || 0).toLocaleString('it-IT');
		}

		function formatDec(val) {
			return (Number(val) || 0).toLocaleString('it-IT', {
				minimumFractionDigits: 1,
				maximumFractionDigits: 1
			});
		}

		function formatDateIt(isoDate) {
			if (!isoDate || isoDate.length < 10) return isoDate || '-';
			var parts = isoDate.split('-');
			if (parts.length !== 3) return isoDate;
			return parts[2] + '/' + parts[1] + '/' + parts[0];
		}

		function addDaysIso(isoDate, deltaDays) {
			var parts = isoDate.split('-');
			var d = new Date(Date.UTC(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2])));
			d.setUTCDate(d.getUTCDate() + deltaDays);
			return d.toISOString().slice(0, 10);
		}

		function showError(msg) {
			errorBanner.textContent = msg;
			errorBanner.classList.remove('nk-hidden');
		}

		function hideError() {
			errorBanner.textContent = '';
			errorBanner.classList.add('nk-hidden');
		}

		function setLoading(isLoading) {
			loadingOverlay.classList.toggle('nk-hidden', !isLoading);
			refreshBtn.disabled = isLoading;
			refreshBtn.classList.toggle('is-loading', isLoading);
		}

		// Mappatura completa Province italiane -> Regioni (incluse varianti nominali comuni).
		var PROVINCE_TO_REGION = {
			// Abruzzo
			"chieti": "Abruzzo", "l'aquila": "Abruzzo", "laquila": "Abruzzo", "aquila": "Abruzzo", "pescara": "Abruzzo", "teramo": "Abruzzo",
			// Basilicata
			"matera": "Basilicata", "potenza": "Basilicata",
			// Calabria
			"catanzaro": "Calabria", "cosenza": "Calabria", "crotone": "Calabria", "reggio calabria": "Calabria", "reggio di calabria": "Calabria", "vibo valentia": "Calabria",
			// Campania
			"avellino": "Campania", "benevento": "Campania", "caserta": "Campania", "napoli": "Campania", "salerno": "Campania",
			// Emilia-Romagna
			"bologna": "Emilia-Romagna", "ferrara": "Emilia-Romagna", "forlì-cesena": "Emilia-Romagna", "forli-cesena": "Emilia-Romagna", "forlì": "Emilia-Romagna", "forli": "Emilia-Romagna",
			"modena": "Emilia-Romagna", "parma": "Emilia-Romagna", "piacenza": "Emilia-Romagna", "ravenna": "Emilia-Romagna",
			"reggio emilia": "Emilia-Romagna", "reggio nell'emilia": "Emilia-Romagna", "rimini": "Emilia-Romagna",
			// Friuli-Venezia Giulia
			"gorizia": "Friuli-Venezia Giulia", "pordenone": "Friuli-Venezia Giulia", "trieste": "Friuli-Venezia Giulia", "udine": "Friuli-Venezia Giulia",
			// Lazio
			"frosinone": "Lazio", "latina": "Lazio", "rieti": "Lazio", "roma": "Lazio", "viterbo": "Lazio",
			// Liguria
			"genova": "Liguria", "imperia": "Liguria", "la spezia": "Liguria", "savona": "Liguria",
			// Lombardia
			"bergamo": "Lombardia", "brescia": "Lombardia", "como": "Lombardia", "cremona": "Lombardia", "lecco": "Lombardia", "lodi": "Lombardia",
			"mantova": "Lombardia", "milano": "Lombardia", "monza": "Lombardia", "monza e brianza": "Lombardia", "monza e della brianza": "Lombardia",
			"pavia": "Lombardia", "sondrio": "Lombardia", "varese": "Lombardia",
			// Marche
			"ancona": "Marche", "ascoli piceno": "Marche", "fermo": "Marche", "macerata": "Marche", "pesaro e urbino": "Marche", "pesaro": "Marche", "urbino": "Marche",
			// Molise
			"campobasso": "Molise", "isernia": "Molise",
			// Piemonte
			"alessandria": "Piemonte", "asti": "Piemonte", "biella": "Piemonte", "cuneo": "Piemonte", "novara": "Piemonte", "torino": "Piemonte",
			"verbano-cusio-ossola": "Piemonte", "verbania": "Piemonte", "vco": "Piemonte", "vercelli": "Piemonte",
			// Puglia
			"bari": "Puglia", "barletta-andria-trani": "Puglia", "bat": "Puglia", "brindisi": "Puglia", "foggia": "Puglia", "lecce": "Puglia", "taranto": "Puglia",
			// Sardegna
			"cagliari": "Sardegna", "nuoro": "Sardegna", "oristano": "Sardegna", "sassari": "Sardegna", "sud sardegna": "Sardegna",
			"olbia-tempio": "Sardegna", "ogliastra": "Sardegna", "medio campidano": "Sardegna", "carbonia-iglesias": "Sardegna",
			// Sicilia
			"agrigento": "Sicilia", "caltanissetta": "Sicilia", "catania": "Sicilia", "enna": "Sicilia", "messina": "Sicilia",
			"palermo": "Sicilia", "ragusa": "Sicilia", "siracusa": "Sicilia", "trapani": "Sicilia",
			// Toscana
			"arezzo": "Toscana", "firenze": "Toscana", "grosseto": "Toscana", "livorno": "Toscana", "lucca": "Toscana",
			"massa-carrara": "Toscana", "massa carrara": "Toscana", "massa e carrara": "Toscana", "massa": "Toscana", "pisa": "Toscana", "pistoia": "Toscana", "prato": "Toscana", "siena": "Toscana",
			// Trentino-Alto Adige
			"bolzano": "Trentino-Alto Adige", "trento": "Trentino-Alto Adige",
			// Umbria
			"perugia": "Umbria", "terni": "Umbria",
			// Valle d'Aosta
			"aosta": "Valle d'Aosta", "valle d'aosta": "Valle d'Aosta",
			// Veneto
			"belluno": "Veneto", "padova": "Veneto", "rovigo": "Veneto", "treviso": "Veneto", "venezia": "Veneto", "verona": "Veneto", "vicenza": "Veneto"
		};

		function getRegionForProvince(provinceName) {
			var raw = (provinceName || '').trim();
			if (!raw) return 'Senza provincia';

			var key = raw.toLowerCase().replace(/[’`]/g, "'").replace(/\s+/g, ' ');
			if (PROVINCE_TO_REGION[key]) {
				return PROVINCE_TO_REGION[key];
			}

			var normalized = key.replace(/[-_/]+/g, ' ').replace(/\s+/g, ' ').trim();
			if (PROVINCE_TO_REGION[normalized]) {
				return PROVINCE_TO_REGION[normalized];
			}

			// Varianti comuni senza "e" / con trattini (es. "Monza Brianza", "Pesaro Urbino", "Forli Cesena").
			var compactAliases = {
				"monza brianza": "Lombardia",
				"pesaro urbino": "Marche",
				"forli cesena": "Emilia-Romagna",
				"forlì cesena": "Emilia-Romagna",
				"barletta andria trani": "Puglia",
				"verbano cusio ossola": "Piemonte",
				"bolzano bozen": "Trentino-Alto Adige",
				"aosta aoste": "Valle d'Aosta"
			};
			if (compactAliases[normalized]) {
				return compactAliases[normalized];
			}

			return 'Non mappata (' + raw + ')';
		}

		// Filtra righe in base alla vista attiva (escludendo sempre le notizie scartate).
		function getViewRows() {
			if (state.activeView === 'regioni' || state.activeView === 'mappa') {
				var regionalRows = [];
				state.rawRows.forEach(function (r) {
					if (r.m === 'pubblicata' && r.c === 'news_locali' && r.dim !== 'Nazionale') {
						regionalRows.push({
							d: r.d,
							c: r.c,
							dim: getRegionForProvince(r.dim),
							m: r.m,
							n: r.n
						});
					}
				});
				return regionalRows;
			}

			return state.rawRows.filter(function (r) {
				if (r.m !== 'pubblicata') {
					return false;
				}
				switch (state.activeView) {
					case 'province':
						return r.c === 'news_locali' && r.dim !== 'Nazionale';
					case 'nazionale':
						return r.c === 'news_locali' && r.dim === 'Nazionale';
					case 'verticali':
						return r.c === 'verticali';
					case 'tutte':
						return true;
					default:
						return true;
				}
			});
		}

		// Risolve il nome canonico di una dimensione in modo case-insensitive.
		function resolveCanonicalDim(inputVal) {
			var normalized = inputVal.trim().toLowerCase();
			if (!normalized) return '';
			var viewRows = getViewRows();
			for (var i = 0; i < viewRows.length; i++) {
				if (viewRows[i].dim && viewRows[i].dim.toLowerCase() === normalized) {
					return viewRows[i].dim;
				}
			}
			return inputVal.trim();
		}

		// Restituisce le date uniche ordinate disponibili nella vista attiva.
		function getAvailableDates() {
			var set = new Set();
			getViewRows().forEach(function (r) {
				if (r.d) set.add(r.d);
			});
			return Array.from(set).sort();
		}

		// Aggiorna i giorni abilitati nei selettori Flatpickr per rendere non selezionabili i giorni fuori dai dati.
		function syncDateInputBounds(dates) {
			if (state.fpFrom && state.fpTo) {
				state.fpFrom.set('enable', dates.length ? dates : []);
				state.fpTo.set('enable', dates.length ? dates : []);
				return;
			}
			if (!dates.length) {
				dateFromInput.removeAttribute('min');
				dateFromInput.removeAttribute('max');
				dateToInput.removeAttribute('min');
				dateToInput.removeAttribute('max');
				return;
			}
			var minDate = dates[0];
			var maxDate = dates[dates.length - 1];
			dateFromInput.min = minDate;
			dateFromInput.max = maxDate;
			dateToInput.min = minDate;
			dateToInput.max = maxDate;
		}

		function setPickerValues(from, to) {
			state.dateFrom = from;
			state.dateTo = to;
			if (state.fpFrom && state.fpTo) {
				state.fpFrom.setDate(from || null, false);
				state.fpTo.setDate(to || null, false);
			} else {
				dateFromInput.value = from;
				dateToInput.value = to;
			}
		}

		// Calcola intervallo date in base al preset selezionato.
		function applyPresetDates(preset) {
			var dates = getAvailableDates();
			syncDateInputBounds(dates);

			var minDate = dates.length ? dates[0] : '';
			var maxDate = dates.length ? dates[dates.length - 1] : '';

			if (!maxDate) {
				setPickerValues('', '');
				return;
			}

			var from = minDate;
			if (preset === '1d') {
				from = maxDate;
			} else if (preset === '7d') {
				from = addDaysIso(maxDate, -6);
			} else if (preset === '30d') {
				from = addDaysIso(maxDate, -29);
			} else if (preset === 'month') {
				from = maxDate.slice(0, 8) + '01';
			} else if (preset === 'all') {
				from = minDate;
			}

			if (from < minDate) from = minDate;

			setPickerValues(from, maxDate);
		}

		// Popola datalist per filtro singolo elemento.
		function populateDimensionOptions() {
			var viewRows = getViewRows();
			var dimsSet = new Set();
			viewRows.forEach(function (r) {
				if (r.dim) dimsSet.add(r.dim);
			});

			var dims = Array.from(dimsSet).sort(function (a, b) {
				return a.localeCompare(b, 'it');
			});

			dimDatalist.innerHTML = '';
			dims.forEach(function (dim) {
				var opt = document.createElement('option');
				opt.value = dim;
				dimDatalist.appendChild(opt);
			});
		}

		// Restituisce righe filtrate per vista + date (+ opzionale dimensione case-insensitive).
		function getFilteredRows(includeDimensionFilter) {
			var targetDim = state.selectedDim.trim().toLowerCase();
			return getViewRows().filter(function (r) {
				if (state.dateFrom && r.d < state.dateFrom) return false;
				if (state.dateTo && r.d > state.dateTo) return false;
				if (includeDimensionFilter && targetDim) {
					return (r.dim || '').toLowerCase() === targetDim;
				}
				return true;
			});
		}

		// Aggiorna le 4 KPI card.
		function renderKpis(rows) {
			var total = 0;
			var dayTotals = {};
			var dimTotals = {};

			rows.forEach(function (r) {
				var val = Number(r.n) || 0;
				total += val;
				if (r.d) {
					dayTotals[r.d] = (dayTotals[r.d] || 0) + val;
				}
				if (r.dim) {
					dimTotals[r.dim] = (dimTotals[r.dim] || 0) + val;
				}
			});

			var days = Object.keys(dayTotals).sort();
			var activeDays = days.length;
			var avg = activeDays > 0 ? total / activeDays : 0;

			kpiTotal.textContent = formatInt(total);
			kpiTotalSub.textContent = activeDays + ' giorni con dati';

			kpiAvg.textContent = formatDec(avg);
			kpiAvgSub.textContent = 'media su giorni attivi';

			if (activeDays > 0) {
				var lastDay = days[days.length - 1];
				kpiLast.textContent = formatInt(dayTotals[lastDay]);
				kpiLastSub.textContent = formatDateIt(lastDay);
			} else {
				kpiLast.textContent = '-';
				kpiLastSub.textContent = 'nessun dato';
			}

			if (state.selectedDim) {
				kpiTopLabel.textContent = 'Picco giornaliero';
				var peakVal = 0;
				var peakDate = '';
				days.forEach(function (d) {
					if (dayTotals[d] >= peakVal) {
						peakVal = dayTotals[d];
						peakDate = d;
					}
				});
				kpiTop.textContent = activeDays > 0 ? formatInt(peakVal) : '-';
				kpiTopSub.textContent = peakDate ? formatDateIt(peakDate) : '';
			} else {
				kpiTopLabel.textContent = (state.activeView === 'regioni' || state.activeView === 'mappa') ? 'Top Regione' : 'Top Provincia / Elemento';
				var topName = '-';
				var topVal = 0;
				Object.keys(dimTotals).forEach(function (dim) {
					if (dimTotals[dim] > topVal) {
						topVal = dimTotals[dim];
						topName = dim;
					}
				});
				kpiTop.textContent = topName;
				kpiTopSub.textContent = topName !== '-' ? formatInt(topVal) + ' articoli' : '';
			}
		}

		// Aggiorna il grafico giornaliero Chart.js.
		function renderChart(rows) {
			var dayMap = {};
			rows.forEach(function (r) {
				if (!r.d) return;
				dayMap[r.d] = (dayMap[r.d] || 0) + (Number(r.n) || 0);
			});

			var sortedDates = Object.keys(dayMap).sort();
			var labels = sortedDates.map(formatDateIt);
			var values = sortedDates.map(function (d) { return dayMap[d]; });

			if (state.selectedDim) {
				chartTitle.textContent = 'Andamento giornaliero — ' + state.selectedDim;
				activeChip.classList.remove('nk-hidden');
				chipName.textContent = state.selectedDim;
			} else {
				chartTitle.textContent = 'Andamento giornaliero (totale vista attiva)';
				activeChip.classList.add('nk-hidden');
			}

			if (state.chart) {
				state.chart.destroy();
			}

			state.chart = new Chart(chartCanvas.getContext('2d'), {
				type: 'bar',
				data: {
					labels: labels,
					datasets: [{
						label: state.selectedDim ? state.selectedDim : 'Articoli',
						data: values,
						backgroundColor: state.selectedDim ? '#00599C' : 'rgba(0, 89, 156, 0.85)',
						hoverBackgroundColor: '#00467A',
						borderRadius: 6,
						borderSkipped: false,
						maxBarThickness: 38
					}]
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					interaction: {
						mode: 'index',
						intersect: false
					},
					plugins: {
						legend: { display: false },
						tooltip: {
							backgroundColor: '#0f172a',
							titleFont: { size: 12, weight: '600' },
							bodyFont: { size: 13 },
							padding: 10,
							cornerRadius: 8,
							displayColors: false,
							callbacks: {
								label: function (ctx) {
									return formatInt(ctx.parsed.y) + ' articoli';
								}
							}
						}
					},
					scales: {
						x: {
							grid: { display: false },
							ticks: {
								color: '#64748b',
								font: { size: 11 },
								maxRotation: 0,
								autoSkipPadding: 14
							}
						},
						y: {
							beginAtZero: true,
							suggestedMax: values.length ? undefined : 10,
							border: { display: false },
							grid: {
								color: '#f1f5f9'
							},
							ticks: {
								precision: 0,
								color: '#64748b',
								font: { size: 11 },
								callback: function (val) { return formatInt(val); }
							}
						}
					}
				}
			});
		}

		// Costruisce i dati aggregati per la modalità Riepilogo.
		function buildSummaryData(rows) {
			var allDates = rows.map(function (r) { return r.d; }).filter(Boolean).sort();
			var globalLastDay = allDates.length ? allDates[allDates.length - 1] : '';

			var grouped = {};
			rows.forEach(function (r) {
				var key = r.dim || '(Senza nome)';
				if (!grouped[key]) {
					grouped[key] = {
						dim: key,
						totale: 0,
						days: {}
					};
				}
				var val = Number(r.n) || 0;
				grouped[key].totale += val;
				if (r.d) {
					grouped[key].days[r.d] = (grouped[key].days[r.d] || 0) + val;
				}
			});

			return Object.keys(grouped).map(function (key) {
				var g = grouped[key];
				var dayValues = Object.keys(g.days).map(function (d) { return g.days[d]; });
				var activeDays = dayValues.length;
				var minDay = activeDays ? Math.min.apply(null, dayValues) : 0;
				var maxDay = activeDays ? Math.max.apply(null, dayValues) : 0;
				var lastDayVal = globalLastDay && g.days[globalLastDay] ? g.days[globalLastDay] : 0;

				return {
					dim: g.dim,
					totale: g.totale,
					media: activeDays ? Number((g.totale / activeDays).toFixed(1)) : 0,
					ultimo_giorno: lastDayVal,
					giorni_attivi: activeDays,
					min_giorno: minDay,
					max_giorno: maxDay
				};
			}).sort(function (a, b) {
				return b.totale - a.totale;
			});
		}

		// Renderizza la tabella Tabulator.
		function renderTable(periodRows) {
			var isSummary = state.tableMode === 'summary';
			var tableData = isSummary ? buildSummaryData(periodRows) : getFilteredRows(true).slice().sort(function (a, b) {
				return b.d.localeCompare(a.d);
			});

			var intFormatter = function (cell) {
				return formatInt(cell.getValue());
			};

			var totalLabel = function () { return 'Totale'; };

			var columns = isSummary ? [
				{ title: 'Dimensione', field: 'dim', headerFilter: false, widthGrow: 2, bottomCalc: totalLabel },
				{ title: 'Totale', field: 'totale', hozAlign: 'right', sorter: 'number', formatter: intFormatter, bottomCalc: 'sum', bottomCalcFormatter: intFormatter },
				{ title: 'Media/gg', field: 'media', hozAlign: 'right', sorter: 'number', formatter: function (c) { return formatDec(c.getValue()); } },
				{ title: 'Ultimo gg', field: 'ultimo_giorno', hozAlign: 'right', sorter: 'number', formatter: intFormatter, bottomCalc: 'sum', bottomCalcFormatter: intFormatter },
				{ title: 'Min', field: 'min_giorno', hozAlign: 'right', sorter: 'number', formatter: intFormatter },
				{ title: 'Max', field: 'max_giorno', hozAlign: 'right', sorter: 'number', formatter: intFormatter }
			] : [
				{ title: 'Data', field: 'd', sorter: 'string', formatter: function (c) { return formatDateIt(c.getValue()); }, bottomCalc: totalLabel },
				{ title: 'Canale', field: 'c', sorter: 'string' },
				{ title: 'Dimensione', field: 'dim', sorter: 'string', widthGrow: 2 },
				{ title: 'Metrica', field: 'm', sorter: 'string' },
				{ title: 'Conteggio', field: 'n', hozAlign: 'right', sorter: 'number', formatter: intFormatter, bottomCalc: 'sum', bottomCalcFormatter: intFormatter }
			];

			if (state.table) {
				state.table.destroy();
			}

			var targetDimLower = state.selectedDim.trim().toLowerCase();

			state.table = new Tabulator('#nk-tabulator-table', {
				data: tableData,
				columns: columns,
				layout: 'fitColumns',
				pagination: 'local',
				paginationSize: 25,
				paginationSizeSelector: [15, 25, 50, 100],
				placeholder: 'Nessun dato disponibile per i filtri selezionati.',
				rowFormatter: function (row) {
					if (isSummary) {
						var el = row.getElement();
						el.classList.add('nk-row-clickable');
						var rowDim = (row.getData().dim || '').toLowerCase();
						if (targetDimLower && rowDim === targetDimLower) {
							el.classList.add('nk-row-selected');
						}
					}
				}
			});

			if (isSummary) {
				state.table.on('rowClick', function (e, row) {
					var clickedDim = row.getData().dim;
					if (state.selectedDim.toLowerCase() === clickedDim.toLowerCase()) {
						setSelectedDimension('');
					} else {
						setSelectedDimension(clickedDim);
					}
				});
			}

			state.table.on('tableBuilt', function () {
				if (tableSearchInput.value.trim() !== '') {
					applyTableSearch();
				}
			});
		}

		function applyTableSearch() {
			if (!state.table || !state.table.initialized) return;
			var term = tableSearchInput.value.trim().toLowerCase();
			if (!term) {
				if (state.table.getFilters().length > 0) {
					state.table.clearFilter();
				}
				return;
			}

			state.table.setFilter(function (data) {
				return Object.keys(data).some(function (key) {
					return String(data[key]).toLowerCase().indexOf(term) !== -1;
				});
			});
		}

		function setSelectedDimension(dim) {
			var canonical = resolveCanonicalDim(dim);
			state.selectedDim = canonical;
			dimSearchInput.value = canonical;
			clearDimBtn.classList.toggle('nk-hidden', !canonical);
			renderAll();
		}

		function escapeHtml(str) {
			return String(str)
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;')
				.replace(/'/g, '&#039;');
		}

		function ensureItalyGeoJson() {
			if (state.italyGeoPromise) {
				return state.italyGeoPromise;
			}
			if (!nkReportConfig.italyGeoJsonUrl || typeof echarts === 'undefined') {
				return Promise.reject(new Error('Libreria mappa o GeoJSON non disponibili.'));
			}
			state.italyGeoPromise = fetch(nkReportConfig.italyGeoJsonUrl)
				.then(function (res) {
					if (!res.ok) throw new Error('Impossibile caricare la mappa dell\'Italia.');
					return res.json();
				})
				.then(function (geoJson) {
					echarts.registerMap('italy', geoJson);
					return geoJson;
				});
			return state.italyGeoPromise;
		}

		function renderMapSidebar(regionTotals, grandTotal) {
			if (!mapDetailList) return;

			if (!state.selectedDim) {
				mapSidebarTitle.textContent = 'Classifica Regioni';
				mapSidebarSub.textContent = 'Clicca su una regione per vedere le province';
				if (mapBackBtn) mapBackBtn.classList.add('nk-hidden');

				var sortedRegions = Object.keys(regionTotals)
					.map(function (name) { return { name: name, total: regionTotals[name] }; })
					.filter(function (item) { return item.total > 0; })
					.sort(function (a, b) { return b.total - a.total; });

				if (!sortedRegions.length) {
					mapDetailList.innerHTML = '<div class="nk-map-empty">Nessun dato disponibile per il periodo selezionato.</div>';
					return;
				}

				var maxReg = sortedRegions[0].total || 1;
				mapDetailList.innerHTML = sortedRegions.map(function (item, idx) {
					var pctWidth = Math.max(4, Math.round((item.total / maxReg) * 100));
					var sharePct = grandTotal > 0 ? ((item.total / grandTotal) * 100).toFixed(1) : '0.0';
					return '<div class="nk-map-item is-clickable" data-region="' + escapeHtml(item.name) + '">'
						+ '<div class="nk-map-item-top">'
						+ '<span>' + (idx + 1) + '. ' + escapeHtml(item.name) + '</span>'
						+ '<span class="nk-map-item-val">' + formatInt(item.total) + '</span>'
						+ '</div>'
						+ '<div class="nk-map-bar-track"><div class="nk-map-bar-fill" style="width:' + pctWidth + '%"></div></div>'
						+ '<div class="nk-map-item-meta"><span>Quota nazionale</span><span>' + sharePct.replace('.', ',') + '%</span></div>'
						+ '</div>';
				}).join('');

				mapDetailList.querySelectorAll('.nk-map-item[data-region]').forEach(function (el) {
					el.addEventListener('click', function () {
						setSelectedDimension(el.dataset.region);
					});
				});
				return;
			}

			// Vista dettagliata delle singole province della regione selezionata.
			var targetRegion = state.selectedDim;
			var targetLower = targetRegion.toLowerCase();
			var provRows = state.rawRows.filter(function (r) {
				if (r.m !== 'pubblicata' || r.c !== 'news_locali' || r.dim === 'Nazionale') return false;
				if (state.dateFrom && r.d < state.dateFrom) return false;
				if (state.dateTo && r.d > state.dateTo) return false;
				return getRegionForProvince(r.dim).toLowerCase() === targetLower;
			});

			var provSummary = buildSummaryData(provRows);
			var regionSum = provSummary.reduce(function (acc, p) { return acc + p.totale; }, 0);
			var regionShare = grandTotal > 0 ? ((regionSum / grandTotal) * 100).toFixed(1) : '0.0';

			mapSidebarTitle.textContent = 'Province — ' + targetRegion;
			mapSidebarSub.textContent = formatInt(regionSum) + ' articoli (' + regionShare.replace('.', ',') + '% del totale)';
			if (mapBackBtn) mapBackBtn.classList.remove('nk-hidden');

			if (!provSummary.length) {
				mapDetailList.innerHTML = '<div class="nk-map-empty">Nessuna provincia con dati in questo periodo.</div>';
				return;
			}

			var maxProv = provSummary[0].totale || 1;
			mapDetailList.innerHTML = provSummary.map(function (p) {
				var pctWidth = Math.max(4, Math.round((p.totale / maxProv) * 100));
				return '<div class="nk-map-item">'
					+ '<div class="nk-map-item-top">'
					+ '<span>' + escapeHtml(p.dim) + '</span>'
					+ '<span class="nk-map-item-val">' + formatInt(p.totale) + '</span>'
					+ '</div>'
					+ '<div class="nk-map-bar-track"><div class="nk-map-bar-fill" style="width:' + pctWidth + '%"></div></div>'
					+ '<div class="nk-map-item-meta">'
					+ '<span>Media: ' + formatDec(p.media) + '/gg</span>'
					+ '<span>Ultimo gg: ' + formatInt(p.ultimo_giorno) + '</span>'
					+ '</div>'
					+ '</div>';
			}).join('');
		}

		function renderMapView(periodRows) {
			if (!mapContainer) return;

			var ALL_REGIONS = [
				'Abruzzo', 'Basilicata', 'Calabria', 'Campania', 'Emilia-Romagna',
				'Friuli-Venezia Giulia', 'Lazio', 'Liguria', 'Lombardia', 'Marche',
				'Molise', 'Piemonte', 'Puglia', 'Sardegna', 'Sicilia', 'Toscana',
				'Trentino-Alto Adige', 'Umbria', "Valle d'Aosta", 'Veneto'
			];

			var regionTotals = {};
			ALL_REGIONS.forEach(function (reg) { regionTotals[reg] = 0; });

			var grandTotal = 0;
			periodRows.forEach(function (r) {
				var val = Number(r.n) || 0;
				grandTotal += val;
				if (r.dim && regionTotals.hasOwnProperty(r.dim)) {
					regionTotals[r.dim] += val;
				}
			});

			renderMapSidebar(regionTotals, grandTotal);

			ensureItalyGeoJson().then(function () {
				if (!state.mapChart) {
					state.mapChart = echarts.init(mapContainer);
					state.mapChart.on('click', function (params) {
						if (!params || !params.name) return;
						if (state.selectedDim.toLowerCase() === params.name.toLowerCase()) {
							setSelectedDimension('');
						} else {
							setSelectedDimension(params.name);
						}
					});
					window.addEventListener('resize', function () {
						if (state.mapChart && state.activeView === 'mappa') {
							state.mapChart.resize();
						}
					});
				}

				var mapData = ALL_REGIONS.map(function (reg) {
					var isSelected = state.selectedDim && state.selectedDim.toLowerCase() === reg.toLowerCase();
					return {
						name: reg,
						value: regionTotals[reg] || 0,
						selected: Boolean(isSelected)
					};
				});

				var maxVal = Math.max.apply(null, mapData.map(function (d) { return d.value; }));
				if (!maxVal || maxVal < 1) maxVal = 10;

				state.mapChart.setOption({
					tooltip: {
						trigger: 'item',
						backgroundColor: '#0f172a',
						borderColor: '#0f172a',
						borderWidth: 0,
						padding: [10, 14],
						textStyle: { color: '#ffffff', fontFamily: 'Roboto, sans-serif', fontSize: 13 },
						formatter: function (params) {
							var val = Number(params.value) || 0;
							var share = grandTotal > 0 ? ((val / grandTotal) * 100).toFixed(1).replace('.', ',') : '0,0';
							return '<div style="font-weight:700;margin-bottom:4px;font-size:14px;">' + escapeHtml(params.name) + '</div>'
								+ '<div>Articoli: <strong>' + formatInt(val) + '</strong> (' + share + '%)</div>'
								+ '<div style="font-size:11px;color:#94a3b8;margin-top:4px;">Clicca per vedere le province</div>';
						}
					},
					visualMap: {
						min: 0,
						max: maxVal,
						left: 12,
						bottom: 12,
						text: ['Alto', 'Basso'],
						calculable: true,
						hoverLink: true,
						orient: 'horizontal',
						itemWidth: 14,
						itemHeight: 130,
						textStyle: { color: '#475569', fontFamily: 'Roboto, sans-serif', fontSize: 11 },
						inRange: {
							color: ['#e8f2fb', '#99c7ea', '#3b89c9', '#00599C', '#003865']
						},
						outOfRange: {
							color: ['#f1f5f9']
						}
					},
					series: [{
						name: 'Pubblicazioni',
						type: 'map',
						map: 'italy',
						roam: false,
						selectedMode: 'single',
						layoutCenter: ['50%', '49%'],
						layoutSize: '96%',
						itemStyle: {
							areaColor: '#f1f5f9',
							borderColor: '#ffffff',
							borderWidth: 1.2
						},
						emphasis: {
							label: { show: false },
							itemStyle: {
								areaColor: '#f97316',
								borderColor: '#ffffff',
								borderWidth: 1.8
							}
						},
						select: {
							label: { show: false },
							itemStyle: {
								areaColor: '#0f172a',
								borderColor: '#ffffff',
								borderWidth: 2.2
							}
						},
						data: mapData
					}]
				}, true);

				state.mapChart.resize();
			}).catch(function (err) {
				showError(err.message || 'Errore nel caricamento della mappa.');
			});
		}

		function renderAll() {
			if (nazionaleNotice) {
				nazionaleNotice.classList.toggle('nk-hidden', state.activeView !== 'nazionale');
			}
			var isMap = state.activeView === 'mappa';
			if (mapPanel) mapPanel.classList.toggle('nk-hidden', !isMap);
			if (chartPanel) chartPanel.classList.toggle('nk-hidden', isMap);
			if (tablePanel) tablePanel.classList.toggle('nk-hidden', isMap);

			var periodRows = getFilteredRows(false);
			var chartKpiRows = getFilteredRows(true);

			renderKpis(chartKpiRows);
			if (isMap) {
				renderMapView(periodRows);
			} else {
				renderChart(chartKpiRows);
				renderTable(periodRows);
			}
		}

		// Caricamento dati via AJAX.
		function fetchData(forceRefresh) {
			setLoading(true);
			hideError();

			var body = new URLSearchParams();
			body.append('action', forceRefresh ? 'nk_refresh_report_data' : 'nk_get_report_data');
			body.append('nonce', nkReportConfig.nonce);

			fetch(nkReportConfig.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			})
			.then(function (res) { return res.json(); })
			.then(function (res) {
				setLoading(false);

				if (!res || !res.success) {
					var msg = (res && res.data && res.data.message) ? res.data.message : 'Impossibile caricare i dati del report.';
					showError(msg);
					return;
				}

				state.rawRows = res.data.rows || [];
				if (updatedBadgeText) {
					updatedBadgeText.textContent = 'Aggiornato: ' + (res.data.last_updated || '-');
				}

				populateDimensionOptions();
				applyPresetDates(state.activePreset);
				renderAll();
			})
			.catch(function (err) {
				setLoading(false);
				showError('Errore di rete durante il caricamento dei dati: ' + err.message);
			});
		}

		// Event listeners.
		refreshBtn.addEventListener('click', function () {
			fetchData(true);
		});

		function moveTabIndicator(targetBtn) {
			if (!tabIndicator || !targetBtn) return;
			tabIndicator.style.width = targetBtn.offsetWidth + 'px';
			tabIndicator.style.height = targetBtn.offsetHeight + 'px';
			tabIndicator.style.transform = 'translate3d(' + targetBtn.offsetLeft + 'px, ' + targetBtn.offsetTop + 'px, 0)';
		}

		function syncActiveTabIndicator() {
			var activeBtn = root.querySelector('.nk-tab.is-active');
			if (activeBtn) {
				moveTabIndicator(activeBtn);
			}
		}

		if (tabsContainer && tabIndicator) {
			tabButtons.forEach(function (btn) {
				btn.addEventListener('mouseenter', function () {
					tabsContainer.classList.add('is-hovering');
					moveTabIndicator(btn);
				});
			});

			tabsContainer.addEventListener('mouseleave', function () {
				tabsContainer.classList.remove('is-hovering');
				syncActiveTabIndicator();
			});

			window.addEventListener('resize', syncActiveTabIndicator);
			requestAnimationFrame(syncActiveTabIndicator);
		}

		tabButtons.forEach(function (btn) {
			btn.addEventListener('click', function () {
				tabButtons.forEach(function (b) { b.classList.remove('is-active'); });
				btn.classList.add('is-active');
				moveTabIndicator(btn);
				state.activeView = btn.dataset.view;
				state.selectedDim = '';
				dimSearchInput.value = '';
				clearDimBtn.classList.add('nk-hidden');

				if (dimLabel) {
					dimLabel.textContent = (state.activeView === 'regioni' || state.activeView === 'mappa') ? 'Regione' : 'Provincia / Elemento';
				}

				populateDimensionOptions();
				if (state.activePreset !== 'custom') {
					applyPresetDates(state.activePreset);
				}
				renderAll();
			});
		});

		presetButtons.forEach(function (btn) {
			btn.addEventListener('click', function () {
				presetButtons.forEach(function (b) { b.classList.remove('is-active'); });
				btn.classList.add('is-active');
				state.activePreset = btn.dataset.preset;
				applyPresetDates(state.activePreset);
				renderAll();
			});
		});

		function onCustomDateChange() {
			var dates = getAvailableDates();
			presetButtons.forEach(function (b) { b.classList.remove('is-active'); });
			state.activePreset = 'custom';

			var from = dateFromInput.value;
			var to = dateToInput.value;

			if (dates.length) {
				var minDate = dates[0];
				var maxDate = dates[dates.length - 1];

				if (from) {
					if (from < minDate) from = minDate;
					if (from > maxDate) from = maxDate;
					// Aggancia al primo giorno effettivamente presente >= from.
					var snappedFrom = dates.find(function (d) { return d >= from; });
					if (snappedFrom) from = snappedFrom;
				}
				if (to) {
					if (to < minDate) to = minDate;
					if (to > maxDate) to = maxDate;
					// Aggancia all'ultimo giorno effettivamente presente <= to.
					for (var i = dates.length - 1; i >= 0; i--) {
						if (dates[i] <= to) {
							to = dates[i];
							break;
						}
					}
				}
				if (from && to && from > to) {
					to = from;
				}
			}

			setPickerValues(from, to);
			renderAll();
		}

		if (!state.fpFrom) {
			dateFromInput.addEventListener('change', onCustomDateChange);
			dateToInput.addEventListener('change', onCustomDateChange);
		}

		dimSearchInput.addEventListener('input', function () {
			var rawVal = dimSearchInput.value.trim();
			var lowerVal = rawVal.toLowerCase();
			clearDimBtn.classList.toggle('nk-hidden', !rawVal);

			if (rawVal === '') {
				state.selectedDim = '';
				renderAll();
				return;
			}

			var viewRows = getViewRows();
			var matchedCanonical = '';
			for (var i = 0; i < viewRows.length; i++) {
				if (viewRows[i].dim && viewRows[i].dim.toLowerCase() === lowerVal) {
					matchedCanonical = viewRows[i].dim;
					break;
				}
			}

			if (matchedCanonical) {
				state.selectedDim = matchedCanonical;
				renderAll();
			}
		});

		dimSearchInput.addEventListener('change', function () {
			var canonical = resolveCanonicalDim(dimSearchInput.value);
			state.selectedDim = canonical;
			dimSearchInput.value = canonical;
			clearDimBtn.classList.toggle('nk-hidden', !canonical);
			renderAll();
		});

		clearDimBtn.addEventListener('click', function () {
			setSelectedDimension('');
		});

		chipReset.addEventListener('click', function () {
			setSelectedDimension('');
		});

		if (mapBackBtn) {
			mapBackBtn.addEventListener('click', function () {
				setSelectedDimension('');
			});
		}

		modeButtons.forEach(function (btn) {
			btn.addEventListener('click', function () {
				modeButtons.forEach(function (b) { b.classList.remove('is-active'); });
				btn.classList.add('is-active');
				state.tableMode = btn.dataset.tableMode;
				renderTable(getFilteredRows(false));
			});
		});

		tableSearchInput.addEventListener('input', applyTableSearch);

		exportCsvBtn.addEventListener('click', function () {
			if (!state.table) return;
			var filename = 'netweek-report-' + state.activeView + '-' + (state.dateTo || 'export') + '.csv';
			state.table.download('csv', filename);
		});

		// Avvio iniziale.
		fetchData(false);
	});
})();
