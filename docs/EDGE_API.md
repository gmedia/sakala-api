# Edge API

Kontrak antara `sakala-api` dan Sakala Edge (ADR-019), lapisan routing yang
menerima trafik `*.<SAKALA_PROJECT_DEFAULT_DOMAIN>` lalu meneruskannya ke router
runtime node yang menyimpan workload project, dengan header `Host` dipertahankan.
API adalah sumber kebenaran "project ini dilayani node mana"; Edge menarik tabel
route, menyimpan salinan terakhir yang valid, dan tetap melayani dengan salinan itu
saat API tidak bisa dihubungi.

Route family `/api/edge/v1/*` diversikan terpisah dari `/api/v1/*` dan
`/api/agent/v1/*`. Saat ini hanya ada satu endpoint, dan sifatnya hanya baca.
Sumber kebenaran bentuk response adalah `EdgeRouteTableData` dan
`EdgeRouteTableResource`; test `tests/Feature/Api/Edge/EdgeRouteTableTest.php`
memeriksa bentuk dan aturan di bawah; nilai pada contoh hanya ilustrasi.

## Autentikasi

- Header `Authorization: Bearer <token>`. Token Edge terpisah dari sesi pengguna dan
  token agent, dan tidak bisa membuka endpoint lain.
- Token dibuat dan diganti dengan `php artisan edge:token {name} --reason=...`, yang
  mencetak `SAKALA_EDGE_TOKEN=<64 karakter>` sekali saja. Menjalankannya lagi untuk
  nama yang sama mengganti token (token lama langsung ditolak). `--revoke` mencabutnya.
  API hanya menyimpan HMAC-nya (`APP_KEY`), jadi token yang hilang diganti, tidak
  dipulihkan. Setiap perubahan dicatat sebagai audit event `edge_token.issued`,
  `edge_token.rotated`, atau `edge_token.revoked` (actor `system`/`cli`, tanpa token).
