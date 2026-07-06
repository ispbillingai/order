<?php
/**
 * Device monitor — pings the shop devices THROUGH the MikroTik router's RouterOS
 * API and records up/down status in the `devices` table.
 *
 * WHY VIA THE ROUTER: the devices sit on the shop LAN (192.168.100.0/24) behind
 * the MikroTik. This server reaches that router over WireGuard, but not the LAN
 * hosts directly. The router's own `/tool fetch` can't POST out (broken on this
 * unit), so instead the server PULLS: it opens the RouterOS API (port 8728),
 * asks the router to ping each device, and reads the replies back.
 *
 * Config lives in config/devices.php under the 'router' section:
 *   'router' => [
 *       'host' => '192.168.200.15',   // MikroTik over WireGuard
 *       'port' => 8728,
 *       'user' => 'admin',
 *       'pass' => '...',
 *       'ping_count' => 2,
 *   ],
 *
 * All functions are dependency-free (raw socket API client) so the poller runs
 * from CLI/cron without extra packages.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/devices.php';

/**
 * Minimal RouterOS API client. Connects, logs in, and runs /ping for a host,
 * returning [bool up, ?float latency_ms]. Never throws for a down host; throws
 * only on connect/login failure so the caller can distinguish "router
 * unreachable" from "device down".
 */
final class RouterOsApi
{
    /** @var resource */
    private $sock;

    public function __construct(string $host, int $port, float $timeout = 5.0)
    {
        $errno = 0;
        $errstr = '';
        $sock = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (!$sock) {
            throw new RuntimeException("RouterOS connect failed: $errstr ($errno)");
        }
        stream_set_timeout($sock, (int) ceil($timeout) + 5);
        $this->sock = $sock;
    }

    public function login(string $user, string $pass): void
    {
        // RouterOS 6.43+ plain login: single sentence with =name= / =password=.
        $this->writeSentence(['/login', '=name=' . $user, '=password=' . $pass]);
        $reply = $this->readSentence();
        if (($reply[0] ?? '') !== '!done') {
            throw new RuntimeException('RouterOS login failed: ' . implode(' ', $reply));
        }
    }

    /**
     * Ping a host $count times. Returns [up, latency_ms].
     * up = at least one reply; latency = the smallest reported time.
     */
    public function ping(string $address, int $count = 2): array
    {
        $this->writeSentence(['/ping', '=address=' . $address, '=count=' . $count]);

        $received = 0;
        $minMs = null;
        // The router streams one !re per ping, then a final !done.
        while (true) {
            $sentence = $this->readSentence();
            if (!$sentence) {
                break;
            }
            $type = $sentence[0];
            if ($type === '!re') {
                $attrs = $this->parseAttrs($sentence);
                // A reply that actually returned has a non-empty 'time'. Timeouts
                // report 'status=timeout' with no time.
                if (isset($attrs['time']) && $attrs['time'] !== '') {
                    $received++;
                    $ms = $this->timeToMs($attrs['time']);
                    if ($ms !== null && ($minMs === null || $ms < $minMs)) {
                        $minMs = $ms;
                    }
                }
            } elseif ($type === '!done' || $type === '!trap' || $type === '!fatal') {
                break;
            }
        }
        return [$received > 0, $minMs];
    }

    public function close(): void
    {
        if (is_resource($this->sock)) {
            @fclose($this->sock);
        }
    }

    // ---- RouterOS API wire protocol ------------------------------------

    /** @param string[] $sentence */
    private function parseAttrs(array $sentence): array
    {
        $attrs = [];
        foreach ($sentence as $word) {
            if ($word !== '' && $word[0] === '=') {
                $eq = strpos($word, '=', 1);
                if ($eq !== false) {
                    $attrs[substr($word, 1, $eq - 1)] = substr($word, $eq + 1);
                }
            }
        }
        return $attrs;
    }

    /** RouterOS time strings like "1ms234us", "563us", "5ms604us", "1s200ms". */
    private function timeToMs(string $t): ?float
    {
        if (!preg_match_all('/([\d.]+)(us|ms|s(?!$)|s$)/', $t, $m, PREG_SET_ORDER)) {
            // Sometimes just a bare number of ms.
            return is_numeric($t) ? (float) $t : null;
        }
        $ms = 0.0;
        foreach ($m as $part) {
            $val = (float) $part[1];
            switch ($part[2]) {
                case 'us': $ms += $val / 1000.0; break;
                case 'ms': $ms += $val; break;
                default:   $ms += $val * 1000.0; break; // seconds
            }
        }
        return $ms;
    }

    /** @param string[] $words */
    private function writeSentence(array $words): void
    {
        foreach ($words as $w) {
            $this->writeWord($w);
        }
        fwrite($this->sock, chr(0)); // empty word terminates the sentence
    }

    private function writeWord(string $w): void
    {
        fwrite($this->sock, $this->encodeLength(strlen($w)) . $w);
    }

