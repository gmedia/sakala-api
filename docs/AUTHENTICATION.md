# Authentication

## Sanctum SPA Session

`sakala-console` menggunakan autentikasi stateful Laravel Sanctum berbasis cookie HTTP-only dan session. Browser tidak menerima maupun menyimpan bearer token di localStorage atau sessionStorage.

### CSRF Handshake

Sebelum mengirim request yang memerlukan autentikasi, browser harus melakukan CSRF handshake:

```text
GET /sanctum/csrf-cookie
-> browser menerima cookie XSRF-TOKEN (HTTP-only)
```

### Endpoint Contract

```text
GET /api/v1/auth/user
-> 200 + user resource (session valid)
-> 401 + {"message": "Unauthenticated."} (guest)

POST /api/v1/auth/logout
-> 204 (session invalidated, CSRF token regenerated)
```

### Error Response Distinction

- **401** — user belum login atau session expired.
- **403** — user login tetapi tidak memiliki akses ke resource.
- **419** — CSRF token tidak valid atau expired.

### Environment Configuration

Pastikan environment variable berikut dikonfigurasi:

```env
SANCTUM_STATEFUL_DOMAINS=app.sakala.localhost:5173,api.sakala.localhost:8000
CORS_ALLOWED_ORIGINS=http://app.sakala.localhost:5173
SESSION_DRIVER=database
SESSION_DOMAIN=.sakala.localhost
SESSION_SAME_SITE=lax
SESSION_SECURE_COOKIE=false
```

### Setup Local

1. Tambahkan entry `/etc/hosts`:
   ```text
   127.0.0.1 app.sakala.localhost api.sakala.localhost
   ```

2. Jalankan API di `http://api.sakala.localhost:8000`.

3. Jalankan Console di `http://app.sakala.localhost:5173`.

4. Pastikan cookie session domain di-set ke `.sakala.localhost` agar dapat dibagikan antara subdomain.

## Console OAuth Flow

`sakala-console` adalah SPA first-party. Flow:

1. Browser membuka halaman login di Console.
2. Console mengarahkan browser ke endpoint GitHub OAuth pada API.
3. API melakukan redirect ke GitHub menggunakan Laravel Socialite.
4. Setelah autentikasi berhasil, GitHub melakukan callback ke API.
5. API memvalidasi OAuth state dan response dari provider.
6. API melakukan sinkronisasi User dan OAuthAccount.
7. API melakukan `Auth::login()` dan `session()->regenerate()`.
8. API melakukan redirect kembali ke Console tanpa token.
9. Console mengambil informasi user melalui endpoint:
   `GET /api/v1/auth/user`.
10. Session browser sepenuhnya dikelola oleh Laravel menggunakan cookie HTTP-only.

GitHub OAuth hanya digunakan untuk memverifikasi identitas pengguna. Setelah proses OAuth berhasil, autentikasi browser menggunakan Laravel Session Authentication yang disimpan dalam HTTP-only Cookie melalui Sanctum SPA Authentication. Browser tidak menerima maupun menyimpan Personal Access Token.

## Agent dan Machine Client

Agent tidak memakai session browser. Kontrak agent akan menggunakan `Authorization: Bearer <token>` dan `X-Agent-Id`. Token harus dapat dirotasi, dicabut, di-hash saat disimpan, dan tidak pernah masuk log.

JWT tidak menjadi default untuk console. Personal access token Sanctum hanya dipakai ketika kebutuhan machine client sudah didefinisikan.
