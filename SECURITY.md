# Security Policy

## Melaporkan Kerentanan

Jangan membuka public issue untuk kerentanan yang belum diperbaiki. Laporkan secara privat kepada maintainer Sakala atau security contact GMEDIA yang diumumkan di organisasi repository. Sertakan dampak, langkah reproduksi, dan saran mitigasi bila tersedia.

## Boundary Keamanan

- API dan console tidak boleh mengakses Docker socket.
- Runtime command hanya dieksekusi oleh agent yang terautentikasi.
- Browser first-party memakai Sanctum cookie/CSRF, bukan token di local storage.
- Agent token dan OAuth secret tidak boleh dicatat dalam log atau commit.
- CORS dan Reverb origin harus berupa allowlist eksplisit.
- Environment variable sensitif tidak boleh dikirim kembali melalui API.

## Status

Repository masih foundation dan belum production-ready. Security review diperlukan sebelum endpoint auth, webhook, agent command, atau deployment publik diaktifkan.
