-- Neomatra platform registry V3
-- Demo connectors remain available for tests; only OLX has production sync.
-- Facebook Marketplace and Carousell use guarded CSV import; other manual
-- sources remain manual_url until a real connector is implemented.
SET NAMES utf8mb4;
START TRANSACTION;
INSERT INTO `platforms` (`name`,`slug`,`source_type`,`access_type`,`connector`,`base_url`,`is_active`,`sync_enabled`,`status`,`last_synced_at`,`created_at`,`updated_at`) VALUES
('Tokopedia', 'tokopedia', 'demo', 'demo_dataset', 'demo-dataset', 'https://www.tokopedia.com', 1, 0, 'ready', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('Shopee', 'shopee', 'demo', 'demo_dataset', 'demo-dataset', 'https://shopee.co.id', 1, 0, 'ready', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('OLX', 'olx', 'scrape', 'public_html', 'olx-public-web', 'https://www.olx.co.id', 1, 0, 'ready', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('Facebook Marketplace', 'facebook', 'manual', 'csv_import', 'manual-import', 'https://www.facebook.com/marketplace/', 1, 0, 'ready', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('Carousell', 'carousell', 'manual', 'csv_import', 'manual-import', 'https://id.carousell.com', 1, 0, 'ready', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('Blibli', 'blibli', 'manual', 'manual_url', NULL, 'https://www.blibli.com', 1, 0, 'ready', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('Lazada', 'lazada', 'manual', 'manual_url', NULL, 'https://www.lazada.co.id', 1, 0, 'ready', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('TikTok Shop', 'tiktok-shop', 'manual', 'manual_url', NULL, 'https://shop.tiktok.com/', 1, 0, 'ready', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('Jagofon', 'jagofon', 'manual', 'manual_url', NULL, 'https://jagofon.com', 1, 0, 'ready', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('Laku6', 'laku6', 'manual', 'manual_url', NULL, 'https://www.laku6.com', 1, 0, 'ready', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('Bhinneka', 'bhinneka', 'manual', 'manual_url', NULL, 'https://www.bhinneka.com', 1, 0, 'ready', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('Jakmall', 'jakmall', 'manual', 'manual_url', NULL, 'https://www.jakmall.com', 1, 0, 'ready', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('EnterKomputer', 'enterkomputer', 'manual', 'manual_url', NULL, 'https://www.enterkomputer.com', 1, 0, 'ready', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('PEMMZ', 'pemmz', 'manual', 'manual_url', NULL, 'https://pemmz.com', 1, 0, 'ready', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('Erafone', 'erafone', 'manual', 'manual_url', NULL, 'https://erafone.com', 1, 0, 'ready', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('Eraspace', 'eraspace', 'manual', 'manual_url', NULL, 'https://eraspace.com', 1, 0, 'ready', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('Digimap', 'digimap', 'manual', 'manual_url', NULL, 'https://www.digimap.co.id', 1, 0, 'ready', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('ASUS Store Indonesia', 'asus-store-indonesia', 'manual', 'manual_url', NULL, 'https://www.asus.com/id/store/', 1, 0, 'ready', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('Acer E-Store Indonesia', 'acer-estore-indonesia', 'manual', 'manual_url', NULL, 'https://www.acerid.com/store', 1, 0, 'ready', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('Samsung Indonesia', 'samsung-indonesia', 'manual', 'manual_url', NULL, 'https://www.samsung.com/id/', 1, 0, 'ready', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('HP Indonesia Store', 'hp-indonesia-store', 'manual', 'manual_url', NULL, 'https://www.hp.com/id-id/shop/', 1, 0, 'ready', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
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
