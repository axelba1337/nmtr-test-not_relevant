# Neomatra

Neomatra adalah prototipe portofolio berbasis Laravel 12 untuk mencari dan
membandingkan listing produk lintas marketplace. Pengguna dapat menulis
pertanyaan alami, misalnya mencari GPU bekas dengan varian, kondisi, lokasi,
seller, dan batas anggaran tertentu. Backend melakukan semantic retrieval,
filter atribut, perhitungan harga, serta ranking; Chat API hanya membantu
memahami intent, menjelaskan hasil yang telah dihitung, dan menjawab pertanyaan
umum melalui Google Search grounding yang terpisah.

Implementasi ini mengikuti kebutuhan Neomatra terbaru. Neomatra tidak melakukan
transaksi, tidak menjamin kondisi barang atau keuntungan, tidak melakukan
scraping, dan tidak menjadikan hasil web sebagai data marketplace. Data
marketplace MVP adalah dataset demo untuk Tokopedia, Shopee, dan OLX, bukan
listing nyata.

## Kemampuan utama

- pencarian produk dan alias secara semantik dengan embedding dan cosine
  similarity;
- filter deterministik berdasarkan identitas produk, varian, kondisi, lokasi,
  seller, serta budget;
- perbandingan listing lintas platform;
- perhitungan harga pasar, total biaya, undervalued, margin reseller, sinyal
  risiko, freshness, dan ranking oleh backend;
- knowledge base untuk panduan Neomatra dan suggested questions;
- riwayat percakapan bounded maksimal enam pesan dan jawaban pengetahuan umum
  dengan Google Search grounding beserta sumber terverifikasi;
- halaman perbandingan produk dan redirect sumber yang divalidasi;
- panel admin berbasis session untuk katalog, knowledge, sinkronisasi,
  monitoring query, dan profil ranking;
- pencatatan query yang belum terjawab untuk ditinjau admin.

## Tiga integrasi yang terpisah

| Integrasi | Implementasi final | Tanggung jawab |
| --- | --- | --- |
| Chat API | Gemini `gemini-3.1-flash-lite`; web grounding `gemini-2.5-flash` | Mengekstrak intent terstruktur, menyusun penjelasan dari fakta backend, serta menjawab pertanyaan umum melalui Google Search grounding. Tidak membuat harga, stok, listing marketplace, atau hasil perhitungan. |
| Embedding API | Gemini `gemini-embedding-2`, 768 dimensi | Membuat vector query, produk, alias, dan knowledge pada ruang vector yang sama. |
| Marketplace | Adapter demo, sinkronisasi manual | Menormalisasi dataset demo Tokopedia, Shopee, dan OLX. Adapter ini tidak memanggil API atau website eksternal. |

Chat API bukan sumber data marketplace. Google Search hanya dipakai oleh method
grounding khusus untuk pengetahuan umum atau informasi web pendukung; sumber web
tidak boleh mengubah listing maupun perhitungan backend. Ketika Chat API gagal mengekstrak
intent, parser lokal deterministik tetap digunakan. Ketika penjelasan Chat API
gagal, hasil dan angka backend tetap dikembalikan dengan kalimat fallback yang
aman. Kegagalan Embedding API menghentikan semantic retrieval untuk request
tersebut. Query umum atau produk yang memang memakai web tetap dapat turun ke
Google Search tanpa data internal; route non-web mengembalikan respons 503 yang
aman karena ruang vector diperlukan untuk retrieval.

## Arsitektur

- Backend: PHP 8.2+ dan Laravel 12.
- Database aplikasi: MySQL 8 atau MariaDB yang kompatibel.
- Frontend: Blade, CSS, dan JavaScript biasa melalui Vite.
- Authentication: session Laravel dengan gate `access-admin` dan atribut
  `users.is_admin`.
- HTTP integration: Laravel HTTP client dengan timeout, retry terbatas memakai exponential backoff + jitter, dan
  pesan kegagalan yang tidak membocorkan API key atau raw response.
