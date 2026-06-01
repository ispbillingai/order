<?php
/**
 * ThermalPrinter — network thermal printer over raw TCP (ESC/POS, port 9100).
 *
 * Ported from the parking app (Parking\Printer\Thermal). Used to print a
 * kitchen ticket when an order is sent to the kitchen — the printer cuts the
 * slip itself, no browser dialog. Reached server-side over Tailscale.
 *
 * Config: deviceConfig('kitchen_printer') => host, port(9100), timeout(5),
 *         width(32 chars), codepage(2 = CP850 for accents).
 */
class ThermalPrinter
{
    private const ESC = "\x1b";
    private const GS  = "\x1d";
    private const LF  = "\n";

    private array $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    public function isEnabled(): bool
    {
        return !empty($this->cfg['host']);
    }

    /**
     * Print a kitchen ticket.
     *
     * @param array{
     *   title?:string, table_label?:string, table?:string,
     *   order_label?:string, order_number?:string,
     *   waiter_label?:string, waiter?:string, time?:string,
     *   items?:array<int,array{qty?:int,name?:string,mods?:array<int,string>,note?:string}>
     * } $t
     * @return array{ok:bool, error?:string, bytes?:int}
     */
    public function printKitchenTicket(array $t): array
    {
        if (!$this->isEnabled()) {
            return ['ok' => false, 'error' => 'printer_not_configured'];
        }
        $host    = (string) $this->cfg['host'];
        $port    = (int) ($this->cfg['port'] ?? 9100);
        $timeout = (int) ($this->cfg['timeout'] ?? 5);

        $errno = 0; $errstr = '';
        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (!$fp) {
            return ['ok' => false, 'error' => trim("$errno $errstr") ?: 'connect_failed'];
        }
        stream_set_timeout($fp, $timeout);

        $payload = $this->buildKitchen($t);
        $written = @fwrite($fp, $payload);
        @fclose($fp);

        if ($written === false || $written < strlen($payload)) {
            return ['ok' => false, 'error' => 'short_write'];
        }
        return ['ok' => true, 'bytes' => $written];
    }

    private function buildKitchen(array $t): string
    {
        $w    = (int) ($this->cfg['width'] ?? 32);
        $cp   = (int) ($this->cfg['codepage'] ?? 2);
        $line = str_repeat('-', $w);

        $out  = self::ESC . '@';                 // init
        $out .= self::ESC . 't' . chr($cp);      // code page

        // Title (centred, double size)
        $out .= self::ESC . 'a' . "\x01";
        $out .= self::ESC . '!' . "\x30";
        $out .= $this->enc((string) ($t['title'] ?? 'CUCINA')) . self::LF;
        $out .= self::ESC . '!' . "\x00";
        $out .= $line . self::LF;

        // Header (left). Table number is the most important — print it big.
        $out .= self::ESC . 'a' . "\x00";
        $out .= self::ESC . '!' . "\x30";
        $out .= $this->enc(($t['table_label'] ?? 'Tavolo') . ': ' . (string) ($t['table'] ?? '')) . self::LF;
        $out .= self::ESC . '!' . "\x00";
        if (!empty($t['order_number'])) {
            $out .= $this->enc(($t['order_label'] ?? 'Ordine') . ': ' . (string) $t['order_number']) . self::LF;
        }
        if (!empty($t['waiter'])) {
            $out .= $this->enc(($t['waiter_label'] ?? 'Cameriere') . ': ' . (string) $t['waiter']) . self::LF;
        }
        if (!empty($t['time'])) {
            $out .= $this->enc((string) $t['time']) . self::LF;
        }
        $out .= $line . self::LF;

        // Items
        foreach (($t['items'] ?? []) as $it) {
            $qty  = (int) ($it['qty'] ?? 1);
            $name = (string) ($it['name'] ?? '');
            $out .= self::ESC . '!' . "\x08";    // emphasized (bold)
            $out .= $this->enc($qty . 'x ' . $name) . self::LF;
            $out .= self::ESC . '!' . "\x00";
            foreach (($it['mods'] ?? []) as $m) {
                $out .= $this->enc('   ' . (string) $m) . self::LF;
            }
            if (!empty($it['note'])) {
                $out .= $this->enc('   * ' . (string) $it['note']) . self::LF;
            }
        }

        $out .= $line . self::LF;
        $out .= str_repeat(self::LF, 4);
        $out .= self::GS . 'V' . "\x01";         // partial cut
        return $out;
    }

    /** UTF-8 -> printer code page so accents (è, à, ò) print correctly. */
    private function enc(string $s): string
    {
        $cp = (int) ($this->cfg['codepage'] ?? 2);
        $target = $cp === 0 ? 'CP437' : 'CP850';
        $r = @iconv('UTF-8', $target . '//TRANSLIT//IGNORE', $s);
        return $r === false ? $s : $r;
    }
}
