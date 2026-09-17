<?php

namespace App\Services\Catalog;

use App\Helpers\Normalizer;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAlias;
use App\Rules\SafeProductAliases;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use SplFileObject;
use Throwable;

final class ProductCsvImportService
{
    /** @var list<string> */
    public const HEADERS = [
        'category',
        'canonical_name',
        'brand',
        'model',
        'variant',
        'sku_or_part_number',
        'aliases',
        'specification_source',
        'is_active',
    ];

    public function __construct(
        private readonly CatalogScope $scope,
        private readonly ProductMutationService $products,
    ) {}

    /**
     * @return array{
     *   summary:array{rows:int,ready:int,existing:int,invalid:int,duplicate:int},
     *   details:list<array<string,mixed>>,
     *   rows:list<array{row_number:int,values:array<string,string>}>
     * }
     */
    public function previewFile(string $path, string $filename): array
    {
        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'csv') {
            throw new InvalidArgumentException(
                __('ui.admin_pages.product_import.errors.csv_only'),
            );
        }

        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException(
                __('ui.admin_pages.product_import.errors.unreadable'),
            );
        }

        $contents = file_get_contents($path);

        if (! is_string($contents) || $contents === '') {
            throw new InvalidArgumentException(
                __('ui.admin_pages.product_import.errors.minimum_row'),
            );
        }

        if (! mb_check_encoding($contents, 'UTF-8')) {
            throw new InvalidArgumentException(
                __('ui.admin_pages.product_import.errors.utf8'),
            );
        }

        $file = new SplFileObject($path, 'rb');
        $file->setFlags(
            SplFileObject::READ_CSV
            | SplFileObject::DROP_NEW_LINE
            | SplFileObject::SKIP_EMPTY,
        );
        $file->setCsvControl(',', '"', '');
        $header = $file->fgetcsv();

        if (! is_array($header) || ! $this->validHeader($header)) {
            throw new InvalidArgumentException(
                __('ui.admin_pages.product_import.errors.header'),
            );
        }

        $records = [];

        while (! $file->eof()) {
            $row = $file->fgetcsv();
            $rowNumber = $file->key() + 1;

            if ($this->emptyRow($row)) {
                continue;
            }

            if (! is_array($row) || count($row) !== count(self::HEADERS)) {
                $records[] = [
                    'row_number' => $rowNumber,
                    'values' => null,
                    'error' => 'wrong_column_count',
                ];

                continue;
            }

            $values = array_combine(self::HEADERS, array_map(
                static fn (mixed $value): string => is_string($value)
                    ? trim($value)
                    : '',
                $row,
            ));
            $records[] = [
                'row_number' => $rowNumber,
                'values' => is_array($values) ? $values : null,
                'error' => is_array($values) ? null : 'malformed_csv',
            ];
        }

        if ($records === []) {
            throw new InvalidArgumentException(
                __('ui.admin_pages.product_import.errors.minimum_row'),
            );
        }

        return $this->previewRecords($records);
    }

    /**
     * @param  list<array{row_number?:int,values:mixed,error?:string|null}>  $records
     * @return array{
     *   summary:array{rows:int,ready:int,existing:int,invalid:int,duplicate:int},
     *   details:list<array<string,mixed>>,
     *   rows:list<array{row_number:int,values:array<string,string>}>
     * }
     */
    public function previewRecords(array $records): array
    {
        $categories = $this->categoryLookup();
        $existingProducts = Product::query()->get([
            'id', 'name', 'brand', 'model', 'variant',
            'sku_or_part_number', 'normalized_key',
        ]);
        $existingByKey = $existingProducts->keyBy('normalized_key');
        $existingByIdentity = $existingProducts->keyBy(
            fn (Product $product): string => $this->identityWithoutSku([
                'canonical_name' => $product->name,
                'brand' => $product->brand,
                'model' => $product->model,
                'variant' => $product->variant,
            ]),
        );
        $existingBySku = $existingProducts
            ->filter(fn (Product $product): bool => filled($product->sku_or_part_number))
            ->keyBy(fn (Product $product): string => $this->skuKey(
                (string) $product->sku_or_part_number,
            ));
        $existingAliases = ProductAlias::query()
            ->get(['product_id', 'normalized_alias'])
            ->keyBy('normalized_alias');
        $summary = [
            'rows' => 0,
            'ready' => 0,
            'existing' => 0,
            'invalid' => 0,
            'duplicate' => 0,
        ];
        $details = [];
        $readyRows = [];
        $seenIdentities = [];
        $seenSkus = [];
        $seenAliases = [];

        foreach ($records as $index => $record) {
            $summary['rows']++;
            $rowNumber = max(2, (int) ($record['row_number'] ?? $index + 2));
            $values = $record['values'] ?? null;

            if (! is_array($values)) {
                $this->recordDetail(
                    $summary,
                    $details,
                    $rowNumber,
                    [],
                    'invalid',
                    (string) ($record['error'] ?? 'malformed_csv'),
                );

                continue;
            }

            /** @var array<string, string> $values */
            $validation = $this->normalizeAndValidateRow($values, $categories);

            if ($validation['error'] !== null) {
                $this->recordDetail(
                    $summary,
                    $details,
                    $rowNumber,
                    $values,
                    'invalid',
                    $validation['error'],
                );

                continue;
            }

            $normalized = $validation['normalized'];
            $productKey = (string) $normalized['normalized_key'];
            $identityKey = (string) $normalized['identity_key'];
            $skuKey = (string) $normalized['sku_key'];
            $aliases = $normalized['aliases'];

            if ($existingByKey->has($productKey)
                || $existingByIdentity->has($identityKey)) {
                $this->recordDetail(
                    $summary,
                    $details,
                    $rowNumber,
                    $values,
                    'existing',
                    'existing_product',
                    $normalized,
                );

                continue;
            }

            if (isset($seenIdentities[$identityKey])) {
                $this->recordDetail(
                    $summary,
                    $details,
                    $rowNumber,
                    $values,
                    'duplicate',
                    'duplicate_in_csv',
                    $normalized,
                );

                continue;
            }

            if ($skuKey !== '' && $existingBySku->has($skuKey)) {
                $this->recordDetail(
                    $summary,
                    $details,
                    $rowNumber,
                    $values,
                    'invalid',
                    'duplicate_sku',
                    $normalized,
                );

                continue;
            }

            if ($skuKey !== '' && isset($seenSkus[$skuKey])) {
                $this->recordDetail(
                    $summary,
                    $details,
                    $rowNumber,
                    $values,
                    'duplicate',
                    'duplicate_sku_csv',
                    $normalized,
                );

                continue;
            }

            $conflictingAlias = collect($aliases)->first(
                fn (string $alias): bool => $existingAliases->has(
                    Normalizer::text($alias),
                ),
            );

            if (is_string($conflictingAlias)) {
                $this->recordDetail(
                    $summary,
                    $details,
                    $rowNumber,
                    $values,
                    'invalid',
                    'alias_conflict',
                    $normalized,
                );

                continue;
            }

            $duplicateCsvAlias = collect($aliases)->first(
                fn (string $alias): bool => isset(
                    $seenAliases[Normalizer::text($alias)],
                ),
            );

            if (is_string($duplicateCsvAlias)) {
                $this->recordDetail(
                    $summary,
                    $details,
                    $rowNumber,
                    $values,
                    'invalid',
                    'alias_conflict_csv',
                    $normalized,
                );

                continue;
            }

            $seenIdentities[$identityKey] = $rowNumber;

            if ($skuKey !== '') {
                $seenSkus[$skuKey] = $rowNumber;
            }

            foreach ($aliases as $alias) {
                $seenAliases[Normalizer::text($alias)] = $rowNumber;
            }

            $this->recordDetail(
                $summary,
                $details,
                $rowNumber,
                $values,
                'ready',
                'ready',
                $normalized,
            );
            $readyRows[] = [
                'row_number' => $rowNumber,
                'values' => $values,
            ];
        }

        return [
            'summary' => $summary,
            'details' => $details,
            'rows' => $readyRows,
        ];
    }

    /**
     * @param  list<array{row_number:int,values:array<string,string>}>  $rows
     * @param  list<int>  $approvedRowNumbers
     * @return array{created:int,skipped_existing:int,skipped_duplicate:int,invalid:int,failed:int}
     */
    public function importRows(array $rows, array $approvedRowNumbers): array
    {
        $approved = array_fill_keys(array_map('intval', $approvedRowNumbers), true);
        $selected = array_values(array_filter(
            $rows,
            static fn (array $row): bool => isset(
                $approved[(int) ($row['row_number'] ?? 0)],
            ),
        ));
        $preview = $this->previewRecords($selected);
        $counts = [
            'created' => 0,
            'skipped_existing' => $preview['summary']['existing'],
            'skipped_duplicate' => $preview['summary']['duplicate'],
            'invalid' => $preview['summary']['invalid'],
            'failed' => 0,
        ];
        $categories = $this->categoryLookup();

        foreach ($preview['rows'] as $row) {
            $validation = $this->normalizeAndValidateRow(
                $row['values'],
                $categories,
            );

            if ($validation['error'] !== null) {
                $counts['invalid']++;

                continue;
            }

            $normalized = $validation['normalized'];

            try {
                $this->products->create(
                    $this->productData($normalized),
                    $normalized['aliases'],
                );
                $counts['created']++;
            } catch (ValidationException) {
                $counts['skipped_existing']++;
            } catch (Throwable) {
                $counts['failed']++;
            }
        }

        return $counts;
    }

    /** @param list<mixed> $header */
    public function validHeader(array $header): bool
    {
        $normalized = array_map(static function (mixed $value, int $index): string {
            $value = is_string($value) ? trim($value) : '';

            return $index === 0
                ? preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value
                : $value;
        }, $header, array_keys($header));

        return $normalized === self::HEADERS;
    }

    /** @return Collection<string, Category> */
    private function categoryLookup(): Collection
    {
        $lookup = collect();

        foreach ($this->scope->categoryOptions() as $category) {
            $lookup->put(Normalizer::text((string) $category->slug), $category);
            $lookup->put(Normalizer::text((string) $category->name), $category);
        }

        return $lookup;
    }

    /**
     * @param  array<string, string>  $values
     * @param  Collection<string, Category>  $categories
     * @return array{error:?string,normalized:array<string,mixed>}
     */
    private function normalizeAndValidateRow(
        array $values,
        Collection $categories,
    ): array {
        $categoryValue = trim((string) ($values['category'] ?? ''));
        $canonicalName = trim((string) ($values['canonical_name'] ?? ''));

        if ($categoryValue === '' || $canonicalName === '') {
            return ['error' => 'missing_required', 'normalized' => []];
        }

        $category = $categories->get(Normalizer::text($categoryValue));

        if ($category === null || ! $this->scope->supports($category)) {
            return ['error' => 'invalid_category', 'normalized' => []];
        }

        $nullable = static function (mixed $value): ?string {
            $value = trim((string) $value);

            return $value === '' ? null : $value;
        };
        $brand = $nullable($values['brand'] ?? null);
        $model = $nullable($values['model'] ?? null);
        $variant = $nullable($values['variant'] ?? null);
        $sku = $nullable($values['sku_or_part_number'] ?? null);
        $specificationSource = $nullable(
            $values['specification_source'] ?? null,
        );
        $activeValue = strtolower(trim((string) ($values['is_active'] ?? '')));

        if (! in_array($activeValue, ['true', 'false'], true)) {
            return ['error' => 'invalid_active', 'normalized' => []];
        }

        foreach ([$canonicalName, $brand, $model, $variant, $sku] as $value) {
            if (is_string($value) && mb_strlen($value, 'UTF-8') > 255) {
                return ['error' => 'field_too_long', 'normalized' => []];
            }
        }

        if (is_string($specificationSource)) {
            $sourceLimit = filter_var(
                $specificationSource,
                FILTER_VALIDATE_URL,
            ) !== false ? 2048 : 120;

            if (mb_strlen($specificationSource, 'UTF-8') > $sourceLimit) {
                return ['error' => 'field_too_long', 'normalized' => []];
            }
        }

        $rawAliases = trim((string) ($values['aliases'] ?? ''));
        $aliasParts = $rawAliases === ''
            ? []
            : array_map('trim', explode('|', $rawAliases));
        $aliasParts = array_values(array_filter(
            $aliasParts,
            static fn (string $alias): bool => $alias !== '',
        ));
        $normalizedAliasKeys = array_map(
            static fn (string $alias): string => Normalizer::text($alias),
            $aliasParts,
        );

        if (count($normalizedAliasKeys) !== count(array_unique($normalizedAliasKeys))) {
            return ['error' => 'duplicate_alias_row', 'normalized' => []];
        }

        foreach ($aliasParts as $alias) {
            if (mb_strlen($alias, 'UTF-8') > 255) {
                return ['error' => 'field_too_long', 'normalized' => []];
            }
        }

        $aliasValidator = Validator::make(
            ['aliases' => implode("\n", $aliasParts)],
            ['aliases' => ['nullable', 'string', 'max:10000', new SafeProductAliases]],
        );

        if ($aliasValidator->fails()) {
            return ['error' => 'invalid_alias', 'normalized' => []];
        }

        $aliases = $this->products->normalizeAliases(
            $rawAliases,
            '/\|+/u',
        );
        $normalizedKey = Product::makeNormalizedKey(
            $canonicalName,
            $brand,
            $model,
            $variant,
            $sku,
        );

        return [
            'error' => null,
            'normalized' => [
                'category_id' => (int) $category->getKey(),
                'category_name' => (string) $category->name,
                'canonical_name' => $canonicalName,
                'brand' => $brand,
                'model' => $model,
                'variant' => $variant,
                'sku_or_part_number' => $sku,
                'aliases' => $aliases,
                'specification_source' => $specificationSource,
                'is_active' => $activeValue === 'true',
                'normalized_key' => $normalizedKey,
                'identity_key' => $this->identityWithoutSku([
                    'canonical_name' => $canonicalName,
                    'brand' => $brand,
                    'model' => $model,
                    'variant' => $variant,
                ]),
                'sku_key' => $sku === null ? '' : $this->skuKey($sku),
            ],
        ];
    }

    /** @param array<string, mixed> $normalized */
    private function productData(array $normalized): array
    {
        $source = $normalized['specification_source'] ?? null;
        $sourceIsUrl = is_string($source)
            && filter_var($source, FILTER_VALIDATE_URL) !== false;

        return [
            'category_id' => $normalized['category_id'],
            'name' => $normalized['canonical_name'],
            'brand' => $normalized['brand'],
            'model' => $normalized['model'],
            'variant' => $normalized['variant'],
            'sku_or_part_number' => $normalized['sku_or_part_number'],
            'attributes' => null,
            'spec_source_name' => $sourceIsUrl
                ? parse_url((string) $source, PHP_URL_HOST)
                : $source,
            'spec_source_url' => $sourceIsUrl ? $source : null,
            'spec_source_checked_at' => null,
            'is_active' => $normalized['is_active'],
        ];
    }

    /** @param array<string, mixed> $values */
    private function identityWithoutSku(array $values): string
    {
        return Product::makeNormalizedKey(
            (string) ($values['canonical_name'] ?? ''),
            $values['brand'] ?? null,
            $values['model'] ?? null,
            $values['variant'] ?? null,
            null,
        );
    }

    private function skuKey(string $sku): string
    {
        return Normalizer::text($sku);
    }

    /**
     * @param  array{rows:int,ready:int,existing:int,invalid:int,duplicate:int}  $summary
     * @param  list<array<string,mixed>>  $details
     * @param  array<string, string>  $values
     * @param  array<string, mixed>  $normalized
     */
    private function recordDetail(
        array &$summary,
        array &$details,
        int $rowNumber,
        array $values,
        string $status,
        string $note,
        array $normalized = [],
    ): void {
        $summary[$status]++;
        $details[] = [
            'row_number' => $rowNumber,
            'status' => $status,
            'note' => $note,
            'canonical_name' => $normalized['canonical_name']
                ?? ($values['canonical_name'] ?? null),
            'category' => $normalized['category_name']
                ?? ($values['category'] ?? null),
            'brand' => $normalized['brand'] ?? ($values['brand'] ?? null),
            'model' => $normalized['model'] ?? ($values['model'] ?? null),
            'variant' => $normalized['variant'] ?? ($values['variant'] ?? null),
            'sku' => $normalized['sku_or_part_number']
                ?? ($values['sku_or_part_number'] ?? null),
            'aliases' => $normalized['aliases']
                ?? ($values['aliases'] ?? null),
        ];
    }

    private function emptyRow(mixed $row): bool
    {
        return ! is_array($row)
            || $row === [null]
            || collect($row)->every(
                static fn (mixed $value): bool => trim((string) $value) === '',
            );
    }
}
