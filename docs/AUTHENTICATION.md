# Authentication

## Console

`sakala-console` adalah SPA first-party. Browser memakai Sanctum SPA
cookie/session, bukan bearer token atau personal access token. Cookie session
bersifat HTTP-only; Console mengirim request lintas origin dengan credentials
dan memakai CSRF cookie Laravel untuk request yang mengubah state.

### Session Foundation

Endpoint berikut tersedia untuk Console:

| Endpoint | Kegunaan |
| --- | --- |
| `GET /sanctum/csrf-cookie` | Menetapkan cookie CSRF sebelum request state-changing. |
| `GET /api/v1/auth/user` | Mengembalikan user dari session browser yang aktif. |
| `POST /api/v1/auth/logout` | Mengakhiri session browser aktif. |

Flow session lokal:

1. Console memanggil `GET /sanctum/csrf-cookie` dengan credentials.
2. Console memanggil endpoint API dengan origin first-party yang diizinkan.
3. Setelah authentication berhasil, Console memanggil `GET /api/v1/auth/user`.
4. Saat keluar, Console memanggil `POST /api/v1/auth/logout`.
5. Request `GET /api/v1/auth/user` berikutnya akan menghasilkan `401`.

### GitHub OAuth

GitHub OAuth akan ditambahkan pada delivery berikutnya di atas session
foundation ini. Flow browser yang direncanakan:

1. Browser membuka redirect GitHub OAuth pada API.
2. Socialite memproses callback stateful dan menyinkronkan user.
3. Laravel membuat session baru dan meregenerasi session ID.
4. API mengarahkan browser kembali ke Console tanpa credential pada URL.
5. Console mengambil user melalui `GET /api/v1/auth/user`.

## Agent dan Machine Client

Agent tidak memakai session browser. Kontrak agent akan menggunakan `Authorization: Bearer <token>` dan `X-Agent-Id`. Token harus dapat dirotasi, dicabut, di-hash saat disimpan, dan tidak pernah masuk log.

JWT tidak menjadi default untuk Console. Personal access token Sanctum hanya
dipakai ketika kebutuhan machine client sudah didefinisikan.
