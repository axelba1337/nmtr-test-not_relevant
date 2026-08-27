<?php

namespace App\Services\Marketplace;

use App\Contracts\Marketplace\MarketplaceConnector;

final class MarketplaceConnectorRegistry
{
    /** @var array<string, MarketplaceConnector> */
    private array $connectors;

    public function __construct(
        DemoMarketplaceAdapter $demo,
        OlxMarketplaceConnector $olx,
    ) {
        $this->connectors = [
            $demo->key() => $demo,
            'demo-dataset' => $demo,
            $olx->key() => $olx,
        ];
    }

    public function find(
        string $connectorKey,
        string $platformSlug,
    ): ?MarketplaceConnector {
        $connector = $this->connectors[strtolower(trim($connectorKey))]
            ?? null;

        return $connector?->supports($platformSlug) === true
            ? $connector
            : null;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->connectors);
    }
}
