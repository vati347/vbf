<?php
declare(strict_types=1);

// ====== KONFIG ======
$TO   = 'info@vbfplus.hu';
$SITE = 'vbfplus.hu';
$FROM = 'noreply@vbfplus.hu';

$MAX_LEN_NAME  = 80;
$MAX_LEN_EMAIL = 120;
$MAX_LEN_PHONE = 40;
$MAX_LEN_MSG   = 3000;

$DEV = false; // élesben: false

$ALLOWED_HOSTS = ['vbfplus.hu', 'www.vbfplus.hu']; // Origin/Referer ellenőrzés

// ✅ DEV: engedjük a lokális hostokat + az aktuális hostot (port nélkül)
if ($DEV) {
    $ALLOWED_HOSTS[] = 'localhost';
    $ALLOWED_HOSTS[] = '127.0.0.1';
    $ALLOWED_HOSTS[] = '::1';

    // ha pl. 192.168.x.x:8000-ról tesztelsz, ezt is automatikusan engedjük
    $hh = (string)($_SERVER['HTTP_HOST'] ?? '');
    $hh = preg_replace('/:\d+$/', '', $hh) ?? '';
    $hh = mb_strtolower($hh);
    if ($hh !== '') $ALLOWED_HOSTS[] = $hh;

    // duplikációk kiszűrése
    $ALLOWED_HOSTS = array_values(array_unique(array_map('mb_strtolower', $ALLOWED_HOSTS)));
}

$RL_MAX = 5;           // max 5 kérés
$RL_WINDOW_SEC = 300;  // 5 perc

$MAX_URLS_IN_MESSAGE = 5;

// ====== MINIMÁL MONITOR / DEBUG ======
$MONITOR_ENABLED = true;

// ⚠️ Élesben ezt tedd false-ra (PII log!)
$MONITOR_LOG_PII = false;

// ====== LOG ROTÁLÁS / RETENTION ======
$MONITOR_DAILY_ROTATE   = true; // monitor-YYYYMMDD.log
$MONITOR_RETENTION_DAYS = 14;   // ennyi napot tartunk meg (0 = nincs törlés)
$MONITOR_CLEANUP_PROB   = 0.02; // 2% eséllyel fut cleanup requestenként

// ====== KÖRNYEZET (hibák) ======
if ($DEV) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// ====== SEGÉD ======
function clean_line(string $s): string {
    $s = str_replace(["\r", "\n", "\0"], ' ', $s);
    return trim($s);
}

function normalize_newlines(string $s): string {
    $s = str_replace("\0", '', $s);
    $s = preg_replace("/\r\n?|\n/u", "\n", $s);
    return $s ?? '';
}

function encode_subject(string $s): string {
    $s = clean_line($s);
    if (function_exists('mb_encode_mimeheader')) {
        return mb_encode_mimeheader($s, 'UTF-8', 'B', "\r\n");
    }
    return $s;
}

function get_client_ip(): string {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return $ip !== '' ? $ip : 'unknown';
}

function ensure_dir_or_fail(string $dir, int $mode = 0700): void {
    if (is_dir($dir)) return;
    if (!mkdir($dir, $mode, true)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Szerver hiba (runtime könyvtár).');
    }
    @chmod($dir, $mode);
}

function pick_runtime_base_dir(): string {
    // /web/vbfplus.hu/contact.php esetén:
    // __DIR__ = /web/vbfplus.hu
    // dirname(__DIR__) = /web
    // dirname(dirname(__DIR__)) = (a /web és /tmp közös szülője)
    $root = dirname(dirname(__DIR__));
    $preferred = $root . DIRECTORY_SEPARATOR . 'tmp';

    if (is_dir($preferred) && is_writable($preferred)) return $preferred;

    $fallback = sys_get_temp_dir();
    if (is_dir($fallback) && is_writable($fallback)) return $fallback;

    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Szerver hiba (nincs írható temp).');
}

function make_request_id(): string {
    return bin2hex(random_bytes(16));
}

