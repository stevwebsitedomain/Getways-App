# ClickPesa Automatic Payout — Setup Guide

## Wapi kuweka API credentials (Where to put API keys)

### 1. Faili kuu la `.env` (recommended kwa XAMPP/local)

Nakili `.env.example` → `.env` kwenye **mzizi wa mradi**:

```
c:\xampp\htdocs\Getways-App\.env
```

Weka thamani halisi kutoka ClickPesa Dashboard → **Settings → Developers**:

```env
CLICKPESA_CLIENT_ID=your-client-id-here
CLICKPESA_API_KEY=your-api-key-here
CLICKPESA_CHECKSUM_SECRET=your-checksum-secret-if-enabled
CLICKPESA_WEBHOOK_URL=https://YOUR-DOMAIN.com/api/clickpesa/webhook

CLICKPESA_PAYOUT_ENABLED=false
CLICKPESA_PAYOUT_TEST_MODE=true
CLICKPESA_DEFAULT_PAYOUT_PHONE=255715296092
```

**Muhimu:** `.env` haipaswi kuingizwa Git. PHP inasoma `.env` kupitia `common/bootstrap/EnvBootstrap.php`.

### 2. Server environment (production)

Kwenye hosting (Railway, cPanel, Apache, nginx), weka vigezo vilevile kwenye panel ya environment variables — si kwenye frontend JS.

### 3. Yii params-local (alternative)

Unaweza pia kuweka kwenye:

- `common/config/params-local.php` (baada ya `php init`)
- `environments/dev/common/config/params-local.php` (template)

```php
'clickpesa' => [
    'clientId' => '...',
    'apiKey' => '...',
],
```

### 4. Node backend (`tis-clickpesa/`) — collections tu

Kwa malipo ya TIS/checkout (si payout), tumia `tis-clickpesa/.env`:

```env
CLIENT_ID=
API_KEY=
CHECKSUM_KEY=
```

Payout ya Mobile Money inafanywa na **PHP Yii** (`ClickPesaPayoutService`), si Node.

---

## Webhook URL

Sajili kwenye ClickPesa Dashboard:

```
https://YOUR-DOMAIN.com/api/clickpesa/webhook
```

Local XAMPP:

```
http://localhost/Getways-App/frontend/web/api/clickpesa/webhook
```

---

## Database migration

```bash
php yii migrate --migrationPath=@console/migrations
```

---

## Cron jobs (kila dakika)

```bash
php yii clickpesa/process-payouts
php yii clickpesa/sync-payout-status
php yii clickpesa/generate-token
```

---

## Hatua za kuwasha (staging → live)

| Hatua | CLICKPESA_PAYOUT_ENABLED | CLICKPESA_PAYOUT_TEST_MODE | Admin mode |
|-------|--------------------------|----------------------------|------------|
| Dev/Test | false | true | TEST |
| Staging | true | true | MANUAL_APPROVAL |
| Live | true | false | LIVE_AUTO (baada ya uthibitisho) |

1. Weka credentials kwenye `.env`
2. Thibitisha webhook na balance kwenye admin dashboard
3. Jaribu **Manual payout** (preview → confirm) kwenye TEST MODE
4. Weka `CLICKPESA_PAYOUT_TEST_MODE=false` tu baada ya ClickPesa kuthibitisha Payout API
5. Weka `CLICKPESA_PAYOUT_ENABLED=true` na admin **Auto payout ON**

---

## API endpoints (admin / server-side)

| Method | Path | Maelezo |
|--------|------|---------|
| GET | `/api/clickpesa/account-balance` | Salio la wallet |
| GET | `/api/clickpesa/payout-summary` | Takwimu za dashboard |
| GET | `/api/clickpesa/payouts` | Orodha + filters |
| POST | `/api/clickpesa/preview-payout` | Manual payout hatua 1 |
| POST | `/api/clickpesa/confirm-payout` | Manual payout hatua 2 |
| POST | `/api/clickpesa/refresh-payout-status` | Sync status |
| GET/POST | `/api/clickpesa/auto-payout/settings` | Mipangilio |
| POST | `/api/clickpesa/webhook` | ClickPesa callbacks |

Admin portal (`admin-dashboard.php`) hutumia `admin-api.php?action=...` — credentials hazionekani kwenye browser.

---

## Default recipient

| Muonekano | Thamani |
|-----------|---------|
| UI | +255715296092 |
| API | 255715296092 |

Weka kwenye `.env`:

```env
CLICKPESA_DEFAULT_PAYOUT_PHONE=255715296092
```

---

## Usalama

- Kamwe usiweke `CLICKPESA_API_KEY` kwenye JavaScript au Git
- Weka `CLICKPESA_PAYOUT_ENABLED=false` hadi ujaribu
- Weka `CLICKPESA_PAYOUT_TEST_MODE=true` wakati wa maendeleo
- Tumia **Emergency Stop** kwenye admin settings kuzima payout mara moja
