# Authentication

## Console

`sakala-console` adalah SPA first-party. Browser memakai Sanctum SPA
cookie/session, bukan bearer token atau personal access token. Cookie session
bersifat HTTP-only; Console mengirim request lintas origin dengan credentials
dan memakai CSRF cookie Laravel untuk request yang mengubah state.

Endpoint Console hanya menerima session dari guard `web`. Bearer token yang
nantinya dipakai agent atau machine client tidak dapat mengakses endpoint
Console.

### Cookie dan Aplikasi Pengguna

Aplikasi pengguna berjalan di `*.run.sakala.dev`, yaitu server milik orang lain pada site yang sama dengan API dan Console. Dua hal mengikuti dari sana: cookie yang memakai `Domain=.sakala.dev` ikut terkirim ke server mereka, dan `SameSite=Lax` tidak menghalangi halaman mereka mengirim request ke API, karena bagi browser itu bukan lintas site.

| Cookie | Scope | Sampai ke aplikasi pengguna? | Isi |
| --- | --- | --- | --- |
| `__Host-sakala-api-session` | Host API saja, `Secure`, `HttpOnly`, `Path=/`, `SameSite=Lax` | Tidak | ID session terenkripsi |
| `XSRF-TOKEN` | `SAKALA_XSRF_COOKIE_DOMAIN`, `Secure`, terbaca JavaScript, `SameSite=Lax` | Ya | Token CSRF terenkripsi |

Console tetap mengirim session cookie, karena fetch dari `app.` ke `api.` adalah same-site dan cookie host-only milik `api.` ikut pada request ke `api.`. Prefix `__Host-` membuat browser menolak cookie dengan nama itu yang dipasang dari subdomain lain, sehingga aplikasi pengguna tidak bisa menanamkan session-nya ke browser korban.

**`XSRF-TOKEN` memang masih sampai ke aplikasi pengguna, dan ini sudah dinilai tidak berbahaya.** Nilainya terenkripsi dengan `APP_KEY`. Untuk dipakai, nilai itu harus dikirim sebagai header `X-XSRF-TOKEN`, sedangkan CORS tidak mengizinkan origin aplikasi pengguna mengirim header itu. Sebagai `_token` di form, nilainya tidak cocok, karena `_token` dibandingkan dengan token mentah yang tidak pernah mereka lihat.

**Halaman aplikasi pengguna tidak bisa bertindak dengan session.** Setiap route punya penjaganya:

| Route | Penjaga | Hasil dari halaman aplikasi pengguna |
| --- | --- | --- |
| `/api/v1/*`, `/api/agent/v1/agents/*` | Sanctum hanya memulai session bila `Referer` atau `Origin` ada di `SANCTUM_STATEFUL_DOMAINS` | `401`, walaupun cookie ikut terkirim |
| `POST /broadcasting/auth` | `EnsureFirstPartyOrigin`, karena Laravel mendaftarkan route ini tanpa pemeriksaan CSRF | `403 ORIGIN_NOT_ALLOWED` |
| `GET /auth/*` | Hanya `GET`. Callback OAuth dan setup GitHub butuh `state` yang tersimpan di session | Tanpa `state` yang benar, tidak ada yang berubah |

`PreventRequestForgery::allowSameSite` sengaja tetap mati: request same-site dari `app.` ke `api.` tetap wajib membawa token.

**Cookie yang ditanam aplikasi pengguna gagal dengan aman.** `XSRF-TOKEN` palsu menghasilkan `419`, tidak ada yang berubah, dan response API berikutnya menimpanya. Yang tersisa adalah gangguan: aplikasi pengguna bisa memasang `XSRF-TOKEN` dengan `Path` yang lebih panjang untuk sebagian halaman Console, yang lalu gagal `419` sampai cookie itu kedaluwarsa atau dihapus. Pengunjung harus membuka aplikasi itu lebih dulu, dan tidak ada data yang bisa diambil lewat cara ini.