- Vector: disimpan sebagai JSON dan dihitung dengan cosine similarity di PHP;
  arsitektur ini ditujukan untuk volume prototipe.
- Marketplace: kontrak connector, registry, adapter demo, normalisasi DTO,
  deduplication, audit sync, dan cache lock per platform.
- Proses rebuild dan marketplace sync berjalan synchronous; queue tidak
  diperlukan untuk dataset MVP.

Alur query publik:

1. Backend memvalidasi dan menormalisasi input.
2. Chat API mengekstrak produk, kategori, brand, model, varian, SKU, budget,
   kondisi, lokasi, tipe seller, dan mode buyer/reseller. Parser lokal menjadi
   fallback bila API intent gagal.
3. Embedding API membuat query vector.
4. Backend memvalidasi model, dimensi, hash, dan isi vector produk/alias lalu
   mengurutkan kandidat dengan cosine similarity.
5. Backend menerapkan pencocokan atribut secara ketat, mengambil Top-K kandidat
   yang lolos, lalu memfilter listing.
6. Backend menghitung seluruh angka dan ranking, serta mengambil knowledge
   Top-K secara terpisah.
7. Chat API menjelaskan data backend tanpa menghitung ulang atau menambah fakta.
8. Untuk `general_question` atau informasi web pendukung pada `product_search`,
   method terpisah dapat memakai Google Search grounding dan hanya menerima
   jawaban yang memiliki metadata sumber web yang valid.
9. Respons terstruktur dan log aktivitas minimum dikembalikan.

## Kebutuhan lokal

- PHP 8.2 atau lebih baru;
- ekstensi database PHP untuk MySQL/MariaDB dan SQLite untuk test;
- Composer;
- Node.js 22.12 atau lebih baru dan npm;
- MySQL 8 atau MariaDB yang kompatibel;
- Gemini API key dengan akses ke model chat dan embedding final.

Automated test memakai SQLite in-memory dan HTTP fake. Test tidak memakai quota
Gemini dan adapter marketplace demo tidak melakukan network request.

## Instalasi dari fresh clone

1. Masuk ke folder repository dan pasang dependency PHP:

   ```shell
   composer install
   ```

2. Salin environment example.

   PowerShell:

   ```powershell
   Copy-Item .env.example .env
   ```

   Linux/macOS:

   ```shell
   cp .env.example .env
   ```

3. Buat database kosong, lalu atur `DB_*`, `CHAT_API_KEY`, dan
   `EMBEDDING_API_KEY` di `.env`. API key boleh sama bila satu Gemini project
   dipakai untuk keduanya, tetapi tanggung jawab dan konfigurasinya tetap
   dipisahkan. Jangan commit `.env`.

4. Siapkan key aplikasi, schema, dan data demo:

   ```shell
   php artisan key:generate
   php artisan migrate --seed
   ```

   Migration dan seeder bersifat offline. Dataset V3 menyediakan lima domain
   taxonomy, 170 produk kanonik dengan provenance spesifikasi, minimal tiga
   alias per produk, registry 21 platform, serta 47 knowledge bilingual. Dataset
   demo listing dari tahap sebelumnya tetap tersedia untuk pengujian perhitungan
   backend. Tokopedia, Shopee, dan OLX memakai connector dataset demo; platform
   lain terdaftar untuk input URL manual dan tidak mengklaim memiliki connector
   aktif.

   Sumber Dataset V3 tersimpan di `database/data/v3`. Seeder membacanya melalui
   parser SQL yang tidak mengeksekusi statement MySQL mentah, sehingga proses
   seed tetap kompatibel dengan MySQL/MariaDB dan SQLite pada automated test.

5. Pasang dependency frontend dan buat asset production:

   ```shell
   npm ci
   npm run build
   ```

6. Buat akun admin. Password diminta secara tersembunyi dan minimal 12
   karakter:

   ```shell
   php artisan admin:create
   ```

   Nama dan email juga dapat diberikan sebagai option:

   ```shell
   php artisan admin:create --name="Admin Neomatra" --email="admin@example.test"
   ```

