# ClickPesa BillPay + Safe Auto Payout

## Webhook URL (register in ClickPesa dashboard)

Pretty URLs (preferred):

```
https://getway.legitconsult.co.tz/api/clickpesa/webhook
```

With `index.php` (if rewrite not enabled):

```
https://getway.legitconsult.co.tz/index.php/api/clickpesa/webhook
```

Local XAMPP example:

```
http://localhost/Getways-App/frontend/web/api/clickpesa/webhook
```

Health check: `GET` the same URL (returns JSON). ClickPesa must use `POST`.

## Migrations

```bash
php yii migrate --migrationPath=@console/migrations
```

## Console jobs (cron)

```bash
# Every minute — process delayed / queued payouts
php yii clickpesa/process-payouts

# Sync pending payout status from ClickPesa
php yii clickpesa/sync-payout-status

# Warm or validate the cached ClickPesa token
php yii clickpesa/generate-token
```

## Environment variables

| Variable | Purpose |
|----------|---------|
| `CLICKPESA_CLIENT_ID` | API client id |
| `CLICKPESA_API_KEY` | API key |
| `CLICKPESA_CHECKSUM_KEY` | Canonical HMAC key |
| `CLICKPESA_API_BASE_URL` | Default `https://api.clickpesa.com/third-parties` |
| `CLICKPESA_WEBHOOK_TOKEN` | Optional shared webhook token |
| `CLICKPESA_ENCRYPTION_KEY` | Encrypts payout destination at rest |
| `CLICKPESA_INTERNAL_API_TOKEN` | Protects control-number / payout-status APIs |
| `CLICKPESA_AUTO_PAYOUT_ENABLED` | `false` until tests pass |
| `CLICKPESA_AUTO_PAYOUT_PHONE` | Default `255715296092` |
| `CLICKPESA_AUTO_PAYOUT_PERCENTAGE` | Default `100` |
| `CLICKPESA_AUTO_PAYOUT_MINIMUM_AMOUNT` | Default `1000` |
| `CLICKPESA_AUTO_PAYOUT_DELAY_SECONDS` | Default `60` |

## Deployment stages

1. **TEST MODE** — Auto payout OFF (default). Webhooks saved, no payout sent.
2. **MANUAL APPROVAL** — Enable auto payout + keep “Require manual approval”.
3. **LIVE AUTO PAYOUT** — Disable manual approval only after tests pass.

## Admin UI

Backend (login required):

- `/clickpesa/control-numbers`
- `/clickpesa/payouts`
- `/clickpesa/settings` — change auto-payout number (default `+255715296092`, shown masked)

Changing destination or enabling auto payout requires admin password.

## API routes

| Method | Path |
|--------|------|
| POST | `/api/clickpesa/control-number` |
| GET | `/api/clickpesa/control-number/<id>/invoice` |
| GET | `/api/clickpesa/account-balance` |
| GET | `/api/clickpesa/account-statement` |
| POST | `/api/clickpesa/sync-transactions` |
| GET / POST | `/api/clickpesa/auto-payout/settings` |
| GET | `/api/clickpesa/control-numbers` |
| GET | `/api/clickpesa/payouts` |
| POST | `/api/clickpesa/webhook` |
| GET | `/api/clickpesa/payment-status/<reference>` |
| GET | `/api/clickpesa/payout-status/<reference>` |
| POST | `/api/clickpesa/retry-payout/<id>` |

## Rollback

```bash
php yii migrate/down 1
```

Disables new tables/columns from `m260719_200000_clickpesa_billpay_payout_tables`.
Keep auto payout OFF before rolling back if live payouts were queued.
