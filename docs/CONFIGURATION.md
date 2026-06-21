# Configuration

Konfigurasi berasal dari environment dan dibaca melalui file di `config/`. Jangan memanggil `env()` dari application code.

## Aplikasi

- `APP_URL`: URL API.
- `SAKALA_CONSOLE_URL`: URL console first-party.
- `SAKALA_API_RATE_LIMIT`: request per menit untuk limiter API dasar.
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

## GitHub dan Realtime

- `GITHUB_CLIENT_ID`, `GITHUB_CLIENT_SECRET`, `GITHUB_REDIRECT_URI`: credential OAuth.
- `REVERB_APP_*`: credential aplikasi Reverb.
- `REVERB_ALLOWED_ORIGINS`: allowlist origin WebSocket dipisahkan koma.

Nilai credential sengaja kosong di `.env.example`; buat nilai lokal sendiri dan gunakan secret manager di environment deployment.
