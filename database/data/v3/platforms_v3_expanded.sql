-- Neomatra platform registry V3
-- The MVP registry contains only five implemented listing sources.
-- OLX uses the public-web connector; the others use guarded CSV/manual import.
SET NAMES utf8mb4;
START TRANSACTION;
INSERT INTO `platforms` (`name`,`slug`,`source_type`,`access_type`,`connector`,`base_url`,`is_active`,`sync_enabled`,`status`,`last_synced_at`,`created_at`,`updated_at`) VALUES
('Tokopedia', 'tokopedia', 'manual', 'csv_import', 'manual-import', 'https://www.tokopedia.com', 1, 0, 'ready', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('Shopee', 'shopee', 'manual', 'csv_import', 'manual-import', 'https://shopee.co.id', 1, 0, 'ready', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('OLX', 'olx', 'scrape', 'public_html', 'olx-public-web', 'https://www.olx.co.id', 1, 1, 'ready', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('Facebook Marketplace', 'facebook', 'manual', 'csv_import', 'manual-import', 'https://www.facebook.com/marketplace/', 1, 0, 'ready', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('Carousell', 'carousell', 'manual', 'csv_import', 'manual-import', 'https://id.carousell.com', 1, 0, 'ready', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
ON DUPLICATE KEY UPDATE
`name`=VALUES(`name`),
`source_type`=VALUES(`source_type`),
`access_type`=VALUES(`access_type`),
`connector`=VALUES(`connector`),
`base_url`=VALUES(`base_url`),
`is_active`=VALUES(`is_active`),
`sync_enabled`=VALUES(`sync_enabled`),
`status`=VALUES(`status`),
`updated_at`=CURRENT_TIMESTAMP;
COMMIT;
SELECT COUNT(*) AS total_platforms FROM platforms;
SELECT slug, source_type, access_type, sync_enabled, status FROM platforms ORDER BY slug;
