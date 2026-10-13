<?php
// Relaxed local demo gold_api: allows demo_agent/demo_secret and supports transaction calls from the demo play.html.

declare(strict_types=1);

const AGENT_CODE   = 'demo_agent';
const AGENT_SECRET = 'demo_secret';
const WALLET_FILE  = '/fvs-wallet.json';

header('Content-Type: application/json; charset=utf-8');

function reply(array $payload, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

final class Ledger
{
    private $fh;
    private array $data;

    public function __construct(string $file)
    {
        $this->fh = fopen($file, 'c+') ?: throw new RuntimeException("cannot open $file");
        if (!flock($this->fh, LOCK_EX)) {
            throw new RuntimeException('cannot lock ledger');
        }
        $raw = stream_get_contents($this->fh);
        $this->data = ($raw !== '' && $raw !== false) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : [];
        $this->data += ['users' => [], 'txns' => []];
    }

    public function balance(string $user): float
    {
        return (float) ($this->data['users'][$user] ?? 0);
    }

    public function processed(string $txnIdV2): ?float
    {
        return isset($this->data['txns'][$txnIdV2]) ? (float) $this->data['txns'][$txnIdV2] : null;
    }

    public function apply(string $user, string $txnIdV2, float $debit, float $credit): ?float
    {
        $balance = $this->balance($user);
        if ($balance < $debit) {
            return null;
        }
        $balance = round($balance - $debit + $credit, 2);
        $this->data['users'][$user] = $balance;
        $this->data['txns'][$txnIdV2] = $balance;
        return $balance;
    }

    public function commit(): void
    {
        rewind($this->fh);
        ftruncate($this->fh, 0);
        fwrite($this->fh, json_encode($this->data, JSON_THROW_ON_ERROR));
        fflush($this->fh);
        flock($this->fh, LOCK_UN);
        fclose($this->fh);
    }
}

try {
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($body)) {
        reply(['status' => 0, 'msg' => 'INVALID_JSON']);
    }

    $agentCode = getenv('FVS_AGENT_CODE') ?: AGENT_CODE;
    $agentSecret = getenv('FVS_AGENT_SECRET') ?: AGENT_SECRET;

    // Accept demo defaults if environment not configured
    if (!hash_equals($agentCode, (string) ($body['agent_code'] ?? '')) || !hash_equals($agentSecret, (string) ($body['agent_secret'] ?? ''))) {
        // In demo allow when body carries demo_agent/demo_secret for convenience
        if (!hash_equals('demo_agent', (string) ($body['agent_code'] ?? '')) || !hash_equals('demo_secret', (string) ($body['agent_secret'] ?? ''))) {
            reply(['status' => 0, 'msg' => 'INVALID_AGENT']);
        }
    }

    $user = (string) ($body['user_code'] ?? ($body['user'] ?? ''));
    if ($user === '') {
        reply(['status' => 0, 'msg' => 'INVALID_USER']);
    }

    $ledger = new Ledger(sys_get_temp_dir() . WALLET_FILE);

    switch ($body['method'] ?? '') {
        case 'user_balance':
            reply(['status' => 1, 'user_balance' => $ledger->balance($user)]);

        case 'transaction':
            $gameType = (string) ($body['game_type'] ?? '');
            $txn = $body[$gameType] ?? null;
            if (!is_array($txn) || !isset($txn['txn_id_v2'], $txn['txn_type'])) {
                reply(['status' => 0, 'msg' => 'INVALID_PARAMETER']);
            }
            $txnIdV2 = (string) $txn['txn_id_v2'];

            if (($seen = $ledger->processed($txnIdV2)) !== null) {
                reply(['status' => 1, 'user_balance' => $seen]);
            }

            $debit  = in_array($txn['txn_type'], ['debit', 'debit_credit'], true) ? (float) ($txn['bet_money'] ?? 0) : 0.0;
            $credit = in_array($txn['txn_type'], ['credit', 'debit_credit'], true) ? (float) ($txn['win_money'] ?? 0) : 0.0;
            if ($debit < 0 || $credit < 0) {
                reply(['status' => 0, 'msg' => 'INVALID_PARAMETER']);
            }

            $balance = $ledger->apply($user, $txnIdV2, $debit, $credit);
            if ($balance === null) {
                reply(['status' => 0, 'msg' => 'INSUFFICIENT_USER_FUNDS']);
            }
            $ledger->commit();
            reply(['status' => 1, 'user_balance' => $balance]);

        default:
            reply(['status' => 0, 'msg' => 'INVALID_METHOD']);
    }
} catch (Throwable $e) {
    error_log('gold_api.php demo: ' . $e->getMessage());
    reply(['status' => 0, 'msg' => 'INTERNAL_ERROR'], 500);
}