function assert_origin_allowed(array $allowedHosts): void {
    $origin  = (string)($_SERVER['HTTP_ORIGIN']  ?? '');
    $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');

    $host = '';
    if ($origin !== '') {
        $host = (string)(parse_url($origin, PHP_URL_HOST) ?? '');
    } elseif ($referer !== '') {
        $host = (string)(parse_url($referer, PHP_URL_HOST) ?? '');
    } else {
        // nincs Origin/Referer: nem tiltunk automatikusan
        return;
    }

    $host = mb_strtolower($host);
    if ($host === '' || !in_array($host, $allowedHosts, true)) {
        throw new RuntimeException('Tiltott forrás (origin/referer).');
    }
}

function rate_limit_or_throw(string $dir, string $key, int $max, int $windowSec): void {
    ensure_dir_or_fail($dir, 0700);

    $file = $dir . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    $now  = time();

    $fh = @fopen($file, 'c+');
    if (!$fh) throw new RuntimeException('Rate limit fájl hiba.');

    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        throw new RuntimeException('Rate limit lock hiba.');
    }

    $raw = stream_get_contents($fh);
    $data = ['ts' => []];

    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && isset($decoded['ts']) && is_array($decoded['ts'])) {
            $data = $decoded;
        }
    }

    $min = $now - $windowSec;
    $ts = [];
    foreach ($data['ts'] as $t) {
        if (is_int($t) && $t >= $min) $ts[] = $t;
    }

    if (count($ts) >= $max) {
        flock($fh, LOCK_UN);
        fclose($fh);
        throw new OverflowException('Rate limited');
    }

    $ts[] = $now;
    $data['ts'] = $ts;

    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($data, JSON_UNESCAPED_UNICODE));
    fflush($fh);

    flock($fh, LOCK_UN);
    fclose($fh);
}

function monitor_log(string $monitorFile, array $entry): void {
    $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($line)) return;
    @file_put_contents($monitorFile, $line . "\n", FILE_APPEND | LOCK_EX);
}

function cleanup_old_monitor_logs(string $dir, int $retentionDays): void {
    if ($retentionDays <= 0) return;
    if (!is_dir($dir)) return;

    $cutoff = time() - ($retentionDays * 86400);
    $it = @scandir($dir);
    if (!is_array($it)) return;

    foreach ($it as $fn) {
        if ($fn === '.' || $fn === '..') continue;
        if (!preg_match('/^monitor-(\d{8})\.log$/', $fn)) continue;

        $path = $dir . DIRECTORY_SEPARATOR . $fn;
        $mtime = @filemtime($path);
        if ($mtime !== false && $mtime < $cutoff) {
            @unlink($path);
        }
    }
}

function fail_public(
    int $code,
    string $publicMsg,
    string $requestId,
    bool $includeId
): never {
    http_response_code($code);
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, max-age=0');
    header('X-Request-ID: ' . $requestId);

    if ($includeId) {
        exit($publicMsg . ' (Hibaazonosító: ' . $requestId . ')');
    }
    exit($publicMsg);
}

// ====== CSAK POST ======
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Method Not Allowed');
}

// Alap hardening headerek
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, max-age=0');

// ====== RUNTIME DIR (Rackhost /tmp preferált) ======
$RUNTIME_BASE = pick_runtime_base_dir();
$APP_RUNTIME  = $RUNTIME_BASE . DIRECTORY_SEPARATOR . 'vbfplus_contact';
$RL_DIR       = $APP_RUNTIME . DIRECTORY_SEPARATOR . 'rl';
$DEVLOG_DIR   = $APP_RUNTIME . DIRECTORY_SEPARATOR . 'dev';
$MON_DIR      = $APP_RUNTIME . DIRECTORY_SEPARATOR . 'monitor';

ensure_dir_or_fail($APP_RUNTIME, 0700);
ensure_dir_or_fail($MON_DIR, 0700);

// ====== Monitor fájlnév (napi rotálás) ======
$today = date('Ymd');
if ($MONITOR_DAILY_ROTATE) {
    $MON_FILE = $MON_DIR . DIRECTORY_SEPARATOR . 'monitor-' . $today . '.log';
} else {
    $MON_FILE = $MON_DIR . DIRECTORY_SEPARATOR . 'monitor.log';
}

