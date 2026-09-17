<?php

namespace App\Services\Marketplace;

use App\Data\AI\ProviderHttpResult;
use App\Data\IntegrationFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class TavilyHttpClient
{
    public function search(array $payload): ProviderHttpResult
    {
        return $this->post('search', $payload);
    }

    public function extract(array $payload): ProviderHttpResult
    {
        return $this->post('extract', $payload);
    }

    private function post(
        string $operation,
        array $payload,
    ): ProviderHttpResult {
        $apiKey = trim((string) config('listing-discovery.tavily.api_key', ''));
        $baseUrl = rtrim(trim(
            (string) config('listing-discovery.tavily.base_url', ''),
        ), '/');

        if ($apiKey === '' || $baseUrl === '') {
            return $this->failed(
                $operation,
                'configuration',
                'Tavily Listing Discovery is not configured.',
            );
        }

        try {
            $response = $this->request($apiKey)->post(
                $baseUrl.'/'.$operation,
                $payload,
            );
        } catch (ConnectionException) {
            return $this->failed(
                $operation,
                'connection',
                'Tavily could not be reached.',
            );
        } catch (Throwable) {
            return $this->failed(
                $operation,
                'client_error',
                'The Tavily request could not be completed.',
            );
        }

        if (! $response->successful()) {
            return $this->failed(
                $operation,
                $this->failureCategory($response),
                $this->safeFailureMessage($response),
                $response->status(),
            );
        }

        if (! is_array($response->json())) {
            return $this->failed(
                $operation,
                'invalid_response',
                'Tavily returned an invalid response.',
                $response->status(),
            );
        }

        return ProviderHttpResult::success($response);
    }

    private function request(string $apiKey): PendingRequest
    {
        $timeout = min(120, max(
            1,
            (int) config('listing-discovery.http.timeout', 30),
        ));
        $retries = min(5, max(
            0,
            (int) config('listing-discovery.http.retries', 1),
        ));
        $retryDelay = min(5000, max(
            0,
            (int) config('listing-discovery.http.retry_delay_ms', 1000),
        ));
        $request = Http::acceptJson()
            ->asJson()
            ->withToken($apiKey)
            ->timeout($timeout);

        if ($retries > 0) {
            $request->retry(
                $retries + 1,
                static function (int $attempt) use ($retryDelay): int {
                    if ($retryDelay === 0) {
                        return 0;
                    }

                    return min(
                        5000,
                        $retryDelay * (2 ** min(5, max(0, $attempt - 1))),
                    );
                },
                static function (Throwable $exception): bool {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    if (! $exception instanceof RequestException
                        || $exception->response === null) {
                        return false;
                    }

                    $status = $exception->response->status();

                    return $status === 408
                        || $status === 429
                        || $status >= 500;
                },
                throw: false,
            );
        }

        return $request;
    }

    private function failureCategory(Response $response): string
    {
        return match (true) {
            in_array($response->status(), [401, 403], true) => 'authentication',
            $response->status() === 429 => 'quota_or_rate_limit',
            $response->status() === 408 => 'timeout',
            $response->serverError() => 'provider_unavailable',
            default => 'provider_rejected',
        };
    }

    private function safeFailureMessage(Response $response): string
    {
        return match (true) {
            in_array($response->status(), [401, 403], true) => 'Tavily authentication failed.',
            $response->status() === 429 => 'Tavily quota or rate limit is unavailable.',
            $response->status() === 408 => 'The Tavily request timed out.',
            $response->serverError() => 'Tavily is temporarily unavailable.',
            default => 'Tavily rejected the request.',
        };
    }

    private function failed(
        string $operation,
        string $category,
        string $message,
        ?int $status = null,
    ): ProviderHttpResult {
        return ProviderHttpResult::failed(new IntegrationFailure(
            'tavily',
            'listing_discovery_'.$operation,
            $category,
            $message,
            null,
            $status,
        ));
    }
}
