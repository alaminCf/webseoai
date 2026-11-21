<?php

namespace App\Domain\Keywords;

use App\Domain\Keywords\Contracts\KeywordProviderInterface;
use App\Domain\Keywords\Drivers\SandboxKeywordDriver;
use App\Models\KeywordResearchRecord;
use App\Models\Website;
use Exception;
use InvalidArgumentException;

class KeywordManager
{
    private array $drivers = [];
    private string $defaultDriver;

    public function __construct()
    {
        $this->defaultDriver = config('keywords.default', 'sandbox');
    }

    /**
     * Get or instantiate a driver instance.
     */
    public function driver(?string $name = null): KeywordProviderInterface
    {
        $name = $name ?? $this->defaultDriver;

        if (!isset($this->drivers[$name])) {
            $this->drivers[$name] = $this->resolveDriver($name);
        }

        return $this->drivers[$name];
    }

    private function resolveDriver(string $name): KeywordProviderInterface
    {
        return match ($name) {
            'sandbox' => new SandboxKeywordDriver(),
            default => throw new InvalidArgumentException("Keyword driver [{$name}] is not supported."),
        };
    }

    /**
     * Search keywords and persist research records to the database.
     */
    public function searchAndStore(
        string $query,
        int $organizationId,
        ?int $websiteId = null,
        array $options = []
    ): array {
        $country = strtoupper($options['country'] ?? config('keywords.defaults.country', 'US'));
        $language = strtolower($options['language'] ?? config('keywords.defaults.language', 'en'));
        $providerName = $options['provider'] ?? $this->defaultDriver;

        $driver = $this->driver($providerName);
        $suggestions = $driver->getKeywordSuggestions($query, array_merge($options, [
            'country' => $country,
            'language' => $language,
        ]));

        $storedRecords = [];

        foreach ($suggestions as $item) {
            $record = KeywordResearchRecord::updateOrCreate(
                [
                    'organization_id' => $organizationId,
                    'website_id' => $websiteId,
                    'keyword' => $item['keyword'],
                    'country' => $country,
                    'language' => $language,
                ],
                [
                    'search_volume' => $item['search_volume'],
                    'difficulty' => $item['difficulty'],
                    'cpc' => $item['cpc'],
                    'intent' => $item['intent'],
                    'competition_level' => $item['competition_level'],
                    'provider' => $item['provider'],
                    'provenance' => $item['provenance'],
                    'related_clusters' => $item['related_clusters'],
                ]
            );

            $storedRecords[] = $record;
        }

        return $storedRecords;
    }

    /**
     * Get semantic clusters for a topic.
     */
    public function getClusters(string $keyword, array $options = []): array
    {
        return $this->driver($options['provider'] ?? null)->getRelatedClusters($keyword, $options);
    }
}