**Migrasi.** Cookie `sakala-api-session` lama di `.sakala.dev` diabaikan setelah nama berganti. Nilainya juga tidak bisa dipakai di bawah nama baru, karena nilai cookie terenkripsi terikat pada namanya. Pengguna perlu login lagi, dan cookie lama hilang sendiri paling lambat setelah `SESSION_LIFETIME`.

**Lokal.** Lokal berjalan di HTTP, sehingga prefix `__Host-` tidak dipakai. `sakala-api-session` host-only di `api.sakala.localhost`, dan `XSRF-TOKEN` di `.sakala.localhost`. Chromium memang menyimpan cookie `__Host-` dari `http://api.sakala.localhost`, tetapi itu perlakuan khusus untuk `localhost`, jadi lokal tidak bergantung padanya. Tanpa prefix, aplikasi lokal bisa menanamkan `sakala-api-session` di `.sakala.localhost`, yang menimpa session dan membuat request berikutnya `401`. Ini hanya berlaku lokal, dan itulah alasan production memakai `__Host-`.

**Risiko yang tersisa.** Selama aplikasi pengguna berada di bawah `sakala.dev`, keduanya tetap same-site, sehingga pertahanan ini bergantung pada konfigurasi `SANCTUM_STATEFUL_DOMAINS` dan CORS yang benar. Arah jangka panjang ada di ADR-016: aplikasi pengguna pindah ke registrable domain terpisah yang masuk Public Suffix List. Perpindahan itu lebih dari sekadar env dan DNS: `SAKALA_PROJECT_DEFAULT_DOMAIN` hanya menentukan domain proyek baru. `projects.default_domain` proyek lama tersimpan per baris dan dikirim ke agent saat deploy, sehingga perlu diubah lalu di-deploy ulang, dan blok `*.run.sakala.dev` di Caddyfile `sakala-deployment` juga perlu diganti.

### Session Foundation

Endpoint berikut tersedia untuk Console:

| Endpoint | Kegunaan |
| --- | --- |
| `GET /sanctum/csrf-cookie` | Menetapkan cookie CSRF sebelum request state-changing. |
| `GET /api/v1/auth/options` | Publik. Pilihan masuk yang tersedia; lihat "Pilihan Masuk". |
| `POST /api/v1/auth/login` | Membuat session browser dari email/password yang valid dan terverifikasi. |
| `GET /api/v1/auth/user` | Mengembalikan user dari session browser yang aktif. |
| `POST /api/v1/auth/logout` | Mengakhiri session browser aktif. |

### Pilihan Masuk

`GET /api/v1/auth/options` bersifat publik, tidak menyentuh database, dan memberi tahu Console pilihan yang benar-benar bisa dipakai:

```json
{ "data": { "email_delivery_enabled": false, "providers": { "github": true, "google": false } } }
```

`providers.github` dan `providers.google` bernilai `true` hanya bila `client_id`, `client_secret`, dan `redirect` provider itu terisi.

### Pengiriman Email