- Setiap request yang lolos autentikasi dicatat di token: `last_seen_at` dan isi
  header Edge di bawah, paling sering sekali per menit kecuali ada nilai yang berubah.
  Lihat [Pemantauan](#pemantauan).

| Keadaan | Status |
| --- | --- |
| Header tidak ada, bukan `Bearer`, atau token tidak dikenal | `401` |
| Token dicabut | `403` |
| Lebih dari `SAKALA_EDGE_RATE_LIMIT` request per menit dari satu alamat (default 120) | `429`, dengan `Retry-After` |

Rate limit dihitung per alamat klien sebelum autentikasi, sehingga banjir token
salah juga dibatasi. Satu instance Edge yang polling setiap 2 sampai 5 detik memakai
12 sampai 30 request per menit.

## `GET /api/edge/v1/routes`

### Request

| Header | Wajib | Keterangan |
| --- | --- | --- |
| `Authorization` | ya | `Bearer <token>`. |
| `If-None-Match` | tidak | ETag dari response sebelumnya (`"<revision>"`, dengan tanda kutip). Bila sama dengan revisi sekarang, API menjawab `304`. |
| `User-Agent` | tidak | `sakala-edge/<versi>`, versi berbentuk SemVer `X.Y.Z` dengan akhiran pre-release atau build opsional (`^sakala-edge/(\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.\-+]+)?)$`), versi paling panjang 64 karakter. Dicatat sebagai `last_version` tanpa awalan `sakala-edge/`. |
| `Sakala-Edge-Applied-Revision` | tidak | Revisi yang sedang disajikan Caddy di Edge: 64 karakter hex huruf kecil (`^[0-9a-f]{64}$`), atau persis `none` sebelum Edge pernah menerapkan tabel. |
| `Sakala-Edge-Status` | tidak | `ok` bila tabel terakhir yang diambil berhasil diterapkan, selain itu kode penolakan atau error dari sakala-edge, misalnya `below_expected_minimum` atau `invalid_revision`: snake_case huruf kecil, diawali huruf, paling panjang 64 karakter (`^[a-z][a-z0-9_]*$`). |

Ketiga header terakhir hanya untuk pemantauan. Header yang tidak ada atau tidak cocok
dengan aturannya (misalnya huruf besar, revisi 63 karakter, atau `User-Agent` klien
lain) dicatat sebagai "tidak diketahui" (`null`) dan **tidak pernah** menggagalkan
request: Edge versi lama tidak mengirimnya, dan versi yang sudah rilis hanya mengirim
`User-Agent`.

### Response `200`

Body tanpa pembungkus `data`. Header: `ETag: "<revision>"`,
`Cache-Control: no-cache, private`.

```json
{
  "generated_at": "2026-11-18T08:15:00+00:00",
  "revision": "5f2c0e9d4b6a7c1e3f8a2d9b0c4e6f1a7b3d5c9e2f0a8b6d4c1e7f3a9b5d2c0e",
  "expected_minimum_routes": 2,
  "routes": [
    {
      "host": "toko-buku.sakala.app",
      "project_id": "019a8f3c-6a2e-7b41-9c0d-2f5e8a1b3c4d",
      "upstreams": ["10.0.47.201:8180"]
    },
    {
      "host": "portofolio.sakala.app",
      "project_id": "019a8f3c-7d10-7e22-8a5b-6c9d0e1f2a3b",
      "upstreams": ["10.0.47.202:8180"]
    }
  ],
  "skipped_routes": [
    {
      "host": "kasir.sakala.app",
      "project_id": "019a8f3c-8e44-7a90-b1c2-3d4e5f6a7b8c",
      "reason": "node_without_edge_address"
    }
  ]
}
```

| Field | Tipe | Null | Keterangan |
| --- | --- | --- | --- |
| `generated_at` | string RFC 3339 | tidak | Kapan API menyusun tabel ini. Bisa lebih tua sampai `SAKALA_EDGE_ROUTES_CACHE_SECONDS` detik (lihat Cache). |
| `revision` | string, 64 karakter hex huruf kecil | tidak | SHA-256 dari JSON `{"expected_minimum_routes", "routes", "skipped_routes"}` persis seperti di response, urutan kunci itu, `JSON_UNESCAPED_SLASHES`. `generated_at` tidak ikut, jadi revisi hanya berubah bila isi yang dipakai Edge berubah. Juga nilai ETag. Edge cukup memperlakukannya sebagai string buram. |
| `expected_minimum_routes` | integer ≥ 0 | tidak | Jumlah route yang disusun API, sama dengan panjang `routes`. Edge **wajib** menolak response yang `routes`-nya lebih sedikit (terpotong) dan tetap memakai salinan terakhir. |
| `routes` | array | tidak | Satu item per project yang punya workload di node, urut menurut `host`. Array kosong hanya bila memang tidak ada project yang punya workload. |
| `routes[].host` | string | tidak | Domain project (`projects.default_domain`), huruf kecil, selalu tepat satu label di bawah `SAKALA_PROJECT_DEFAULT_DOMAIN` (misalnya `toko-buku.sakala.app`, atau `demo.staging.sakala.app` di staging). Unik di seluruh tabel. |
| `routes[].project_id` | string UUID | tidak | ID project di API. Untuk log dan alert; Edge tidak perlu memakainya untuk routing. |
| `routes[].upstreams` | array of string, minimal 1 item | tidak | Alamat router runtime node yang menyimpan workload project, masing-masing `host:port` tanpa skema (IPv4, IPv6 dalam kurung siku, atau nama DNS), urut dan unik. Edge meneruskan request HTTP biasa ke alamat ini dengan `Host` asli. Saat ini selalu satu item; nanti satu item per node bila replika tersebar ke beberapa node, dan Edge membagi request ke upstream yang sehat. |
| `skipped_routes` | array | tidak | Project yang punya workload tetapi sengaja tidak diberi route, urut menurut `host`. Tidak pernah dihilangkan diam-diam: Edge sebaiknya meng-alert bila array ini tidak kosong. |
| `skipped_routes[].host` | string | tidak | Domain project seperti tersimpan (bisa berada di luar domain project, lihat `invalid_host`). |
| `skipped_routes[].project_id` | string UUID | tidak | ID project. |
| `skipped_routes[].reason` | string enum | tidak | Lihat tabel di bawah. |

| `reason` | Arti | Perbaikan |
| --- | --- | --- |
| `node_without_edge_address` | Node yang menyimpan workload tidak punya alamat Edge: tidak ada override admin dan heartbeat-nya tidak melaporkan `metadata.runtime.edge_address`. | Set `SAKALA_AGENT_EDGE_ADDRESS` di agent, atau `php artisan agent:node-edge-address {node} {host:port}`. |
| `invalid_host` | Domain project bukan tepat satu label di bawah `SAKALA_PROJECT_DEFAULT_DOMAIN`, misalnya project yang masih di domain lama. Edge tidak memegang sertifikat untuk nama itu. | Pindahkan domain (`projects:move-domain`) lalu deploy ulang. |

### Response `304`

Tanpa body, dengan `ETag` yang sama. Edge memakai salinan yang sudah ada.

### Error

Semua error berbentuk JSON `{"message": "..."}`.

| Status | Kapan |
| --- | --- |
| `401`, `403`, `429` | Lihat Autentikasi. |
| `5xx` | Tabel gagal disusun (misalnya database tidak bisa dibaca). API **tidak pernah** menjawab `200` dengan route yang kurang karena kegagalan; Edge memperlakukan setiap non-`200`/`304` sebagai "pakai salinan terakhir". |

## Isi tabel

Satu route untuk setiap project yang tidak dihapus dan masih punya workload di node
(`ProjectWorkloadService::retainedWorkloads()`):

- project yang berjalan, `crashed`, atau redeploy-nya gagal: node dari deployment
  aktif;
- project yang **tidur**: container tetap di node, dan router node menampilkan
  halaman bangun;
- project yang **ditangguhkan**: penangguhan menidurkan container dengan halaman
  "tidak tersedia", jadi route tetap ada dan node yang menjawab.

Tidak diberi route: project yang belum pernah di-deploy, yang di-stop (container
sudah dihapus), yang deployment-nya belum diambil node mana pun, dan yang dihapus,
termasuk selama stop-nya masih berjalan. Untuk host yang tidak ada di tabel, Edge
menjawab sendiri.

Alamat node (`edge_address`) adalah override admin bila ada, selain itu nilai
`metadata.runtime.edge_address` dari heartbeat terakhir node. Node yang offline atau
token-nya dicabut tetap dicantumkan: container dan router-nya bisa tetap melayani,
dan health check Edge yang menentukan upstream sehat atau tidak.

## Cache

API menyimpan tabel yang sudah disusun selama `SAKALA_EDGE_ROUTES_CACHE_SECONDS`
detik (default 5, `0` menyusun ulang di setiap request), bukan menghapus cache setiap
ada perubahan. Perubahan route datang dari banyak tempat (deployment sukses, tidur,
bangun, penangguhan, stop, hapus, alamat node dari heartbeat atau admin, domain
project), dan satu invalidasi yang terlewat akan membuat tabel basi sampai ada
perubahan lain. Dengan TTL pendek, route paling lambat basi selama TTL ditambah
interval polling Edge, dan beberapa instance Edge cukup memicu satu penyusunan per
TTL. Kegagalan tidak disimpan di cache.

Penyusunan tabel membaca project, deployment, command lifecycle, dan node dalam
empat query, berapa pun jumlah project-nya.

## Yang diharapkan dari Edge

1. Polling setiap beberapa detik dengan `If-None-Match`.
2. Terapkan tabel baru hanya bila response `200`, JSON valid, dan
   `len(routes) >= expected_minimum_routes`; selain itu pertahankan salinan terakhir.
3. Alert bila `skipped_routes` tidak kosong, bila polling gagal terus, atau bila
   salinan terakhir sudah terlalu tua.
4. Teruskan request ke salah satu `upstreams` dengan `Host` asli; untuk host yang
   tidak dikenal, jawab dengan halaman default Edge.
5. Kirim `User-Agent`, `Sakala-Edge-Applied-Revision`, dan `Sakala-Edge-Status` di
   setiap polling, supaya API bisa memberi tahu admin bila Edge bermasalah (lihat
   Pemantauan).

## Pemantauan

API memantau setiap instance Edge dari request yang dikirimnya sendiri, lalu
mengirim email ke admin bila Edge berhenti mengambil tabel, gagal menerapkannya, atau
tertinggal revisi. Tidak ada yang perlu dipasang di Edge selain mengirim header di
atas.

### Yang dicatat

Setiap request yang lolos autentikasi (jawaban `200` maupun `304`) dicatat di baris
`edge_tokens` milik token itu (`RecordEdgeContactAction`):

| Kolom | Isi |
| --- | --- |
| `last_seen_at` | Kapan request terakhir dicatat. |
| `last_version` | Versi dari `User-Agent`, atau `null`. |
| `last_applied_revision` | Nilai `Sakala-Edge-Applied-Revision` (`none` ikut disimpan), atau `null`. |
| `last_status` | Nilai `Sakala-Edge-Status`, atau `null`. |
| `status_since` | Kapan `last_status` terakhir berganti nilai, termasuk dari dan ke `null`. |

Edge polling setiap beberapa detik, jadi baris hanya ditulis bila salah satu nilai
berubah, atau bila penulisan terakhir (`last_seen_at`) sudah lebih dari 60 detik
(`EdgeToken::LAST_SEEN_RESOLUTION_SECONDS`). Nilai yang tidak dikirim di request
terakhir menjadi `null`, karena yang dicatat adalah keadaan Edge menurut request
terakhirnya.

### Kapan Edge dianggap tidak sehat

Ambangnya `SAKALA_EDGE_STALE_AFTER_SECONDS` (default 300). Hanya token yang tidak
dicabut dan pernah terlihat (`last_seen_at` terisi) yang dinilai; selain itu
keadaannya "tidak diketahui" dan tidak pernah memicu peringatan. Edge tidak sehat bila
salah satu kondisi ini berlangsung terus-menerus **lebih lama** dari ambang (tepat
sama dengan ambang masih sehat):

| Alasan | Kondisi | Dihitung sejak |
| --- | --- | --- |
| `stale` | Edge tidak mengambil tabel route. | `last_seen_at` |
| `status` | `last_status` diketahui dan bukan `ok`. | `status_since` |
| `revision_behind` | `last_applied_revision` diketahui (termasuk `none`) dan berbeda dari revisi tabel sekarang. | `revision_mismatch_since` |

Revisi sekarang adalah `revision` dari tabel route yang sama dengan yang diterima Edge
(cache sampai `SAKALA_EDGE_ROUTES_CACHE_SECONDS`). `revision_mismatch_since` diisi oleh
`edge:check-health` saat pertama kali melihat revisi berbeda, dan dikosongkan saat
melihat revisi sama atau tidak diketahui; jadi satu kali cocok di antaranya memulai
hitungan dari awal. Edge yang tidak mengirim revisi (versi lama) hanya dinilai dengan
aturan `stale`.

Penilaian ini (`EvaluateEdgeHealthAction`) dipakai oleh command dan endpoint admin di
bawah, sehingga keduanya selalu sepakat. Waktu tidak sehat (`unhealthy_since`) adalah
saat paling awal salah satu kondisi yang aktif melewati ambang, yaitu "dihitung sejak"
ditambah ambang.

### `edge:check-health`

`php artisan edge:check-health` dijadwalkan setiap menit di `routes/console.php`
(`withoutOverlapping`, `onOneServer`). Untuk setiap token yang dinilai, di dalam
transaction dengan row lock, command ini memperbarui `revision_mismatch_since`, menilai
Edge, lalu membandingkannya dengan keadaan peringatan yang tersimpan
(`edge_tokens.unhealthy_since`, `null` berarti sehat):

- Sehat menjadi tidak sehat: `unhealthy_since` diisi waktu tidak sehat, audit event
  `edge.unhealthy` ditulis, dan semua admin (`role = admin`) menerima email
  `edge-unhealthy` sekali.
- Tidak sehat menjadi sehat: `unhealthy_since` dikosongkan, audit event
  `edge.recovered` ditulis, dan semua admin menerima email `edge-recovered` sekali.
- Keadaan tidak berubah, termasuk alasan yang berganti selama masih tidak sehat: tidak
  ada yang dikirim.

Email dikirim lewat queue setelah transaction selesai, dan hanya bila
`SAKALA_EMAIL_DELIVERY_ENABLED` aktif; audit event tetap ditulis tanpa email. Email
yang gagal dilaporkan dan tidak diulang di menit berikutnya. Email tidak sehat menyebut
nama Edge, sejak kapan, alasannya (tidak mengambil tabel sejak kapan, kode status, atau
revisi yang disajikan dibanding revisi API, masing-masing 12 karakter pertama), bahwa
proyek yang sudah berjalan tetap dilayani dari salinan route terakhir sementara
deployment baru dan perubahan route tidak sampai, serta perintah pertama untuk
memeriksa di server Edge: `systemctl status sakala-edge`,
`cat /var/lib/sakala-edge/status.json`, dan `journalctl -u sakala-edge`. Email pulih
menyebut sejak kapan Edge sehat lagi (waktu pengecekan) dan berapa lama tidak sehat.

Audit event memakai actor `system` dengan ID `edge:check-health` dan subject token
Edge, tanpa token, hash, atau prefix-nya:

| Event | Metadata |
| --- | --- |
| `edge.unhealthy` | `name`, `reasons` (list alasan), `unhealthy_since`, `status`, `applied_revision`, `current_revision`, `last_seen_at`, `version` |
| `edge.recovered` | `name`, `unhealthy_since`, `recovered_at`, `unhealthy_seconds` |

### `GET /api/v1/admin/edges`

Khusus admin (sesi Console; `401` tanpa sesi, `403` untuk bukan admin). Semua token
Edge, termasuk yang dicabut, urut menurut `name`, tanpa pagination karena satu token
mewakili satu instance Edge. Kesehatannya dinilai saat request, dengan aturan di atas,
jadi bisa lebih dulu menunjukkan tidak sehat daripada email yang menunggu command
berikutnya (paling lambat satu menit).

```json
{
  "data": [
    {
      "name": "edge-jkt-1",
      "last_seen_at": "2026-11-18T07:20:00+00:00",
      "last_version": "1.0.0",
      "applied_revision": "9a1b3c5d7e9f0a2b4c6d8e0f1a3b5c7d9e1f3a5b7c9d0e2f4a6b8c0d2e4f6a8b",
      "current_revision": "5f2c0e9d4b6a7c1e3f8a2d9b0c4e6f1a7b3d5c9e2f0a8b6d4c1e7f3a9b5d2c0e",
      "status": "below_expected_minimum",
      "healthy": false,
      "unhealthy_reasons": ["stale", "status", "revision_behind"],
      "unhealthy_since": "2026-11-18T07:15:00+00:00",
      "revoked": false
    }
  ]
}
```

| Field | Tipe | Null | Keterangan |
| --- | --- | --- | --- |
| `name` | string | tidak | Nama instance Edge, seperti pada `edge:token {name}`. |
| `last_seen_at` | string RFC 3339 | ya | Request terakhir yang dicatat; `null` bila belum pernah terlihat. |
| `last_version` | string | ya | Versi sakala-edge dari `User-Agent`; `null` bila tidak diketahui. |
| `applied_revision` | string | ya | Revisi yang disajikan Edge (64 hex) atau `none`; `null` bila tidak diketahui. |
| `current_revision` | string, 64 hex | tidak | Revisi tabel route sekarang; sama di setiap item. |
| `status` | string | ya | `ok` atau kode dari sakala-edge; `null` bila tidak diketahui. |
| `healthy` | boolean | ya | `null` untuk token yang dicabut atau belum pernah terlihat (tidak dinilai). |
| `unhealthy_reasons` | array of string enum | tidak | `stale`, `status`, `revision_behind`, urut seperti itu; kosong bila sehat atau tidak diketahui. |
| `unhealthy_since` | string RFC 3339 | ya | Saat paling awal salah satu `unhealthy_reasons` melewati ambang; `null` kecuali tidak sehat. |
| `revoked` | boolean | tidak | Token dicabut. |
