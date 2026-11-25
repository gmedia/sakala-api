# Custom Domain

Custom domain adalah alamat tambahan sebuah project (ADR-024), misalnya `www.contoh.com`, di samping `<slug>.sakala.app` yang tetap jalan. Pemilik membuktikan kepemilikan dengan record TXT berisi token acak, lalu mengarahkan domainnya dengan CNAME ke satu nama tetap milik Sakala. Domain baru melayani pengunjung setelah TXT, arah DNS, dan CAA lolos bersamaan; setelah itu domain diperiksa ulang setiap 6 jam, dan domain yang gagal terus dinonaktifkan dengan pemberitahuan, tidak pernah dihapus diam-diam.

Fitur ini hanya aktif bila `SAKALA_CUSTOM_DOMAINS_ENABLED=true` (default mati). Selama mati, semua endpoint di bawah dijawab `404`, tidak ada command agent yang membawa `custom_domains`, tabel Edge tidak punya bagian custom (isi dan revisinya persis seperti sebelum fitur ini), dan `custom-domains:check` tidak melakukan apa-apa. Data domain yang sudah ada tetap tersimpan. Production tetap mati sampai versi pemberitahuan privasi yang menyebut Let's Encrypt (privasi-v4) berlaku.

Kontrak dengan agent ada di [Agent API](AGENT_API.md#custom-domain), kontrak dengan Sakala Edge di [Edge API](EDGE_API.md#custom-domain).

## Alur singkat

1. Owner atau member menambah domain. API menormalkan namanya dan membuat klaim `pending` dengan token baru.
2. Pengguna memasang dua record yang ditampilkan Console: TXT `_sakala.<domain>` berisi `sakala-verify=<token>`, dan CNAME `<domain>` ke target (`edge.sakala.app` di hosted). Untuk apex, record kedua adalah CNAME flattening, ALIAS, atau ANAME.
3. `custom-domains:check` memeriksa klaim `pending` setiap menit selama 30 menit pertama, lalu setiap 15 menit. Tombol "Periksa sekarang" memeriksa saat itu juga.
4. Begitu lolos, klaim `verified`: Edge menulis site untuk domain itu dan Caddy menerbitkan sertifikat Let's Encrypt, sementara node yang menyimpan route project menerima `UpdateProjectDomains`.
5. Domain `verified` diperiksa setiap 6 jam. Gagal terus selama 24 jam: email peringatan. Gagal terus selama 72 jam: `disabled`, route dicabut, email. Domain `disabled` diperiksa sekali sehari dan aktif lagi sendiri begitu DNS-nya benar, selama 30 hari.

## Nama domain

`POST` menerima nama dalam bentuk apa pun yang biasa ditempel pengguna dan menyimpan bentuk akhirnya: huruf kecil, tanpa skema, path, port, atau titik di akhir, dengan nama internasional sebagai punycode (`bücher.de` menjadi `xn--bcher-kva.de`). Bentuk akhir inilah yang dikirim ke agent dan Edge, yang memeriksanya tanpa menormalkan lagi.

Ditolak dengan pesan di `errors.hostname` (`422`):

- alamat IP, wildcard (`*.contoh.com`), dan nama satu label;
- label yang bukan huruf, angka, dan tanda hubung, atau lebih dari 63 karakter; nama lebih dari 253 karakter; label `xn--` yang bukan punycode valid;
- nama di bawah atau sama dengan zona Sakala: domain project (`SAKALA_PROJECT_DEFAULT_DOMAIN`) dan `SAKALA_CUSTOM_DOMAIN_FORBIDDEN_ZONES` (default `sakala.app,sakala.dev,localhost`, harus sama dengan daftar di agent dan Edge);
- public suffix itu sendiri (`ac.id`, `my.id`, `co.uk`, `github.io`) dan nama dengan akhiran yang tidak dikenal;
- nama yang sudah ada di project yang sama (kecuali yang `released`), dan lebih dari 32 domain per project, batas agent dan Edge.

Jenis domain ditentukan dengan Public Suffix List lewat `jeremykendall/php-domain-parser`: **apex** bila nama sama dengan registered domain-nya (`contoh.com`, `kampus.ac.id`, `nama.my.id`), selain itu **subdomain** (`www.contoh.com`, `himpunan.kampus.ac.id`). File list ada di `resources/data/public_suffix_list.dat` (versi tercatat di baris `VERSION` file itu). Untuk memperbaruinya, unduh ulang dari `https://publicsuffix.org/list/public_suffix_list.dat` ke path yang sama lewat PR; `SAKALA_PUBLIC_SUFFIX_LIST_PATH` bisa menunjuk ke salinan lain.

## Endpoint

Semua endpoint project berada di `/api/v1/app/projects/{project}/domains` dan memakai sesi Console. Peran mengikuti `ProjectPolicy`: semua anggota workspace membaca, owner dan member mengubah, admin boleh semuanya. Pengguna di luar workspace menerima `403` generik; domain milik project lain lewat URL project ini menerima `404`.

| Endpoint | Owner, member | Viewer | Hasil |
| --- | --- | --- | --- |
| `GET .../domains` | ya | ya | `200 {data: ProjectDomainResource[]}`, yang paling lama dulu, tanpa pagination. Klaim yang dihapus tidak ada. |
| `POST .../domains` | ya | `403` | Body `hostname`. `201 {data: ProjectDomainResource}`, status `pending`. `409 DOMAIN_TAKEN` bila nama itu `verified` di project lain; `422 CUSTOM_DOMAIN_LIMIT_EXCEEDED` bila kuota workspace habis. |
| `POST .../domains/{domain}/check` | ya | `403` | Memeriksa DNS sekarang dan menerapkan hasilnya persis seperti pemeriksaan terjadwal. `200 {data: ProjectDomainResource}`. Paling banyak 6 kali per menit per domain, dan 12 per menit per pengguna untuk semua domain (`429`). Klaim `released` dikembalikan tanpa diperiksa. |
| `DELETE .../domains/{domain}` | ya | `403` | `204`. Soft delete; domain `verified` berhenti melayani dalam sekitar 10 detik. Nama itu langsung bisa diklaim project lain dengan token baru. |

`ProjectDomainResource`:

| Field | Tipe | Keterangan |
| --- | --- | --- |
| `id`, `project_id` | UUID | |
| `hostname` | string | Bentuk akhir. |
| `kind` | `apex` atau `subdomain` | |
| `status` | `pending`, `verified`, `failed`, `disabled`, `released` | Lihat [Status](#status). |
| `target` | string | Nama tujuan CNAME (`SAKALA_CUSTOM_DOMAIN_TARGET`). Tidak pernah alamat IP. |
| `dns_records` | array | Dua record yang harus dipasang, persis: `{type: "TXT", name: "_sakala.<hostname>", value: "sakala-verify=<token>"}`, lalu `{type: "CNAME", name: <hostname>, value: <target>}` untuk subdomain atau `{type: "ALIAS", ...}` untuk apex (CNAME flattening, ALIAS, atau ANAME, sesuai nama di DNS provider). |
| `last_check.checked_at` | RFC 3339 atau `null` | `null` sebelum pemeriksaan pertama. |
| `last_check.reasons` | array `{code, message}` | Kosong bila pemeriksaan terakhir lolos. `code` stabil (tabel di bawah), `message` berbahasa Indonesia dan menyebut record yang harus diubah. |
| `last_check.routing_match` | `cname`, `addresses`, atau `null` | Cara pemeriksaan terakhir yang lolos menemukan domain mengarah ke Sakala. `addresses` hanya untuk apex: flattening, atau record A manual yang tidak bisa dibedakan dan akan putus saat alamat Sakala berubah; Console sebaiknya memberi peringatan itu. |
| `next_check_at` | RFC 3339 atau `null` | Pemeriksaan otomatis berikutnya; `null` bila tidak ada (`failed`, `released`, dinonaktifkan admin, atau lewat 30 hari `disabled`). |
| `verified_at` | RFC 3339 atau `null` | Terakhir kali menjadi `verified`. |
| `failing_since`, `disable_at` | RFC 3339 atau `null` | Untuk domain `verified` yang sedang gagal: pemeriksaan gagal pertama sejak lolos terakhir, dan kapan domain dinonaktifkan bila DNS belum diperbaiki. |
| `failed_at`, `disabled_at`, `released_at` | RFC 3339 atau `null` | |
| `reactivation_ends_at` | RFC 3339 atau `null` | Batas domain `disabled` aktif lagi sendiri; `null` bila dinonaktifkan admin. |
| `admin_disabled`, `admin_disabled_reason` | boolean, string atau `null` | Admin menonaktifkan domain ini; alasannya dikirim juga ke pemilik. |
| `waiting_for_node` | boolean | `true` bila domain `verified` tetapi node yang menjalankan project belum bisa melayani custom domain (agent tanpa capability `custom-domains`): pengunjung mendapat 404 sampai project berjalan di node yang bisa. |
| `created_at` | RFC 3339 | |

Kuota ada di `GET app/pilot-limits`: `quotas.max_custom_domains` dan `quotas.current_custom_domains_count`, plus `custom_domains.enabled` dan `custom_domains.target` untuk Console.

## Pemeriksaan DNS

Setiap pemeriksaan menjalankan tiga bagian, dan klaim lolos hanya bila ketiganya lolos bersamaan:

- **Kepemilikan.** Salah satu nilai TXT di `_sakala.<domain>` sama persis dengan `sakala-verify=<token klaim ini>`. Token klaim lain tidak dihitung, jadi beberapa klaim nama yang sama bisa punya TXT masing-masing di nama itu.
- **Arah.** Subdomain: rantai CNAME (paling banyak 8 langkah, termasuk lewat nama milik pengguna sendiri) harus sampai ke target. Subdomain yang punya record A atau AAAA sendiri ditolak dengan `cname_required`, juga bila alamatnya alamat Edge, karena domain seperti itu putus saat alamat Edge berubah. Apex: rantai CNAME ke target diterima, dan alamat hasil flattening juga diterima bila semua alamat A dan AAAA apex ada di alamat target saat itu, dengan minimal satu IPv4. Alamat target di-resolve dari `SAKALA_CUSTOM_DOMAIN_TARGET` setiap kali, jadi pindah IP Edge cukup dengan mengubah DNS target.
- **CAA.** Record CAA terdekat, dari domain naik sampai registered domain-nya, harus mengizinkan `letsencrypt.org` bila punya tag `issue`. Record kritis (flag 128) dengan tag yang tidak dikenal juga menolak. Tanpa CAA, lolos.

Pertanyaan DNS dikirim langsung ke nameserver otoritatif zona domain itu (dicari lewat record NS dari resolver rekursif), supaya jawaban NXDOMAIN yang tersimpan di cache resolver tidak membuat pengguna menunggu. Resolver rekursif (`SAKALA_CUSTOM_DOMAIN_RESOLVERS`, default `/etc/resolv.conf`) hanya dipakai untuk mencari nameserver dan me-resolve target. Library DNS-nya `mikepultz/netdns2`, di balik interface `App\Services\CustomDomain\Dns\DnsResolver`; test memakai resolver palsu dan tidak pernah menyentuh DNS sungguhan. Semua pertanyaan dijalankan sebelum transaksi database, jadi tidak ada row lock yang menunggu nameserver.

| `code` | Arti |
| --- | --- |
| `txt_missing` | `_sakala.<domain>` tidak punya nilai `sakala-verify=`. |
| `txt_mismatch` | Ada nilai `sakala-verify=`, tetapi tidak satu pun dengan token klaim ini. |
| `cname_required` | Subdomain memakai record A atau AAAA sendiri, bukan CNAME ke target. |
| `points_elsewhere` | Rantai CNAME atau alamat domain mengarah ke tempat lain, termasuk proxy Cloudflare (awan oranye) dan rantai yang berputar. |
| `extra_addresses` | Apex punya alamat target ditambah alamat lain, misalnya AAAA sisa hosting lama. |
| `no_address` | Domain tidak punya CNAME maupun alamat. |
| `caa_forbids_issuer` | CAA tidak mengizinkan Let's Encrypt. |
| `dns_error` | Nameserver timeout, menolak, atau SERVFAIL. Dihitung sebagai gagal. |
| `domain_taken` | Semua record lolos, tetapi project lain sudah `verified` dengan nama ini lebih dulu. |

## Status

| Status | Pemeriksaan lolos | Pemeriksaan gagal |
| --- | --- | --- |
| `pending` | `verified`, kecuali nama itu `verified` di project lain (`domain_taken`, tetap `pending`) | tetap; menjadi `failed` setelah 72 jam sejak ditambahkan |
| `failed` | `verified` (hanya lewat "Periksa sekarang") | tetap |
| `verified` | jam `failing_since` dikosongkan | `failing_since` dicatat; email peringatan di 24 jam; `disabled` di 72 jam |
| `disabled` | `verified` lagi, kecuali dinonaktifkan admin | tetap; pemeriksaan otomatis berhenti 30 hari setelah `disabled_at` |
| `released` | tidak diperiksa | tidak diperiksa |

Klaim `pending` di project lain **tidak** menghalangi klaim baru, supaya tidak ada yang bisa mengunci domain orang lain hanya dengan mengklaimnya lebih dulu. Unique index parsial `project_domains_hostname_verified_unique` (`hostname` di baris `verified` yang tidak dihapus) adalah penjaga terakhir bila dua klaim lolos bersamaan: yang kalah mendapat `domain_taken`.

Ketika sebuah klaim menjadi `verified`, klaim `disabled` nama yang sama di project lain menjadi `released`: tidak bisa aktif lagi, tidak diperiksa lagi, dan pemiliknya diberi tahu. Itu jalan keluar untuk domain yang berpindah tangan, karena pemilik baru memasang TXT dengan tokennya sendiri. Klaim `released` tetap tercatat sampai dihapus pemiliknya dan tidak dihitung ke kuota.

Menghapus project menghapus (soft delete) semua domainnya dalam transaksi yang sama; `StopProject` mencabut route di node dan project yang dihapus tidak punya route Edge. Memulihkan project tidak memulihkan domainnya.

Semua waktu bisa diatur lewat env dalam menit (lihat [Configuration](CONFIGURATION.md)), supaya uji staging tidak perlu menunggu 72 jam.

## Kuota

`limit_profiles.max_custom_domains`: berapa klaim yang boleh dimiliki semua project satu workspace bersama-sama. `null` mengikuti `SAKALA_CUSTOM_DOMAIN_DEFAULT_MAX_COUNT`, yang default-nya **1** (keputusan hosted 25 November 2026); `0` menutup fitur untuk workspace itu; paling besar 100. Yang dihitung klaim `pending`, `verified`, `failed`, dan `disabled`, supaya klaim gantung tidak bisa dipakai membanjiri pemeriksaan DNS. Kuota diperiksa di bawah row lock workspace, jadi dua permintaan bersamaan tidak bisa sama-sama mengambil slot terakhir. Menurunkan kuota tidak menghapus domain.

## Ke node dan Edge

Selama fitur aktif:

- `DeployProject`, `WakeProject`, `RestartProject`, dan `SleepProject` membawa `custom_domains`, yaitu daftar hostname `verified` project itu (urut), **diisi saat node melakukan poll**, bukan saat command dibuat. Hanya untuk node yang mengiklankan capability `custom-domains`; node lain tidak menerima field itu sama sekali. Command yang menunggu di antrean tidak pernah memasang kembali domain yang sudah dihapus.
- `UpdateProjectDomains {custom_domains}` dikirim ke node yang menyimpan workload project (`ProjectWorkloadService::retainedWorkload()`) setiap kali daftar `verified` berubah: domain diverifikasi, aktif lagi, dinonaktifkan, atau dihapus. Daftarnya juga dibaca saat poll, jadi satu command `Pending` per project dan node cukup untuk beberapa perubahan. Tanpa workload tidak ada yang dikirim; command berikutnya yang menulis route membawa daftarnya. Node tanpa capability tidak pernah menerima command ini, dan domainnya ditandai `waiting_for_node`.
- Hasil `updated`, `unchanged`, dan `no_route` dicatat di command. `runtime_custom_domain_taken` dikirim ulang satu menit kemudian, paling banyak 5 kali, karena biasanya penghapusan nama itu di project lain node yang sama belum berjalan. Kode lain dicatat di audit `project_domain.route_update_failed` dan log error. Lease yang habis juga mengirim ulang. Deploy yang gagal dengan `runtime_custom_domain_invalid` atau `runtime_custom_domain_taken` mendapat penjelasan berbahasa Indonesia di field `failure`.
- Tabel Edge memuat `custom_routes` dan `expected_minimum_custom_routes`: satu item per domain `verified` dari project yang tidak dihapus, dengan upstream project bila workload-nya ada di node ber-capability `custom-domains`, selain itu `upstreams: []` (Edge menjawab 404 sendiri dan tetap merawat sertifikat).

## Notifikasi

Semua lewat `UserNotifier`, kategori `project_issue`, setelah transaksi commit. Notifikasi di Console ke owner dan member workspace; email ke owner workspace dan orang yang menambahkan domain, dengan email untuk kategori itu menyala dan alamat terverifikasi.

| Kejadian | Console | Email (`ProjectAlert`) |
| --- | --- | --- |
| Domain aktif | "Domain aktif" | tidak |
| Gagal terus 24 jam | "Domain akan dinonaktifkan" | `custom_domain_failing` |
| Dinonaktifkan (72 jam atau admin) | "Domain dinonaktifkan" | `custom_domain_disabled` |
| Aktif lagi | "Domain aktif lagi" | `custom_domain_reactivated` |
| Dilepas ke project lain | "Domain dipakai proyek lain" | `custom_domain_released` |

## Admin

Di bawah `/api/v1/admin`, `404` selama fitur mati:

| Endpoint | Keterangan |
| --- | --- |
| `GET custom-domains` | Semua domain dari project yang tidak dihapus, terbaru dulu, dengan pagination admin. Filter `status`, `search` (hostname), `project_id`, `workspace_id`, `failing` (`true` untuk domain `verified` yang sedang gagal). Setiap item: `domain` (`ProjectDomainResource`), `project` (`id`, `name`, `slug`, `workspace_id`), `created_by`. |
| `POST custom-domains/{domain}/disable` | Body `reason` (3 sampai 500 karakter). Domain menjadi `disabled`, route dicabut, pemilik diberi tahu dengan alasannya, dan domain tidak pernah aktif sendiri sampai admin mengaktifkannya. Untuk abuse seperti phishing. |
| `POST custom-domains/{domain}/enable` | Mencabut penonaktifan admin. Domain tetap `disabled`, diperiksa segera, dan menjadi `verified` lagi bila DNS-nya lolos. `422` bila domain tidak sedang dinonaktifkan admin. |

## Audit

Subjek `App\Models\ProjectDomain`, metadata selalu memuat `project_id`, `hostname`, dan `status`; token tidak pernah dicatat. Aksi: `project_domain.created`, `.verified`, `.failed`, `.failing`, `.warned`, `.disabled`, `.reactivated`, `.released`, `.deleted` (dengan `reason: project_deleted` bila ikut terhapus bersama project), `.admin_disabled`, `.admin_enabled`, dan `.route_update_failed`. Pemeriksaan terjadwal tercatat dengan aktor sistem `custom-domains:check`.

## Urutan lock

Menambah domain mengunci baris workspace. Pemeriksaan, hapus, dan aksi admin hanya mengunci baris `project_domains` yang bersangkutan (pemeriksaan yang memverifikasi juga mengunci klaim `disabled` nama yang sama sesudahnya), tidak pernah baris project atau node. Menghapus project mengunci project dulu, baru domainnya. Membuat `UpdateProjectDomains` hanya meng-insert command, yang mengambil key-share lock di baris node, jadi tidak pernah menunggu heartbeat (#210).
