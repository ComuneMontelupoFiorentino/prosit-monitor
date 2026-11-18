<?php
/**
 * Riquadro "Stato di script e Lizmap" nella pagina iniziale dell'Amministrazione di Lizmap.
 * Legge lo stato scritto da /usr/local/bin/prosit/monitor/monitor.php (cron di prosit2):
 * qui non si fa nessun controllo pesante, la pagina resta veloce.
 *
 * Percorso del file di stato: [prositmonitor] stato=... in var/config/localconfig.ini.php
 * (predefinito /srv/data/prosit/monitor/stato.json).
 */
class prositmonitorListener extends jEventListener
{
    /** dopo quanti minuti lo stato e' considerato vecchio (il monitor gira ogni 10) */
    const STATO_VECCHIO_MIN = 30;

    public function onmasterAdminGetDashboardWidget($event)
    {
        if (!jAcl2::check('lizmap.admin.services.view')) {
            return;
        }
        try {
            $box = new masterAdminDashboardWidget();
            $box->title = 'Stato di script e Lizmap';
            $box->order = 1;
            $box->content = $this->html();
            $event->add($box);
        } catch (Throwable $e) {
            jLog::log('prositmonitor: ' . $e->getMessage(), 'error');
        }
    }

    private function html(): string
    {
        $cfg = jApp::config()->prositmonitor ?? [];
        $file = $cfg['stato'] ?? '/srv/data/prosit/monitor/stato.json';
        $css = $this->css();
        if (!is_readable($file)) {
            return $css . '<div class="pmon"><p class="pmon-alert pmon-errore"><b>Lo stato non è disponibile.</b> '
                . 'Il file ' . $this->e($file) . ' non esiste o non è leggibile: controllare che nel crontab di prosit2 ci sia '
                . '<code>*/10 * * * * php /usr/local/bin/prosit/monitor/monitor.php</code>.</p></div>';
        }
        $s = json_decode((string) file_get_contents($file), true);
        if (!is_array($s)) {
            return $css . '<div class="pmon"><p class="pmon-alert pmon-errore">File di stato non valido: ' . $this->e($file) . '</p></div>';
        }
        $now = time();
        $h = '<div class="pmon">';

        // il monitor sta girando?
        $eta = $now - (int) ($s['generato_ts'] ?? 0);
        if ($eta > self::STATO_VECCHIO_MIN * 60) {
            $h .= '<p class="pmon-alert pmon-errore"><b>Il controllo non gira da ' . $this->durata($eta) . '.</b> '
                . 'I dati sotto sono dell\'ultimo controllo riuscito: verificare il cron di monitor.php.</p>';
        }

        // sintesi
        $n = $s['sintesi'] ?? ['ok' => 0, 'avviso' => 0, 'errore' => 0];
        $h .= '<p class="pmon-sintesi">'
            . $this->pill('errore', (int) $n['errore'] . ' da verificare') . ' '
            . $this->pill('avviso', (int) $n['avviso'] . ' avvisi') . ' '
            . $this->pill('ok', (int) $n['ok'] . ' ok')
            . ' <span class="pmon-muted">controllo delle ' . date('H:i', (int) $s['generato_ts'])
            . ' · errori delle ultime ' . (int) ($s['finestra_ore'] ?? 24) . ' ore'
            . (!empty($s['fuso']) && $s['fuso'] !== date_default_timezone_get() ? ' · orari del crontab in ' . $this->e($s['fuso']) : '')
            . '</span></p>';

        // script
        $h .= '<table class="pmon-table"><thead><tr><th></th><th>Script</th><th>Ultima attività</th><th>Pianificazione</th><th>Errori</th><th>Note</th></tr></thead><tbody>';
        foreach ($s['script'] ?? [] as $c) {
            $ult = $c['ultima_attivita'] ? $this->fa($now - (int) $c['ultima_attivita']) : '—';
            $pian = $c['cron'] ? $this->e(implode(' | ', $c['cron'])) : '<span class="pmon-muted">' . ($c['motivi'] && in_array('non pianificato nel crontab', $c['motivi'], true) ? 'non nel crontab' : 'a mano') . '</span>';
            if (!empty($c['previsto'])) {
                $pian .= '<br><span class="pmon-muted">ultimo previsto ' . date('d/m H:i', (int) $c['previsto']) . '</span>';
            }
            $err = (int) $c['errori'] > 0 ? '<b>' . (int) $c['errori'] . '</b>' : '0';
            if ((int) $c['avvisi'] > 0) { $err .= ' <span class="pmon-muted">(+' . (int) $c['avvisi'] . ' avvisi)</span>'; }
            $note = $this->e(implode('; ', $c['motivi'] ?? []));
            if (!empty($c['ultimi_errori'])) {
                $note .= $this->dettagli('ultimi errori', $c['ultimi_errori'], true);
            }
            $h .= '<tr class="pmon-r-' . $this->e($c['stato']) . '"><td>' . $this->pill($c['stato']) . '</td><td>' . $this->e($c['nome']) . '</td><td>' . $ult
                . '</td><td>' . $pian . '</td><td>' . $err . '</td><td>' . $note . '</td></tr>';
        }
        $h .= '</tbody></table>';

        // Lizmap
        $l = $s['lizmap'] ?? [];
        $h .= '<h4 class="pmon-h">' . $this->pill($l['stato'] ?? 'avviso') . ' Lizmap ' . $this->e((string) ($l['versione'] ?? '')) . '</h4><ul class="pmon-list">';
        $h .= '<li>Lizmap: ' . (($l['raggiungibile'] ?? 0) == 200 ? 'risponde' : '<b>non risponde</b> (HTTP ' . (int) ($l['raggiungibile'] ?? 0) . ')') . '</li>';
        if (array_key_exists('qgis_server', $l) && $l['qgis_server'] !== null) {
            $q = (int) $l['qgis_server'];
            $h .= '<li>QGIS Server: ' . (($q > 0 && $q < 500) ? 'risponde' : '<b>non risponde</b> (HTTP ' . $q . ')') . '</li>';
        }
        $h .= '<li>Registro errori di Lizmap (errors.log), ultime ' . (int) ($s['finestra_ore'] ?? 24) . ' ore: <b>' . (int) ($l['errori'] ?? 0)
            . '</b> errori, ' . (int) ($l['avvisi'] ?? 0) . ' avvisi, ' . (int) ($l['notice'] ?? 0) . ' notice'
            . (!empty($l['ultimi_errori']) ? $this->dettagli('errori più frequenti', $l['ultimi_errori'], false) : '') . '</li>';
        if (!empty($l['progetti_problemi'])) {
            $h .= '<li>' . $this->pill('errore') . ' <b>Mappa incompleta all\'avvio</b> (mancano legenda, navigazione, localizzazione) per:<ul>';
            foreach ($l['progetti_problemi'] as $pp) {
                $h .= '<li>' . $this->e($pp['progetto']) . ', gruppo <b>' . $this->e($pp['gruppo']) . '</b>: l\'unico layer acceso all\'apertura ('
                    . $this->e(implode(', ', $pp['layer'])) . ') non e\' visibile in legenda. In QGIS, plugin Lizmap: spuntare '
                    . '"Visualizza nell\'albero dei livelli" per uno di questi layer.</li>';
            }
            $h .= '</ul></li>';
        }
        if (!empty($l['progetti_avvisi'])) {
            $h .= '<li>Avvisi sui progetti QGIS: ' . (int) $l['progetti_avvisi'] . ' (vedi la pagina dei progetti)</li>';
        }
        $h .= '</ul>';

        // dischi e pulizia log
        $h .= '<h4 class="pmon-h">Server</h4><ul class="pmon-list">';
        foreach ($s['dischi'] ?? [] as $d) {
            $h .= '<li>' . $this->pill($d['stato']) . ' Disco ' . $this->e($d['percorso']) . ': ' . (int) $d['usato_pct'] . '% usato, '
                . $this->e((string) $d['libero_gb']) . ' GB liberi</li>';
        }
        $p = $s['pulizia'] ?? null;
        if (is_array($p) && !empty($p['quando'])) {
            $vecchia = $now - (int) $p['quando'] > 2 * 86400;
            $st = ((int) ($p['non_riusciti'] ?? 0) > 0 || $vecchia) ? 'avviso' : 'ok';
            $h .= '<li>' . $this->pill($st) . ' Pulizia log (' . (int) ($p['conserva_giorni'] ?? 90) . ' giorni): ultima ' . $this->fa($now - (int) $p['quando'])
                . ', ' . (int) $p['cancellati'] . ' cancellati, ' . (int) $p['compressi'] . ' compressi, ' . (int) $p['ruotati'] . ' ruotati'
                . ((int) ($p['non_riusciti'] ?? 0) > 0 ? ', <b>' . (int) $p['non_riusciti'] . ' non riusciti</b> (permessi: vedi log del monitor)' : '')
                . ($vecchia ? ' — <b>non gira da più di 2 giorni</b>' : '') . '</li>';
        } else {
            $h .= '<li>' . $this->pill('avviso') . ' Pulizia log: mai eseguita (manca nel crontab <code>monitor.php --pulizia</code>)</li>';
        }
        $h .= '<li class="pmon-muted">PHP ' . $this->e((string) ($s['php'] ?? '')) . ' · ' . $this->e((string) ($s['host'] ?? '')) . ' · monitor ' . $this->e((string) ($s['versione'] ?? '')) . '</li>';
        $h .= '</ul></div>';
        return $css . $h;
    }

