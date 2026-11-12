# Configuration

Konfigurasi berasal dari environment dan dibaca melalui file di `config/`. Jangan memanggil `env()` dari application code.

## Aplikasi

- `APP_URL`: URL API.
- `SAKALA_CONSOLE_URL`: URL console first-party.
- `SAKALA_LANDING_URL`: situs publik yang menyajikan `/privasi` dan `/syarat` (default `https://sakala.dev`). Footer setiap email menautkan keduanya. Deployment self-hosted mengisinya dengan situsnya sendiri.
- `SAKALA_MAIL_LOGO_URL`: URL HTTPS absolut ke PNG logo di header setiap email (default `https://sakala.dev/email/sakala-logo@2x.png`). Gambarnya 280 x 118 px dan tampil 140 x 59 px. Deployment self-hosted boleh membiarkan default-nya atau mengarahkannya ke PNG sendiri dengan ukuran yang sama; detailnya di [Email](EMAIL.md#layout).
- `SAKALA_API_RATE_LIMIT`: request per menit per user untuk route API yang sudah login (default `240`, yaitu 4 request per detik). Satu halaman deployment yang memantau status, event, dan log memakai kira-kira 60 sampai 90 request per menit, jadi 60 sudah habis oleh dua tab, sedangkan 240 masih memberi ruang untuk beberapa tab.
- `SAKALA_API_GUEST_RATE_LIMIT`: request per menit per IP untuk route API tanpa login (default `60`). Limiter login, registrasi, email verifikasi, dan reset kata sandi berjalan terpisah di atasnya dan tidak berubah.
- `SAKALA_LOGIN_RATE_LIMIT`: percobaan login per menit berdasarkan email ternormalisasi dan IP.
- `SAKALA_REGISTER_RATE_LIMIT`: percobaan registrasi per menit berdasarkan IP (default `5`).
- `SAKALA_OAUTH_RATE_LIMIT`: request per menit per IP untuk browser OAuth route.
- `SAKALA_EMAIL_VERIFICATION_RATE_LIMIT`: request pengiriman ulang email verifikasi per menit berdasarkan email ternormalisasi dan IP (default `5`).
- `SAKALA_PASSWORD_RESET_RATE_LIMIT`: request lupa dan reset kata sandi per menit berdasarkan email ternormalisasi dan IP (default `5`).
- `SAKALA_ACCOUNT_SECURITY_RATE_LIMIT`: request ganti kata sandi dan email, serta membaca, menerima, dan menolak undangan workspace, per menit per user (default `5`, dihitung bersama).
- `SAKALA_INSPECTION_RATE_LIMIT`: permintaan inspeksi ulang per menit per user (default `5`).
- `SAKALA_ACCOUNT_EXPORT_RATE_LIMIT`: ekspor data per jam per user (default `3`).
- `SAKALA_WORKSPACE_INVITATION_RATE_LIMIT`: undangan workspace per jam per user (default `20`), karena setiap undangan mengirim email ke alamat pilihan pengguna.
- `SAKALA_ACCOUNT_EXPORT_MAX_ITEMS`: isi maksimal per koleksi dalam satu ekspor (default `1000`).
- `SAKALA_OAUTH_REAUTHENTICATION_MINUTES`: berapa menit setelah login lewat provider akun tanpa kata sandi boleh menghapus akunnya (default `15`).
- `SAKALA_LEGAL_TERMS_VERSION`, `SAKALA_LEGAL_ACCEPTABLE_USE_VERSION`, `SAKALA_LEGAL_PRIVACY_NOTICE_VERSION`: versi dokumen yang berlaku (default `syarat-v1`, `penggunaan-wajar-v1`, `privasi-v1`). Mengganti dua yang pertama meminta semua pengguna menyetujui ulang. Lihat [Persetujuan Dokumen](AUTHENTICATION.md#persetujuan-dokumen).
- `SAKALA_ABUSE_EMAIL`, `SAKALA_PRIVACY_EMAIL`: kontak yang disebut di pesan ke pengguna (default `abuse@sakala.dev`, `privacy@sakala.dev`). Deployment self-hosted mengganti keduanya.
- `SAKALA_NOTIFICATION_RETENTION_DAYS`: umur notifikasi in-app sebelum dihapus `notifications:prune` (default `90`).
- `SAKALA_DELETED_PROJECT_RETENTION_DAYS`: berapa hari project yang dihapus disimpan sebelum `projects:purge-deleted` menghapusnya permanen (default `30`, minimal `1`). `GET /api/v1/app/pilot-limits` menyajikannya sebagai `deleted_project_retention_days`, jadi console tidak perlu menyimpan salinannya. Lihat [Menghapus Project](API_CONVENTIONS.md#menghapus-project).
- `SAKALA_MAX_OWNED_TEAMS_PER_USER`: jumlah workspace tim yang boleh dimiliki satu user (default `2`). `users.max_owned_teams` menaikkannya per user.
- `SAKALA_WORKSPACE_INVITATION_TTL_HOURS`: masa berlaku undangan workspace dalam jam (default `168`).
- `SAKALA_WORKSPACE_INVITATION_RETENTION_HOURS`: berapa jam undangan, tertutup atau tidak, disimpan setelah masa berlakunya sebelum `workspaces:prune-invitations` menghapusnya (default `168`). Selama itu tautannya masih menjelaskan kenapa tidak berlaku. Lihat [Undangan](API_CONVENTIONS.md#undangan).
- `SAKALA_MAX_PROJECTS_PER_USER`, `SAKALA_MAX_ACTIVE_DEPLOYMENTS_PER_USER`, `SAKALA_MAX_CONTAINER_MEMORY_MB`, `SAKALA_MAX_CONTAINER_CPU_MILLIS`: batas workspace pribadi yang tidak punya profil batas. Namanya lebih tua dari workspace; artinya per workspace. `SAKALA_MAX_ACTIVE_CONTAINERS_PER_WORKSPACE` membatasi container berjalan, dan bila kosong sama dengan jumlah project, sehingga tidak pernah membatasi.
- `SAKALA_TEAM_MAX_PROJECTS` (default `2`), `SAKALA_TEAM_MAX_ACTIVE_CONTAINERS` (default `2`), `SAKALA_TEAM_MAX_ACTIVE_DEPLOYMENTS` (default `1`): batas workspace tim tanpa profil batas. Sengaja kecil dan tidak mengikuti batas pribadi, supaya membuat tim tidak menggandakan kuota; nilai kosong memakai default ini. `SAKALA_TEAM_MAX_CONTAINER_MEMORY_MB` dan `SAKALA_TEAM_MAX_CONTAINER_CPU_MILLIS` yang kosong memakai batas pribadi. Lihat [Batas Workspace](API_CONVENTIONS.md#batas-workspace).
- `SAKALA_API_VERSION`: versi kontrak yang ditampilkan pada OpenAPI.
- `SCRAMBLE_ENABLED`: izinkan akses dokumentasi API di environment selain `local`.

`AUTH_VERIFICATION_EXPIRE` menentukan masa berlaku signed URL verifikasi email
dalam menit (default `60`).

- `SAKALA_PROJECT_DEFAULT_DOMAIN`: domain induk project, misalnya `run.sakala.dev`.
- `SAKALA_PROJECT_URL_SCHEME`, `SAKALA_PROJECT_URL_PORT`: membentuk `public_url` project. Default `https` tanpa port; lingkungan lokal biasanya `http`, dan port diisi bila runtime tidak di 80 atau 443.
- `SAKALA_AVATAR_STORAGE`: `local` menyimpan foto profil di `storage/app/public` (perlu `php artisan storage:link`, sudah dijalankan `composer setup`). Default `s3` memakai bucket `AWS_*`. Bila penyimpanan tidak bisa dipakai, API menjawab `503 AVATAR_STORAGE_UNAVAILABLE`.
- `GITHUB_PUBLIC_API_TOKEN`: opsional, disarankan untuk produksi. Token GitHub baca-saja untuk repository publik. Tanpa token, GitHub membatasi 60 request per jam per IP server untuk semua user. Token ini hanya dikirim ke GitHub, tidak pernah ke agent, browser, atau log. Beri akses publik saja; API tetap menolak membaca repository private lewat alur URL publik. Bila GitHub menolak token ini (`401`, atau `403` karena kebijakan organisasi seperti batas umur fine-grained token 366 hari atau SAML SSO), pembacaan jatuh ke request anonim dan log mendapat warning berisi pesan GitHub. Pakai fine-grained token dengan masa berlaku paling lama 366 hari supaya organisasi seperti itu tetap mendapat kuota token.

`APP_LOCALE` menentukan bahasa pesan validasi, default `id`.

GitHub App harus berlangganan event `push` supaya `auto_deploy` bekerja, selain event `installation` yang sudah dipakai.

## Agent

- `SAKALA_AGENT_COMMAND_BATCH_SIZE`: jumlah maksimum command per response polling.
- `SAKALA_AGENT_SUPPORTED_PROTOCOL_VERSIONS`: daftar protocol revision (dipisah koma) yang boleh menerima command; default `4`. Node dengan revisi lain tetap dapat heartbeat tetapi tidak dijadwalkan.
- `SAKALA_AGENT_OFFLINE_AFTER_SECONDS`: node yang heartbeat terakhirnya lebih lama dari ini tidak dipilih sebagai target command baru dan ditandai `offline` oleh `agent:mark-offline-nodes`; default `60`.
- `SAKALA_AGENT_LEASE_GRACE_SECONDS`: ditambahkan ke `command_timeout_seconds` saat claim untuk membentuk lease command; setelah lewat, `agent:expire-commands` menutup command dan deployment terkait. Default `60`.

## Deployments

- `SAKALA_SIMULATE_DEPLOYMENTS`: `true` menjalankan `SimulatedDeploymentJob` yang memalsukan lifecycle deployment tanpa runtime node — hanya untuk pengembangan console lokal. Default `false`; jangan aktifkan bila ada agent yang terhubung karena keduanya akan menggerakkan record deployment yang sama.

## Agent Reporting dan Log Retention

- `SAKALA_LOG_MAX_LINE_LENGTH`: batas panjang satu message dalam byte.
- `SAKALA_LOG_MAX_BATCH_LINES`: jumlah maksimum item dalam satu report.
- `SAKALA_LOG_MAX_TOTAL_BYTES`: budget kumulatif message log per command.
- `SAKALA_LOG_MAX_REQUEST_BYTES`: batas ukuran body setiap request report, terpisah dari budget kumulatif.
- `SAKALA_LOG_RETENTION_DAYS`: durasi retensi log pilot dalam hari (default: `7`). Lihat [Pilot Log Retention Policy](LOG_RETENTION.md). Cleanup otomatis berjalan melalui scheduler harian ketika runtime menjalankan Laravel scheduler.

## Database dan Infrastruktur

- `DB_*`: koneksi PostgreSQL. `DB_TIMEZONE` (default `UTC`) mengunci timezone sesi PostgreSQL; control plane menyimpan dan mengembalikan UTC apa pun timezone server database.
- `REDIS_*`: cache dan scaling Reverb. Runtime lokal memakai Valkey (`REDIS_HOST=valkey`, port `FORWARD_VALKEY_PORT`); Valkey kompatibel protokol Redis sehingga client `phpredis` dan konfigurasi `redis` Laravel tidak berubah.
- `CACHE_STORE`: cache store default yang juga dipakai Laravel scheduler untuk mutex `withoutOverlapping`/`onOneServer`. Gunakan store yang mendukung lock dan dibagi bersama (Valkey/Redis pada deployment multi-instance).
- `QUEUE_CONNECTION`: queue driver; local default memakai database.
- `MAIL_*`: Mailpit pada local runtime.
- `SAKALA_EMAIL_DELIVERY_ENABLED`: apakah email benar-benar bisa sampai ke pengguna. Bila kosong atau tidak diset, nilainya `true` untuk mailer selain `log` dan `array`, dan `false` untuk keduanya. Lihat "Pengiriman email".

Flow registrasi dan pengiriman ulang email verifikasi menggunakan queue
asynchronous setelah transaksi commit. Production wajib menggunakan
`QUEUE_CONNECTION=database` atau `QUEUE_CONNECTION=redis`, menjalankan
`php artisan queue:work` sebagai worker yang selalu aktif, dan mengisi
`MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`,
`MAIL_FROM_ADDRESS`, serta `MAIL_FROM_NAME` sesuai mailer/provider yang
digunakan. Jangan menggunakan `QUEUE_CONNECTION=sync` untuk flow email
verifikasi.

## Pengiriman email

`config('sakala.email_delivery_enabled')` adalah satu-satunya sumber kebenaran untuk ketersediaan email. Saat nilainya `false`, API menjawab `503 EMAIL_DELIVERY_UNAVAILABLE` pada registrasi, lupa kata sandi, kirim ulang verifikasi, dan ganti email, lalu Console menyembunyikan pilihan itu lewat `GET /api/v1/auth/options`. Login dengan kata sandi untuk akun yang sudah terverifikasi tetap berjalan.

Staging dan production yang memakai `MAIL_MAILER=log` tidak mengirim apa pun, jadi fitur itu mati dengan sendirinya. Untuk menyalakannya, cukup isi env berikut lalu buat ulang container API dan queue worker, tanpa perubahan kode:

| Env | Isi |
| --- | --- |
| `MAIL_MAILER` | `smtp`. Nilai ini sudah membuat flag menjadi `true`. |
| `MAIL_HOST`, `MAIL_PORT` | Host dan port SMTP dari provider, biasanya port `587`. |
| `MAIL_USERNAME`, `MAIL_PASSWORD` | Credential SMTP dari provider. Simpan sebagai secret. |
| `MAIL_SCHEME` | Kosongkan untuk STARTTLS di port `587`, atau `smtps` untuk port `465`. |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Alamat dan nama pengirim. Nama pengirim default-nya `Sakala` dan tidak mengikuti `APP_NAME`. |

Tampilan, isi, dan cara melihat pratinjau setiap email ada di [Email](EMAIL.md).

Domain pada `MAIL_FROM_ADDRESS` wajib punya SPF dan DKIM yang mengizinkan provider tersebut. Tanpa keduanya, email kemungkinan besar masuk spam atau ditolak. Queue worker juga harus berjalan, karena email dikirim lewat queue.

Local development memakai `MAIL_MAILER=log` dengan sengaja dan membaca tautan dari `storage/logs/laravel.log`, sehingga `.env.example` mengisi `SAKALA_EMAIL_DELIVERY_ENABLED=true`. File `.env` lokal yang dibuat sebelum variabel ini ada perlu menambahkan baris yang sama; tanpa itu registrasi lokal menjawab `503`. Environment `testing` mengisinya di `phpunit.xml`.

Nilai `false` tidak mematikan mailer. Email yang sudah terlanjur antre, misalnya pemberitahuan ke alamat lama saat tautan ganti email dibuka, tetap diproses oleh mailer yang aktif.

## Reverse Proxy

API selalu berada di belakang rantai proxy (`Cloudflare -> host Caddy -> nginx -> PHP-FPM`). `bootstrap/app.php` mempercayai loopback dan private range sebagai hop internal, sehingga `request()->ip()` mengembalikan alamat client sebenarnya dari `X-Forwarded-For`.

Tanpa ini, seluruh request terlihat berasal dari hop internal terakhir: rate limiter per-IP pada login, registrasi, OAuth, dan verifikasi email akan membatasi semua user sebagai satu IP, dan audit log kehilangan asal request.

Edge wajib menormalkan `X-Forwarded-For` menjadi satu alamat client. Bila edge meneruskan rantai penuh (`client, cloudflare`), hop terakhir yang tidak dipercaya adalah IP Cloudflare dan nilai itulah yang akan terbaca sebagai client. Konfigurasi edge ada di `sakala-deployment`.

## Browser Authentication

- `SESSION_COOKIE`: nama cookie session. Production dan staging memakai `__Host-sakala-api-session`, yang hanya diterima browser lewat HTTPS. Nama ini memaksa `Secure`, `Path=/`, dan tanpa `Domain`, apa pun isi env lain. Lokal berjalan di HTTP, jadi memakai `sakala-api-session`.
- `SESSION_DOMAIN`: kosongkan. Cookie session host-only di host API. Domain yang mencakup `SAKALA_PROJECT_DEFAULT_DOMAIN` selalu diabaikan untuk cookie session, supaya cookie itu tidak terkirim ke aplikasi pengguna walaupun env lama masih mengisinya.
- `SAKALA_XSRF_COOKIE_DOMAIN`: domain untuk `XSRF-TOKEN` saja, misalnya `.sakala.dev`. Console membaca cookie ini lewat JavaScript dari host lain, jadi cookie ini perlu parent domain. Bila kosong, nilainya mengikuti `SESSION_DOMAIN`.
- `SESSION_SECURE_COOKIE`: `true` di HTTPS. `__Host-` selalu `Secure`.
- `SANCTUM_STATEFUL_DOMAINS`: host/port console dan API yang dianggap first-party. Jangan pernah memakai wildcard yang mencakup `SAKALA_PROJECT_DEFAULT_DOMAIN`: daftar ini adalah batas yang memisahkan Console dari aplikasi pengguna.
- `CORS_ALLOWED_ORIGINS`: daftar origin console dipisahkan koma.

Alasan dan batas keamanannya ada di [Autentikasi](AUTHENTICATION.md#cookie-dan-aplikasi-pengguna).

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
- `REVERB_ALLOWED_ORIGINS`: hostname yang boleh membuka WebSocket, dipisahkan koma, misalnya `app.sakala.dev,localhost`. Reverb hanya membandingkan hostname, jadi nilai berbentuk URL dinormalkan menjadi hostname saat config dibaca, dan port tidak ikut dibandingkan. `*` dan `*.contoh.dev` juga diterima.

Nilai credential sengaja kosong di `.env.example`; buat nilai lokal sendiri dan gunakan GitLab File variable untuk private key di environment deployment. Private key, webhook secret, dan installation token tidak boleh masuk log, OpenAPI, atau database.

## Login Google

- `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`: credential OAuth client Google.
- `GOOGLE_REDIRECT_URI`: callback login Google. Callback local default:
  `http://api.sakala.localhost:8000/auth/google/callback`.

Callback URI Google Cloud OAuth Client harus sama persis dengan
`GOOGLE_REDIRECT_URI`. `GOOGLE_CLIENT_ID` adalah public identifier dan normal
untuk dikirim pada authorization redirect ke browser. `GOOGLE_CLIENT_SECRET`
adalah credential rahasia yang hanya boleh dibaca server melalui
`config/services.php`; jangan masukkan ke browser, log, atau response API.

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
## RustFS Setup

The development environment uses RustFS as an S3-compatible object storage backend.

### Environment

```env
AWS_BUCKET=sakala
AWS_ENDPOINT=http://rustfs:9000
AWS_USE_PATH_STYLE_ENDPOINT=true
AWS_URL=http://localhost:9000/sakala
```

`AWS_ENDPOINT` is the internal endpoint used by the application container to communicate with RustFS through the Docker network.

`AWS_URL` is the public base URL used when generating object URLs consumed by the browser or host environment.

With this configuration, avatar objects use the following URL format:

```text
http://localhost:9000/sakala/avatars/<object-key>
```

The `s3` and `avatars` filesystem disks share the same S3-compatible provider configuration. The `avatars` disk remains a logical domain-specific filesystem boundary, allowing avatar-specific configuration to be added independently when needed.
