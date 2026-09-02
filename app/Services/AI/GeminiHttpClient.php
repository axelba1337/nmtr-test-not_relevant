<?php

namespace App\Services\AI;

use App\Data\AI\ProviderHttpResult;
use App\Data\IntegrationFailure;
use App\Services\Search\SearchExecutionBudget;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class GeminiHttpClient
{
    public function __construct(
        private readonly SearchExecutionBudget $searchBudget,
    ) {}

    public function post(
        string $configuration,
        string $operation,
        string $model,
        string $path,
        array $payload,
    ): ProviderHttpResult {
        $provider = strtolower(trim(
            (string) config("ai.{$configuration}.provider", ''),
        ));
        $apiKey = trim((string) config("ai.{$configuration}.api_key", ''));
        $baseUrl = rtrim(
            trim((string) config("ai.{$configuration}.base_url", '')),
            '/',
        );

        if ($provider !== 'gemini'
            || $apiKey === ''
            || $baseUrl === ''
            || trim($model) === '') {
            return ProviderHttpResult::failed(new IntegrationFailure(
                'gemini',
                $operation,
                'configuration',
                __('ui.messages.ai.configuration'),
                $model !== '' ? $model : null,
            ));
        }

        if ($this->searchBudget->hardExceeded()) {
            return ProviderHttpResult::failed(new IntegrationFailure(
                'gemini',
                $operation,
                'timeout',
                __('ui.messages.ai.timeout'),
                $model,
            ));
        }

        try {
            $response = $this->request($apiKey)->post(
                $baseUrl.'/'.ltrim($path, '/'),
                $payload,
            );
        } catch (ConnectionException) {
            return ProviderHttpResult::failed(new IntegrationFailure(
                'gemini',
                $operation,
                'connection',
                __('ui.messages.ai.connection'),
                $model,
            ));
        } catch (Throwable) {
            return ProviderHttpResult::failed(new IntegrationFailure(
                'gemini',
                $operation,
                'client_error',
                __('ui.messages.ai.request_failed'),
                $model,
            ));
        }

        if (! $response->successful()) {
            $upstreamCode = $response->json('error.status');

            return ProviderHttpResult::failed(new IntegrationFailure(
                'gemini',
                $operation,
                $this->failureCategory($response),
                $this->safeFailureMessage($response),
                $model,
                $response->status(),
                is_string($upstreamCode) && $upstreamCode !== ''
                    ? mb_substr($upstreamCode, 0, 100, 'UTF-8')
                    : null,
            ));
        }

        return ProviderHttpResult::success($response);
    }

    private function request(string $apiKey): PendingRequest
    {
        $timeout = $this->searchBudget->remainingTimeoutSeconds(
            max(1, (int) config('ai.http.timeout', 25)),
        );
        $retries = min(5, max(
            0,
            (int) config('ai.http.retries', 2),
        ));

        if ($this->searchBudget->active()) {
            $retries = 0;
        }
        $retryDelay = min(5000, max(
            0,
            (int) config('ai.http.retry_delay_ms', 1000),
        ));

        $request = Http::acceptJson()
            ->asJson()
            ->withHeaders(['x-goog-api-key' => $apiKey])
            ->timeout($timeout);

        if ($retries > 0) {
            $request->retry(
                $retries + 1,
                static function (int $attempt) use ($retryDelay): int {
                    if ($retryDelay === 0) {
                        return 0;
                    }

                    $delay = min(
                        5000,
                        $retryDelay * (2 ** min(5, max(0, $attempt - 1))),
                    );
                    $jitter = random_int(
                        0,
                        min(250, max(1, intdiv($delay, 4))),
                    );

                    return $delay + $jitter;
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
            $response->status() === 429 => 'quota_or_rate_limit',
            in_array($response->status(), [401, 403], true) => 'authentication',
            $response->status() === 408 => 'timeout',
            $response->serverError() => 'provider_unavailable',
            default => 'provider_rejected',
        };
    }

    private function safeFailureMessage(Response $response): string
    {
        return match (true) {
            $response->status() === 429 => __('ui.messages.ai.quota'),
            in_array($response->status(), [401, 403], true) => __('ui.messages.ai.authentication'),
            $response->status() === 408 => __('ui.messages.ai.timeout'),
            $response->serverError() => __('ui.messages.ai.unavailable'),
            default => __('ui.messages.ai.rejected'),
        };
    }
}
