<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\KnowledgeItem;
use Illuminate\Database\Seeder;

class KnowledgeItemSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            [
                'title' => 'Apa yang dapat dilakukan Neomatra?',
                'content' => 'Neomatra membantu membandingkan listing barang lintas platform, menghitung estimasi harga pasar, total biaya, persentase undervalued, dan margin reseller. Hasil bersifat informasional dan transaksi tetap dilakukan di platform sumber.',
                'keywords' => ['fitur Neomatra', 'pencarian barang', 'perbandingan harga'],
                'sort_order' => 10,
            ],
            [
                'title' => 'Bagaimana Neomatra menentukan harga pasar?',
                'content' => 'Harga pasar adalah median harga listing pembanding yang valid untuk produk, varian, dan kondisi yang sama. Listing tanpa stok, harga nol, cicilan, uang muka, harga tidak penuh, dan duplikat dikeluarkan.',
                'keywords' => ['median', 'harga pasar', 'listing pembanding'],
                'sort_order' => 20,
            ],
            [
                'title' => 'Apakah kondisi dan keamanan barang dijamin?',
                'content' => 'Tidak. Kondisi mengikuti deskripsi seller dan tidak diverifikasi secara fisik oleh Neomatra. Harga, kondisi, seller, sinyal risiko, dan margin adalah informasi atau estimasi, bukan jaminan keamanan maupun keuntungan.',
                'keywords' => ['kondisi seller', 'risiko', 'disclaimer'],
                'sort_order' => 30,
            ],
        ];

        $categoryId = Category::query()
            ->where('slug', 'panduan-neomatra')
            ->value('id');

        foreach ($items as $item) {
            KnowledgeItem::query()->updateOrCreate(
                ['title' => $item['title']],
                [
                    'category_id' => $categoryId,
                    'content' => $item['content'],
                    'keywords' => $item['keywords'],
                    'source_name' => 'SRS Neomatra v2.0',
                    'source_url' => null,
                    'is_active' => true,
                    'is_featured' => true,
                    'sort_order' => $item['sort_order'],
                ],
            );
        }
    }
}
