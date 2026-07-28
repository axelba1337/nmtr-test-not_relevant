# Semantic Chatbot Lite

Semantic Chatbot Lite adalah aplikasi Laravel 12 yang menjawab pertanyaan
berdasarkan FAQ lokal. Aplikasi menggunakan Gemini untuk dua kemampuan yang
berbeda:

1. Embedding mengubah pertanyaan pengguna dan FAQ menjadi vector.
2. Chat completion menyusun jawaban hanya dari konteks FAQ Top-K.

Retrieval memvalidasi vector, menghitung cosine similarity, mengurutkan seluruh
kandidat valid dari skor tertinggi, lalu mengambil Top-K. Tidak ada similarity
threshold, minimum score, relevance cutoff, atau keputusan jawaban berbasis
angka similarity. Skor nol dan negatif tetap dapat menjadi kandidat.

## Kebutuhan

- PHP 8.2 atau lebih baru dengan ekstensi `pdo_mysql` untuk aplikasi
- Composer
- Node.js 22.12 atau lebih baru dan npm
- MySQL 8 atau MariaDB yang kompatibel
- Gemini API key

Test otomatis memakai SQLite in-memory dan tidak memerlukan MySQL atau quota API
Gemini.

## Instalasi dari fresh clone

1. Pasang dependency dan buat file environment:

   ```shell
   composer install
   ```

   PowerShell:

   ```powershell
   Copy-Item .env.example .env
   ```

   Linux/macOS:

   ```shell
   cp .env.example .env
   ```

2. Buat database MySQL, lalu sesuaikan `DB_*` dan isi `GEMINI_API_KEY` di
   `.env`. Jangan menyimpan key di source code atau Git.

3. Siapkan aplikasi:

   ```shell
   php artisan key:generate
   php artisan migrate --seed
   npm ci
   npm run build
   ```

4. Buat akun admin secara interaktif:

   ```shell
   php artisan admin:create
   ```

5. Bangun embedding FAQ aktif:

   ```shell
   php artisan chatbot:embed-knowledge
   ```

Setup juga dapat dijalankan melalui `composer run setup` setelah database dan
konfigurasi `.env` tersedia. Migration dan seeder tidak memanggil Gemini.

## Route aplikasi

### Publik

| Method | Path | Fungsi |
| --- | --- | --- |
| `GET` | `/` | Halaman chatbot |
| `GET` | `/api/chatbot/suggestions` | Maksimal empat FAQ aktif, featured-first |
| `POST` | `/api/chatbot/query` | Mengirim satu pertanyaan |
| `GET` | `/up` | Health check Laravel |

### Admin

| Method | Path | Fungsi |
| --- | --- | --- |
| `GET` | `/admin/login` | Form login |
| `POST` | `/admin/login` | Autentikasi admin |
| `POST` | `/admin/logout` | Logout |
| `GET` | `/admin` | Dashboard |
| `GET` | `/admin/knowledge` | Daftar, search, filter, dan pagination FAQ |
| `GET` | `/admin/knowledge/create` | Form tambah FAQ |
| `POST` | `/admin/knowledge` | Simpan FAQ |
| `GET` | `/admin/knowledge/{knowledgeItem}/edit` | Form edit FAQ |
| `PUT/PATCH` | `/admin/knowledge/{knowledgeItem}` | Perbarui FAQ |
| `DELETE` | `/admin/knowledge/{knowledgeItem}` | Hapus FAQ |
| `PATCH` | `/admin/knowledge/{knowledgeItem}/active` | Toggle status aktif |
| `PATCH` | `/admin/knowledge/{knowledgeItem}/featured` | Toggle featured |
| `POST` | `/admin/knowledge/{knowledgeItem}/embedding/rebuild` | Rebuild satu FAQ |
| `POST` | `/admin/knowledge/rebuild` | Rebuild semua FAQ aktif |

Seluruh route admin selain login dilindungi session authentication dan
authorization `is_admin`. Mutasi web menggunakan CSRF protection. Login dibatasi
lima percobaan per menit per kombinasi email dan IP.

## Chatbot dan suggested questions

Suggested questions berasal dari database, hanya mengambil FAQ aktif, diurutkan
featured terlebih dahulu lalu `sort_order` dan `id`, serta dibatasi empat item.
Chip hanya tampil sebelum percakapan dimulai dan langsung mengirim pertanyaan
saat diklik.

Request chatbot:

```http
POST /api/chatbot/query
Content-Type: application/json

{"message":"Bagaimana chatbot menggunakan knowledge?"}
```

`message` wajib berupa string non-whitespace dengan panjang maksimal 2.000
karakter. Respons sukses:

```json
{
  "answer": "Jawaban berdasarkan knowledge.",
  "matches": [
    {
      "id": 1,
      "title": "Pertanyaan FAQ",
      "similarity": 0.812345
    }
  ]
}
```

Isi FAQ dan embedding tidak diekspos dalam `matches`. Respons teknis yang
mungkin dikembalikan:

