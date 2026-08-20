# ClickPesa BillPay + Safe Auto Payout

## Webhook URLs (register in ClickPesa dashboard)

Node backend on Render (wallet / TIS payments):

```
https://getways-app.onrender.com/webhook
```

Yii2 BillPay module (pretty URLs):

```
https://getway.legitconsult.co.tz/api/clickpesa/webhook
```

With `index.php` (if rewrite not enabled):

```
https://getway.legitconsult.co.tz/index.php/api/clickpesa/webhook
```

Local XAMPP example (Yii module only):

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

## Environment variables (Payout v2)

See full guide: [docs/CLICKPESA_PAYOUT_SETUP.md](CLICKPESA_PAYOUT_SETUP.md)

| Variable | Purpose |
|----------|---------|
| `CLICKPESA_BASE_URL` | Default `https://api.clickpesa.com/third-parties` |
| `CLICKPESA_CLIENT_ID` | API client id |
| `CLICKPESA_API_KEY` | API key |
| `CLICKPESA_CHECKSUM_ENABLED` | `true` when checksum required |
| `CLICKPESA_CHECKSUM_SECRET` | HMAC secret |
| `CLICKPESA_WEBHOOK_URL` | Public webhook URL |
| `CLICKPESA_PAYOUT_ENABLED` | `false` until tested |
| `CLICKPESA_PAYOUT_TEST_MODE` | `true` in dev — skips real create API |
| `CLICKPESA_DEFAULT_PAYOUT_PHONE` | Default `255715296092` |
| `CLICKPESA_HTTP_TIMEOUT_SECONDS` | Default `30` |
| `CLICKPESA_TOKEN_REFRESH_BEFORE_EXPIRY_SECONDS` | Default `300` |

Legacy aliases still work: `CLICKPESA_API_BASE_URL`, `CLICKPESA_AUTO_PAYOUT_*`, `CLICKPESA_CHECKSUM_KEY`.

## Automatic payout after USSD (same credentials — no separate Payout product)

USSD AutoPay uses `AUTOPAY_CLIENT_ID` / `AUTOPAY_API_KEY` to `generate-token`, then:

1. `POST /payouts/preview-mobile-money-payout`
2. `POST /payouts/create-mobile-money-payout`
3. `GET /payouts/{orderReference}`

Recipient: `255715296092`. Order references must be **alphanumeric only**.


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
