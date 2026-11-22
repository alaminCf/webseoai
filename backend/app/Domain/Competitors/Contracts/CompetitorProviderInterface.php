<?php

namespace App\Domain\Competitors\Contracts;

interface CompetitorProviderInterface
{
    public function getName(): string;

    public function getLabel(): string;

    public function isConfigured(): bool;

    /**
     * @return string[] subset of ['discovery', 'domain_metrics', 'keywords', 'backlinks', 'serp']
     */
    public function supportedCapabilities(): array;

    public function supports(string $capability): bool;

    /**
     * Discover competing domains for a target domain.
     *
     * @return array{available: bool, provider: string, methodology: string, candidates: array<int, array<string, mixed>>, error?: string, cost_usd?: float, http_status?: int}
     */
    public function discoverCompetitors(string $domain, string $country = 'US', string $language = 'en', array $context = []): array;

    /**
     * Fetch domain-level SEO overview metrics. All unsupported metrics MUST be null.
     *
     * @return array{available: bool, provider: string, classification: string, metrics: array<string, mixed>, error?: string, cost_usd?: float, http_status?: int}
     */
    public function fetchDomainMetrics(string $domain, string $country = 'US', string $language = 'en'): array;

    /**
     * Fetch organic ranking keywords for a domain.
     *
     * @return array{available: bool, provider: string, keywords: array<int, array<string, mixed>>, error?: string, cost_usd?: float, http_status?: int}
     */
    public function fetchDomainKeywords(string $domain, string $country = 'US', string $language = 'en', int $limit = 50): array;

    /**
     * Fetch referring domains & backlink records for a domain.
     *
     * @return array{available: bool, provider: string, summary: array<string, mixed>, backlinks: array<int, array<string, mixed>>, error?: string, cost_usd?: float, http_status?: int}
     */
    public function fetchBacklinks(string $domain, int $limit = 50): array;

    /**
     * Fetch organic SERP snapshot for a keyword.
     *
     * @return array{available: bool, provider: string, serp_features: array<int, string>, organic_results: array<int, array<string, mixed>>, error?: string, cost_usd?: float, http_status?: int}
     */
    public function fetchSerp(string $keyword, string $country = 'US', string $language = 'en', string $device = 'desktop', ?string $location = null): array;
}
