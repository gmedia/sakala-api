# Authentication

## Console

`sakala-console` adalah SPA first-party. Browser memakai Sanctum SPA
cookie/session, bukan bearer token atau personal access token. Cookie session
bersifat HTTP-only; Console mengirim request lintas origin dengan credentials
dan memakai CSRF cookie Laravel untuk request yang mengubah state.

Endpoint Console hanya menerima session dari guard `web`. Bearer token yang
nantinya dipakai agent atau machine client tidak dapat mengakses endpoint
Console.

### Session Foundation

Endpoint berikut tersedia untuk Console:

| Endpoint | Kegunaan |
| --- | --- |
| `GET /sanctum/csrf-cookie` | Menetapkan cookie CSRF sebelum request state-changing. |
| `POST /api/v1/auth/login` | Membuat session browser dari email/password yang valid dan terverifikasi. |
| `GET /api/v1/auth/user` | Mengembalikan user dari session browser yang aktif. |
| `POST /api/v1/auth/logout` | Mengakhiri session browser aktif. |

Flow session lokal:

1. Console memanggil `GET /sanctum/csrf-cookie` dengan credentials.
2. Console mengirim `POST /api/v1/auth/login` dengan `email` dan `password`, menggunakan origin first-party yang diizinkan.
3. API hanya membuat session untuk user dengan password valid dan email terverifikasi. Respons sukses berisi `UserResource` dan tidak berisi bearer token.
4. Setelah authentication berhasil, Console dapat memanggil `GET /api/v1/auth/user` untuk bootstrap user.
5. Saat keluar, Console memanggil `POST /api/v1/auth/logout`.
6. Request `GET /api/v1/auth/user` berikutnya akan menghasilkan `401`.

Endpoint login memiliki rate limiter khusus yang default-nya mengizinkan 5
percobaan per menit untuk setiap kombinasi email ternormalisasi dan IP. Limiter
ini berjalan sebagai tambahan dari limiter API global.

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
8. API mengarahkan browser kembali ke Console tanpa credential pada URL.
9. Console mengambil user melalui `GET /api/v1/auth/user`.

GitHub App tidak memakai OAuth scope URL. Permission user dan repository
ditentukan pada registrasi GitHub App. User access token dan refresh token
disimpan terenkripsi untuk menjaga session koneksi dan mengotorisasi pilihan
installation maupun repository pengguna. Token installation hanya dipakai API
untuk operasi layanan setelah project terikat, tidak disimpan di database, dan
hanya dicache terenkripsi sebelum kedaluwarsa.

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

Callback yang gagal selalu mengarahkan user ke halaman login Console dengan
kode error non-sensitif: `github_access_denied`, `github_invalid_state`,
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