7. Bangun seluruh vector aktif. Command ini memanggil Embedding API dan memakai
   quota provider:

   ```shell
   php artisan neomatra:rebuild-embeddings --force
   ```

8. Bersihkan cache konfigurasi setelah mengubah `.env`:

   ```shell
   php artisan optimize:clear
   ```

Sebagai alternatif, `composer run setup` menjalankan pemasangan dependency,
pembuatan `.env` bila belum ada, app key, migration + seeder, dan build frontend.
Database dan environment harus sudah dapat digunakan. Akun admin dan embedding
tetap perlu dibuat melalui command pada langkah 6 dan 7.

## Menjalankan aplikasi

Untuk development dengan Laravel server dan Vite dalam satu terminal:

```shell
composer run dev
```

Atau jalankan server backend saja setelah `npm run build`:

```shell
php artisan serve
```

URL default Artisan server:

- chatbot pengguna: `http://127.0.0.1:8000/`;
- login admin: `http://127.0.0.1:8000/admin/login`;
- dashboard admin setelah login: `http://127.0.0.1:8000/admin`;
- health check: `http://127.0.0.1:8000/up`.

Pada Laragon, gunakan virtual host yang dibuat Laragon, misalnya
`http://neomatra-cbot.test`, dengan path yang sama.

## Environment final

Gunakan nama konfigurasi baru yang memisahkan Chat dan Embedding. Nilai di bawah
adalah placeholder atau default non-rahasia, bukan API key nyata.

```dotenv
APP_NAME=Neomatra
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000
APP_TIMEZONE=Asia/Jakarta
APP_LOCALE=id
APP_FALLBACK_LOCALE=en

LOG_CHANNEL=stack
LOG_LEVEL=debug

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=neomatra
DB_USERNAME=root
DB_PASSWORD=
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci

CHAT_PROVIDER=gemini
CHAT_API_KEY=<gemini-chat-api-key>
CHAT_MODEL=gemini-3.1-flash-lite
CHAT_API_BASE_URL=https://generativelanguage.googleapis.com/v1beta
CHAT_INTENT_MAX_OUTPUT_TOKENS=512
CHAT_MAX_OUTPUT_TOKENS=512
CHAT_MAX_CONTEXT_CHARS=12000
CHAT_HISTORY_MAX_MESSAGES=6
CHAT_HISTORY_MAX_CHARS=12000
CHAT_WEB_GROUNDING_ENABLED=true
CHAT_WEB_MODEL=gemini-2.5-flash
CHAT_WEB_MAX_OUTPUT_TOKENS=1024
CHAT_WEB_MAX_ANSWER_CHARS=8000
CHAT_WEB_MAX_SOURCES=5

EMBEDDING_PROVIDER=gemini
EMBEDDING_API_KEY=<gemini-embedding-api-key>
EMBEDDING_MODEL=gemini-embedding-2
EMBEDDING_DIMENSIONS=768
EMBEDDING_API_BASE_URL=https://generativelanguage.googleapis.com/v1beta

AI_HTTP_TIMEOUT=25
AI_HTTP_RETRIES=2
AI_HTTP_RETRY_DELAY_MS=1000

MARKETPLACE_PROVIDER=demo
MARKETPLACE_SYNC_MODE=manual
MARKETPLACE_TARGETS=tokopedia,shopee,olx
MARKETPLACE_PAGE_SIZE=100
MARKETPLACE_MAX_PAGES=25
MARKETPLACE_SYNC_LOCK_SECONDS=300

NEOMATRA_DEFAULT_CURRENCY=IDR
NEOMATRA_PRODUCT_CANDIDATE_K=20
NEOMATRA_RESULT_TOP_K=5
NEOMATRA_KNOWLEDGE_TOP_K=3
NEOMATRA_MINIMUM_COMPARABLES=2
NEOMATRA_LISTING_STALE_AFTER_HOURS=168
NEOMATRA_UNANSWERED_RETENTION_DAYS=30

CHATBOT_RATE_LIMIT=20
SESSION_DRIVER=file
SESSION_LIFETIME=120
CACHE_STORE=file
```

