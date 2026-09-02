<?php

namespace Database\Seeders;

use App\Helpers\Normalizer;
use App\Models\Category;
use App\Models\CategoryAlias;
use App\Models\KnowledgeItem;
use App\Models\Product;
use App\Models\ProductAlias;
use App\Services\Search\ProductFamilyResolver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class NeomatraSearchVocabularySeeder extends Seeder
{
    private const SOURCE = 'Neomatra Search Vocabulary V1';

    public function run(): void
    {
        DB::transaction(function (): void {
            $this->seedCategoryAliases();
            $this->seedProductMetadataAndAliases();
            $this->seedKnowledge();
        });
    }

    private function seedCategoryAliases(): void
    {
        $vocabulary = [
            'gpu' => [
                'gpu', 'graphic card', 'graphics card', 'video card',
                'video graphics card', 'vga', 'vga card', 'kartu grafis',
                'kartu grafik', 'kartu vga',
            ],
            'cpu' => ['cpu', 'processor', 'prosesor', 'cpu processor'],
            'ram' => ['ram', 'computer memory', 'system memory', 'memori ram'],
            'ssd' => ['ssd', 'solid state drive', 'storage ssd'],
            'hdd' => ['hdd', 'hard disk', 'hard drive', 'harddisk'],
            'motherboard' => ['motherboard', 'mainboard', 'mobo'],
            'psu' => ['psu', 'power supply', 'power supply unit'],
            'cpu-cooler' => ['cpu cooler', 'processor cooler', 'pendingin cpu'],
            'smartphone' => [
                'phone', 'smartphone', 'handphone', 'hp', 'ponsel',
                'mobile phone',
            ],
            'laptop' => ['laptop', 'notebook', 'notebook computer'],
            'monitor' => ['monitor', 'pc monitor', 'computer display'],
            'keyboard' => ['keyboard', 'papan ketik'],
            'mouse' => ['mouse', 'computer mouse'],
            'router' => ['router', 'wifi router', 'wireless router', 'router wifi'],
            'access-point' => [
                'access point', 'wireless access point', 'wifi access point',
            ],
            'switch' => ['network switch', 'ethernet switch', 'lan switch'],
            'network-adapter' => [
                'network adapter', 'lan card', 'nic', 'ethernet adapter',
                'wifi adapter', 'wireless adapter',
            ],
        ];

        foreach ($vocabulary as $slug => $aliases) {
            $category = Category::query()->where('slug', $slug)->first();

            if (! $category instanceof Category) {
                throw new RuntimeException(
                    "Kategori vocabulary tidak ditemukan: {$slug}",
                );
            }

            foreach ($aliases as $alias) {
                CategoryAlias::query()->updateOrCreate(
                    [
                        'category_id' => $category->id,
                        'normalized_alias' => Normalizer::text($alias),
                    ],
                    [
                        'alias' => $alias,
                        'language' => $this->language($alias),
                        'source' => self::SOURCE,
                        'is_active' => true,
                    ],
                );
            }
        }

        $gpu = Category::query()->where('slug', 'gpu')->firstOrFail();
        CategoryAlias::query()->updateOrCreate(
            [
                'category_id' => $gpu->id,
                'normalized_alias' => 'grafics card',
            ],
            [
                'alias' => 'grafics card',
                'language' => 'en',
                'source' => self::SOURCE.' curated_typo',
                'is_active' => true,
            ],
        );
    }

    private function seedProductMetadataAndAliases(): void
    {
        $resolver = app(ProductFamilyResolver::class);

        Product::query()
            ->active()
            ->with('category')
            ->lazyById(100)
            ->each(function (Product $product) use ($resolver): void {
                $family = $resolver->resolve(implode(' ', array_filter([
                    $product->brand,
                    $product->model,
                    $product->name,
                ])));

                if ($family === null) {
                    return;
                }

                $attributes = is_array($product->attributes)
                    ? $product->attributes
                    : [];
                $metadata = array_filter([
                    'brand_family' => $family['brand_family'],
                    'product_family' => $family['family'],
                    'generation' => $family['generation'],
                    'model_number' => $family['model_number'],
                ], static fn (mixed $value): bool => $value !== null);
                $merged = [...$attributes, ...$metadata];

                if ($merged !== $attributes) {
                    $product->forceFill([
                        'attributes' => $merged,
                        'embedding_hash' => null,
                        'embedding_error' => null,
                    ])->save();
                }

                foreach ($this->productAliases($product, $family) as $alias) {
                    ProductAlias::query()->updateOrCreate(
                        [
                            'product_id' => $product->id,
                            'normalized_alias' => Normalizer::text($alias),
                        ],
                        ['alias' => $alias],
                    );
                }
            });
    }

    /**
     * @param  array<string, mixed>  $family
     * @return list<string>
     */
    private function productAliases(Product $product, array $family): array
    {
        $model = trim((string) $product->model);

        if ($model === '') {
            return [];
        }

        $aliases = [
            $model,
            preg_replace('/[\s\-_]+/u', '', $model) ?: $model,
            trim((string) $product->brand.' '.$model),
        ];

        if ($family['category_slug'] === 'gpu') {
            $aliases = [
                ...$aliases,
                'VGA '.$model,
                'Graphic Card '.$model,
                'Graphics Card '.$model,
                'Kartu Grafis '.$model,
            ];
        } elseif ($family['category_slug'] === 'cpu') {
            $aliases[] = 'Processor '.$model;
            $aliases[] = 'Prosesor '.$model;
        } elseif ($family['category_slug'] === 'smartphone') {
            $aliases[] = 'HP '.$model;
            $aliases[] = 'Smartphone '.$model;
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (string $alias): string => trim($alias),
            $aliases,
        ))));
    }

    private function seedKnowledge(): void
    {
        $categoryId = Category::query()->updateOrCreate(
            ['slug' => 'panduan-neomatra'],
            [
                'parent_id' => null,
                'name' => 'Panduan Neomatra',
                'description' => 'Knowledge umum penggunaan dan batasan Neomatra.',
                'is_active' => true,
                'sort_order' => 5,
            ],
        )->id;

        $items = [
            [
                'Category vocabulary / Kosakata kategori',
                'ID: GPU, VGA, graphic card, graphics card, video card, kartu grafis, dan kartu VGA mengacu pada kategori GPU. Istilah generik disimpan sebagai vocabulary kategori, bukan alias satu produk. EN: GPU, VGA, graphic card, graphics card, video card, kartu grafis, and kartu VGA refer to the GPU category. Generic terms are category vocabulary, not aliases for one product.',
                ['gpu', 'vga', 'graphic card', 'graphics card', 'kartu grafis'],
            ],
            [
                'NVIDIA RTX dan GTX / NVIDIA RTX and GTX',
                'ID: GeForce RTX dan GeForce GTX adalah family GPU NVIDIA yang berbeda. Alternatif GTX tidak boleh disebut sebagai RTX. EN: GeForce RTX and GeForce GTX are different NVIDIA GPU families. A GTX alternative must never be described as RTX.',
                ['nvidia', 'rtx', 'gtx', 'family'],
            ],
            [
                'AMD Radeon RX',
                'ID: Radeon RX merupakan family GPU AMD. Hasil Radeon harus disebut sebagai alternatif berbeda brand ketika query meminta NVIDIA. EN: Radeon RX is an AMD GPU family. Radeon results must be identified as a different-brand alternative when the request asks for NVIDIA.',
                ['amd', 'radeon', 'rx', 'family'],
            ],
            [
                'Fallback produk terkait / Related product fallback',
                'ID: Jika produk exact tidak tersedia, Neomatra mencari produk aktif dengan urutan same family, same brand, same category, lalu external platform. EN: If the exact product is unavailable, Neomatra searches active products in this order: same family, same brand, same category, then an external platform.',
                [
                    'fallback',
                    'alternative',
                    'related product',
                    'produk alternatif',
                    'mencari produk alternatif',
                ],
            ],
            [
                'Transparansi alternatif / Alternative transparency',
                'ID: Neomatra wajib menjelaskan ketika alternatif berbeda family, generation, atau brand dari produk yang diminta. EN: Neomatra must state when an alternative differs in family, generation, or brand from the requested product.',
                ['alternative', 'family', 'generation', 'brand', 'transparency'],
            ],
            [
                'Handoff marketplace eksternal / External marketplace handoff',
                'ID: Neomatra tidak boleh menyatakan produk tersedia di marketplace eksternal tanpa listing terverifikasi. Jika hanya base URL tersedia, sistem hanya memberikan platform dan istilah pencarian. EN: Neomatra must not claim marketplace availability without a verified listing. If only a base URL is available, it provides only the platform and search term.',
                ['external', 'handoff', 'marketplace', 'verified listing'],
            ],
            [
                'Pencarian kategori generik / Generic category search',
                'ID: Query seperti graphic card di bawah Rp3 juta, laptop di bawah Rp8 juta, atau wifi router di bawah Rp1 juta dapat mencari listing berdasarkan kategori tanpa model exact. Query kategori tanpa kriteria tambahan akan meminta klarifikasi. EN: Queries such as graphic card under Rp3m, laptop under Rp8m, or wifi router under Rp1m can search listings by category without an exact model. A category-only query asks for clarification.',
                ['category search', 'budget', 'graphic card', 'laptop', 'wifi router'],
            ],
        ];

        foreach ($items as $index => [$title, $content, $keywords]) {
            $existing = KnowledgeItem::query()->where('title', $title)->first();
            $payload = [
                'content' => $content,
                'category_id' => $categoryId,
                'keywords' => $keywords,
                'source_name' => self::SOURCE,
                'source_url' => null,
                'is_active' => true,
                'is_featured' => false,
                'sort_order' => 500 + ($index * 10),
            ];
            $changed = $existing !== null && (
                $existing->content !== $content
                || $existing->keywords !== $keywords
                || $existing->source_name !== self::SOURCE
            );
            $item = KnowledgeItem::query()->updateOrCreate(
                ['title' => $title],
                $payload,
            );

            if ($changed) {
                $item->forceFill([
                    'embedding_hash' => null,
                    'embedding_error' => null,
                ])->save();
            }
        }
    }

    private function language(string $alias): string
    {
        foreach ([
            'kartu', 'grafik', 'prosesor', 'memori', 'pendingin', 'ponsel',
            'papan ketik',
        ] as $indonesian) {
            if (str_contains($alias, $indonesian)) {
                return 'id';
            }
        }

        return preg_match('/\b(gpu|cpu|ram|ssd|hdd|psu|vga|hp|nic)\b/u', $alias)
            ? 'und'
            : 'en';
    }
}
