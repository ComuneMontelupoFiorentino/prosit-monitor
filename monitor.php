<?php
/**
 * Prosit monitor - controllo degli script di /usr/local/bin/prosit, di Lizmap e pulizia dei log.
 *
 *   php monitor.php                 controlla e scrive lo stato (JSON) letto dal cruscotto
 *   php monitor.php --pulizia       comprime/cancella/ruota i log secondo monitor.ini
 *   php monitor.php --pulizia --prova   mostra cosa farebbe la pulizia, senza toccare niente
 *   php monitor.php --pulizia --dettagli elenca ogni file trattato (altrimenti solo il riepilogo)
 *   php monitor.php --stampa        controlla e stampa il riepilogo a video
 *   php monitor.php --azzera mail   gli errori di "mail" fino ad ora non contano piu' (id = sezione di monitor.ini;
 *                                   piu' id separati da virgola, oppure "tutti")
 *
 * Gira come prosit2 dal cron (vedi README.md). Non modifica niente fuori dai log configurati.
 */
declare(strict_types=1);

const VERSIONE = '1.0.2';

$opt = getopt('', ['pulizia', 'prova', 'stampa', 'dettagli', 'config:']);
$cfgFile = $opt['config'] ?? __DIR__ . '/monitor.ini';
$ini = @parse_ini_file($cfgFile, true, INI_SCANNER_RAW);
if (!is_array($ini) || !isset($ini['generale'])) {
    fwrite(STDERR, "Configurazione non leggibile: $cfgFile\n");
    exit(2);
}
$G = $ini['generale'];
unset($ini['generale']);
// fuso con cui gli script PHP scrivono i log (quello della PHP da riga di comando), prima di cambiarlo
$GLOBALS['TZ_LOG'] = new DateTimeZone(ini_get('date.timezone') ?: 'UTC');
$tz = sistemaTz();
date_default_timezone_set($tz);
$now = time();
$base = rtrim(v($G, 'base', '/usr/local/bin/prosit'), '/');

if (isset($opt['pulizia'])) {
    exit(pulizia($G, $ini, $base, isset($opt['prova']), isset($opt['prova']) || isset($opt['dettagli'])));
}

// ------------------------------------------------------------------ controllo
$finestra = (int) v($G, 'finestra_ore', '24') * 3600;
$reErr = v($G, 'errori', '/\[(ERROR|CRITICAL|FATAL)\]|\b(ERROR|ERRORE|CRITICAL)\b|Fatal error|Uncaught |Traceback \(most recent|PHP (Fatal|Parse) error/');
$reWarn = v($G, 'avvisi', '/\[(WARN|WARNING)\]|\b(WARN|WARNING)\b|PHP Warning/');
$crontab = leggiCrontab();
// da quando ogni riga del crontab e' presente e da quando la macchina e' accesa:
// un orario precedente non conta come "non ha girato"
$fileVisti = dirname(v($G, 'stato', '/srv/data/prosit/monitor/stato.json')) . '/cron_visti.json';
$GLOBALS['cronVisti'] = json_decode((string) @file_get_contents($fileVisti), true) ?: [];
$GLOBALS['avvio'] = ($u = @file_get_contents('/proc/uptime')) ? $now - (int) $u : 0;
$fileAzz = dirname(v($G, 'stato', '/srv/data/prosit/monitor/stato.json')) . '/azzerati.json';
$GLOBALS['azzerati'] = json_decode((string) @file_get_contents($fileAzz), true) ?: [];
if (($i = array_search('--azzera', $argv, true)) !== false) {
    $chi = $argv[$i + 1] ?? '';
    $ids = $chi === 'tutti' ? array_keys($ini) : array_filter(explode(',', $chi), fn($x) => isset($ini[$x]));
    if (!$ids) { fwrite(STDERR, "Uso: monitor.php --azzera <id>[,<id>...]|tutti   id: " . implode(' ', array_keys($ini)) . "\n"); exit(1); }
    foreach ($ids as $x) { $GLOBALS['azzerati'][$x] = $now; echo "azzerato: $x (" . v($ini[$x], 'nome', $x) . ")\n"; }
    file_put_contents($fileAzz, json_encode($GLOBALS['azzerati'], JSON_PRETTY_PRINT));
}
$checks = [];
foreach ($ini as $id => $c) {
    $checks[] = controllaScript($id, $c, $base, $now, $finestra, $reErr, $reWarn, $crontab);
}
if (!in_array('--prova', $argv, true)) { @file_put_contents($fileVisti, json_encode($GLOBALS['cronVisti'], JSON_PRETTY_PRINT)); }
usort($checks, fn($a, $b) => [peso($b['stato']), $a['nome']] <=> [peso($a['stato']), $b['nome']]);

