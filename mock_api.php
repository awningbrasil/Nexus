<?php
/**
 * mock_api.php — Local mock of the FiversCan API for development and demo.
 *
 * Usage:
 *   php -S 127.0.0.1:8080 -t . router.php
 *   Then set environment variables when running clients / server:
 *     FVS_API_URL=http://127.0.0.1:8080/mock_api.php
 *     FVS_AGENT_CODE=demo_agent
 *     FVS_AGENT_TOKEN=demo_token
 *     FVS_AGENT_SECRET=demo_secret
 *
 * This mock implements the API contract used by the example clients in this repo:
 *   provider_list, game_list, user_create, user_deposit, user_withdraw, game_launch, money_info, transfer_status
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true) ?: [];

// Basic auth check: every call must carry agent_code + agent_token
$agentCode = $body['agent_code'] ?? getenv('FVS_AGENT_CODE') ?: 'demo_agent';
$agentToken = $body['agent_token'] ?? getenv('FVS_AGENT_TOKEN') ?: 'demo_token';

if (!isset($body['method'])) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'msg' => 'INVALID_METHOD']);
    exit;
}

$method = $body['method'];

// Simple persistent ledger on disk (temp file)
$ledgerFile = sys_get_temp_dir() . '/nexus_mock_ledger.json';
$ledger = is_file($ledgerFile) ? json_decode((string) file_get_contents($ledgerFile), true) : [];
$ledger['users'] ??= [];
$ledger['txns'] ??= [];
$ledger['agent_balance'] ??= 10000.0;

function save_ledger(array $ledger, string $file): void
{
    file_put_contents($file, json_encode($ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

try {
    switch ($method) {
        case 'provider_list':
            $providers = [
                ['code' => 'PRAGMATIC', 'name' => 'Pragmatic Play', 'status' => 1],
                ['code' => 'PGSOFT', 'name' => 'PG Soft', 'status' => 1],
                ['code' => 'EVOLUTION', 'name' => 'Evolution', 'status' => 1],
            ];
            echo json_encode(['status' => 1, 'msg' => 'SUCCESS', 'providers' => $providers]);
            break;

        case 'game_list':
            $provider = $body['provider_code'] ?? $body['provider'] ?? '';
            $games = [];
            if ($provider === 'PRAGMATIC') {
                $games = [
                    ['game_code' => 'sweet_bonanza', 'game_name' => 'Sweet Bonanza', 'banner' => '', 'status' => 1],
                    ['game_code' => 'gates_of_olympus', 'game_name' => 'Gates of Olympus', 'banner' => '', 'status' => 1],
                    ['game_code' => 'dog_house', 'game_name' => 'The Dog House', 'banner' => '', 'status' => 1],
                ];
            } elseif ($provider === 'PGSOFT') {
                $games = [
                    ['game_code' => 'mahjong_ways', 'game_name' => 'Mahjong Ways', 'banner' => '', 'status' => 1],
                    ['game_code' => 'wizard_gem', 'game_name' => 'Wizard of Gems', 'banner' => '', 'status' => 1],
                ];
            } elseif ($provider === 'EVOLUTION') {
                $games = [
                    ['game_code' => 'blackjack_live', 'game_name' => 'Blackjack Live', 'banner' => '', 'status' => 1],
                    ['game_code' => 'roulette_live', 'game_name' => 'Roulette Live', 'banner' => '', 'status' => 1],
                ];
            }
            echo json_encode(['status' => 1, 'msg' => 'SUCCESS', 'games' => $games]);
            break;

        case 'user_create':
            $user = (string) ($body['user_code'] ?? '');
            if ($user === '') {
                echo json_encode(['status' => 0, 'msg' => 'INVALID_USER']);
                break;
            }
            if (!isset($ledger['users'][$user])) {
                $ledger['users'][$user] = ['balance' => 0.0, 'fc_code' => 'FC_' . strtoupper(substr(md5($user), 0, 8))];
                save_ledger($ledger, $ledgerFile);
                echo json_encode(['status' => 1, 'msg' => 'SUCCESS', 'user_code' => $user, 'fc_code' => $ledger['users'][$user]['fc_code']]);
            } else {
                // Return success but mark duplicate in msg (clients expect this behaviour)
                echo json_encode(['status' => 1, 'msg' => 'Duplicated User', 'user_code' => $user, 'fc_code' => $ledger['users'][$user]['fc_code']]);
            }
            break;

        case 'user_deposit':
        case 'user_withdraw':
            $user = (string) ($body['user_code'] ?? '');
            $amount = isset($body['amount']) ? (float) $body['amount'] : 0.0;
            $sign = $body['agent_sign'] ?? $body['sign'] ?? null;
            if ($user === '' || $amount <= 0) {
                echo json_encode(['status' => 0, 'msg' => 'INVALID_PARAMETER']);
                break;
            }
            // idempotency
            if ($sign !== null && isset($ledger['txns'][$sign])) {
                $txn = $ledger['txns'][$sign];
                echo json_encode(['status' => 1, 'msg' => 'Completed', 'agent_balance' => $ledger['agent_balance'], 'user_balance' => $ledger['users'][$txn['user']]['balance'] ?? 0]);
                break;
            }

            $ledger['users'][$user] ??= ['balance' => 0.0, 'fc_code' => 'FC_' . strtoupper(substr(md5($user), 0, 8))];

            if ($method === 'user_deposit') {
                // agent -> user
                $ledger['users'][$user]['balance'] += $amount;
                $ledger['agent_balance'] -= $amount;
                $txType = 'deposit';
            } else {
                // user -> agent
                if ($ledger['users'][$user]['balance'] < $amount) {
                    echo json_encode(['status' => 0, 'msg' => 'INSUFFICIENT_USER_FUNDS']);
                    break;
                }
                $ledger['users'][$user]['balance'] -= $amount;
                $ledger['agent_balance'] += $amount;
                $txType = 'withdraw';
            }

            if ($sign !== null) {
                $ledger['txns'][$sign] = ['user' => $user, 'amount' => $amount, 'type' => $txType];
            }
            save_ledger($ledger, $ledgerFile);
            echo json_encode(['status' => 1, 'msg' => 'SUCCESS', 'agent_balance' => $ledger['agent_balance'], 'user_balance' => $ledger['users'][$user]['balance']]);
            break;

        case 'game_launch':
            $user = (string) ($body['user_code'] ?? 'demo_user');
            $provider = (string) ($body['provider_code'] ?? $body['provider'] ?? 'PRAGMATIC');
            $game = (string) ($body['game_code'] ?? $body['game'] ?? '');
            $lang = (string) ($body['lang'] ?? 'en');
            $lobbyUrl = (string) ($body['lobby_url'] ?? $body['return_url'] ?? '');

            $launchUrl = "https://demo.game.local/play?user=" . rawurlencode($user) . "&provider=" . rawurlencode($provider) . "&game=" . rawurlencode($game) . "&lang=" . rawurlencode($lang);
            if ($lobbyUrl !== '') $launchUrl .= "&return=" . rawurlencode($lobbyUrl);

            echo json_encode(['status' => 1, 'msg' => 'SUCCESS', 'launch_url' => $launchUrl, 'fc_code' => 'FC_DEMO']);
            break;

        case 'money_info':
            $user = (string) ($body['user_code'] ?? '');
            $agent = ['agent_code' => $agentCode, 'balance' => $ledger['agent_balance']];
            $resp = ['status' => 1, 'msg' => 'SUCCESS', 'agent' => $agent];
            if ($user !== '') {
                $resp['user'] = ['user_code' => $user, 'balance' => $ledger['users'][$user]['balance'] ?? 0.0];
            }
            echo json_encode($resp);
            break;

        case 'transfer_status':
            $user = (string) ($body['user_code'] ?? '');
            $sign = $body['agent_sign'] ?? $body['sign'] ?? null;
            if ($sign === null || !isset($ledger['txns'][$sign])) {
                http_response_code(404);
                echo json_encode(['status' => 0, 'msg' => 'Not found']);
                break;
            }
            $tx = $ledger['txns'][$sign];
            $userBal = $ledger['users'][$tx['user']]['balance'] ?? 0.0;
            echo json_encode(['status' => 1, 'msg' => 'Completed', 'amount' => $tx['amount'], 'agent_balance' => $ledger['agent_balance'], 'user_balance' => $userBal, 'type' => $tx['type']]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['status' => 0, 'msg' => 'INVALID_METHOD']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'msg' => 'INTERNAL_ERROR', 'detail' => $e->getMessage()]);
}