    private function dettagli(string $titolo, array $righe, bool $conFile): string
    {
        $o = '<details class="pmon-det"><summary>' . $this->e($titolo) . '</summary><ul>';
        foreach ($righe as $r) {
            $o .= '<li><span class="pmon-muted">' . date('d/m H:i', (int) ($r['quando'] ?? 0))
                . ($conFile && !empty($r['file']) ? ' · ' . $this->e($r['file']) : '')
                . ((int) ($r['volte'] ?? 1) > 1 ? ' · ' . (int) $r['volte'] . ' volte' : '') . '</span><br><code>' . $this->e((string) ($r['testo'] ?? '')) . '</code></li>';
        }
        return $o . '</ul></details>';
    }

    private function pill(string $stato, ?string $testo = null): string
    {
        $t = $testo ?? ['ok' => 'OK', 'avviso' => 'Avviso', 'errore' => 'Errore'][$stato] ?? $stato;
        return '<span class="pmon-pill pmon-' . $this->e($stato) . '">' . $this->e($t) . '</span>';
    }

    private function fa(int $sec): string { return $this->durata($sec) . ' fa'; }

    private function durata(int $sec): string
    {
        if ($sec < 120) { return max(0, $sec) . ' s'; }
        if ($sec < 7200) { return intdiv($sec, 60) . ' min'; }
        if ($sec < 172800) { return intdiv($sec, 3600) . ' ore'; }
        return intdiv($sec, 86400) . ' giorni';
    }

