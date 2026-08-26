<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            NeomatraCatalogSeeder::class,
            NeomatraDatasetV2Seeder::class,
            KnowledgeItemSeeder::class,
            NeomatraDatasetV3Seeder::class,
            NeomatraSearchVocabularySeeder::class,
        ]);
    }
}
