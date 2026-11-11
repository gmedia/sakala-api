# Email

Semua email Sakala memakai satu layout bermerek dan punya versi teks biasa. Untuk melihat semuanya tanpa mengirim apa pun, jalankan `php artisan mail:preview`.

## Daftar email

| Nama pratinjau | Pemicu | Penerima | Subjek | Tombol |
| --- | --- | --- | --- | --- |
| `verify-email` | Daftar dengan email, atau minta kirim ulang verifikasi | Alamat yang didaftarkan | Verifikasi email akun Sakala kamu | Verifikasi email (signed URL API, berlaku `AUTH_VERIFICATION_EXPIRE` menit) |
| `reset-password` | Lupa kata sandi | Pemilik akun | Permintaan reset kata sandi akun Sakala | Buat kata sandi baru (Console `/reset-password`, token sekali pakai) |
| `existing-account` | Daftar dengan email yang sudah punya akun | Pemilik akun, bukan pendaftar | Email kamu sudah terdaftar di Sakala | Masuk ke Sakala (Console `/login`) |
| `email-change-confirm` | Ganti email | Alamat baru | Konfirmasi email baru untuk akun Sakala | Konfirmasi email baru (signed URL API) |
| `email-change-requested` | Ganti email | Alamat lama | Ada permintaan ganti email akun Sakala | Reset kata sandi (Console `/forgot-password`) |
| `email-change-applied` | Tautan konfirmasi ganti email dibuka | Alamat lama | Email akun Sakala kamu sudah diganti | Tidak ada; email ini menyebut kontak privasi |
| `workspace-invitation` | Owner tim mengundang alamat email | Alamat yang diundang | {pengundang} mengundangmu ke {workspace} di Sakala | Lihat undangan (Console `/workspace-invitations/{id}?token=`) |
| `project-suspended` | Admin menangguhkan proyek | Semua yang bisa mengelola proyek itu | Proyek {nama} di Sakala ditangguhkan | Buka proyek (Console `/projects/{id}`) |
| `account-deleted` | Pengguna menghapus akunnya | Alamat akun yang dihapus | Akun Sakala kamu sudah dihapus | Tidak ada; tidak ada lagi yang bisa dibuka |

Notifikasi lain, misalnya deployment gagal atau perubahan peran di tim, hanya muncul di dalam aplikasi (`UserAlertNotification`) dan tidak dikirim lewat email.

## Layout

Setiap notifikasi membangun `App\Notifications\Messages\SakalaMailMessage`, yaitu `MailMessage` dengan tiga tambahan: `preheader()` untuk teks pratinjau di daftar inbox, `headline()` untuk judul di kartu, dan `reason()` untuk kalimat di footer yang menjelaskan kenapa alamat itu menerima email. Template-nya ada di `resources/views/vendor/notifications/email.blade.php` dan komponen `resources/views/vendor/mail/`. Laravel menempelkan CSS dari `resources/views/vendor/mail/html/themes/sakala.css` ke atribut `style` setiap elemen (`config/mail.php`, `markdown.theme`).

Susunannya satu kolom, paling lebar 600 px: wordmark Sakala, kartu putih berisi judul, isi, satu tombol utama dengan tautan cadangan tepat di bawahnya, lalu footer. Footer berisi alasan email dikirim, identitas pengirim sesuai halaman legal (Sakala Maintainers, dengan GMEDIA sebagai founding sponsor), serta tautan ke `/privasi` dan `/syarat` di `SAKALA_LANDING_URL`.

Keputusan yang perlu dijaga:

