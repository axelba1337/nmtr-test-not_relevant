<?php

namespace Tests\Unit;

use Database\Seeders\Support\SqlDatasetParser;
use PHPUnit\Framework\TestCase;

final class SqlDatasetParserTest extends TestCase
{
    public function test_it_reads_all_v3_source_rows_without_executing_mysql_sql(): void
    {
        $parser = new SqlDatasetParser;

        $products = $parser->rows(
            dirname(__DIR__, 2).'/database/data/v3/products_v3_expanded.sql',
            'INSERT INTO `products`',
            'ON DUPLICATE KEY UPDATE',
        );
        $platforms = $parser->rows(
            dirname(__DIR__, 2).'/database/data/v3/platforms_v3_expanded.sql',
            'INSERT INTO `platforms`',
            'ON DUPLICATE KEY UPDATE',
        );
        $knowledge = $parser->rows(
            dirname(__DIR__, 2).'/database/data/v3/knowledge_items_v3_expanded.sql',
            'INSERT INTO `_neomatra_knowledge_seed`',
            ';',
        );

        $this->assertCount(170, $products);
        $this->assertCount(21, $platforms);
        $this->assertCount(47, $knowledge);
        $this->assertContains('@cat_gpu', array_column($products, 0));
        $this->assertSame('Tokopedia', $platforms[0][0]);
        $this->assertSame(
            'Tentang Neomatra / About Neomatra',
            $knowledge[0][0],
        );
        $this->assertStringContainsString(
            "listing's total cost",
            $knowledge[10][1],
        );

        foreach ($products as $row) {
            $this->assertCount(20, $row);
            $this->assertIsArray(json_decode((string) $row[7], true));
        }

        foreach ($platforms as $row) {
            $this->assertCount(12, $row);
        }

        foreach ($knowledge as $row) {
            $this->assertCount(5, $row);
            $this->assertIsArray(json_decode((string) $row[2], true));
        }
    }
}
