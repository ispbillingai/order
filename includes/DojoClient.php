<?php
/**
 * DojoClient — card-present payment via the Dojo Cloud API ("Pay at Counter").
 *
 * Unlike the Ingenico/RTS terminal (PosClient), the Dojo terminal is driven
 * through Dojo's CLOUD REST API — we never talk to the terminal directly. The
 * flow is Stripe-style and asynchronous:
 *
 *   1) POST /payment-intents               -> { id: "pi_...", status: "Created" }
 *   2) POST /terminal-sessions             -> { id: "ts_...", status: "InitiateRequested" }
 *      (references the payment-intent id + the terminalId; Dojo pushes the
 *       prompt to the physical terminal over its own cloud link)
 *   3) GET  /terminal-sessions/{id}  (poll) until a terminal state:
 *        success: "Captured" (Auto capture) / "Authorized" (Manual capture)
 *        failure: "Declined" / "Expired" / "Canceled"
 *        signature: "SignatureVerificationRequired" (see note below)
 *
 * pay() blocks through all three steps (like PosClient::pay) so the cashier UI
 * stays a single "follow the terminal" action, identical to the Ingenico button.
 *
 * AUTH: Dojo mirrors Stripe — the secret key is the Basic-auth USERNAME with an
 * empty password, i.e. `Authorization: Basic base64("<secret_key>:")`. Keys are
 * `sk_sandbox_…` (test) or `sk_prod_…` (live). A dated `version` header is
 * required on every call. Terminal endpoints additionally want `reseller-id`
 * and `software-house-id` headers (EPOS partner onboarding) — sent when config
 * provides them.
 *
 * Config (deviceConfig('dojo') / till device_config 'dojo'):
 *   base_url          https://api.dojo.tech   (sandbox uses the same host)
 *   secret_key        sk_prod_… / sk_sandbox_…
 *   terminal_id       Dojo terminalId for this till's card machine
 *   version           API version date, e.g. 2026-02-27
 *   reseller_id       (optional) partner reseller id
 *   software_house_id (optional) EPOS software-house id
 *   capture_mode      Auto | Manual        (default Auto)
 *   connect_timeout   seconds (default 5)
 *   read_timeout      total seconds to wait for the tap (default 90, matches POS)
 *   poll_interval_ms  poll cadence (default 1500)
 *   verify_ssl        (default true)
 *
 * SIGNATURE: if the terminal falls back to signature verification, this first
 * version does NOT auto-accept (that would bypass the check). pay() returns
 * ok=false / error='signature_required' and leaves the session for the cashier
 * to resolve on the terminal. Chip+PIN / contactless do not hit this path.
 */
class DojoClient
{
    private array $cfg;

    /** ISO 4217 numeric -> alpha, for the few currencies this deployment uses. */
    private const CURRENCY_ALPHA = [978 => 'EUR', 826 => 'GBP', 840 => 'USD'];

    private const SUCCESS_STATES  = ['Captured', 'Authorized'];
    private const FAILURE_STATES  = ['Declined', 'Expired', 'Canceled', 'Cancelled'];
    private const SIGNATURE_STATE = 'SignatureVerificationRequired';

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    public function enabled(): bool
    {
        return !empty($this->cfg['base_url'])
            && !empty($this->cfg['secret_key'])
            && !empty($this->cfg['terminal_id']);
    }

