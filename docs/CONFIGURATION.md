# Configuration

Konfigurasi berasal dari environment dan dibaca melalui file di `config/`. Jangan memanggil `env()` dari application code.

## Aplikasi

- `APP_URL`: URL API.
- `SAKALA_CONSOLE_URL`: URL console first-party.
- `SAKALA_API_RATE_LIMIT`: request per menit untuk limiter API dasar.
- `SAKALA_OAUTH_RATE_LIMIT`: request per menit per IP untuk browser OAuth route.
- `SAKALA_API_VERSION`: versi kontrak yang ditampilkan pada OpenAPI.
- `SCRAMBLE_ENABLED`: izinkan akses dokumentasi API di environment selain `local`.

## Database dan Infrastruktur

- `DB_*`: koneksi PostgreSQL.
- `REDIS_*`: cache dan scaling Reverb.
- `QUEUE_CONNECTION`: queue driver; local default memakai database.
- `MAIL_*`: Mailpit pada local runtime.

## Browser Authentication

- `SESSION_DOMAIN`: parent domain cookie, misalnya `.sakala.localhost`.
- `SANCTUM_STATEFUL_DOMAINS`: host/port console dan API yang dianggap first-party.
- `CORS_ALLOWED_ORIGINS`: daftar origin console dipisahkan koma.

Untuk Sanctum SPA session, `CORS_ALLOWED_ORIGINS` harus berisi origin Console
secara eksplisit dan tidak boleh menggunakan wildcard. Console harus mengirim
request dengan credentials agar cookie session dan CSRF dapat dipakai.

## GitHub App dan Realtime

- `GITHUB_APP_ID`, `GITHUB_APP_SLUG`: identitas GitHub App.
- `GITHUB_APP_CLIENT_ID`, `GITHUB_APP_CLIENT_SECRET`: credential user-to-server OAuth GitHub App.
- `GITHUB_APP_PRIVATE_KEY_PATH`: path File variable private key PEM GitHub App.
- `GITHUB_APP_WEBHOOK_SECRET`: secret HMAC SHA-256 webhook GitHub App.
- `GITHUB_APP_REDIRECT_URI`, `GITHUB_APP_SETUP_URI`: callback login dan setup installation.
  Callback login local default:
  `http://api.sakala.localhost:8000/auth/github/callback`.
- `REVERB_APP_*`: credential aplikasi Reverb.
- `REVERB_ALLOWED_ORIGINS`: allowlist origin WebSocket dipisahkan koma.

Nilai credential sengaja kosong di `.env.example`; buat nilai lokal sendiri dan gunakan GitLab File variable untuk private key di environment deployment. Private key, webhook secret, dan installation token tidak boleh masuk log, OpenAPI, atau database.
