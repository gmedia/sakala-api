# Authentication

## Console

`sakala-console` adalah SPA first-party. Flow target:

1. Browser mengambil `/sanctum/csrf-cookie`.
2. Browser membuka redirect GitHub OAuth pada API.
3. Socialite memproses callback dan API membuat session.
4. API mengarahkan browser kembali ke console.
5. Request berikutnya memakai cookie HTTP-only dengan CSRF protection.

Endpoint flow tersebut belum diimplementasikan pada foundation ini.

## Agent dan Machine Client

Agent tidak memakai session browser. Kontrak agent akan menggunakan `Authorization: Bearer <token>` dan `X-Agent-Id`. Token harus dapat dirotasi, dicabut, di-hash saat disimpan, dan tidak pernah masuk log.

JWT tidak menjadi default untuk console. Personal access token Sanctum hanya dipakai ketika kebutuhan machine client sudah didefinisikan.
