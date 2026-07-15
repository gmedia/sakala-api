# Authentication

## Console

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
