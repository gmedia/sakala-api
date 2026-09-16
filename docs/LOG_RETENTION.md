# Pilot Retention Policy

Dokumen ini mendefinisikan kebijakan retensi data operasional pada Sakala API fase Pilot MVP, meliputi deployment log/event dan usage signal records.

---

## 1. Ruang Lingkup

Retensi berlaku untuk dua kategori data:

| Kategori | Tabel | Sumber |
| --- | --- | --- |
| **Deployment Log & Event** | `deployment_logs`, `deployment_events` | Laporan agent selama build dan runtime |
| **Usage Signal Records** | `usage_signal_records` | Agregat operasional (deployment attempts, agent failures, rejected limits, dll.) |

---

## 2. Durasi Retensi

| Kategori | Durasi | Konfigurasi |
| --- | --- | --- |
| Deployment Log & Event | **7 hari** sejak waktu terminal deployment | `SAKALA_LOG_RETENTION_DAYS` (default: `7`) |
| Usage Signal Records | **30 hari** sejak `collected_at` | `SAKALA_USAGE_SIGNALS_RETENTION_DAYS` (default: `30`) |

Waktu terminal deployment memakai urutan prioritas: `finished_at` → `cancelled_at` → `created_at`.

Retention bersifat *bounded rolling window*. Record yang sudah melewati batas durasi dan memenuhi syarat status terminal akan dipangkas secara otomatis oleh scheduler harian.

### Proteksi Deployment Aktif

Deployment yang masih berstatus aktif (`queued`, `cloning`, `analyzing`, `building`, `deploying`, `routing`, `health_checking`) **tidak akan dipangkas**, terlepas dari usianya.

---

## 3. Mekanisme Pembersihan

Pembersihan dilakukan melalui Artisan console command yang dijalankan scheduler.

### Deployment Logs

```bash
# Simulasi (tanpa menghapus)
php artisan pilot:prune-logs --dry-run

# Eksekusi dengan retensi default (7 hari)
php artisan pilot:prune-logs

# Retensi kustom
php artisan pilot:prune-logs --days=14 --batch=500
```

### Usage Signals

```bash
# Simulasi (tanpa menghapus)
php artisan usage:signals-prune --dry-run

# Eksekusi dengan retensi default (30 hari)
php artisan usage:signals-prune

# Retensi kustom
php artisan usage:signals-prune --days=60 --batch=1000
```

Scheduler menjalankan kedua command secara terpisah:
- `pilot:prune-logs` → harian, `withoutOverlapping()`, `onOneServer()`
- `usage:signals-prune` → harian, `withoutOverlapping()`, `onOneServer()`

Penghapusan dilakukan secara bertahap (*chunked batches*) untuk mencegah table lock escalation pada PostgreSQL. Cleanup aman untuk diulang; jika proses terhenti, iterasi berikutnya hanya menghapus record eligible yang tersisa.

---

## 4. Koleksi Sinyal Operasional

Signal agregat dikumpulkan setiap jam melalui command `usage:signals-collect`:

```bash
php artisan usage:signals-collect
```

Scheduler menjadwalkan secara hourly dengan `withoutOverlapping()` dan `onOneServer()`.

Signal yang dicatat:
- `deployment_attempt` — jumlah attempt deployment per window
- `successful_deployment` — jumlah deployment sukses per window
- `active_projects` — jumlah project dengan runtime_status running/deploying
- `agent_failure` — jumlah agent command gagal per window
- `repeated_build_failure` — project dengan ≥N deployment gagal berturut (threshold configurable)

---

## 5. Tidak Ada Garansi SLA Durabilitas

> [!IMPORTANT]
> **Pernyataan Pilot MVP**: Sakala API pada fase pilot disediakan sebagai lingkungan pengujian kelayakan sistem dan **BUKAN** layanan produksi ber-SLA tinggi.

Data operasional (log, event, signal) tidak disimpan secara permanen. Database PostgreSQL adalah *operational metadata store*, bukan sistem cold storage. Sakala tidak memberikan komitmen durabilitas data operasional dan dapat melakukan pembersihan sewaktu-waktu demi menjaga kelangsungan sistem.

---

## 6. Referensi

- [Database Design (DATABASE.md)](DATABASE.md)
- [API Conventions (API_CONVENTIONS.md)](API_CONVENTIONS.md)
- [Architecture (ARCHITECTURE.md)](../ARCHITECTURE.md)
