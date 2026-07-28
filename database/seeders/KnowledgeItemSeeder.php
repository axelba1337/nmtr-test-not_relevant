<?php

namespace Database\Seeders;

use App\Models\KnowledgeItem;
use Illuminate\Database\Seeder;

class KnowledgeItemSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            [
                'title' => 'Tentang Semantic Chatbot Lite',
                'content' => 'Semantic Chatbot Lite adalah aplikasi chatbot stateless yang menjawab pertanyaan berdasarkan knowledge base lokal.',
                'sort_order' => 10,
            ],
            [
                'title' => 'Cara chatbot menggunakan knowledge',
                'content' => 'Chatbot membuat embedding pertanyaan, mengurutkan knowledge aktif dengan cosine similarity, lalu menggunakan hasil Top-K sebagai konteks jawaban.',
                'sort_order' => 20,
            ],
            [
                'title' => 'Ketika informasi tidak tersedia',
                'content' => 'Jika knowledge yang diberikan tidak memuat jawaban, chatbot menyatakan bahwa informasi tersebut belum tersedia.',
                'sort_order' => 30,
            ],
        ];

        foreach ($items as $item) {
            KnowledgeItem::query()->firstOrCreate(
                [
                    'title' => $item['title'],
                    'content' => $item['content'],
                ],
                [
                    'embedding' => null,
                    'embedding_hash' => null,
                    'is_active' => true,
                    'is_featured' => true,
                    'sort_order' => $item['sort_order'],
                ],
            );
        }
    }
}