`NEOMATRA_LISTING_STALE_AFTER_HOURS` dan
`NEOMATRA_UNANSWERED_RETENTION_DAYS` menampung kebijakan operasional, tetapi MVP
saat ini belum memiliki scheduler yang otomatis menonaktifkan listing atau
menghapus log berdasarkan kedua nilai tersebut.

Simpan secret production melalui secret manager platform. API key tidak boleh
diletakkan di controller, Blade, JavaScript, log, test, atau Git.

## Semantic search dan embedding

Konfigurasi final memakai `gemini-embedding-2` dengan 768 dimensi. Model ini
memerlukan format prompt retrieval yang konsisten:

- query: `task: search result | query: {teks query}`;
- produk, alias, dan knowledge: `title: {judul} | text: {teks dokumen}`.

Teks dokumen internal dibentuk secara deterministik. Contoh knowledge:

```text
Judul: {judul}
Kategori: {kategori, bila ada}
Kata kunci: {kata kunci terurut, bila ada}
Isi: {isi}
Sumber: {nama sumber, bila ada}
```

Produk memakai nama, kategori, brand, model, varian, SKU/part number, dan atribut
terurut. Alias memakai alias beserta identitas produk kanonik. Hash embedding
mencakup versi format, model, dimensi, dan teks sumber sehingga vector lama atau
stale tidak dianggap current.

Query, produk, alias, dan knowledge wajib memakai model, dimensi, serta pasangan
prefix query/document yang kompatibel. Jalankan rebuild penuh setelah mengubah
model, dimensi, format teks, atau skema prefix retrieval:

```shell
php artisan neomatra:rebuild-embeddings --force
```

Pilihan rebuild terfokus:

```shell
php artisan neomatra:rebuild-embeddings --entity=products --force
php artisan neomatra:rebuild-embeddings --entity=knowledge --force
```

Tanpa `--force`, vector current dilewati. Rebuild hanya memproses entitas aktif,
berjalan dalam chunk/lazy iteration, memakai cache lock untuk mencegah proses
ganda, dan melaporkan jumlah berhasil, dilewati, serta gagal. Jika embedding
baru gagal, vector lama dipertahankan; perubahan sumber membuat hash lama stale
sehingga vector tersebut tidak dipakai sebagai vector current. Vector kosong,
rusak, nonnumerik, non-finite, atau berbeda dimensi selalu diabaikan.

### Retrieval tanpa threshold

Cosine similarity hanya digunakan untuk mengurutkan kandidat. Tidak ada
similarity threshold, minimum score, relevance cutoff, atau `if score < angka`
yang menerima/menolak kandidat. Skor nol dan negatif tetap dapat masuk Top-K.

Nilai default:

- 20 kandidat produk/alias setelah pengurutan similarity;
- 5 hasil listing setelah filter, perhitungan, dan ranking;
- 3 knowledge item berdasarkan similarity.

Setelah kandidat semantic diambil, backend menerapkan kecocokan identitas dan
atribut serta filter listing. Kandidat dapat hilang karena data produk/varian
tidak cocok, listing tidak aktif/tidak tersedia, mata uang, kondisi, lokasi,
seller, atau budget—bukan karena cutoff similarity. Nilai Top-1 yang disimpan di
dashboard hanya untuk monitoring.

## Riwayat percakapan dan Google Search grounding

`ChatClient::parseIntent()` menerima riwayat opsional dan
`ChatExplanationRequest` membawa riwayat yang sama. Adapter hanya memakai enam
pesan valid terakhir dengan role `user` atau `assistant` dan membatasi total
karakter. Karena payload client dapat dipalsukan, seluruh riwayat dikodekan
sebagai JSON tidak tepercaya di dalam satu turn `user`; role `assistant` dari
browser tidak pernah dipromosikan menjadi role `model` Gemini. Riwayat hanya
membantu memahami referensi pertanyaan lanjutan dan tidak pernah menjadi sumber
angka backend.

