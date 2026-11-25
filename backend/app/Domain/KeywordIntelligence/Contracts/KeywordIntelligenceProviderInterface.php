<?php

namespace App\Domain\KeywordIntelligence\Contracts;

interface KeywordIntelligenceProviderInterface
{
    public function getName(): string;

    public function getLabel(): string;

    public function isConfigured(?int $organizationId = null): bool;

    /**
     * @return array<int, string>
     */
    public function supportedCapabilities(): array;

    /**
     * Fetch keyword ideas for a seed keyword.
     *
     * Every returned item MUST strictly preserve provider truth:
     * missing metrics MUST be null — NEVER invented.
     *
     * @return array{
     *   available: bool,
     *   provider: string,
     *   keywords: array<int, array<string, mixed>>,
     *   credits_used?: int,
     *   cost_usd?: float,
     *   error?: string
     * }
     */
    public function fetchKeywordIdeas(
        string $seed,
        string $country = 'US',
        string $language = 'en',
        array $options = []
    ): array;
}
