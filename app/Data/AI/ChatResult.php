<?php

namespace App\Data\AI;

use App\Data\IntegrationFailure;

final readonly class ChatResult
{
    /**
     * @param  array{
     *     prompt_tokens: int|null,
     *     output_tokens: int|null,
     *     total_tokens: int|null
     * }  $usage
     * @param  list<array{
     *     kind: string,
     *     title: string,
     *     publisher: string,
     *     url: string
     * }>  $sources
     */
    private function __construct(
        public ?string $text,
        public array $usage,
        public ?string $modelVersion,
        public ?bool $supportedByContext,
        public array $sources,
        public ?string $searchEntryPointHtml,
        public ?IntegrationFailure $failure,
    ) {}

    /**
     * @param  array{
     *     prompt_tokens: int|null,
     *     output_tokens: int|null,
     *     total_tokens: int|null
     * }  $usage
     * @param  list<array{
     *     kind: string,
     *     title: string,
     *     publisher: string,
     *     url: string
     * }>  $sources
     */
    public static function success(
        string $text,
        array $usage,
        ?string $modelVersion,
        ?bool $supportedByContext = null,
        array $sources = [],
        ?string $searchEntryPointHtml = null,
    ): self {
        return new self(
            $text,
            $usage,
            $modelVersion,
            $supportedByContext,
            $sources,
            $searchEntryPointHtml,
            null,
        );
    }

    /**
     * @param  array{
     *     prompt_tokens: int|null,
     *     output_tokens: int|null,
     *     total_tokens: int|null
     * }|null  $usage
     */
    public static function failed(
        IntegrationFailure $failure,
        ?array $usage = null,
    ): self {
        return new self(
            null,
            $usage ?? self::emptyUsage(),
            null,
            null,
            [],
            null,
            $failure,
        );
    }

    public function succeeded(): bool
    {
        return $this->text !== null;
    }

    /**
     * @return array{
     *     prompt_tokens: null,
     *     output_tokens: null,
     *     total_tokens: null
     * }
     */
    private static function emptyUsage(): array
    {
        return [
            'prompt_tokens' => null,
            'output_tokens' => null,
            'total_tokens' => null,
        ];
    }
}