    /**
     * Charge the card on the Dojo terminal. Blocks up to read_timeout seconds
     * while the customer taps and the acquirer authorises.
     *
     * @param int    $amountCents   amount in minor units (e.g. 1050 = €10.50)
     * @param int    $currencyNum   ISO 4217 numeric code (978 = EUR)
     * @param string $reference     short merchant reference (e.g. "order-42")
     * @param string $description   human description on the payment intent
     * @return array{ok:bool, error?:string, status?:string, auth_code?:string,
     *               operation_number?:string, pan?:string,
     *               payment_intent_id?:string, session_id?:string, raw?:array}
     */
    public function pay(int $amountCents, int $currencyNum, string $reference, string $description = ''): array
    {
        if (!$this->enabled()) {
            return ['ok' => false, 'error' => 'dojo_disabled'];
        }
        $currency = $this->cfg['currency_code'] ?? (self::CURRENCY_ALPHA[$currencyNum] ?? 'EUR');

        // 1) Payment intent.
        $intent = $this->request('POST', '/payment-intents', [
            'amount'      => ['value' => max(0, $amountCents), 'currencyCode' => $currency],
            'reference'   => $reference,
            'description' => $description !== '' ? $description : $reference,
            'captureMode' => (string) ($this->cfg['capture_mode'] ?? 'Auto'),
        ]);
        if (!$intent['ok']) {
            error_log('[dojo] intent error: ' . ($intent['error'] ?? '?'));
            return ['ok' => false, 'error' => $intent['error'] ?? 'intent_failed'];
        }
        $intentId = (string) ($intent['body']['id'] ?? '');
        if ($intentId === '') {
            return ['ok' => false, 'error' => 'intent_no_id'];
        }

        // 2) Terminal session — pushes the prompt to the physical terminal.
        $session = $this->request('POST', '/terminal-sessions', [
            'paymentIntentId' => $intentId,
            'terminalId'      => (string) $this->cfg['terminal_id'],
            'captureMode'     => (string) ($this->cfg['capture_mode'] ?? 'Auto'),
        ]);
        if (!$session['ok']) {
            error_log('[dojo] session error: ' . ($session['error'] ?? '?'));
            return ['ok' => false, 'error' => $session['error'] ?? 'session_failed', 'payment_intent_id' => $intentId];
        }
        $sessionId = (string) ($session['body']['id'] ?? '');
        if ($sessionId === '') {
            return ['ok' => false, 'error' => 'session_no_id', 'payment_intent_id' => $intentId];
        }

        // 3) Poll until a terminal state or timeout.
        $deadline = $this->now() + (int) ($this->cfg['read_timeout'] ?? 90);
        $intervalUs = max(300, (int) ($this->cfg['poll_interval_ms'] ?? 1500)) * 1000;
        $last = $session['body'];

        while (true) {
            $status = (string) ($last['status'] ?? '');

            if (in_array($status, self::SUCCESS_STATES, true)) {
                $card = $this->extractCardInfo($last);
                return [
                    'ok'                => true,
                    'status'            => 'approved',
                    'auth_code'         => $card['auth_code'],
                    'operation_number'  => $card['operation_number'] ?: $sessionId,
                    'pan'               => $card['pan'],
                    'payment_intent_id' => $intentId,
                    'session_id'        => $sessionId,
                    'raw'               => $last,
                ];
            }
            if (in_array($status, self::FAILURE_STATES, true)) {
                return [
                    'ok'                => false,
                    'status'            => 'declined',
                    'error'             => $this->declineReason($last, $status),
                    'payment_intent_id' => $intentId,
                    'session_id'        => $sessionId,
                    'raw'               => $last,
                ];
            }
            if ($status === self::SIGNATURE_STATE) {
                // Do not auto-accept — leave the session for the cashier/terminal.
                error_log('[dojo] signature verification required, session ' . $sessionId);
                return [
                    'ok'                => false,
                    'status'            => 'signature',
                    'error'             => 'signature_required',
                    'payment_intent_id' => $intentId,
                    'session_id'        => $sessionId,
                    'raw'               => $last,
                ];
            }

            if ($this->now() >= $deadline) {
                $this->cancel($sessionId); // best-effort; don't leave it dangling
                return [
                    'ok'                => false,
                    'status'            => 'timeout',
                    'error'             => 'timeout',
                    'payment_intent_id' => $intentId,
                    'session_id'        => $sessionId,
                ];
            }

            usleep($intervalUs);
            $poll = $this->request('GET', '/terminal-sessions/' . rawurlencode($sessionId));
            if (!$poll['ok']) {
                // Transient poll error — keep trying until the deadline.
                error_log('[dojo] poll error: ' . ($poll['error'] ?? '?'));
                continue;
            }
            $last = $poll['body'];
        }
    }