`ChatClient::explainWithWebGrounding()` memakai model terpisah
`gemini-2.5-flash` dan tool `googleSearch`. Request ini menghasilkan plain text,
tanpa `responseSchema`; intent dan explanation internal tetap memakai structured
JSON tanpa tool web. Jawaban grounding dianggap berhasil hanya jika respons
memiliki minimal satu `groundingChunks.web` dengan URL HTTP(S) tanpa kredensial.
Sumber dideduplikasi berdasarkan URL, fragmen dibuang, teks judul dibersihkan,
dan jumlahnya dibatasi maksimal delapan oleh backend (default lima).

Kebijakan runtime bersifat web-first untuk `product_search` dan
`general_question`: sumber web menjadi sumber eksternal utama, sedangkan hanya
satu knowledge Top-K dikirim sebagai konteks pendukung. Pertanyaan internal yang
tepat cocok dengan knowledge tetap memakai database. Istilah 75/25 adalah arah
provenance, bukan bobot numerik, threshold similarity, atau jaminan proporsi
token. Listing, harga, landed cost, risk, dan ranking selalu berasal dari data
serta perhitungan backend.

Jika Search grounding dipakai, respons juga harus membawa Google Search entry
point. Frontend menampilkannya dalam iframe terisolasi tanpa script, form, atau
same-origin, sedangkan judul jawaban dan sumber tetap dirender sebagai teks.

Grounding memiliki quota/rate limit provider tersendiri. Nonaktifkan secara aman
dengan `CHAT_WEB_GROUNDING_ENABLED=false`. Kegagalan provider, respons tanpa
sumber, URL tidak aman, atau jawaban berlebih menghasilkan failure object aman;
tidak ada raw response maupun API key yang diteruskan ke pengguna.

## Marketplace demo dan sinkronisasi

`DemoMarketplaceAdapter` mengimplementasikan kontrak marketplace yang sama untuk
Tokopedia, Shopee, dan OLX. Record demo berasal dari konfigurasi lokal yang telah
dinormalisasi. Seeder juga menyediakan pembanding terstruktur untuk kondisi
`new`, `used`, `open_box`, `refurbished`, `ex_display`, dan `unknown`. Semua URL
demo memakai domain `example.test`, judulnya berlabel `Data Demo`, dan payload
menyimpan flag `demo`. Tidak ada endpoint marketplace fiktif, scraping, atau
credential marketplace.

Sinkronisasi bersifat manual dan idempotent. Data dicocokkan dengan
`platform_id + external_listing_id` atau fingerprint sumber. Produk kanonik
dipetakan menggunakan normalized key atau kombinasi brand/model/varian/SKU yang
ketat. Setiap eksekusi dicatat pada `marketplace_sync_runs`, dan cache lock
mencegah dua sync platform yang sama berjalan bersamaan.

Sinkronkan semua platform aktif:

```shell
php artisan marketplace:sync
```

Sinkronkan satu platform dan batasi halaman:

```shell
php artisan marketplace:sync tokopedia --pages=1
php artisan marketplace:sync shopee --pages=1
php artisan marketplace:sync olx --pages=1
```

Admin juga dapat menjalankan sync dari menu Platform. Implementasi connector
nyata di masa depan harus ditambahkan melalui `MarketplaceConnector`; jangan
mengubah Chat API atau Embedding API menjadi sumber listing.

## Perhitungan backend

Seluruh angka berikut dihitung secara deterministik oleh PHP dan hanya diberikan
kepada Chat API untuk dijelaskan.

### Harga pasar

Harga pasar adalah median harga listing pembanding dengan produk kanonik,
kondisi, dan mata uang yang sama. Fingerprint duplikat dihitung satu kali.
Listing tidak aktif, stok habis, harga tidak positif, serta harga cicilan/DP atau
jenis selain `full_price` dikeluarkan. Perhitungan membutuhkan minimal dua
pembanding secara default.

