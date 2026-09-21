# Monitoring API + Admin dashboard

Links localhost installations (Online Business Tracker / Tra-Masaki) to ACS Portal.

## Endpoints

| Method | URL |
|--------|-----|
| POST | `https://getway.legitconsult.co.tz/monitoring-api/receive-logs.php` |
| POST | `https://getway.legitconsult.co.tz/monitoring-api/check-device.php` |

Admin UI (after admin login): sidebar **Monitoring** → Devices / Activity / Device detail.  
Admin JSON: `monitoring-admin-api.php?action=devices|device|activity|update-device` (session auth; never returns API keys).

## Auth headers (required)

- `Authorization: Bearer {CLIENT_API_KEY}`
- `X-Device-ID: BOSS-PC-001`
- `X-Timestamp: {unix seconds}`
- `X-Signature: HMAC-SHA256(api_key, timestamp + "." + raw_json_body)` as hex
- `Content-Type: application/json`
- HTTPS only (localhost allowed for XAMPP)
- Timestamp skew max ±300 seconds
- Rate limit: 60 requests / API key / minute

Seeded device:

- `device_id`: `BOSS-PC-001`
- `api_key`: `qbp385A4STrK6hRattuLqI7NM2peQNVHLACwJ3go` (stored hashed only)
- `status`: `active`
- `daily_search_limit`: `100`
- `license_expires_at`: `2026-12-31 23:59:59`

## Schema / migrate

- SQL: `docs/sql/monitoring_schema.sql`
- Yii: `php yii migrate --migrationPath=@console/migrations` (class `m260921_220000_create_monitoring_tables`)
- Tables also auto-create + seed on first `monitoring-api` / admin API hit via `monEnsureSchema()`.

## Curl tests

Replace `BASE` if testing locally (`http://localhost/Getways-App/frontend/web`).

### Bash / Git Bash

```bash
BASE="https://getway.legitconsult.co.tz"
KEY="qbp385A4STrK6hRattuLqI7NM2peQNVHLACwJ3go"
DEVICE="BOSS-PC-001"
TS=$(date +%s)

# --- check-device ---
BODY='{"device_id":"BOSS-PC-001","device_uuid":"uuid-here","checked_at":"2026-09-21T12:00:00+03:00"}'
SIG=$(printf '%s' "${TS}.${BODY}" | openssl dgst -sha256 -hmac "$KEY" | awk '{print $2}')

curl -sS -X POST "$BASE/monitoring-api/check-device.php" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $KEY" \
  -H "X-Device-ID: $DEVICE" \
  -H "X-Timestamp: $TS" \
  -H "X-Signature: $SIG" \
  -d "$BODY"

# --- receive-logs ---
TS=$(date +%s)
BODY='{"device_id":"BOSS-PC-001","device_uuid":"uuid-here","sent_at":"2026-09-21T12:00:00+03:00","logs":[{"local_id":123,"user_id":null,"username":"masaki","action":"search_performed","description":"Search: masaki","endpoint":"/api/airbnb/jobs","request_method":"POST","status":"ok","ip_address":"127.0.0.1","device_id":"BOSS-PC-001","metadata":{"query":"masaki"},"created_at":"2026-09-21 12:00:00"}]}'
SIG=$(printf '%s' "${TS}.${BODY}" | openssl dgst -sha256 -hmac "$KEY" | awk '{print $2}')

curl -sS -X POST "$BASE/monitoring-api/receive-logs.php" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $KEY" \
  -H "X-Device-ID: $DEVICE" \
  -H "X-Timestamp: $TS" \
  -H "X-Signature: $SIG" \
  -d "$BODY"
```

### PowerShell

```powershell
$Base = "https://getway.legitconsult.co.tz"
$Key = "qbp385A4STrK6hRattuLqI7NM2peQNVHLACwJ3go"
$Device = "BOSS-PC-001"
$Ts = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds().ToString()
$Body = '{"device_id":"BOSS-PC-001","device_uuid":"uuid-here","checked_at":"2026-09-21T12:00:00+03:00"}'
$hmac = New-Object System.Security.Cryptography.HMACSHA256
$hmac.Key = [Text.Encoding]::UTF8.GetBytes($Key)
$Sig = ([BitConverter]::ToString($hmac.ComputeHash([Text.Encoding]::UTF8.GetBytes("$Ts.$Body")))).Replace("-","").ToLowerInvariant()

Invoke-RestMethod -Method POST -Uri "$Base/monitoring-api/check-device.php" -ContentType "application/json" -Body $Body -Headers @{
  Authorization = "Bearer $Key"
  "X-Device-ID" = $Device
  "X-Timestamp" = $Ts
  "X-Signature" = $Sig
}
```

Expected `check-device` shape:

```json
{
  "success": true,
  "device_status": "active",
  "license_expires_at": "2026-12-31 23:59:59",
  "daily_search_limit": 100,
  "maintenance_mode": false,
  "message": null
}
```

Expected `receive-logs`: `{ "success": true, "accepted": N, "message": "Logs stored" }`

## Security notes

- API keys hashed at rest (`password_hash`)
- Prepared statements everywhere
- Admin UI never receives plaintext or hashed keys
- Remote control fields only: status, license_expires_at, daily_search_limit, maintenance_mode, message
- No remote file delete / DB wipe / code execution