    /** Best-effort cancel of a terminal session. Never throws. */
    public function cancel(string $sessionId): void
    {
        if ($sessionId === '' || !$this->enabled()) {
            return;
        }
        try {
            $this->request('PUT', '/terminal-sessions/' . rawurlencode($sessionId) . '/cancel');
        } catch (Throwable $e) {
            error_log('[dojo] cancel failed: ' . $e->getMessage());
        }
    }

    /**
     * Pull card metadata out of a completed session. Field names follow Dojo's
     * card-present result (authCode / last4PAN / acquirerPaymentId); looked up
     * defensively across a few nesting shapes since the exact envelope is the
     * one thing to confirm against a sandbox capture. The full session is stored
     * in device_meta regardless, so nothing is lost for reconciliation.
     *
     * @return array{auth_code:string, pan:string, operation_number:string}
     */
    private function extractCardInfo(array $session): array
    {
        $info = $session['cardPresentPaymentInfo']
            ?? $session['cardDetails']
            ?? $session['payment']['cardPresentPaymentInfo']
            ?? [];
        if (!is_array($info)) {
            $info = [];
        }
        $last4 = (string) ($info['last4PAN'] ?? $info['last4'] ?? $info['maskedPan'] ?? '');
        return [
            'auth_code'        => (string) ($info['authCode'] ?? $info['authorizationCode'] ?? ''),
            'pan'              => $last4 !== '' ? ('************' . $last4) : '',
            'operation_number' => (string) ($info['acquirerPaymentId'] ?? $info['acquirerTransactionId'] ?? ''),
        ];
    }

    private function declineReason(array $session, string $status): string
    {
        $reason = trim((string) (
            $session['declineReason']
            ?? $session['errorMessage']
            ?? $session['statusReason']
            ?? ''
        ));
        return $reason !== '' ? $reason : ('dojo_' . strtolower($status));
    }

    /**
     * One authenticated JSON request to the Dojo API.
     *
     * @return array{ok:bool, status?:int, body?:array, error?:string}
     */
    private function request(string $method, string $path, ?array $json = null): array
    {
        $url = rtrim((string) $this->cfg['base_url'], '/') . $path;

        // Stripe-style: secret key as Basic username, empty password.
        $authValue = 'Basic ' . base64_encode(((string) $this->cfg['secret_key']) . ':');

        $headers = [
            'Authorization: ' . $authValue,
            'version: ' . (string) ($this->cfg['version'] ?? '2026-02-27'),
            'Accept: application/json',
        ];
        if (!empty($this->cfg['reseller_id'])) {
            $headers[] = 'reseller-id: ' . (string) $this->cfg['reseller_id'];
        }
        if (!empty($this->cfg['software_house_id'])) {
            $headers[] = 'software-house-id: ' . (string) $this->cfg['software_house_id'];
        }

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_CONNECTTIMEOUT => (int) ($this->cfg['connect_timeout'] ?? 5),
            CURLOPT_TIMEOUT        => (int) ($this->cfg['read_timeout'] ?? 90) + 10,
            CURLOPT_SSL_VERIFYPEER => ($this->cfg['verify_ssl'] ?? true) ? 1 : 0,
            CURLOPT_SSL_VERIFYHOST => ($this->cfg['verify_ssl'] ?? true) ? 2 : 0,
        ];
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $errno = curl_errno($ch);
            $err   = curl_error($ch);
            curl_close($ch);
            return ['ok' => false, 'error' => "curl({$errno}): {$err}"];
        }
        $status = (int) (curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 0);
        curl_close($ch);

        $body = json_decode((string) $raw, true);
        if (!is_array($body)) {
            $body = [];
        }
        if ($status < 200 || $status >= 300) {
            $msg = (string) ($body['message'] ?? $body['error'] ?? '');
            return ['ok' => false, 'status' => $status, 'body' => $body,
                    'error' => $msg !== '' ? $msg : "http_{$status}"];
        }
        return ['ok' => true, 'status' => $status, 'body' => $body];
    }

    /** Wall-clock seconds; isolated so the poll loop is easy to reason about. */
    private function now(): int
    {
        return time();
    }
}