$stato = [
    'versione' => VERSIONE,
    'generato' => date('c', $now),
    'generato_ts' => $now,
    'fuso' => $tz,
    'host' => gethostname(),
    'php' => PHP_VERSION,
    'finestra_ore' => $finestra / 3600,
    'script' => $checks,
    'lizmap' => controllaLizmap($G, $now, $finestra),
    'dischi' => controllaDischi($G),
    'crontab_righe' => count($crontab),
    'pulizia' => json_decode((string) @file_get_contents(dirname(v($G, 'stato', '/srv/data/prosit/monitor/stato.json')) . '/pulizia.json'), true),
];
$cont = ['ok' => 0, 'avviso' => 0, 'errore' => 0];
foreach ($checks as $c) { $cont[$c['stato']]++; }
$cont[$stato['lizmap']['stato']]++;
foreach ($stato['dischi'] as $d) { $cont[$d['stato']]++; }
$stato['sintesi'] = $cont;

$out = v($G, 'stato', '/srv/data/prosit/monitor/stato.json');
@mkdir(dirname($out), 0755, true);
$tmp = $out . '.tmp' . getmypid();
file_put_contents($tmp, json_encode($stato, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
@chmod($tmp, 0644);
rename($tmp, $out);

// una riga per esecuzione nel log del cron: serve anche al monitor per controllare se stesso
printf("%s controllo eseguito: %d ok, %d avvisi, %d da verificare\n", date('Y-m-d H:i:s', $now), $cont['ok'], $cont['avviso'], $cont['errore']);
if (isset($opt['stampa'])) {
    printf("Stato %s  (ok %d, avvisi %d, errori %d)\n", date('Y-m-d H:i', $now), $cont['ok'], $cont['avviso'], $cont['errore']);
    foreach ($checks as $c) {
        printf("  %-7s %-34s %s\n", strtoupper($c['stato']), $c['nome'], implode('; ', $c['motivi']) ?: 'ok');
    }
    $l = $stato['lizmap'];
    printf("  %-7s %-34s %s\n", strtoupper($l['stato']), 'Lizmap', implode('; ', $l['motivi']) ?: 'ok');
    foreach ($stato['dischi'] as $d) {
        printf("  %-7s %-34s %d%% usato\n", strtoupper($d['stato']), 'Disco ' . $d['percorso'], $d['usato_pct']);
    }
    echo "Scritto: $out\n";
}
exit(0);

// ================================================================== funzioni

function v(array $a, string $k, string $d = ''): string { return isset($a[$k]) && $a[$k] !== '' ? (string) $a[$k] : $d; }
function peso(string $s): int { return ['errore' => 2, 'avviso' => 1, 'ok' => 0][$s] ?? 0; }

function sistemaTz(): string
{
    $t = @trim((string) @file_get_contents('/etc/timezone'));
    if ($t !== '' && in_array($t, timezone_identifiers_list(), true)) { return $t; }
    $l = @readlink('/etc/localtime');
    if ($l && preg_match('#zoneinfo/(.+)$#', $l, $m) && in_array($m[1], timezone_identifiers_list(), true)) { return $m[1]; }
    return 'UTC';
}

/** Percorsi dei log di un controllo (globs relativi a $base). */
function percorsi(array $c, string $base, bool $ancheGz = false): array
{
    $files = [];
    foreach ((array) ($c['log'] ?? []) as $p) {
        $p = $p[0] === '/' ? $p : "$base/$p";
        $pats = $ancheGz ? [$p, "$p.gz", "$p.*.gz"] : [$p];
        foreach ($pats as $pp) {
            foreach (glob($pp, GLOB_BRACE | GLOB_NOSORT) ?: [] as $f) {
                if (is_file($f) && !is_link($f) && ($ancheGz || !str_ends_with($f, '.gz'))) { $files[$f] = true; }
            }
        }
    }
    return array_keys($files);
}

/** Toglie password, chiavi e token da una riga prima di mostrarla. */
function maschera(string $s): string
{
    $s = preg_replace('/((?:api[_-]?key|apikey|password|passwd|pwd|secret|token|authorization|key)\s*["\']?\s*[:=]\s*["\']?)([^"\'&\s,;}]+)/i', '$1***', $s) ?? $s;
    $s = preg_replace('#(://[^:/@\s]+:)[^@\s]+@#', '$1***@', $s) ?? $s;
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? $s;
    return mb_strlen($s) > 300 ? mb_substr($s, 0, 300) . ' …' : $s;
}

/** Data e ora all'inizio della riga, nei formati usati dagli script. */
function tsRiga(string $l, ?DateTimeZone $tz = null): ?int
{
    if (preg_match('/^\[?(\d{4}-\d{2}-\d{2})[ T_](\d{2})[:\-](\d{2})[:\-](\d{2})/', $l, $m)) {
        $d = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', "{$m[1]} {$m[2]}:{$m[3]}:{$m[4]}", $tz ?? $GLOBALS['TZ_LOG']);
        return $d ? $d->getTimestamp() : null;
    }
    return null;
}

/** Legge al massimo gli ultimi $max byte di un file. */
function coda(string $f, int $max = 2_000_000): array
{
    $h = @fopen($f, 'rb');
    if (!$h) { return []; }
    $size = filesize($f) ?: 0;
    if ($size > $max) { fseek($h, $size - $max); fgets($h); }
    $out = [];
    while (($l = fgets($h)) !== false) { $out[] = rtrim($l, "\r\n"); }
    fclose($h);
    return $out;
}

function normalizza(string $s): string
{
    $s = preg_replace('/^\[?\d{4}-\d{2}-\d{2}[ T_][\d:\-]{8}\]?\s*/', '', $s) ?? $s;
    // identificativi che cambiano a ogni richiesta (X-Request-Id, uuid, hash): non distinguono gli errori
    $s = preg_replace('/X-Request-Id\s*`[^`]*`|\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b|\b[0-9a-f]{16,}\b/i', '<id>', $s) ?? $s;
    return trim(preg_replace('/\d+/', '#', $s) ?? $s);
}

function controllaScript(string $id, array $c, string $base, int $now, int $finestra, string $reErr, string $reWarn, array $crontab): array
{
    $r = ['id' => $id, 'nome' => v($c, 'nome', $id), 'stato' => 'ok', 'motivi' => [], 'ultima_attivita' => null,
        'file_recenti' => 0, 'errori' => 0, 'avvisi' => 0, 'ultimi_errori' => [], 'cron' => [], 'previsto' => null];
    $reErr = v($c, 'errori', $reErr);
    $reIgn = v($c, 'ignora', '');
    $tzc = null;
    try { if (v($c, 'fuso') !== '') { $tzc = new DateTimeZone(v($c, 'fuso')); } } catch (Exception) {}
    $files = percorsi($c, $base);
    $last = 0;
    foreach ($files as $f) { $last = max($last, (int) filemtime($f)); }
    $r['ultima_attivita'] = $last ?: null;

    // errori e avvisi nella finestra
    $cut = $now - $finestra;
    // "azzerato" a mano (monitor.php --azzera <id>): gli errori fino a quel momento non contano piu'
    $azz = (int) ($GLOBALS['azzerati'][$id] ?? 0);
    if ($azz > $cut) { $cut = $azz; }
    $err = [];
    $ultimoErr = 0;
    foreach ($files as $f) {
        if (filemtime($f) < $cut) { continue; }
        $r['file_recenti']++;
        $ts = null;
        foreach (coda($f) as $l) {
            $t = tsRiga($l, $tzc);
            if ($t !== null) { $ts = $t; }
            if ($ts !== null && $ts < $cut) { continue; }
            if ($l === '' || ($reIgn !== '' && @preg_match($reIgn, $l))) { continue; }
            if (@preg_match($reErr, $l)) {
                $r['errori']++;
                $ultimoErr = max($ultimoErr, $ts ?? (int) filemtime($f));
                $k = normalizza($l);
                $err[$k] = ['quando' => $ts ?? filemtime($f), 'file' => basename($f), 'testo' => maschera($l), 'volte' => ($err[$k]['volte'] ?? 0) + 1];
            } elseif (@preg_match($reWarn, $l)) {
                $r['avvisi']++;
            }
        }
    }
    uasort($err, fn($a, $b) => $b['quando'] <=> $a['quando']);
    $r['ultimi_errori'] = array_values(array_slice($err, 0, 6));

    // pianificazione: ultimo orario previsto dal crontab
    $match = v($c, 'cron', '');
    $manuale = filter_var(v($c, 'manuale', '0'), FILTER_VALIDATE_BOOLEAN);
    $prev = null;
    $prevReale = null;   // ultimo orario previsto, anche se precedente all'aggiunta della riga nel crontab
    if ($match !== '') {
        foreach ($crontab as $riga) {
            if (!str_contains($riga['comando'], $match)) { continue; }
            $r['cron'][] = $riga['orario'];
            $k = md5($riga['orario'] . ' ' . $riga['comando']);
            $GLOBALS['cronVisti'][$k] ??= $now;
            $p = cronPrecedente($riga['orario'], $now, $riga['tz']);
            if ($p !== null && $p >= $GLOBALS['avvio'] && ($prevReale === null || $p > $prevReale)) { $prevReale = $p; }
            if ($p !== null && ($p < $GLOBALS['cronVisti'][$k] || $p < $GLOBALS['avvio'])) {
                $r['in_attesa'] = true;   // riga aggiunta (o macchina accesa) dopo quell'orario
                continue;
            }
            if ($p !== null && ($prev === null || $p > $prev)) { $prev = $p; }
        }
    }
    $r['previsto'] = $prev;
    $grazia = (int) v($c, 'grazia_minuti', '20') * 60;
    if ($files === []) {
        $r['motivi'][] = 'nessun file di log trovato';
        $r['stato'] = $manuale ? 'ok' : 'avviso';
    }
    if (!$manuale && $match !== '' && $r['cron'] === []) {
        $r['motivi'][] = 'non pianificato nel crontab';
        $r['stato'] = 'avviso';
    }
    if ($prev !== null && $now - $prev > $grazia && $last < $prev - 120) {
        $r['motivi'][] = 'non ha girato alle ' . date('d/m H:i', $prev) . ($last ? ' (ultima attività ' . date('d/m H:i', $last) . ')' : '');
        $r['stato'] = 'errore';
    }
    $maxOre = (int) v($c, 'max_ore', '0');
    if ($maxOre > 0 && $last && $now - $last > $maxOre * 3600) {
        $r['motivi'][] = "nessuna attività da più di $maxOre ore";
        $r['stato'] = 'errore';
    }
    // risolto: l'ultimo giro previsto e' partito dopo l'ultimo errore, ha lasciato traccia e non ha dato errori
    $risolto = $r['errori'] > 0 && $prevReale !== null && $ultimoErr < $prevReale && $last >= $prevReale;
    if ($risolto) {
        $r['risolto'] = true;
        $r['motivi'][] = 'risolto: ' . $r['errori'] . ' righe di errore fino alle ' . date('d/m H:i', $ultimoErr) . ', poi regolare';
    } elseif ($r['errori'] > 0) {
        $r['motivi'][] = $r['errori'] . ' righe di errore nelle ultime ' . ($finestra / 3600) . ' ore';
        $r['stato'] = 'errore';
    } elseif ($r['avvisi'] > 0 && $r['stato'] === 'ok') {
        $r['motivi'][] = $r['avvisi'] . ' avvisi';
        $r['stato'] = 'avviso';
    }
    return $r;
}

/** Righe attive del crontab dell'utente: [orario, comando, tz]. */
function leggiCrontab(): array
{
    // crontab dell'utente (prosit2) + crontab di sistema (/etc/crontab, /etc/cron.d), che hanno il campo utente
    $fonti = [[(string) @shell_exec('crontab -l 2>/dev/null'), false]];
    foreach (array_merge(['/etc/crontab'], glob('/etc/cron.d/*') ?: []) as $f) {
        if (is_file($f) && is_readable($f)) { $fonti[] = [(string) file_get_contents($f), true]; }
    }
    $out = [];
    foreach ($fonti as [$txt, $conUtente]) { $out = array_merge($out, righeCron($txt, $conUtente)); }
    return $out;
}

function righeCron(string $txt, bool $conUtente): array
{
    $tz = null;
    $out = [];
    foreach (preg_split('/\R/', $txt) as $l) {
        $l = trim($l);
        if ($l === '' || $l[0] === '#') { continue; }
        if (preg_match('/^(CRON_TZ|TZ)\s*=\s*(\S+)/', $l, $m)) { $tz = $m[2]; continue; }
        if (preg_match('/^[A-Za-z_]+\s*=/', $l)) { continue; }
        if ($conUtente) {                              // togli il campo utente: "m h dom mon dow utente comando"
            $l = preg_match('/^(@\w+)\s+\S+\s+(.*)$/', $l, $m) ? "{$m[1]} {$m[2]}"
                : (preg_replace('/^((?:\S+\s+){5})\S+\s+/', '$1', $l) ?? $l);
        }
        if (preg_match('/^(@\w+)\s+(.*)$/', $l, $m)) {
            $map = ['@yearly' => '0 0 1 1 *', '@annually' => '0 0 1 1 *', '@monthly' => '0 0 1 * *', '@weekly' => '0 0 * * 0',
                '@daily' => '0 0 * * *', '@midnight' => '0 0 * * *', '@hourly' => '0 * * * *'];
            if (isset($map[$m[1]])) { $out[] = ['orario' => $map[$m[1]], 'comando' => $m[2], 'tz' => $tz]; }
            continue;
        }
        $p = preg_split('/\s+/', $l, 6);
        if (count($p) === 6) { $out[] = ['orario' => implode(' ', array_slice($p, 0, 5)), 'comando' => $p[5], 'tz' => $tz]; }
    }
    return $out;
}

function cronCampo(string $f, int $min, int $max, array $nomi = []): ?array
{
    $f = strtolower($f);
    foreach ($nomi as $i => $n) { $f = str_replace($n, (string) ($i + $min), $f); }
    $val = [];
    foreach (explode(',', $f) as $parte) {
        $step = 1;
        if (str_contains($parte, '/')) { [$parte, $s] = explode('/', $parte, 2); $step = max(1, (int) $s); }
        if ($parte === '*') { $a = $min; $b = $max; }
        elseif (str_contains($parte, '-')) { [$a, $b] = array_map('intval', explode('-', $parte, 2)); }
        elseif (is_numeric($parte)) { $a = (int) $parte; $b = str_contains($f, '/') ? $max : $a; }
        else { return null; }
        for ($i = $a; $i <= $b; $i += $step) { $val[$i] = true; }
    }
    return $val;
}

/** Ultimo istante (<= $now) in cui l'espressione cron sarebbe scattata. */
function cronPrecedente(string $expr, int $now, ?string $tz): ?int
{
    $p = preg_split('/\s+/', trim($expr));
    if (count($p) !== 5) { return null; }
    $mi = cronCampo($p[0], 0, 59); $h = cronCampo($p[1], 0, 23); $dom = cronCampo($p[2], 1, 31);
    $mon = cronCampo($p[3], 1, 12, ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec']);
    $dow = cronCampo($p[4], 0, 7, ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat']);
    if (!$mi || !$h || !$dom || !$mon || !$dow) { return null; }
    if (isset($dow[7])) { $dow[0] = true; }
    $domStar = $p[2] === '*'; $dowStar = $p[4] === '*';
    try { $zone = new DateTimeZone($tz ?: date_default_timezone_get()); } catch (Exception) { $zone = new DateTimeZone('UTC'); }
    $d = (new DateTimeImmutable('@' . ($now - $now % 60)))->setTimezone($zone);
    for ($i = 0; $i < 400 * 1440; $i++) {
        $M = (int) $d->format('n'); $D = (int) $d->format('j'); $W = (int) $d->format('w');
        if (!isset($mon[$M])) { $d = $d->setTime(0, 0)->modify('-1 minute'); continue; }
        $giorno = ($domStar || $dowStar) ? (isset($dom[$D]) && isset($dow[$W])) : (isset($dom[$D]) || isset($dow[$W]));
        if (!$giorno) { $d = $d->setTime(0, 0)->modify('-1 minute'); continue; }
        if (!isset($h[(int) $d->format('G')])) { $d = $d->setTime((int) $d->format('G'), 0)->modify('-1 minute'); continue; }
        if (isset($mi[(int) $d->format('i')])) { return $d->getTimestamp(); }
        $d = $d->modify('-1 minute');
    }
    return null;
}

function controllaLizmap(array $G, int $now, int $finestra): array
{
    $r = ['stato' => 'ok', 'motivi' => [], 'versione' => null, 'raggiungibile' => null, 'qgis_server' => null,
        'errori' => 0, 'avvisi' => 0, 'notice' => 0, 'ultimi_errori' => [], 'progetti_avvisi' => 0];
    // pagina iniziale e non /view/app/metadata: chiamata senza utente, metadata scrive
    // "Rejecting Lizmap metadata access" in lizmap-admin.log a ogni controllo
    $url = v($G, 'url_lizmap', 'http://127.0.0.1/lizmap/index.php');
    $url = preg_replace('#/view/app/metadata/?$#', '', $url) ?? $url;
    [$code] = http($url);
    $r['raggiungibile'] = $code;
    if ($code < 200 || $code >= 400) {
        $r['motivi'][] = "Lizmap non risponde (HTTP $code)";
        $r['stato'] = 'errore';
    }
    $lz = rtrim(v($G, 'lizmap', preg_replace('#/www$#', '', (string) realpath('/var/www/html/lizmap')) ?: '/var/www/lizmap-web-client/lizmap'), '/');
    // versione letta dal file del pacchetto installato
    $px = @file_get_contents("$lz/project.xml");
    if ($px && preg_match('#<version[^>]*>([^<]+)</version>#', $px, $m)) { $r['versione'] = trim($m[1]); }
    // QGIS Server: l'indirizzo e' quello configurato in Lizmap
    $wms = null;
    $cfg = @parse_ini_file("$lz/var/config/lizmapConfig.ini.php", true, INI_SCANNER_RAW);
    if (is_array($cfg)) { $wms = $cfg['services']['wmsServerURL'] ?? null; }
    if ($wms) {
        // stato del server tramite il plugin Lizmap Server (lo stesso controllo che fa Lizmap):
        // una GetCapabilities senza progetto risponderebbe 400 e sporcherebbe il registro di QGIS Server
        $u = parse_url($wms);
        $srv = ($u['scheme'] ?? 'http') . '://' . ($u['host'] ?? '127.0.0.1') . (isset($u['port']) ? ':' . $u['port'] : '');
        $base = preg_replace('#/ows/?$|/qgis_mapserv\.fcgi$#', '', rtrim($u['path'] ?? '', '/')) ?? '';
        [$qc] = http($srv . $base . '/lizmap/server.json', 15);
        if ($qc === 404) { [$qc] = http($srv . rtrim($u['path'] ?? '', '/') . '/lizmap/server.json', 15); }
        $r['qgis_server'] = $qc;
        if ($qc === 0 || $qc >= 500) { $r['motivi'][] = "QGIS Server non risponde (HTTP $qc)"; $r['stato'] = 'errore'; }
    }
    // errors.log di Lizmap: data<TAB>ip<TAB>tipo<TAB>messaggio, scritta nel fuso di Lizmap (timeZone)
    $tzLz = new DateTimeZone('Europe/Paris');
    foreach (["$lz/app/system/mainconfig.ini.php", "$lz/var/config/mainconfig.ini.php", "$lz/var/config/localconfig.ini.php"] as $cf) {
        $c = @parse_ini_file($cf, false, INI_SCANNER_RAW);
        if (is_array($c) && !empty($c['timeZone'])) { try { $tzLz = new DateTimeZone(trim($c['timeZone'], '"')); } catch (Exception) {} }
    }
    $cut = $now - $finestra;
    $err = [];
    foreach (['errors.log'] as $nome) {
        $f = "$lz/var/log/$nome";
        if (!is_readable($f) || filemtime($f) < $cut) { continue; }
        $ts = null; $tipo = null;
        foreach (coda($f) as $l) {
            $p = explode("\t", $l, 4);
            if (count($p) < 3 || ($t = tsRiga($p[0], $tzLz)) === null) { continue; }
            $ts = $t;
            if (preg_match('/^\[(\d+)\]$/', trim($p[1]), $mm)) {
                // errore PHP: data<TAB>[codice]<TAB>messaggio<TAB>file<TAB>riga
                $msg = $p[2] . (isset($p[3]) ? ' (' . basename(strtok($p[3], "\t")) . ')' : '');
                $codice = (int) $mm[1];
                $tipo = in_array($codice, [1, 4, 16, 64, 256], true) ? 'error' : (in_array($codice, [2, 32, 128, 512], true) ? 'warning' : 'notice');
                // query fallite e simili sono "warning" per PHP ma errori veri per noi
                if (preg_match('/Query failed|Unable to prepare|Uncaught|Fatal|Allowed memory/i', $p[2])) { $tipo = 'error'; }
            } elseif (count($p) === 4) {
                $tipo = strtolower(trim($p[2])); $msg = $p[3];
            } else { continue; }
            if ($ts < $cut) { continue; }
            if ($tipo === 'error' || $tipo === 'strict') {
                $r['errori']++;
                $k = normalizza($msg);
                $err[$k] = ['quando' => $ts, 'tipo' => $tipo, 'testo' => maschera($msg), 'volte' => ($err[$k]['volte'] ?? 0) + 1];
            } elseif ($tipo === 'warning') { $r['avvisi']++; }
            else { $r['notice']++; }
        }
    }
    uasort($err, fn($a, $b) => [$b['volte'], $b['quando']] <=> [$a['volte'], $a['quando']]);
    $r['ultimi_errori'] = array_values(array_slice($err, 0, 6));
    $fa = "$lz/var/log/lizmap-admin.log";
    if (is_readable($fa) && filemtime($fa) >= $cut) {
        foreach (coda($fa, 500_000) as $l) { if (strpos($l, 'Rejecting Lizmap metadata access') !== false) { continue; } $t = tsRiga($l, $tzLz); if ($t !== null && $t >= $cut) { $r['progetti_avvisi']++; } }
    }
    if ($r['errori'] > 0) {
        $r['motivi'][] = $r['errori'] . ' errori PHP/Lizmap nelle ultime ' . ($finestra / 3600) . ' ore';
        if ($r['stato'] === 'ok') { $r['stato'] = 'avviso'; }
    }
    if ((int) v($G, 'lizmap_errori_allarme', '50') <= $r['errori']) { $r['stato'] = 'errore'; }
    // progetti che per qualche gruppo non completano l'avvio della mappa (difetto di Lizmap 3.9)
    $r['progetti_problemi'] = controllaProgetti($lz);
    if ($r['progetti_problemi']) {
        $r['motivi'][] = count($r['progetti_problemi']) . ' progetti/gruppi con mappa incompleta all\'avvio';
        $r['stato'] = 'errore';
    }
    return $r;
}

/**
 * Lizmap 3.9.12: se per un gruppo di utenti gli UNICI layer accesi all'apertura sono nascosti
 * dalla legenda ("Visualizza nell'albero dei livelli" non spuntato), la mappa non completa l'avvio:
 * mancano legenda, pulsanti di navigazione, localizzazione, popup... senza errori in console.
 * Controlla ogni .qgs.cfg di ogni repository, per ogni gruppo citato nel progetto.
 */
function controllaProgetti(string $lz): array
{
    $out = [];
    $cfg = @parse_ini_file("$lz/var/config/lizmapConfig.ini.php", true, INI_SCANNER_RAW);
    if (!is_array($cfg)) { return $out; }
    $vero = fn($x) => in_array(strtolower(trim((string) $x)), ['true', '1'], true);
    foreach ($cfg as $sez => $rep) {
        if (strpos($sez, 'repository:') !== 0 || empty($rep['path'])) { continue; }
        $repo = substr($sez, 11);
        foreach (glob(rtrim(trim($rep['path'], '"'), '/') . '/*.qgs.cfg') ?: [] as $f) {
            $c = json_decode((string) @file_get_contents($f), true);
            if (!is_array($c) || empty($c['layers'])) { continue; }
            $layers = $c['layers'];
            $gruppi = $c['options']['acl'] ?? [];
            if (!is_array($gruppi)) { $gruppi = []; }
            foreach ($layers as $l) { foreach ((array) ($l['group_visibility'] ?? []) as $g) { $gruppi[] = trim((string) $g); } }
            $gruppi = array_values(array_unique(array_filter($gruppi)));
            $gruppi[] = '';   // utente senza gruppi particolari
            foreach ($gruppi as $g) {
                $accesi = []; $inLegenda = 0;
                foreach ($layers as $nome => $l) {
                    if (($l['type'] ?? '') !== 'layer' || $vero($l['baseLayer'] ?? 'False') || !$vero($l['toggled'] ?? 'False')) { continue; }
                    $gv = array_filter(array_map('trim', (array) ($l['group_visibility'] ?? [])));
                    if ($gv && !in_array($g, $gv, true)) { continue; }
                    $accesi[] = $nome;
                    if (!array_key_exists('displayInLegend', $l) || $vero($l['displayInLegend'])) { $inLegenda++; }
                }
                if ($accesi && $inLegenda === 0) {
                    $out[] = ['progetto' => "$repo/" . basename($f, '.qgs.cfg'), 'gruppo' => $g === '' ? '(utenti senza gruppo)' : $g,
                        'layer' => $accesi];
                }
            }
        }
    }
    return $out;
}

function controllaDischi(array $G): array
{
    $out = [];
    $soglia = (int) v($G, 'disco_allarme_pct', '90');
    foreach ((array) ($G['dischi'] ?? ['/']) as $p) {
        $tot = @disk_total_space($p); $free = @disk_free_space($p);
        if (!$tot) { continue; }
        $pct = (int) round(100 * ($tot - $free) / $tot);
        $out[] = ['percorso' => $p, 'usato_pct' => $pct, 'libero_gb' => round($free / 1e9, 1),
            'stato' => $pct >= $soglia ? 'errore' : ($pct >= $soglia - 10 ? 'avviso' : 'ok')];
    }
    return $out;
}

function http(string $url, int $timeout = 20): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => false]);
    $b = curl_exec($ch);
    $c = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return [$c, $b === false ? null : $b];
}

// ------------------------------------------------------------------ pulizia dei log

function pulizia(array $G, array $checks, string $base, bool $prova, bool $dett): int
{
    $now = time();
    $giorni = (int) v($G, 'conserva_giorni', '90');
    $compr = (int) v($G, 'comprimi_dopo_giorni', '7');
    $ruotaMb = (int) v($G, 'ruota_mb', '20');
    $ruotaGg = (int) v($G, 'ruota_giorni', '30');
    $statoRot = dirname(v($G, 'stato', '/srv/data/prosit/monitor/stato.json')) . '/rotazioni.json';
    $rot = is_readable($statoRot) ? (json_decode((string) file_get_contents($statoRot), true) ?: []) : [];
    $gruppi = $checks;
    $gruppi['_extra'] = ['log' => (array) ($G['pulizia_extra'] ?? [])];
    $tot = ['cancellati' => 0, 'compressi' => 0, 'ruotati' => 0, 'byte_liberati' => 0, 'non_riusciti' => 0];
    $perGruppo = [];
    $say = function (string $id, string $azione, string $msg) use ($dett, &$perGruppo) {
        $perGruppo[$id][$azione] = ($perGruppo[$id][$azione] ?? 0) + 1;
        if ($dett || $azione === 'NON RIUSCITO') { echo "  $azione  $msg\n"; }
    };
    $dirs = [];
    echo ($prova ? "PROVA - " : '') . "Pulizia log: conserva $giorni giorni, comprime dopo $compr, ruota i file unici ogni $ruotaGg giorni o oltre $ruotaMb MB\n";
    foreach ($gruppi as $id => $c) {
        if (filter_var(v($c, 'non_pulire', '0'), FILTER_VALIDATE_BOOLEAN)) { continue; }
        foreach (percorsi($c, $base, true) as $f) {
            $rel = str_starts_with($f, "$base/") ? substr($f, strlen($base) + 1) : $f;
            $datato = (bool) preg_match('#\d{4}[-_/]?\d{2}#', $rel);
            $eta = ($now - filemtime($f)) / 86400;
            $gz = str_ends_with($f, '.gz');
            if ($eta > $giorni && ($gz || $datato)) {
                $sz = (int) filesize($f);
                if ($prova || @unlink($f)) {
                    $say($id, 'cancella', "$rel (" . (int) $eta . ' giorni)');
                    $tot['cancellati']++; $tot['byte_liberati'] += $sz; $dirs[dirname($f)] = true;
                } else { $tot['non_riusciti']++; $say($id, 'NON RIUSCITO', "cancellare $rel (permessi?)"); }
                continue;
            }
            if ($gz) { continue; }
            if ($datato && $eta > $compr) {
                if ($prova) { $say($id, 'comprimi', $rel); $tot['compressi']++; continue; }
                if (gzipFile($f, "$f.gz")) {
                    $diff = max(0, (int) filesize($f) - (int) filesize("$f.gz"));
                    if (@unlink($f)) { $tot['compressi']++; $tot['byte_liberati'] += $diff; $say($id, 'comprimi', $rel); }
                    else { @unlink("$f.gz"); $tot['non_riusciti']++; $say($id, 'NON RIUSCITO', "comprimere $rel (permessi?)"); }
                } else { $tot['non_riusciti']++; $say($id, 'NON RIUSCITO', "comprimere $rel (permessi?)"); }
                continue;
            }
            if (!$datato) {
                $size = filesize($f);
                $ultima = $rot[$f] ?? null;
                if ($ultima === null && !$prova) { $rot[$f] = $now; }
                $scaduto = $ultima !== null && ($now - $ultima) > $ruotaGg * 86400;
                if ($size > 0 && ($size > $ruotaMb * 1048576 || $scaduto)) {
                    $dest = $f . '.' . date('Ymd-His', $now) . '.gz';
                    $msg = "$rel (" . round($size / 1048576, 1) . ' MB) -> ' . basename($dest);
                    if ($prova) { $say($id, 'ruota', $msg); $tot['ruotati']++; continue; }
                    $h = @fopen($f, 'r+');               // copia e svuota: lo script continua a scrivere sullo stesso file
                    if ($h && gzipFile($f, $dest)) {
                        ftruncate($h, 0); fclose($h);
                        $rot[$f] = $now; $tot['ruotati']++; $tot['byte_liberati'] += max(0, $size - (int) filesize($dest)); $say($id, 'ruota', $msg);
                    } else { if ($h) { fclose($h); } $tot['non_riusciti']++; $say($id, 'NON RIUSCITO', "ruotare $rel (permessi?)"); }
                }
            }
        }
    }
    if (!$prova) {
        foreach (array_keys($dirs) as $d) {             // cartelle datate rimaste vuote (es. logs/2026/07/14)
            while (preg_match('#/\d{2,4}$#', $d) && is_dir($d) && count(scandir($d)) === 2) { @rmdir($d); $d = dirname($d); }
        }
        @mkdir(dirname($statoRot), 0755, true);
        file_put_contents($statoRot, json_encode($rot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $tot['quando'] = $now;
        $tot['conserva_giorni'] = $giorni;
        file_put_contents(dirname($statoRot) . '/pulizia.json', json_encode($tot));
        @chmod(dirname($statoRot) . '/pulizia.json', 0644);
    }
    foreach ($perGruppo as $id => $a) {
        $parti = [];
        foreach ($a as $k => $n) { $parti[] = "$n $k"; }
        printf("  %-14s %s\n", $id === '_extra' ? 'altri log' : $id, implode(', ', $parti));
    }
    printf("%s%s - %d cancellati, %d compressi, %d ruotati, %d non riusciti, %.1f MB liberati\n", $prova ? 'PROVA - ' : '',
        date('Y-m-d H:i'), $tot['cancellati'], $tot['compressi'], $tot['ruotati'], $tot['non_riusciti'], $tot['byte_liberati'] / 1048576);
    return 0;
}

function gzipFile(string $src, string $dst): bool
{
    $in = @fopen($src, 'rb'); $out = @gzopen($dst, 'wb6');
    if (!$in || !$out) { return false; }
    while (!feof($in)) { gzwrite($out, (string) fread($in, 1 << 20)); }
    fclose($in); gzclose($out);
    @touch($dst, (int) filemtime($src));
    return true;
}
