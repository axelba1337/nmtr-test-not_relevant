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
                'content' => 'Neomatra membantu mencari produk teknologi secara semantik dan menampilkan listing relevan dengan harga aktual, kondisi, lokasi, sumber, dan freshness. Transaksi tetap dilakukan di platform sumber.',
                'keywords' => ['fitur Neomatra', 'pencarian barang', 'semantic search'],
                'sort_order' => 10,
            ],
            [
                'title' => 'Bagaimana Neomatra menentukan harga pasar?',
                'content' => 'Fitur estimasi harga pasar tidak termasuk dalam scope MVP Neomatra saat ini.',
                'keywords' => ['scope lama'],
                'sort_order' => 20,
                'is_active' => false,
            ],
            [
                'title' => 'Apakah kondisi dan keamanan barang dijamin?',
                'content' => 'Tidak. Kondisi mengikuti deskripsi listing dan tidak diverifikasi secara fisik oleh Neomatra. Harga dan informasi seller berasal dari sumber listing dan bukan jaminan keamanan transaksi.',
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
                    'is_active' => $item['is_active'] ?? true,
                    'is_featured' => $item['is_active'] ?? true,
                    'sort_order' => $item['sort_order'],
                ],
            );
        }
    }
}
