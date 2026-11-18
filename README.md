# Prosit monitor

Controlla ogni 10 minuti gli script di `/usr/local/bin/prosit`, Lizmap e i dischi, e scrive
lo stato in `/srv/data/prosit/monitor/stato.json`. Il riquadro **"Stato di script e Lizmap"**
nella pagina iniziale dell'Amministrazione di Lizmap (modulo `prositmonitor`) legge quel file
ed è visibile solo agli amministratori. Ogni notte pulisce i log.

## Installazione

```bash
git clone https://github.com/ComuneMontelupoFiorentino/prosit-monitor.git /usr/local/bin/prosit/monitor
cd /usr/local/bin/prosit/monitor && cp monitor.ini.example monitor.ini   # poi adattare i percorsi
mkdir -p logs && php monitor.php --stampa
```

Il riquadro nell'Amministrazione di Lizmap richiede anche il modulo `prositmonitor`,
che si trova in `lizmap-module/prositmonitor` di questo repository:

```bash
L=/var/www/lizmap-web-client/lizmap
cp -r lizmap-module/prositmonitor $L/lizmap-modules/
php $L/install/configurator.php prositmonitor
php $L/install/installer.php
```

## Cosa controlla

Per ogni script (sezioni di `monitor.ini`):

- **Ha girato?** Dal crontab di prosit2 calcola l'ultimo orario previsto. Se il log non è
  stato scritto entro `grazia_minuti` (20) da quell'orario, segnala "non ha girato alle …".
- **Errori nelle ultime 24 ore:** righe con `[ERROR]`, `ERROR`, `ERRORE`, `CRITICAL`,
  `Fatal error`, `Uncaught`, `Traceback`. Mostra le ultime 6, raggruppate e con password,
  chiavi e token mascherati.
- **Non pianificato:** lo script non compare nel crontab (`manuale = 1` per quelli lanciati a mano).

Per Lizmap: risponde la pagina, risponde QGIS Server (l'indirizzo è quello di Lizmap),
errori e avvisi di `var/log/errors.log` nelle 24 ore, avvisi sui progetti (`lizmap-admin.log`).
Per i dischi: occupazione di `/` e `/srv/data` (avviso all'80%, errore al 90%).

## Pulizia dei log (ogni notte)

| Tipo di log | Cosa succede |
|---|---|
| Log datati (`logs/2026/10/23/…`, `log_20261023_…txt`, `prosit-2026-10.log`) | compressi (`.gz`) dopo **7 giorni**, cancellati dopo **90 giorni** |
| File unici che crescono (`cron.log`, `errors.log` di Lizmap, …) | ogni **30 giorni** (o oltre 20 MB) copia compressa `nome.AAAAMMGG-HHMMSS.gz` e svuotamento; copie cancellate dopo **90 giorni** |
| Cartelle datate rimaste vuote | rimosse |

Tocca solo i file elencati in `monitor.ini` (`log[]` e `pulizia_extra[]`), mai i file di stato
(per esempio `TWPROJECT…/logs/state/`). I valori si cambiano in `[generale]`.

```bash
php monitor.php --stampa                 # controllo a mano, con riepilogo
php monitor.php --pulizia --prova        # cosa farebbe la pulizia, senza toccare niente
php monitor.php --pulizia --dettagli     # pulizia con l'elenco dei file trattati
```

## Crontab di prosit2 (orari in UTC)

```
*/10 * * * * /usr/bin/php /usr/local/bin/prosit/monitor/monitor.php >> /usr/local/bin/prosit/monitor/logs/monitor.log 2>&1
20 2 * * * /usr/bin/php /usr/local/bin/prosit/monitor/monitor.php --pulizia >> /usr/local/bin/prosit/monitor/logs/pulizia.log 2>&1
```

## Aggiungere o regolare un controllo

Copiare una sezione di `monitor.ini` e cambiare `nome`, `log[]` e `cron`. Per un errore noto
che non si vuole vedere: `ignora = "/testo dell'errore/"`. Le modifiche valgono dal controllo
successivo, senza riavviare niente.

## Aggiornamenti di Lizmap

Il modulo sta in `lizmap/lizmap-modules/prositmonitor` ed è attivo in `localconfig.ini.php`:
lo script `aggiorna_lizmap.sh` li riporta entrambi nella versione nuova.
Per disattivarlo: `php lizmap/install/configurator.php -r prositmonitor`.

## Parte di Prosit

Questo componente fa parte di **[Prosit](https://github.com/ComuneMontelupoFiorentino/prosit)**,
il Sistema Informativo Territoriale del Comune di Montelupo Fiorentino. Nel repository
[prosit](https://github.com/ComuneMontelupoFiorentino/prosit) ci sono l'architettura, l'elenco
di tutti i componenti (topic [`prosit`](https://github.com/search?q=org%3AComuneMontelupoFiorentino+topic%3Aprosit&type=repositories))
e le versioni che funzionano insieme.