`config('sakala.email_delivery_enabled')` menentukan apakah email bisa terkirim; cara mengisinya ada di [Configuration](CONFIGURATION.md#pengiriman-email). Saat `false`, endpoint berikut menjawab `503` dengan body yang sama sebelum validasi dijalankan, sehingga tidak bisa dipakai untuk menebak email terdaftar:

- `POST /api/v1/auth/register`
- `POST /api/v1/auth/forgot-password`
- `POST /api/v1/auth/email/verification-notification`
- `POST /api/v1/auth/email/change`, setelah session diperiksa

```json
{ "code": "EMAIL_DELIVERY_UNAVAILABLE", "message": "Sakala belum bisa mengirim email untuk saat ini, jadi pendaftaran, reset kata sandi, verifikasi, dan ganti email lewat email belum tersedia. Kalau belum punya akun, masuk lewat GitHub atau hubungi admin Sakala." }
```

Yang tetap berjalan: login kata sandi untuk akun terverifikasi, login GitHub dan Google yang terkonfigurasi, ganti kata sandi (pemberitahuannya lewat notifikasi in-app, bukan email), dan `POST /api/v1/auth/reset-password` untuk tautan yang sudah terlanjur diterima.

Akun yang belum terverifikasi tetap menerima `403 email_not_verified`, dengan `message` yang tidak lagi menyuruh membuka tautan atau mengirim ulang. Login GitHub dengan alamat email yang sama tetap ditolak `github_email_conflict`, karena API tidak menautkan akun secara otomatis. Akun seperti ini perlu bantuan admin sampai SMTP terpasang.

### Registrasi dan Verifikasi Email

Registrasi email/password tidak membuat session otomatis. Endpoint yang
tersedia:

| Endpoint | Kegunaan |
| --- | --- |
| `POST /api/v1/auth/register` | Membuat user baru berstatus belum terverifikasi dan mengantrekan email verifikasi. |
| `POST /api/v1/auth/email/verification-notification` | Mengantrekan ulang email verifikasi dengan response generik. |
| `GET /auth/email/verify/{user}/{hash}` | Memproses temporary signed URL dari email dan mengarahkan browser ke Console. |

`POST /api/v1/auth/register` selalu menjawab `202` dengan body yang sama
(`name`, `email`, `verification_required`), baik email itu baru maupun sudah
terdaftar, sehingga endpoint ini tidak bisa dipakai untuk menebak email terdaftar.
Bila sudah terdaftar, tidak ada akun baru: pemilik akun menerima email yang
memberi tahu hal itu, paling banyak sekali per jam per email.

Request register menerima `name`, `email`, `password`,
`password_confirmation`, `accept_terms`, `terms_version`, dan
`acceptable_use_version` (lihat [Persetujuan Dokumen](#persetujuan-dokumen)). Email dinormalisasi menjadi lowercase setelah
trim. User baru mendapat role `user`, username unik yang dibuat dari nama,
dan `email_verified_at` bernilai `null`.

Setelah link verifikasi berhasil, API mengarahkan browser ke
`SAKALA_CONSOLE_URL/email-verified?status=success`. Link yang tidak valid,
kedaluwarsa, atau tidak cocok diarahkan ke URL yang sama dengan
`status=error`. User yang belum terverifikasi tetap tidak dapat login.

Login dengan kata sandi yang benar untuk email yang belum diverifikasi dijawab
`403` dengan body berikut, tanpa membuat session:

```json
{ "code": "email_not_verified", "message": "Email kamu belum diverifikasi. Buka tautan verifikasi yang kami kirim ke email kamu, atau minta kirim ulang tautannya." }
```

Console memakai `code` untuk menawarkan kirim ulang lewat
`POST /api/v1/auth/email/verification-notification` dengan email yang sama;
endpoint itu tidak memerlukan session. Kata sandi salah dan email yang tidak
terdaftar tetap dijawab `401` generik yang sama persis, termasuk untuk akun yang
belum diverifikasi. Jadi `email_not_verified` hanya terlihat oleh orang yang
sudah tahu kata sandinya dan tidak bisa dipakai untuk menebak email terdaftar.
Rate limiter login berlaku untuk semua hasil ini.

Response resend selalu berbentuk response generik sehingga tidak membedakan
email yang tidak terdaftar, sudah terverifikasi, atau berhasil diproses.

Email verifikasi dikirim melalui queue setelah transaksi registrasi selesai.
Production wajib menggunakan queue asynchronous seperti `database` atau
`redis`, menjalankan `php artisan queue:work` secara terus-menerus, dan
mengonfigurasi `MAIL_*` ke mailer/provider yang benar. Jangan memakai
`QUEUE_CONNECTION=sync` untuk flow ini.

Flow session lokal:

1. Console memanggil `GET /sanctum/csrf-cookie` dengan credentials.
2. Console mengirim `POST /api/v1/auth/login` dengan `email` dan `password`, menggunakan origin first-party yang diizinkan.
3. API hanya membuat session untuk user dengan password valid dan email terverifikasi. Respons sukses berisi `UserResource` dan tidak berisi bearer token. Password valid dengan email belum terverifikasi menghasilkan `403 email_not_verified`.
4. Setelah authentication berhasil, Console dapat memanggil `GET /api/v1/auth/user` untuk bootstrap user.
5. Saat keluar, Console memanggil `POST /api/v1/auth/logout`.
6. Request `GET /api/v1/auth/user` berikutnya akan menghasilkan `401`.

Endpoint login memiliki rate limiter khusus yang default-nya mengizinkan 5
percobaan per menit untuk setiap kombinasi email ternormalisasi dan IP. Limiter
ini berjalan sebagai tambahan dari limiter API global.

### Keamanan Akun

| Endpoint | Akses | Kegunaan |
| --- | --- | --- |
| `PUT /api/v1/auth/password` | Session | Mengganti kata sandi dengan `current_password`, `password`, `password_confirmation`. Akun OAuth tanpa kata sandi (`has_password = false`) cukup mengirim dua yang terakhir. Mengembalikan `UserResource`. |
| `POST /api/v1/auth/email/change` | Session | Meminta ganti email dengan `email` dan `current_password`. Menjawab `202` dengan `pending_email`. |
| `GET /auth/email/change/{user}/{hash}` | Signed URL | Link di email baru. Menerapkan email lalu mengarahkan ke `/email-changed?status=success` atau `status=error` di console. |
| `POST /api/v1/auth/forgot-password` | Tamu | Mengirim link reset dengan `email`. Selalu `202` dengan body yang sama. |
| `POST /api/v1/auth/reset-password` | Tamu | Menerapkan `token`, `email`, `password`, `password_confirmation`. `204`, atau `422 invalid_reset_token`. |

Mengganti atau mereset kata sandi mengakhiri session lain pada request berikutnya, lewat middleware `AuthenticateSession` Sanctum yang membandingkan hash kata sandi di session, dan mencabut personal access token. Session yang melakukan perubahan tetap login. Akun OAuth yang baru membuat kata sandi pertama tidak mengakhiri session lain, karena sebelumnya tidak ada hash untuk dibandingkan.

Ganti email berlaku hanya setelah link di alamat baru dibuka, dan alamat lama diberi tahu saat diminta dan saat diterapkan. Permintaan baru mengganti `pending_email`, sehingga link lama mati, dan link hanya bisa dipakai sekali. Alamat yang sudah dipakai akun lain dijawab sama tanpa mengirim email apa pun. Akun tanpa kata sandi harus membuatnya dulu.

Link reset mengarah ke `SAKALA_CONSOLE_URL/reset-password?token=&email=`, memakai password broker Laravel (berlaku 60 menit, jeda 60 detik per akun). Email yang tidak dikenal dan permintaan yang terkena jeda dijawab sama. Reset yang berhasil juga menandai email terverifikasi, karena link itu membuktikan pemilik kotak surat, sehingga akun yang tertahan `email_not_verified` bisa login. Tamu dibatasi `SAKALA_PASSWORD_RESET_RATE_LIMIT` per email dan IP; endpoint ber-session dibatasi `SAKALA_ACCOUNT_SECURITY_RATE_LIMIT` per user.

### Persetujuan Dokumen

Versi yang berlaku ada di `config/sakala.php`, dan `GET /api/v1/auth/options` mengirimnya:

```json
{ "data": { "legal": { "terms_version": "syarat-v1", "acceptable_use_version": "penggunaan-wajar-v1", "privacy_notice_version": "privasi-v1" } } }
```

Formulir punya satu kotak centang wajib, yaitu `accept_terms`, untuk satu pernyataan: "Saya berusia minimal 18 tahun atau sudah mendapat persetujuan orang tua atau wali, dan menyetujui Syarat Layanan dan Kebijakan Penggunaan Wajar." Bersamanya Console mengirim `terms_version` dan `acceptable_use_version` yang ditampilkannya, supaya persetujuan tidak tercatat untuk versi yang tidak pernah dilihat orang itu. Versi lama dijawab `422`.

Pemberitahuan privasi tidak punya kotak centang, karena pemrosesan tidak berdasar pada persetujuan atas dokumen itu. Saat formulir dikirim, API mencatatnya sebagai `acknowledged`.

| Jalur | Cara |
| --- | --- |
| `POST /api/v1/auth/register` | Tiga field di atas wajib. Baris dicatat hanya bila akun benar-benar dibuat, jadi jawabannya tetap sama untuk email yang sudah terdaftar. |
| Login pertama lewat GitHub atau Google, dan setelah versi berganti | `GET /api/v1/auth/user` menjawab `legal_acceptance_required: true`. Console meminta persetujuan lalu memanggil `POST /api/v1/auth/legal-acceptance` dengan `accept_terms`, `terms_version`, `acceptable_use_version`, dan menerima `UserResource`. |

Tabel `legal_acceptances` menyimpan satu baris per user, dokumen, dan versi, dengan `action`, `source`, dan `occurred_at`. Tabel ini tidak menyimpan alamat IP, dan ikut terhapus bersama akun.

`legal_acceptance_required` melihat Syarat Layanan dan Kebijakan Penggunaan Wajar saja. Saat keduanya belum disetujui pada versi terbaru, membuat proyek, inspeksi ulang, dan deploy dijawab `403 LEGAL_ACCEPTANCE_REQUIRED`, dan auto-deploy dari push dilewati dengan notifikasi `project_issue`. Yang tetap jalan: membaca, membatalkan deployment, menghapus proyek, ekspor data, dan menghapus akun, supaya tidak ada yang harus setuju dulu untuk pergi. Mengganti versi privasi tidak meminta apa pun. Akun yang sudah ada sebelum fitur ini diperlakukan sama: diminta sekali, tanpa kehilangan data.

### Menghapus Akun

`DELETE /api/v1/auth/account` hanya menerima session dan menjawab `204`. Body: `email` (harus email akun itu, diketik ulang) dan `current_password`. Akun tanpa kata sandi tidak mengirim `current_password`, tetapi harus sudah login lewat provider-nya pada session yang sama dalam `SAKALA_OAUTH_REAUTHENTICATION_MINUTES` terakhir; bila belum, jawabannya `403 REAUTHENTICATION_REQUIRED`, dan Console mengarahkannya login ulang. Tindakan ini tidak bisa dibatalkan, dan dibatasi `SAKALA_ACCOUNT_SECURITY_RATE_LIMIT`.

| Yang terjadi | Keterangan |
| --- | --- |
| Proyek di workspace pribadi | Lewat jalur [Menghapus Project](API_CONVENTIONS.md#menghapus-project): workload dihentikan, attempt dibatalkan, baris menunggu `projects:purge-deleted`. Selama menunggu, `projects.user_id` dan `projects.workspace_id` bernilai `null`. Environment variable dan salinannya dihapus saat itu juga, tidak menunggu masa simpan. |
| Workspace | Workspace pribadi, semua keanggotaan, dan undangan tertunda yang dikirim akun ini dihapus. Tim yang dimiliki sendirian dan sudah kosong ikut dihapus. Tim lain tetap ada, dan project tim yang dibuat akun ini tetap milik timnya dengan `user_id` kosong. |
| Dihapus saat itu juga | Baris user, OAuth account dan tokennya, feedback, notifikasi, persetujuan dokumen, personal access token, semua session, token reset kata sandi, relasi GitHub installation, dan avatar. `pending_email` hilang bersama baris user, jadi tautan ganti email dan verifikasi yang lama menjadi tidak berlaku. |
| GitHub installation | Baris installation ikut dihapus bila tidak ada user lain yang terhubung dan tidak ada proyek hidup yang memakainya, termasuk proyek tim. Akses GitHub App di sisi GitHub dicabut sendiri oleh pengguna. |
| Yang tetap ada | `audit_events` berisi ID, termasuk event `account.deleted` tanpa email; `retired_project_slugs`; `usage_signal_records` sampai masa simpannya habis; `deployments` milik proyek sampai purge dengan `requested_by` kosong. |
| Email konfirmasi | Dikirim ke alamat lama bila `sakala.email_delivery_enabled` aktif. Penghapusan tidak bergantung pada email ini. |

Pemilik terakhir tim yang masih punya proyek dijawab `409 LAST_WORKSPACE_OWNER` dengan `workspaces` berisi tim tersebut, sebelum apa pun dihapus: jadikan anggota lain owner atau hapus proyeknya dulu. Akun admin dijawab `409 ADMIN_ACCOUNT_CANNOT_BE_DELETED`: peran admin dicabut dulu, supaya tidak ada deployment yang kehilangan satu-satunya admin. Proses bisa diulang kalau terputus, karena setiap langkahnya aman dijalankan lagi. Bila proyek baru terus muncul selama proses, jawabannya `409 ACCOUNT_DELETION_CONFLICT`; foreign key `projects.user_id` dan `projects.workspace_id` tetap `RESTRICT`, sehingga proyek yang masih hidup tidak bisa kehilangan workspace di tengah proses.

### Ekspor Data

`GET /api/v1/auth/account/export` hanya menerima session, dengan `Cache-Control: no-store`. Isinya data milik akun itu dalam `data`: `account` (termasuk `legal_acceptances`, `oauth_accounts`, `github_installations`, dan `workspaces` dengan peran serta waktu bergabung), `projects` dari workspace pribadi (termasuk yang masih dalam masa simpan setelah dihapus; project tim milik timnya dan tidak ikut), `deployments`, `environment_variables`, `notifications`, dan `feedback`.

Environment variable hanya berisi nama, `is_secret`, dan `project_id`, tidak pernah nilainya, juga untuk variabel non-secret. Token OAuth dan hash kata sandi tidak pernah ikut. Log deployment dan jejak audit tidak termasuk.

Setiap koleksi dibatasi `SAKALA_ACCOUNT_EXPORT_MAX_ITEMS` item terbaru, dan `truncated` menyebut koleksi yang terpotong. Ekspor dibatasi `SAKALA_ACCOUNT_EXPORT_RATE_LIMIT` per jam per user.

### GitHub App

GitHub App user-to-server OAuth berjalan di atas session foundation ini. OAuth adalah browser flow,
sehingga route-nya berada di web middleware, bukan di API JSON versioned:

| Endpoint | Kegunaan |
| --- | --- |
| `GET /auth/github/redirect` | Membuka authorization flow GitHub App. |
| `GET /auth/github/callback` | Menerima callback GitHub dan membuat session Console. |

Flow browser:

1. Browser membuka `GET /auth/github/redirect` pada API.
2. API menyimpan dan memverifikasi OAuth state melalui session Laravel.
3. GitHub mengarahkan browser ke `GET /auth/github/callback` pada API.
4. API menemukan identitas berdasarkan kombinasi `provider` dan `provider_user_id`.
5. Jika identitas belum ada, API membuat user baru dari email GitHub yang terverifikasi dan membuat `OAuthAccount`.
6. Jika email sudah dipakai user lain tanpa identity GitHub yang sama, API tidak melakukan account linking otomatis.
7. Laravel membuat session baru dan meregenerasi session ID.
8. API mengarahkan browser kembali ke `SAKALA_CONSOLE_URL/auth/github/callback`
   tanpa credential pada URL.
9. Console mengambil user melalui `GET /api/v1/auth/user`.

GitHub App tidak memakai OAuth scope URL. Permission user dan repository
ditentukan pada registrasi GitHub App. User access token dan refresh token
disimpan terenkripsi untuk menjaga session koneksi dan mengotorisasi pilihan
installation maupun repository pengguna. Token installation hanya dipakai API
untuk operasi layanan setelah project terikat, tidak disimpan di database, dan
hanya dicache terenkripsi sebelum kedaluwarsa.

### Credential repository untuk agent

Repository private tidak pernah diakses agent dengan user token. Saat command
`InspectProject`/`DeployProject` memakai `repository_access =
temporary_credential`, agent yang memiliki command meminta
`POST /api/agent/v1/commands/{command}/repository-credential` dan menerima
installation token GitHub App yang dibatasi ke satu repository dengan
`contents:read`. API memverifikasi kepemilikan command, status command,
installation yang masih aktif, dan bahwa orang yang menjadi sandaran akses
project masih terhubung ke installation sebelum mencetak token (untuk project
tim: owner atau member saat ini yang terhubung ke installation). Token ini tidak di-cache, tidak disimpan,
dan hanya dicatat sebagai audit event tanpa nilai tokennya. Lihat
[Agent API](AGENT_API.md#repository-credential).

### GitHub installation

Repository publik dapat divalidasi dengan URL tanpa credential. Repository
private atau repository organisasi harus dipilih dari GitHub App installation.
Browser membuka `/auth/github/install`, GitHub mengembalikan setup callback,
dan API memverifikasi installation tersebut terhadap user GitHub yang sedang
login sebelum menyimpannya. Webhook `POST /api/v1/webhooks/github` memverifikasi
`X-Hub-Signature-256` dan memperbarui status installation ketika akses berubah.

Untuk mengubah cakupan repository installation yang sudah terhubung, Console
menavigasi browser ke `GET /auth/github/installations/{installation}/configure`.
API memverifikasi kepemilikan installation terlebih dahulu, lalu redirect ke
halaman Configure GitHub yang sesuai untuk akun personal atau organisasi. Saat
GitHub mengirim setup callback setelah perubahan, API kembali memverifikasi
akses user sebelum memperbarui relasi installation.

Callback yang gagal selalu mengarahkan user ke
`SAKALA_CONSOLE_URL/auth/github/callback` dengan query `error` berisi kode
error non-sensitif: `github_access_denied`, `github_invalid_state`,
`github_email_unavailable`, `github_email_conflict`, atau
`github_provider_failure`. Kegagalan tidak mengembalikan bearer token,
personal access token, detail provider, maupun credential pada URL.

Konfigurasi GitHub App harus memakai callback URI yang persis sama dengan
`GITHUB_APP_REDIRECT_URI`. Untuk local development:

```text
http://api.sakala.localhost:8000/auth/github/callback
```

Untuk deployment, gunakan host API yang sebenarnya, misalnya:

```text
https://api.sakala.dev/auth/github/callback
```

Jangan mematikan atau melewati state callback. State session adalah proteksi
callback browser terhadap CSRF.

### Login Google

Login Google memakai Socialite sebagai browser OAuth flow dan menggunakan
session `web` yang sama dengan login email/password. Route OAuth tidak berada
di bawah `/api/v1` karena callback harus mempertahankan state session browser:

| Endpoint | Kegunaan |
| --- | --- |
| `GET /auth/google/redirect` | Membuka authorization flow Google. |
| `GET /auth/google/callback` | Menerima callback Google dan membuat session Console. |

Flow Google:

1. Browser membuka `GET /auth/google/redirect` pada API.
2. API menyimpan OAuth state pada session Laravel dan meminta scope `openid`,
   `profile`, serta `email`.
3. Google mengarahkan browser ke callback API.
4. API hanya menerima profile dengan `email_verified` bernilai true.
5. API menemukan identitas berdasarkan kombinasi `provider=google` dan
   `provider_user_id`.
6. Jika identitas belum ada tetapi email sudah dipakai user lain, API menolak
   login dengan `google_email_conflict` dan tidak melakukan account linking
   otomatis.
7. Jika identitas belum ada dan email belum dipakai, API membuat user baru
   terverifikasi tanpa password lokal dan membuat `OAuthAccount` Google.
8. Laravel membuat session baru dan meregenerasi session ID.
9. API mengarahkan browser kembali ke Console tanpa credential pada URL.
10. Console mengambil user melalui `GET /api/v1/auth/user`.

Access token dan refresh token Google hanya digunakan oleh Socialite selama
callback dan tidak disimpan ke database. Login Google tidak membuat bearer
token atau personal access token. Callback yang gagal mengarahkan user ke
halaman login Console dengan kode error non-sensitif:
`google_access_denied`, `google_invalid_state`,
`google_email_unavailable`, `google_email_conflict`, atau
`google_provider_failure`.

Konfigurasi Google harus memakai callback URI yang persis sama dengan
`GOOGLE_REDIRECT_URI`. Untuk local development:

```text
http://api.sakala.localhost:8000/auth/google/callback
```

State callback tidak boleh dimatikan atau diganti dengan `stateless()`. State
session melindungi callback browser terhadap CSRF.

## Agent dan Machine Client

Agent tidak memakai session browser. Kontrak agent menggunakan `Authorization: Bearer <token>` dan `X-Agent-Id`.

### Provisioning

Agent hanya dapat didaftarkan oleh user berstatus **Admin** melalui endpoint POST `/api/agent/v1/agents`. Endpoint ini tertutup dari publik; Sanctum middleware menolak request tanpa authentication yang valid.

Untuk provisioning dari server — termasuk node pertama, ketika belum ada admin yang bisa login — tersedia command setara:

```bash
php artisan agent:provision "production-runtime-01" \
  --description="Production runtime node" \
  --actor=admin@example.com
```

`--actor` opsional dan hanya menerima user Admin; bila diisi user lain, command ditolak. Output berupa dua baris assignment env (`SAKALA_AGENT_ID`, `SAKALA_AGENT_TOKEN`) agar script provisioning dapat menuliskannya langsung ke file environment agent. Command hanya dapat dijalankan oleh pemegang akses shell server, yang setara dengan akses admin.

Response menyimpan plaintext token sekali saja di body — token tidak disimpan dalam database, tidak masuk log, dan tidak muncul di response berikutnya. Database hanya menyimpan hash HMAC dan 10 karakter awalan (`token_prefix`) untuk keperluan identifikasi.

### Identitas

Setiap agent memiliki `agent_id` berupa UUIDv7 dengan prefix `agent-`, misalnya `agent-a1b2c3d4-...`. Nilai ini ditetapkan saat provisioning dan menjadi identitas tetap node runtime. Sakala agent membaca nilai ini dari konfigurasi lokal (biasanya `SAKALA_AGENT_ID`) dan mengirimkannya pada header `X-Agent-Id` di setiap request.

### Hashing

Token bearer di-hash dengan `hash_hmac('sha256', $token, config('app.key'))`. Middleware menggunakan `hash_equals()` untuk perbandingan waktu-konstan agar tahan timing attack. Kontrak ini sama dengan yang dipakai DemoSeeder untuk node lokal (`local-agent-01`).

### Rotasi dan Pencabutan

- **Rotate** (`POST /api/agent/v1/agents/{id}/rotate`): mengganti kredensial saja. `auth_status` tidak berubah — jika agent Revoked, rotate tetap menghasilkan status Revoked. Admin harus melakukan revoke terpisah untuk menonaktifkan akses.
- **Revoke** (`POST /api/agent/v1/agents/{id}/revoke`): mengubah `auth_status` menjadi `Revoked`. Agent yang sudah dicabut tidak bisa kembali aktif kecuali admin membuat agent baru atau mengubah status secara eksplisit.

Middleware mengembalikan **403 Forbidden** untuk agent Revoked (bukan 401), sehingga berbeda jelas antara "token salah" dan "akses ditarik".

### Status Terpisah

`auth_status` (Active/Revoked) mengontrol **otorisasi**, sedangkan `status` (offline/ready/dst) mengontrol **runtime**. Keduanya independen — rotasi token tidak mengubah runtime status, dan revokasi tidak mengubah cara agent dilaporkan di dashboard.

JWT tidak menjadi default untuk Console. Personal access token Sanctum hanya
dipakai ketika kebutuhan machine client sudah didefinisikan.
