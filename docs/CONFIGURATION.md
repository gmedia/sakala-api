# Configuration

Konfigurasi berasal dari environment dan dibaca melalui file di `config/`. Jangan memanggil `env()` dari application code.

## Aplikasi

- `APP_URL`: URL API.
- `SAKALA_CONSOLE_URL`: URL console first-party.
- `SAKALA_API_RATE_LIMIT`: request per menit untuk limiter API dasar.
- `SAKALA_LOGIN_RATE_LIMIT`: percobaan login per menit berdasarkan email ternormalisasi dan IP.
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

## Login Google

- `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`: credential OAuth client Google.
- `GOOGLE_REDIRECT_URI`: callback login Google. Callback local default:
  `http://api.sakala.localhost:8000/auth/google/callback`.

Callback URI Google Cloud OAuth Client harus sama persis dengan
`GOOGLE_REDIRECT_URI`. Credential Google hanya dibaca melalui `config/services.php`
dan tidak boleh dikirim ke browser, log, atau response API.

## Realtime dan Broadcasting

Deployment yang mengaktifkan realtime menggunakan `BROADCAST_CONNECTION=reverb`
dan menjalankan `php artisan reverb:start` serta `php artisan queue:work`
sebagai proses terpisah dari PHP-FPM. Event deployment mengimplementasikan
`ShouldBroadcast`, sehingga queue worker wajib aktif agar event benar-benar
dikirim ke Reverb.

`REVERB_HOST`, `REVERB_PORT`, dan `REVERB_SCHEME` pada API menunjuk ke endpoint
internal Reverb yang dipakai broadcaster server-side. Browser memakai public
host, port, scheme, dan `REVERB_APP_KEY` melalui konfigurasi publik Console;
`REVERB_APP_SECRET` tidak boleh dikirim ke browser.

Private channel diautentikasi melalui `POST /broadcasting/auth` menggunakan
session `web`. Endpoint tersebut termasuk dalam konfigurasi CORS, tetapi tetap
hanya menerima origin eksplisit dari `CORS_ALLOWED_ORIGINS` dan tidak menerima
bearer token sebagai pengganti session Console.

### Pengaturan di GitHub App

Selain environment API, konfigurasi GitHub App harus memakai nilai berikut agar
flow installation dan perubahan cakupan repository bekerja:

```text
Setup URL:
https://api.sakala.dev/auth/github/setup

Redirect on update:
ON

Request user authorization (OAuth) during installation:
OFF
```

Sesuaikan host pada Setup URL dengan nilai `GITHUB_APP_SETUP_URI`. `Redirect on
update` membuat GitHub memanggil Setup URL setelah user menambah atau menghapus
repository dari installation. OAuth saat installation harus dimatikan karena
Sakala memulai user-to-server OAuth sendiri melalui
`GET /auth/github/redirect`; bila dinyalakan GitHub mengarahkan flow tersebut
ke OAuth callback, bukan Setup URL.
