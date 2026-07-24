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
| `GET /api/v1/auth/user` | Mengembalikan user dari session browser yang aktif. |
| `POST /api/v1/auth/logout` | Mengakhiri session browser aktif. |

Flow session lokal:

1. Console memanggil `GET /sanctum/csrf-cookie` dengan credentials.
2. Console memanggil endpoint API dengan origin first-party yang diizinkan.
3. Setelah authentication berhasil, Console memanggil `GET /api/v1/auth/user`.
4. Saat keluar, Console memanggil `POST /api/v1/auth/logout`.
5. Request `GET /api/v1/auth/user` berikutnya akan menghasilkan `401`.

### GitHub OAuth

GitHub OAuth berjalan di atas session foundation ini. OAuth adalah browser flow,
sehingga route-nya berada di web middleware, bukan di API JSON versioned:

| Endpoint | Kegunaan |
| --- | --- |
| `GET /auth/github/redirect` | Membuka authorization flow GitHub melalui Socialite. |
| `GET /auth/github/callback` | Menerima callback GitHub dan membuat session Console. |

Flow browser:

1. Browser membuka `GET /auth/github/redirect` pada API.
2. Socialite menyimpan dan memverifikasi OAuth state melalui session Laravel.
3. GitHub mengarahkan browser ke `GET /auth/github/callback` pada API.
4. API menemukan identitas berdasarkan kombinasi `provider` dan `provider_user_id`.
5. Jika identitas belum ada, API membuat user baru dari email GitHub yang terverifikasi dan membuat `OAuthAccount`.
6. Jika email sudah dipakai user lain tanpa identity GitHub yang sama, API tidak melakukan account linking otomatis.
7. Laravel membuat session baru dan meregenerasi session ID.
8. API mengarahkan browser kembali ke Console tanpa credential pada URL.
9. Console mengambil user melalui `GET /api/v1/auth/user`.

Sakala hanya meminta scope `read:user` dan `user:email` saat login. Access
token GitHub dipakai sementara oleh Socialite untuk menyelesaikan login dan
tidak disimpan pada `oauth_accounts`. Akses repository akan menjadi flow
persetujuan terpisah ketika fitur koneksi repository dibuat.

Callback yang gagal selalu mengarahkan user ke halaman login Console dengan
kode error non-sensitif: `github_access_denied`, `github_invalid_state`,
`github_email_unavailable`, `github_email_conflict`, atau
`github_provider_failure`. Kegagalan tidak mengembalikan bearer token,
personal access token, detail provider, maupun credential pada URL.

Konfigurasi GitHub OAuth App harus memakai callback URI yang persis sama
dengan `GITHUB_REDIRECT_URI`. Untuk local development:

```text
http://api.sakala.localhost:8000/auth/github/callback
```

Untuk deployment, gunakan host API yang sebenarnya, misalnya:

```text
https://api.sakala.dev/auth/github/callback
```

Jangan gunakan `stateless()` atau membuat OAuth state sendiri. Socialite
stateful flow adalah proteksi callback browser terhadap CSRF.

### Batasan Login Email dan Google

Wireframe Console dapat menampilkan pilihan login email/password atau Google,
tetapi keduanya belum diimplementasikan dalam API. Jangan menambahkan route
atau credential provider baru tanpa issue dan kontrak keamanan terpisah.

## Agent dan Machine Client

Agent tidak memakai session browser. Kontrak agent akan menggunakan `Authorization: Bearer <token>` dan `X-Agent-Id`. Token harus dapat dirotasi, dicabut, di-hash saat disimpan, dan tidak pernah masuk log.

JWT tidak menjadi default untuk Console. Personal access token Sanctum hanya
dipakai ketika kebutuhan machine client sudah didefinisikan.