    /** @return string[] */
    private function readSentence(): array
    {
        $words = [];
        while (true) {
            $len = $this->readLength();
            if ($len === 0) {
                break; // end of sentence
            }
            $words[] = $this->readBytes($len);
        }
        return $words;
    }

    private function encodeLength(int $len): string
    {
        if ($len < 0x80) {
            return chr($len);
        }
        if ($len < 0x4000) {
            $len |= 0x8000;
            return chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
        }
        if ($len < 0x200000) {
            $len |= 0xC00000;
            return chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
        }
        if ($len < 0x10000000) {
            $len |= 0xE0000000;
            return chr(($len >> 24) & 0xFF) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
        }
        return chr(0xF0) . chr(($len >> 24) & 0xFF) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
    }

    private function readLength(): int
    {
        $c = ord($this->readBytes(1));
        if (($c & 0x80) === 0x00) {
            return $c;
        }
        if (($c & 0xC0) === 0x80) {
            return (($c & 0x3F) << 8) + ord($this->readBytes(1));
        }
        if (($c & 0xE0) === 0xC0) {
            $r = ($c & 0x1F) << 16;
            $r += ord($this->readBytes(1)) << 8;
            $r += ord($this->readBytes(1));
            return $r;
        }
        if (($c & 0xF0) === 0xE0) {
            $r = ($c & 0x0F) << 24;
            $r += ord($this->readBytes(1)) << 16;
            $r += ord($this->readBytes(1)) << 8;
            $r += ord($this->readBytes(1));
            return $r;
        }
        $r = ord($this->readBytes(1)) << 24;
        $r += ord($this->readBytes(1)) << 16;
        $r += ord($this->readBytes(1)) << 8;
        $r += ord($this->readBytes(1));
        return $r;
    }

    private function readBytes(int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($this->sock, $n - strlen($buf));
            if ($chunk === '' || $chunk === false) {
                $meta = stream_get_meta_data($this->sock);
                if (!empty($meta['timed_out'])) {
                    throw new RuntimeException('RouterOS read timed out');
                }
                throw new RuntimeException('RouterOS connection closed mid-read');
            }
            $buf .= $chunk;
        }
        return $buf;
    }
}

/**
 * Poll every active device once and write results to the `devices` table.
 * Returns a summary array: ['ok'=>bool, 'checked'=>int, 'up'=>int, 'down'=>int,
 * 'error'=>?string, 'results'=>[['ip','name','up','latency_ms'], ...]].
 *
 * If the router is unreachable, no rows are touched (so a WireGuard blip doesn't
 * mark every device "down") and 'ok' is false with an 'error'.
 */
function pollDevices(): array
{
    $cfg = deviceConfig('router');
    $host = (string) ($cfg['host'] ?? '192.168.200.15');
    $port = (int)    ($cfg['port'] ?? 8728);
    $user = (string) ($cfg['user'] ?? 'admin');
    $pass = (string) ($cfg['pass'] ?? '');
    $count = max(1, (int) ($cfg['ping_count'] ?? 2));

    $pdo = getDBConnection();
    $devices = $pdo->query(
        "SELECT id, name, ip FROM devices WHERE active = 1 ORDER BY sort_order, id"
    )->fetchAll();

    if (!$devices) {
        return ['ok' => true, 'checked' => 0, 'up' => 0, 'down' => 0, 'error' => null, 'results' => []];
    }

    try {
        $api = new RouterOsApi($host, $port);
        $api->login($user, $pass);
    } catch (Throwable $e) {
        return ['ok' => false, 'checked' => 0, 'up' => 0, 'down' => 0,
                'error' => $e->getMessage(), 'results' => []];
    }

    $now = date('Y-m-d H:i:s');
    $up = 0;
    $down = 0;
    $results = [];

    $updUp = $pdo->prepare(
        "UPDATE devices SET status='up', latency_ms=?, last_seen_at=?, last_checked_at=? WHERE id=?"
    );
    $updDown = $pdo->prepare(
        "UPDATE devices SET status='down', latency_ms=NULL, last_checked_at=? WHERE id=?"
    );

    foreach ($devices as $d) {
        try {
            [$isUp, $ms] = $api->ping($d['ip'], $count);
        } catch (Throwable $e) {
            // Mid-run router failure: stop, report what we have, leave rest as-is.
            $api->close();
            return ['ok' => false, 'checked' => count($results), 'up' => $up, 'down' => $down,
                    'error' => $e->getMessage(), 'results' => $results];
        }
        if ($isUp) {
            $updUp->execute([$ms, $now, $now, $d['id']]);
            $up++;
        } else {
            $updDown->execute([$now, $d['id']]);
            $down++;
        }
        $results[] = ['ip' => $d['ip'], 'name' => $d['name'], 'up' => $isUp, 'latency_ms' => $ms];
    }

    $api->close();
    return ['ok' => true, 'checked' => count($results), 'up' => $up, 'down' => $down,
            'error' => null, 'results' => $results];
}