    private function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    private function css(): string
    {
        return '<style>
#dashboard-left-column:has(.pmon),#dashboard-right-column:has(.pmon){width:auto;float:none;max-width:1250px;margin-right:0}
.pmon{font-size:13px}
.pmon-sintesi{margin:4px 0 10px}
.pmon-muted{color:#6b7280;font-size:12px}
.pmon-pill{display:inline-block;padding:1px 8px;border-radius:10px;font-size:11px;font-weight:bold;color:#fff;white-space:nowrap}
.pmon-ok{background:#2e7d32}.pmon-avviso{background:#b26a00}.pmon-errore{background:#c62828}
.pmon-alert{padding:8px 10px;border-radius:4px;color:#fff}
.pmon-table{width:100%;border-collapse:collapse;margin-bottom:12px}
.pmon-table th,.pmon-table td{border-bottom:1px solid #e5e7eb;padding:5px 6px;text-align:left;vertical-align:top}
.pmon-table th{background:#f3f4f6;font-weight:600}
.pmon-r-errore td{background:#fdecea}.pmon-r-avviso td{background:#fff6e5}
.pmon-h{margin:12px 0 6px;font-size:14px}
.pmon-list{margin:0 0 8px 18px;padding:0}
.pmon-det summary{cursor:pointer;color:#1d4ed8}
.pmon-det ul{margin:4px 0 0 14px;padding:0}
.pmon-det code{white-space:pre-wrap;word-break:break-word;font-size:11px;background:#f9fafb;color:#111;display:block;padding:3px 5px;border:1px solid #e5e7eb}
</style>';
    }
}
