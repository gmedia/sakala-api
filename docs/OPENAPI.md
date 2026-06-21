# OpenAPI Documentation

Sakala API menggunakan Scramble untuk menghasilkan spesifikasi OpenAPI 3.1 dari source code Laravel. Kontrak utama tetap berada di route, Form Request, return type, dan API Resource; jangan memelihara spesifikasi terpisah yang mudah tertinggal.

## Endpoint Dokumentasi

- `/docs/api`: UI dokumentasi interaktif.
- `/docs/api.json`: spesifikasi OpenAPI dalam JSON.

Scramble mengizinkan akses otomatis pada environment `local`. Pada staging atau production, akses hanya diizinkan ketika `SCRAMBLE_ENABLED=true`. Spesifikasi tidak boleh berisi token, credential, contoh secret, atau detail infrastruktur internal.

## Workflow

```bash
composer docs:analyse
composer docs:export
```

`docs:analyse` gagal jika Scramble menemukan masalah saat menginfer kontrak. `docs:export` menulis `api.json` untuk inspeksi atau tooling lain; file tersebut diabaikan Git.

## Menambah Endpoint

1. Definisikan named route pada grup versi dan domain yang benar.
2. Gunakan Form Request jika endpoint menerima input.
3. Berikan return type eksplisit pada controller.
4. Kembalikan API Resource untuk response sukses.
5. Tambahkan ringkasan singkat pada docblock controller jika nama method belum cukup menjelaskan operasi.
6. Tambahkan feature test untuk status, struktur response, validation failure, dan authorization failure yang relevan.
7. Jalankan `composer check` dan periksa `/docs/api`.

Jangan menambahkan annotation untuk field yang sudah dapat diinfer dari validation rule atau Resource. Annotation manual hanya dipakai untuk semantik yang memang tidak tersedia di source code.