### Total biaya

```text
total_cost = item_price
           + shipping_cost
           + service_fee
           + inspection_cost
           + repair_estimate
```

Komponen biaya yang belum tersedia dihitung sebagai nol sekaligus ditandai pada
`missing_fields`, sehingga UI tidak menyamakan estimasi yang belum lengkap
dengan data lengkap.

### Persentase undervalued dan risk signal

```text
undervalued_percentage = ((market_price - total_cost) / market_price) * 100
```

Label yang digunakan:

- `< 5%`: Harga normal;
- `5%` sampai `< 10%`: Sedikit di bawah pasar;
- `10%` sampai `< 20%`: Undervalued;
- `20%` sampai `35%`: Sangat undervalued, perlu pemeriksaan;
- `> 35%`: Anomali harga atau risiko tinggi.

Risk signal meminta pemeriksaan pada nilai `20%` sampai `35%`, dan menandai
anomali harga di atas `35%`. Ini adalah sinyal berbasis harga, bukan klaim bahwa
seller atau produk aman/berbahaya.

### Margin reseller

Untuk mode reseller:

```text
net_margin = estimated_resale_price
           - total_cost
           - selling_fee
           - storage_cost

margin_percent = (net_margin / total_cost) * 100
```

Harga pasar menjadi estimasi harga jual pada alur pencarian saat ini; selling
fee dan storage cost bernilai nol sampai sumber data yang valid tersedia.

### Ranking dan freshness

```text
rank_score = 0.40 * relevance
           + 0.30 * price
           + 0.15 * condition
           + 0.10 * seller
           + 0.05 * freshness
```

Semua subscore dinormalisasi ke `0..1`. Relevance memetakan cosine `-1..1` ke
`0..1`; price membatasi persentase undervalued terhadap 35%; condition dan seller
memakai mapping deterministik; freshness bernilai 1.00 (maksimal 24 jam), 0.75
(maksimal 72 jam), 0.50 (maksimal 168 jam), 0.25 (maksimal 720 jam), lalu 0.00.
Bobot dapat dikelola admin tetapi totalnya wajib tepat `1.00`. Hasil diurutkan
berdasarkan rank menurun, total biaya menaik, lalu ID menaik.

## Modul dan schema database

| Tabel | Fungsi utama |
| --- | --- |
| `users` | Akun session dan flag `is_admin`. |
| `categories` | Hierarki kategori, slug, status aktif, dan urutan. |
| `products` | Produk kanonik, identitas/atribut, provenance spesifikasi resmi, status, serta metadata embedding. |
| `product_aliases` | Istilah alternatif unik per produk beserta embedding. |
| `platforms` | Sumber marketplace, connector, status, dan konfigurasi sync. |
| `listings` | Harga, kondisi, seller, lokasi, URL, biaya, freshness, payload sumber, dan fingerprint deduplication. |
| `marketplace_sync_runs` | Audit jumlah record dan status setiap sinkronisasi. |
| `knowledge_items` | Judul, isi, kategori, keywords, sumber, featured/sort order, dan embedding knowledge. |
| `ranking_profiles` | Bobot ranking; hanya satu profil aktif yang digunakan. |
| `chatbot_query_logs` | Intent/filter, jumlah hasil, Top-1 monitoring, token request, failure code, unanswered review, dan timestamp. |

Relasi penting:

- category dapat memiliki parent, produk, dan knowledge;
- product memiliki banyak alias dan listing;
- platform memiliki banyak listing dan sync run;
- penghapusan product membuat `listings.product_id` menjadi null agar data
  sumber tidak orphan, sedangkan alias ikut terhapus;
- query yang belum terjawab dapat ditandai reviewed oleh admin.

## Route publik

