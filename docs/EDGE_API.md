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
- `last_used_at` diperbarui paling sering sekali per menit.

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