- **Tanpa gambar.** Brand Sakala hanya punya logo SVG, dan Gmail serta Outlook tidak menampilkan SVG. Header memakai wordmark teks berwarna teal, jadi tidak ada logo transparan yang hilang di dark mode dan tidak ada gambar yang diblokir. Kalau nanti landing menyajikan PNG logo, pakai URL HTTPS absolut dengan `width`, `height`, dan `alt="Sakala"`.
- **Tanpa pelacak.** Sakala tidak menambahkan piksel atau tautan pelacak. Provider SMTP bisa saja menambahkannya sendiri; matikan fitur itu di dashboard provider.
- **Font sistem.** Tidak ada web font; Outlook desktop dipaksa memakai Segoe UI atau Arial.
- **Outlook desktop.** Layout memakai tabel. Tombol punya kembaran VML (`v:roundrect`) di dalam komentar `<!--[if mso]>`, karena mesin Word mengabaikan padding pada tautan.
- **Dark mode.** `color-scheme: light dark` diumumkan di meta dan CSS. Warna gelap ada di blok `<style>` di `layout.blade.php` (Apple Mail dan iOS Mail lewat `prefers-color-scheme`, Outlook.com lewat `[data-ogsc]` dan `[data-ogsb]`). Gmail tidak membaca keduanya dan memakai warna terang atau inversinya sendiri, yang tetap terbaca karena tidak ada gambar.
- **Mobile.** Di bawah 480 px tombol melebar penuh dan padding mengecil. Tinggi tombol 50 px, di atas batas target sentuh 44 px.

Warna diambil dari token Console (`sakala-console/src/app.css`). Setiap pasangan teks lolos WCAG 2.2 AA (minimal 4,5:1):

| Teks | Latar | Rasio |
| --- | --- | --- |
| Isi `#17211b` | Kartu `#ffffff` | 16,54:1 |
| Footer dan tautan cadangan `#6b6b6b` | Halaman `#f8faf7` / kartu `#ffffff` | 5,08:1 / 5,33:1 |
| Tautan dan wordmark `#0f766e` | Kartu `#ffffff` / halaman `#f8faf7` | 5,47:1 / 5,21:1 |
| Tombol `#ffffff` | `#0f766e` | 5,47:1 |
| Gelap: isi `#e6ede8` | Kartu `#17211b` | 13,89:1 |
| Gelap: footer `#a3b0a7` | Kartu `#17211b` / halaman `#0d1411` | 7,34:1 / 8,29:1 |
| Gelap: tautan dan wordmark `#5fbfb5` | Kartu `#17211b` / halaman `#0d1411` | 7,57:1 / 8,54:1 |

## Menulis email

Ikuti gaya yang sudah ada: bahasa Indonesia, "kamu", tanpa huruf kapital semua, dan istilah teknis seperlunya.

- Subjek menyebut apa yang terjadi.
- Kalimat pertama isi menyebut apa yang perlu dilakukan.
- Masa berlaku ditulis dengan satuan, dan dengan jam WIB kalau waktunya pasti, lewat `SakalaMailMessage::wib()`. Reset kata sandi hanya menyebut durasi, karena token dihitung sejak permintaan dan email dari queue bisa terkirim belakangan.
- Email yang bisa dipicu orang lain (verifikasi, reset, ganti email, undangan) menyebut "abaikan email ini" untuk yang tidak merasa memintanya.
- Paling banyak satu tombol, dan hanya satu tautan sekali pakai.

Notifikasi email baru wajib masuk `App\Support\Mail\MailPreviewCatalog`; `tests/Feature/Mail/TransactionalMailTest.php` gagal kalau ada yang terlewat. Tambahkan juga tes pemicunya di file yang sama: subjek, penerima, tujuan tombol, bagian teks, dan tidak ada token selain tautan yang dimaksud.

## Pratinjau

```bash
php artisan mail:preview                       # semua email ke storage/app/mail-previews
php artisan mail:preview --path=/tmp/email     # folder lain
php artisan mail:preview --only=verify-email   # satu email
```

Perintah ini membuat `<nama>.html` dan `<nama>.txt` dari data contoh, tanpa mengirim email dan tanpa menyentuh database. Di production perintah ini menolak berjalan. Buka file HTML di browser, dan ganti tema sistem ke gelap untuk melihat dark mode. Untuk melihat email sungguhan di klien email, kirim ke Mailpit lokal lewat alur biasa (misalnya daftar akun baru), bukan ke alamat orang.
