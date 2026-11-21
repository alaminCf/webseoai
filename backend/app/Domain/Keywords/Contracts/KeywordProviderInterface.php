<?php

namespace App\Domain\Keywords\Contracts;

interface KeywordProviderInterface
{
    /**
     * Get keyword suggestions for a seed query with metrics.
     *
     * @param string $query
     * @param array $options ['country' => 'US', 'language' => 'en', 'limit' => 20]
     * @return array List of keyword objects with volume, difficulty, cpc, intent, provenance
     */
    public function getKeywordSuggestions(string $query, array $options = []): array;

    /**
     * Get exact search volume and competition metrics for an array of keywords.
     *
     * @param array $keywords List of keyword strings
     * @param array $options ['country' => 'US', 'language' => 'en']
     * @return array Map of keyword => metric data
     */
    public function getKeywordMetrics(array $keywords, array $options = []): array;

    /**
     * Get semantic keyword clusters and groupings for a topic.
     *
     * @param string $keyword
     * @param array $options
     * @return array List of clusters with sub-keywords
     */
    public function getRelatedClusters(string $keyword, array $options = []): array;

    /**
     * Provider unique identifier.
     */
    public function getProviderName(): string;
}