| Method | Path | Fungsi |
| --- | --- | --- |
| `GET` | `/` | UI chatbot Neomatra. |
| `GET` | `/api/chatbot/suggestions` | Maksimal empat knowledge aktif, featured-first. |
| `POST` | `/api/chatbot/query` | Intent, semantic search, kalkulasi, ranking, dan jawaban. |
| `GET` | `/products/{product}/compare` | Perbandingan listing untuk produk aktif. |
| `GET` | `/listings/{listing}/open` | Redirect aman ke URL sumber yang diizinkan. |
| `GET` | `/up` | Health check Laravel. |

Payload query dapat memuat filter eksplisit:

```json
{
  "message": "Cari RTX 3060 12 GB bekas di Surabaya maksimal Rp2.800.000",
  "budget_max": 2800000,
  "location": "Surabaya",
  "condition": "used",
  "seller_type": "store",
  "mode": "buyer",
  "session_key": "6f4f3f12-80eb-4c75-85c8-ec05a5ccae31"
}
```

`message` wajib berupa string non-kosong, maksimal 2.000 karakter. Respons berisi
`answer`, intent, kebutuhan klarifikasi, listing terstruktur, knowledge matches,
dan metadata provider/Top-K. URL listing eksternal tidak diekspos langsung;
respons memakai route redirect internal yang memvalidasi scheme dan host.

Status penting:

- `200`: hasil, knowledge-only, klarifikasi, atau fallback penjelasan aman;
- `422`: input/filter tidak valid;
- `429`: rate limit chatbot terlampaui;
- `503`: Embedding API tidak tersedia, quota habis, atau vector query gagal.

## Route admin

`/admin/login` adalah satu-satunya bagian admin untuk guest. Semua route lain
memerlukan session aktif dan gate `access-admin`; mutasi memakai CSRF dan method
HTTP yang sesuai.

| Method | Path | Fungsi |
| --- | --- | --- |
| `GET`, `POST` | `/admin/login` | Form dan proses login. |
| `POST` | `/admin/logout` | Logout dan invalidasi session. |
| `GET` | `/admin` | Dashboard aktivitas, katalog, unanswered, dan status knowledge. |
| resource tanpa `show` | `/admin/categories` | CRUD hierarki kategori. |
| resource tanpa `show` | `/admin/products` | CRUD produk dan alias. |
| resource tanpa `show` | `/admin/platforms` | CRUD konfigurasi platform. |
| `POST` | `/admin/platforms/{platform}/sync` | Sinkronisasi manual satu platform. |
| resource tanpa `show` | `/admin/listings` | CRUD listing dan biaya. |
| resource tanpa `show` | `/admin/knowledge` | CRUD, search, filter, dan pagination knowledge. |
| `PATCH` | `/admin/knowledge/{knowledgeItem}/active` | Toggle knowledge aktif. |
| `PATCH` | `/admin/knowledge/{knowledgeItem}/featured` | Toggle featured. |
| `POST` | `/admin/knowledge/{knowledgeItem}/embedding/rebuild` | Rebuild satu knowledge item. |
| `POST` | `/admin/knowledge/rebuild` | Rebuild seluruh knowledge aktif. |
| `GET` | `/admin/unanswered-queries` | Daftar query yang perlu ditinjau. |
| `GET`, `PATCH` | `/admin/unanswered-queries/{queryLog}` | Detail dan update status review. |
| `GET`, `PATCH` | `/admin/ranking` | Lihat dan aktifkan profil bobot ranking. |

Untuk resource tanpa `show`, Laravel menyediakan route `index`, `create`,
`store`, `edit`, `update` (`PUT/PATCH`), dan `destroy` (`DELETE`). Slug platform
tidak dapat diubah setelah memiliki listing, kategori yang masih dipakai tidak
dapat dihapus, dan delete/rebuild memerlukan konfirmasi UI.

## Knowledge dan suggested questions

Suggested questions hanya mengambil knowledge aktif, memprioritaskan featured,
lalu `sort_order` dan ID, maksimal empat item. Chip hanya tampil sebelum
percakapan dimulai dan klik langsung mengirim satu request. Frontend mematikan
input, tombol kirim, dan chip selama request, serta merender data dinamis dengan
API DOM yang aman tanpa memasukkan konten database ke `innerHTML`.

