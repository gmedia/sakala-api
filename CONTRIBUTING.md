# Contributing

## Setup

Gunakan PHP 8.5, Composer 2, dan Docker. Salin `.env.example`, pasang dependency, lalu jalankan Sail dan migration sebagaimana dijelaskan di README.

## Branch dan Commit

Gunakan branch pendek seperti `feat/project-api`, `fix/agent-auth`, atau `docs/api-contract`. Commit mengikuti Conventional Commits:

```text
feat(api): add project creation contract
fix(auth): rotate invalid session after login
docs(agent): clarify command claim endpoint
```

## Aturan Perubahan

- Pertahankan boundary API-only.
- Diskusikan dependency baru dan migration destruktif sebelum implementasi.
- Jangan menyimpan secret di repository.
- Gunakan Form Request untuk input, Action untuk use case, Policy untuk authorization, dan API Resource untuk response sukses.
- Pastikan endpoint dapat diinfer oleh Scramble tanpa menduplikasi kontrak dalam annotation yang tidak diperlukan.
- Tambahkan Pest test untuk perubahan perilaku.
- Perbarui docs untuk route, env, dan keputusan arsitektur baru.

## Pull Request Checklist

- [ ] Scope PR fokus dan tidak membawa refactor tak terkait.
- [ ] `composer check` lulus.
- [ ] Endpoint baru terlihat benar pada `/docs/api`.
- [ ] `composer audit --locked` lulus.
- [ ] Migration dapat di-rollback jika ada.
- [ ] Kontrak API dan dokumentasi diperbarui.
- [ ] Tidak ada secret atau data pribadi dalam diff/log/test fixture.
