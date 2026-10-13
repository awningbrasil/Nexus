# Local demo: how to run the Nexus mock locally

This repository already contains example clients and a PHP lobby/wallet integration. To run a fully working local copy that behaves like the real FiversCan API, do the following:

1) Run the built-in PHP server from the repository root:

```bash
php -S 127.0.0.1:8080 -t . router.php
```

2) Export environment variables for the demo API and agent credentials (these are defaults used by the mock if omitted):

```bash
export FVS_API_URL=http://127.0.0.1:8080/mock_api.php
export FVS_AGENT_CODE=demo_agent
export FVS_AGENT_TOKEN=demo_token
export FVS_AGENT_SECRET=demo_secret
```

On Windows (PowerShell):

```powershell
$env:FVS_API_URL = 'http://127.0.0.1:8080/mock_api.php'
$env:FVS_AGENT_CODE = 'demo_agent'
$env:FVS_AGENT_TOKEN = 'demo_token'
$env:FVS_AGENT_SECRET = 'demo_secret'
```

3) Open the demo lobby in your browser:

http://127.0.0.1:8080/index.html

4) What this mock provides

- provider_list / game_list with the same example providers and games used in the README
- user_create, user_deposit, user_withdraw, money_info
- game_launch returns a demo launch URL
- transfer_status to reconcile timed-out transfers

5) Notes

- The mock persists a tiny ledger under the system temporary directory so balances survive short server restarts.
- This is intended for local development and testing only. Do not use the mock in production.

If you want, I can now:
- commit additional UI polish (show provider banners, game grid), or
- add a SQLite-backed ledger instead of a JSON file, or
- run through the full seven-call walkthrough in each language and commit small tweaks so every index.* script runs against the mock out of the box.