Perubahan judul, isi, kategori, keywords, atau nama sumber membangun ulang
embedding knowledge. Perubahan status aktif, featured, sort order, atau URL
sumber tidak memanggil Embedding API. Knowledge nonaktif tidak dipakai retrieval
tetapi vector-nya dipertahankan.

## Monitoring dan keamanan

Dashboard memakai aggregate query untuk pertanyaan hari ini, produk/listing
aktif, pending unanswered, rata-rata Top-1, status embedding knowledge, dan
aktivitas tujuh hari. Similarity hanya ditampilkan sebagai observabilitas dan
tidak menentukan apakah hasil diterima.

Kontrol keamanan utama:

- admin dilindungi session authentication, authorization gate, CSRF, validasi,
  dan rate limit login lima percobaan/menit per email + IP;
- chatbot dibatasi per IP melalui `CHATBOT_RATE_LIMIT`;
- API key hanya dibaca dari `.env` melalui config Laravel;
- raw exception dan response sensitif provider tidak dikirim ke pengguna;
- isi katalog/knowledge diperlakukan sebagai data tidak tepercaya oleh prompt;
- Blade meng-escape output, frontend memakai rendering teks aman, dan URL publik
  hanya menerima `http`/`https` serta host yang sesuai platform;
- sync dan rebuild memakai lock untuk mencegah request ganda.

`meta.usage` dan kolom token log menunjukkan token yang dipakai request, bukan
sisa quota Gemini. Sisa quota harus diperiksa pada dashboard/console provider.

## Test dan pemeriksaan kualitas

Jalankan pemeriksaan utama:

```shell
php artisan test
./vendor/bin/pint --test
npm run build
php artisan route:list
composer validate
```

Di Windows, Pint juga dapat dijalankan dengan:

```powershell
php vendor\bin\pint --test
```

Test JavaScript coordinator:

```shell
npm run test:js
```

Cakupan otomatis meliputi:

- autentikasi dan authorization admin;
- CRUD kategori, produk/alias, platform, listing, dan knowledge;
- validasi, XSS, URL aman, hierarchy cycle, serta perlindungan delete;
- marketplace sync idempotent, lock, mapping produk, dan audit run;
- parsing intent, fallback aman, structured response, dan rate limit;
- history bounded serta Google Search grounding dengan model terpisah,
  deduplikasi/cap sumber, URL aman, dan graceful failure;
- vector validation, cosine ordering, Top-K tanpa threshold, termasuk similarity
  nol dan negatif;
- rebuild current/stale, perubahan semantic vs metadata, partial failure, dan
  preservasi vector lama;
- rumus harga pasar, total biaya, undervalued, margin, risk, freshness, dan bobot
  ranking;
- unanswered review, dashboard aggregate, suggested questions, dan pencegahan
  double-submit frontend.

Seluruh test integrasi AI memakai `Http::fake()` dan mencegah stray request,
sehingga tidak mengonsumsi API key atau quota nyata.

## Batasan MVP

- Data marketplace adalah dataset demo dan sinkronisasi manual, bukan data live.
- Riwayat percakapan dikirim client secara bounded maksimal enam pesan;
  `session_key` hanya dipakai untuk korelasi log dan bukan memory server-side.
- Google Search grounding hanya untuk pertanyaan umum/informasi pendukung dan
  bergantung pada akses, quota, serta dukungan model provider; data web bukan
  data marketplace.
- Cosine similarity dihitung di PHP dengan vector JSON sehingga belum ditujukan
  untuk katalog sangat besar.
- Sync dan rebuild synchronous; volume besar memerlukan queue dan vector store
  setelah ada keputusan arsitektur baru.
- Kondisi berasal dari keterangan seller dan tidak diverifikasi secara fisik.
- Harga pasar, biaya, undervalued, margin, freshness, ranking, serta risk signal
  bersifat informasi/estimasi, bukan jaminan transaksi, keamanan, atau profit.