// Retention cleanup ritkán (ne terheljen)
if ($MONITOR_RETENTION_DAYS > 0) {
    $r = mt_rand() / mt_getrandmax();
    if ($r < $MONITOR_CLEANUP_PROB) {
        cleanup_old_monitor_logs($MON_DIR, $MONITOR_RETENTION_DAYS);
    }
}

// ====== REQUEST ID ======
$requestId = make_request_id();
header('X-Request-ID: ' . $requestId);

// ====== KÖRNYEZET ADATOK (monitorhoz) ======
$ip = get_client_ip();
$ua = clean_line((string)($_SERVER['HTTP_USER_AGENT'] ?? '-'));

$baseMon = [
    'ts'   => date('c'),
    'rid'  => $requestId,
    'ip'   => $ip,
    'ua'   => $ua,
    'path' => (string)($_SERVER['REQUEST_URI'] ?? ''),
];

// ====== FŐ FLOW ======
try {
    // Origin/Referer ellenőrzés
    assert_origin_allowed($ALLOWED_HOSTS);

    // Rate limit
    rate_limit_or_throw($RL_DIR, 'ip:' . $ip, $RL_MAX, $RL_WINDOW_SEC);

    // Honeypot
    $honeypot = trim((string)($_POST['website'] ?? ''));
    if ($honeypot !== '') {
        if ($MONITOR_ENABLED) {
            monitor_log($MON_FILE, $baseMon + ['event' => 'honeypot_hit']);
        }
        http_response_code(200);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('OK');
    }

    // Inputok
    $name    = normalize_newlines(trim((string)($_POST['name'] ?? '')));
    $email   = normalize_newlines(trim((string)($_POST['email'] ?? '')));
    $phone   = normalize_newlines(trim((string)($_POST['phone'] ?? '')));
    $message = normalize_newlines(trim((string)($_POST['message'] ?? '')));

    // Név: “barátságos” karakterkészlet
    $name = preg_replace('/[^\p{L}\p{N}\s\.\-\'"’]/u', '', $name) ?? '';
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

    // Telefon: számok + alap jelek
    $phone = preg_replace('/[^\d\+\-\(\)\/\s]/u', '', $phone) ?? '';
    $phone = trim(preg_replace('/\s+/u', ' ', $phone) ?? '');

    // Validálás
    if ($name === '' || $email === '' || $message === '') {
        fail_public(400, 'Hiányzó kötelező mező.', $requestId, false);
    }
    if (mb_strlen($name) > $MAX_LEN_NAME)   fail_public(400, 'Túl hosszú név.', $requestId, false);
    if (mb_strlen($email) > $MAX_LEN_EMAIL) fail_public(400, 'Túl hosszú e-mail.', $requestId, false);
    if (mb_strlen($phone) > $MAX_LEN_PHONE) fail_public(400, 'Túl hosszú telefon.', $requestId, false);
    if (mb_strlen($message) > $MAX_LEN_MSG) fail_public(400, 'Túl hosszú üzenet.', $requestId, false);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail_public(400, 'Hibás e-mail formátum.', $requestId, false);
    }

    // Link-spam heurisztika
    $urlCount = 0;
    if ($MAX_URLS_IN_MESSAGE > 0) {
        $urlCount = preg_match_all('~https?://~i', $message, $m);
        if (is_int($urlCount) && $urlCount > $MAX_URLS_IN_MESSAGE) {
            fail_public(400, 'Az üzenet túl sok linket tartalmaz.', $requestId, false);
        }
    }

    // Monitor
    if ($MONITOR_ENABLED) {
        $emailHash = hash('sha256', mb_strtolower($email));
        $mon = $baseMon + [
            'event'      => 'validated',
            'name_len'   => mb_strlen($name),
            'email_hash' => $emailHash,
            'phone_len'  => mb_strlen($phone),
            'msg_len'    => mb_strlen($message),
            'url_count'  => is_int($urlCount) ? $urlCount : 0,
        ];
        if ($MONITOR_LOG_PII) {
            $mon['email'] = $email;
            $mon['name']  = $name;
            $mon['phone'] = $phone;
        }
        monitor_log($MON_FILE, $mon);
    }

    // E-mail összeállítás
    $subjectRaw = '[' . $SITE . '] Új üzenet: ' . mb_substr($name, 0, 40);
    $subject    = encode_subject($subjectRaw);

    $body =
        "Új kapcsolatfelvétel érkezett a weboldalról.\n\n" .
        "Név: " . $name . "\n" .
        "E-mail: " . $email . "\n" .
        "Telefon: " . ($phone !== '' ? $phone : '-') . "\n" .
        "IP: " . $ip . "\n" .
        "User-Agent: " . ($ua !== '' ? $ua : '-') . "\n" .
        "Idő: " . date('Y-m-d H:i:s') . "\n" .
        "Request-ID: " . $requestId . "\n\n" .
        "Üzenet:\n" .
        $message . "\n";

    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'From: ' . clean_line($SITE) . ' <' . clean_line($FROM) . '>';
    $headers[] = 'Reply-To: <' . clean_line($email) . '>';
    $headers[] = 'X-Mailer: PHP/' . PHP_VERSION;
    $headers[] = 'X-Request-ID: ' . $requestId;

    // DEV log (privát temp alá) + redirect
    if ($DEV) {
        ensure_dir_or_fail($DEVLOG_DIR, 0700);
        $fn = $DEVLOG_DIR . DIRECTORY_SEPARATOR
            . 'contact_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.txt';

        $payload = "SUBJECT: {$subjectRaw}\n\n{$body}\n";
        if (file_put_contents($fn, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Nem tudtam írni a dev log fájlba.');
        }

        header('Location: /koszonjuk.html', true, 303);
        exit;
    }

    // Küldés – envelope sender a kézbesíthetőséghez
    $additionalParams = '-f' . escapeshellarg($FROM);

    // mail() warning elkapása loghoz
    $mailWarning = null;
    set_error_handler(function($severity, $message) use (&$mailWarning) {
        $mailWarning = $message;
        return true;
    });

    $ok = mail($TO, $subject, $body, implode("\r\n", $headers), $additionalParams);

    restore_error_handler();

    if (!$ok) {
        if ($MONITOR_ENABLED) {
            monitor_log($MON_FILE, $baseMon + [
                'event'   => 'mail_failed',
                'to'      => $TO,
                'warning' => $mailWarning,
            ]);
        }
        fail_public(
            500,
            'Nem sikerült elküldeni az üzenetet. Próbáld később, vagy írj e-mailt közvetlenül.',
            $requestId,
            true
        );
    }

    if ($MONITOR_ENABLED) {
        monitor_log($MON_FILE, $baseMon + ['event' => 'mail_sent', 'to' => $TO]);
    }

    header('Location: /koszonjuk.html', true, 303);
    exit;

} catch (OverflowException $e) {
    if ($MONITOR_ENABLED) {
        monitor_log($MON_FILE, $baseMon + ['event' => 'rate_limited']);
    }
    fail_public(429, 'Túl sok kérés rövid időn belül. Próbáld később.', $requestId, false);

} catch (RuntimeException $e) {
    if ($MONITOR_ENABLED) {
        monitor_log($MON_FILE, $baseMon + [
            'event' => 'runtime_error',
            'err'   => clean_line($e->getMessage()),
        ]);
    }

    if ($e->getMessage() === 'Tiltott forrás (origin/referer).') {
        fail_public(403, 'Tiltott forrás.', $requestId, false);
    }

    fail_public(500, 'Szerver hiba történt.', $requestId, true);

} catch (Throwable $e) {
    if ($MONITOR_ENABLED) {
        monitor_log($MON_FILE, $baseMon + [
            'event' => 'fatal',
            'err'   => clean_line($e->getMessage()),
            'type'  => get_class($e),
        ]);
    }
    fail_public(500, 'Szerver hiba történt.', $requestId, true);
}
