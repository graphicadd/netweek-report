# Netweek Report (`nk-report-analytics`)

![Version](https://img.shields.io/badge/version-1.7.1-00599C)
![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-21759B)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4)
![License](https://img.shields.io/badge/license-GPL--2.0--or--later-green)

Plugin WordPress modulare, pronto per la produzione, per visualizzare una dashboard editoriale interattiva basata su **Google Sheets** e **Google Service Account** (autenticazione JWT RS256 in PHP puro, zero dipendenze Composer, zero tabelle custom nel database).

---

## Funzionalità principali

- **Selezione dinamica da Google Drive**: scegli il Google Sheet e il Tab interno direttamente dal backend (**Impostazioni > Netweek Report**), con fallback manuale opzionale.
- **Viste editoriali**: `Province`, `Regioni` (raggruppamento automatico delle 20 regioni italiane a partire dalle province), `Nazionale`, `Verticali` e `Tutte le pubblicate`.
- **Dashboard interattiva**:
  - 4 KPI card dinamiche (`Totale nel periodo`, `Media giornaliera`, `Ultimo giorno disponibile`, `Top Provincia/Regione` o `Picco giornaliero`).
  - Grafico giornaliero interattivo con **Chart.js** e filtro per singola dimensione con un click dalla tabella.
  - Tabella analitica **Tabulator.js** con modalità *Riepilogo* e *Dettaglio giornaliero*, ricerca rapida, totali a piè di tabella ed export **CSV**.
  - Selettore date **Flatpickr** in italiano vincolato ai soli giorni presenti nei dati.
- **Sicurezza & Controllo Accessi**:
  - Credenziali Service Account lette esclusivamente da `wp-config.php` (`NK_GOOGLE_SA_PATH` o `NK_GOOGLE_SA_B64`).
  - Accesso riservato agli utenti autenticati (capability configurabile, default `edit_posts`) **oppure** accessibile anche ai visitatori non autenticati tramite **password dedicata** (salvata con `wp_hash_password()` e sessione su cookie `HttpOnly` firmato HMAC).
- **Performance**: caching su Transient WordPress (token OAuth2 per 50 minuti, dati Sheet per 6 ore configurabili) con pulsante di aggiornamento manuale in dashboard.

---

## Requisiti

- WordPress `5.8+`
- PHP `7.4+` con estensione `OpenSSL` attiva
- Progetto Google Cloud con **Google Sheets API** e **Google Drive API** abilitate

---

## Installazione da GitHub

1. Clona il repository direttamente nella cartella plugin di WordPress (rinominando la cartella in `nk-report-analytics`):
   ```bash
   cd wp-content/plugins
   git clone https://github.com/graphicadd/netweek-report.git nk-report-analytics
   ```
   *(Oppure scarica lo ZIP dal ramo `main` e caricalo da **Plugin > Aggiungi plugin > Carica plugin**).*
2. Attiva **Netweek Report Analytics** dalla bacheca di WordPress.

---

## Configurazione Google Cloud & `wp-config.php`

1. In [Google Cloud Console](https://console.cloud.google.com/), abilita **Google Sheets API** e **Google Drive API**.
2. Crea un **Service Account** (*IAM e amministrazione > Service Account*), genera una chiave **JSON** e scaricala.
3. Configura **una** delle due costanti in `wp-config.php` (prima di `/* That's all, stop editing! */`):

   **Opzione A — Percorso file fuori dalla webroot (consigliata):**
   ```php
   define( 'NK_GOOGLE_SA_PATH', '/percorso/sicuro/fuori/webroot/service-account.json' );
   ```

   **Opzione B — Contenuto JSON codificato in Base64:**
   ```php
   define( 'NK_GOOGLE_SA_B64', 'BASE64_DEL_FILE_JSON' );
   ```

4. Vai su **Impostazioni > Netweek Report**, copia la `client_email` mostrata nel box *Stato Google Service Account* e condividi su Google Drive i tuoi fogli con quella email (ruolo **Visualizzatore**).

---

## Struttura attesa del Google Sheet

Il plugin legge il range `<NomeTab>!A:E` (riga 1 = intestazione):

| Colonna | Nome | Valori attesi |
| :--- | :--- | :--- |
| **A** | `data` | Data in formato `YYYY-MM-DD` (es. `2026-11-23`) |
| **B** | `canale` | `news_locali` oppure `verticali` |
| **C** | `dimensione` | Provincia italiana (es. `Milano`), `Nazionale` o argomento (`verticali`) |
| **D** | `tipo_metrica` | `pubblicata` (le righe `scartata` vengono escluse dalla dashboard) |
| **E** | `conteggio` | Numero intero di articoli |

---

## Utilizzo dello Shortcode

Inserisci lo shortcode all'interno di una pagina WordPress:

```text
[nk_report_dashboard]
```

- Se vuoi consentire l'accesso anche ai visitatori non autenticati, attiva **Accesso visitatori con password** in **Impostazioni > Netweek Report**, imposta una password e pubblica la pagina.
- Gli asset (`Chart.js`, `Tabulator.js`, `Flatpickr`, CSS/JS del plugin) vengono caricati **solo** nella pagina che contiene lo shortcode e solo dopo l'autorizzazione.

---

## Branching & Contributi

Il repository segue il flusso a due rami principali:
- **`main`**: codice stabile pronto per la produzione e le release.
- **`develop`**: ramo di sviluppo attivo per nuove feature, fix e test prima del merge su `main`.

Per contribuire:
1. Crea un branch a partire da `develop` (`git checkout -b feature/nome-feature develop`).
2. Aggiorna la versione del plugin in `nk-report-analytics.php` (`Version:` e `NK_REPORT_VERSION`).
3. Apri una Pull Request verso `develop`.

---

## Changelog

- **1.7.1** — Rimozione ombre blu dai pulsanti/tab e stile pillola (`border-radius: 999px`) su tutti i controlli.
- **1.7.0** — Aggiunta protezione con password per visitatori non autenticati (cookie `HttpOnly` firmato HMAC).
- **1.6.1** — Fix layout griglia e intestazione calendario Flatpickr.
- **1.6.0** — Sostituzione selettore date nativo con Flatpickr in italiano vincolato ai soli giorni presenti.
- **1.5.3** — Rimozione colonna `GG attivi` dalla tabella di riepilogo.
- **1.5.0** — Nuova barra di navigazione a pillola scorrevole in `#00599C`.
- **1.4.0** — Aggiunta vista **Regioni** con mappatura automatica delle province italiane.
- **1.0.0** — Release iniziale.