| HTTP | Kondisi |
| --- | --- |
| `422` | Input tidak valid |
| `429` | Rate limit terlampaui |
| `503` | Embedding query gagal |
| `503` | Tidak ada FAQ aktif dengan embedding valid |
| `502` | Chat completion gagal |

Jika konteks Top-K tidak memuat jawaban, system instruction meminta model
menyatakan bahwa informasi belum tersedia. Keputusan ini berdasarkan isi
konteks, bukan nilai similarity.

## FAQ, embedding, dan rebuild

Tabel `knowledge_items` menjadi satu-satunya sumber FAQ dan embedding:

| Kolom | Keterangan |
| --- | --- |
| `title`, `content` | Pertanyaan dan jawaban |
| `is_active` | Menentukan apakah FAQ ikut retrieval |
| `is_featured`, `sort_order` | Prioritas suggested questions |
| `embedding` | Vector JSON nullable |
| `embedding_hash` | Hash format, model, dimensi, dan sumber |
| `embedding_model`, `embedded_at` | Metadata embedding |
| `embedding_error` | Pesan kegagalan aman |
| timestamps | Waktu pembuatan dan pembaruan |

Teks FAQ yang dikirim ke embedding API selalu:

```text
Pertanyaan: {question}
Jawaban: {answer}
```

Perubahan pertanyaan atau jawaban membuat embedding baru. Perubahan status,
featured, atau urutan tidak memanggil API embedding. Jika embedding baru gagal,
vector dan metadata lama dipertahankan; hash tidak lagi cocok dengan sumber baru
sehingga vector stale tidak dipakai retrieval.

Rebuild seluruh knowledge berjalan synchronous, memakai cache lock untuk
mencegah request ganda, memproses FAQ aktif dengan `lazyById()`, dan melaporkan
jumlah berhasil, gagal, serta dilewati. Mode ini ditujukan untuk knowledge kecil.

Command berikut melewati embedding yang masih current:

```shell
php artisan chatbot:embed-knowledge
```

Gunakan `--force` setelah model/dimensi berubah atau untuk rebuild penuh:

```shell
php artisan chatbot:embed-knowledge --force
```

## Monitoring

`chatbot_query_logs` hanya menyimpan status hasil, Top-1 cosine similarity bila
tersedia, dan timestamp. Pertanyaan, jawaban, IP, API key, serta vector tidak
disimpan.

Dashboard menampilkan:

- total pertanyaan valid hari ini;
- total FAQ aktif;
- rata-rata Top-1 hari ini sebagai monitoring saja;
- jumlah embedding aktif yang siap, gagal, atau belum dibuat/kedaluwarsa;
- grafik aktivitas tujuh hari dari aggregate query aktual.

## Gemini dan environment

Model bawaan:

- Chat: `gemini-3.5-flash-lite`
- Embedding: `gemini-embedding-2`
- Dimensi embedding: `768`
- Top-K: `3`

Integrasi REST memakai endpoint Gemini API `v1beta` dan header
`x-goog-api-key`. Query dan FAQ wajib memakai model embedding serta dimensi yang
sama. Setelah `GEMINI_EMBED_MODEL` atau `GEMINI_EMBED_DIMENSIONS` berubah,
jalankan rebuild seluruh knowledge.

Environment aplikasi:

```dotenv
APP_NAME="Semantic Chatbot Lite"
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost
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

GEMINI_API_KEY=
GEMINI_CHAT_MODEL=gemini-3.5-flash-lite
GEMINI_EMBED_MODEL=gemini-embedding-2
GEMINI_EMBED_DIMENSIONS=768

CHATBOT_TOP_K=3
CHATBOT_MAX_CONTEXT_CHARS=4000
CHATBOT_MAX_OUTPUT_TOKENS=512
CHATBOT_HTTP_TIMEOUT=25
CHATBOT_HTTP_RETRY=1
CHATBOT_RATE_LIMIT=20

SESSION_DRIVER=file
SESSION_LIFETIME=120
CACHE_STORE=file
```

Jangan commit `.env`. Simpan key production melalui secret manager platform.

## Pengembangan dan pemeriksaan

Jalankan server Laravel dan Vite:

```shell
composer run dev
```

Command pemeriksaan:

```shell
php artisan test
./vendor/bin/pint --test
npm run build
php artisan route:list
composer validate
```

Test PHP menggunakan SQLite `:memory:` dari `phpunit.xml`. Seluruh test AI
memakai HTTP fake dan menolak request eksternal. Test JavaScript coordinator
dapat dijalankan terpisah:

```shell
npm run test:js
```

## Batasan operasional

- Percakapan bersifat single-turn dan stateless.
- Retrieval memindai vector valid di PHP dan ditujukan untuk knowledge kecil.
- Rebuild admin berjalan synchronous; gunakan queue hanya bila volume dan waktu
  proses sudah memerlukannya.
- Cache file dan rate limiter tidak dibagi antar-instance.
- Pembuatan embedding dan jawaban nyata memakai quota Gemini.
