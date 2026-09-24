# Monitoring API + Admin dashboard

Links localhost installations (Online Business Tracker / Tra-Masaki) to ACS Portal using a **dedicated** MySQL database (`MONITORING_DB_*` in project-root `.env`).

Credentials are never committed. `.env` lives outside the public web root; `frontend/web/.htaccess` also denies `.env` if present under web.

## Endpoints

| Method | URL |
|--------|-----|
| POST | `https://getway.legitconsult.co.tz/monitoring-api/receive-logs.php` |
| POST | `https://getway.legitconsult.co.tz/monitoring-api/check-device.php` |

Admin UI (after admin login): sidebar **Monitoring** → Devices / Activity / Device detail.  
Admin JSON: `monitoring-admin-api.php?action=devices|device|activity|update-device|csrf` (session + CSRF on updates; never returns API keys).

## Auth headers

- `Authorization: Bearer {CLIENT_API_KEY}`
- `X-Device-ID: BOSS-PC-001`
- Optional (recommended): `X-Timestamp` + `X-Signature` = HMAC-SHA256(api_key, `timestamp + "." + raw_json_body`) hex
- `Content-Type: application/json`
- HTTPS only (localhost allowed for XAMPP)
- Timestamp skew max ±300 seconds when signature headers are sent
- Rate limit: 60 requests / API key / minute

## Env (project root `.env`)

```env
MONITORING_DB_HOST=localhost
MONITORING_DB_PORT=3306
MONITORING_DB_NAME=reacrisc_tra_masaki
MONITORING_DB_USER=reacrisc_masaki
MONITORING_DB_PASSWORD=
MONITORING_OFFLINE_AFTER_MINUTES=10
```

On StackCP production use `localhost`. Do not point a public ACS host at a developer PC MySQL.

## Schema / seed

- SQL: `docs/sql/monitoring_schema.sql`
- Yii mirror: `console/migrations/m260922_090000_create_monitored_devices_schema.php`
- Tables auto-create on first API hit via `monEnsureSchema()`
- Seed device: `php scripts/seed-monitoring-device.php` (prints plaintext API key once)

Tables: `monitored_devices`, `remote_activity_logs`, `device_control_audit`.

Seeded device:

- `device_id`: `BOSS-PC-001`
- `device_name`: `Boss Local Computer`
- `status`: `active`
- API key stored only as `password_hash()` in `api_key_hash`

## Curl / PowerShell tests

```bash
BASE="https://getway.legitconsult.co.tz"
KEY="YOUR_PLAIN_API_KEY"
DEVICE="BOSS-PC-001"
TS=$(date +%s)
BODY='{"device_id":"BOSS-PC-001","checked_at":"2026-09-22T12:00:00+03:00"}'
SIG=$(printf '%s' "${TS}.${BODY}" | openssl dgst -sha256 -hmac "$KEY" | awk '{print $2}')

curl -sS -X POST "$BASE/monitoring-api/check-device.php" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $KEY" \
  -H "X-Device-ID: $DEVICE" \
  -H "X-Timestamp: $TS" \
  -H "X-Signature: $SIG" \
  -d "$BODY"
```

Or: `php scripts/test-monitoring-api.php http://localhost/Getways-App/frontend/web YOUR_PLAIN_API_KEY`

Expected `check-device`:

```json
{
  "success": true,
  "device_status": "active",
  "license_expires_at": "2026-12-31 23:59:59",
  "daily_search_limit": 100,
  "maintenance_mode": false,
  "message": null,
  "server_time": "2026-09-22 12:00:00"
}
```

Expected `receive-logs`:

```json
{
  "success": true,
  "accepted_ids": [1, 2],
  "duplicate_ids": [],
  "message": "Logs received successfully"
}
```

## Security

- Dedicated monitoring DB via PDO + prepared statements + utf8mb4
- API keys hashed at rest; never returned to admin UI
- Admin session required; CSRF on control updates; audit trail in `device_control_audit`
- Online/offline from `last_seen_at` (default 10 minutes)
- Metadata strips passwords/tokens/cookies/session IDs
- No remote shell / DB wipe / file-delete controls

## Search, downloads, control, SMS

| Method | URL |
|--------|-----|
| POST | `https://getway.legitconsult.co.tz/monitoring-api/receive-search.php` |
| POST | `https://getway.legitconsult.co.tz/monitoring-api/receive-download.php` |

`check-device.php` also returns `device_id`, `control_mode`, `control_version`, `daily_download_limit`, `blocked_message`, and `server_time`.

SMS uses meseji `POST /api/v1/sms/send` with `x-api-key`, `sender_id`, `message`, and `contacts`. Credentials stay in `.env` (`SMS_API_KEY`, `SMS_SENDER_ID=MESEJI`). One row in `sms_alert_logs` per search, so a duplicate sync does not send again.
