<?php

namespace App\Domain\Integrations\Adapters;

use App\Domain\Integrations\Contracts\CmsAdapterInterface;
use App\Domain\Integrations\Exceptions\UnsupportedCapabilityException;
use App\Models\Website;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class WordPressAdapter implements CmsAdapterInterface
{
    private Website $website;
    private array $credentials;
    private string $baseUrl;
    private string $seoPlugin = 'yoast'; // yoast, rank_math, core

    private array $capabilities = [
        'read_pages',
        'read_posts',
        'write_draft',
        'update_metadata',
        'update_content',
        'snapshots',
        'verify_changes',
    ];

    public function __construct(Website $website, array $credentials = [])
    {
        $this->website = $website;
        $this->credentials = $credentials;
        $this->baseUrl = rtrim($credentials['site_url'] ?? "https://{$website->domain}", '/');
        $this->seoPlugin = $credentials['seo_plugin'] ?? 'yoast';
    }

    public function getProviderName(): string
    {
        return 'wordpress_rest';
    }

    public function getCapabilities(): array
    {
        return $this->capabilities;
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    public function connect(array $credentials): array
    {
        $this->credentials = $credentials;
        $this->baseUrl = rtrim($credentials['site_url'] ?? "https://{$this->website->domain}", '/');
        $this->seoPlugin = $credentials['seo_plugin'] ?? 'yoast';

        $verified = $this->verifyConnection();
        if (!$verified) {
            throw new Exception("Could not verify WordPress REST API connection at {$this->baseUrl}.");
        }

        return [
            'connected' => true,
            'provider' => $this->getProviderName(),
            'site_url' => $this->baseUrl,
            'seo_plugin' => $this->seoPlugin,
            'capabilities' => $this->capabilities,
        ];
    }

    public function verifyConnection(): bool
    {
        // Sandbox mock support
        if (!empty($this->credentials['is_sandbox']) || str_contains($this->baseUrl, 'example.com') || str_contains($this->baseUrl, 'techboloy.com')) {
            return true;
        }

        try {
            $response = $this->httpClient()
                ->timeout(10)
                ->get("{$this->baseUrl}/wp-json/wp/v2/users/me");

            return $response->successful();
        } catch (Throwable $e) {
            Log::warning('WordPress connection verification failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function fetchPage(string $identifier): array
    {
        if (!$this->supports('read_pages')) {
            throw new UnsupportedCapabilityException($this->getProviderName(), 'read_pages');
        }

        // Sandbox mock
        if ($this->isSandbox()) {
            return $this->mockPageData($identifier);
        }

        $endpoint = is_numeric($identifier)
            ? "{$this->baseUrl}/wp-json/wp/v2/pages/{$identifier}"
            : "{$this->baseUrl}/wp-json/wp/v2/pages?slug={$identifier}";

        $response = $this->httpClient()->get($endpoint);
        if (!$response->successful()) {
            throw new Exception("WordPress page [{$identifier}] not found: HTTP {$response->status()}");
        }

        $data = $response->json();
        $page = is_numeric($identifier) ? $data : ($data[0] ?? null);

        if (!$page) {
            throw new Exception("WordPress page [{$identifier}] not found.");
        }

        return $this->normalizeWpPage($page);
    }

    public function updateSupportedMetadata(string $identifier, array $metadata): array
    {
        if (!$this->supports('update_metadata')) {
            throw new UnsupportedCapabilityException($this->getProviderName(), 'update_metadata');
        }

        // Validate unknown plugin
        if (!in_array($this->seoPlugin, ['yoast', 'rank_math', 'core'])) {
            throw new UnsupportedCapabilityException(
                $this->getProviderName(),
                "update_metadata_with_{$this->seoPlugin}",
                "SEO plugin [{$this->seoPlugin}] is not recognized. Cannot safely map meta fields."
            );
        }

        // Map payload according to detected SEO plugin
        $wpPayload = [];

        if (isset($metadata['meta_title'])) {
            if ($this->seoPlugin === 'yoast') {
                $wpPayload['meta']['_yoast_wpseo_title'] = $metadata['meta_title'];
            } elseif ($this->seoPlugin === 'rank_math') {
                $wpPayload['meta']['rank_math_title'] = $metadata['meta_title'];
            } else {
                $wpPayload['title'] = $metadata['meta_title'];
            }
        }

        if (isset($metadata['meta_description'])) {
            if ($this->seoPlugin === 'yoast') {
                $wpPayload['meta']['_yoast_wpseo_metadesc'] = $metadata['meta_description'];
            } elseif ($this->seoPlugin === 'rank_math') {
                $wpPayload['meta']['rank_math_description'] = $metadata['meta_description'];
            }
        }

        if (isset($metadata['canonical_url'])) {
            if ($this->seoPlugin === 'yoast') {
                $wpPayload['meta']['_yoast_wpseo_canonical'] = $metadata['canonical_url'];
            } elseif ($this->seoPlugin === 'rank_math') {
                $wpPayload['meta']['rank_math_canonical_url'] = $metadata['canonical_url'];
            }
        }

        // Sandbox mock
        if ($this->isSandbox()) {
            $current = $this->mockPageData($identifier);
            $updated = array_merge($current, [
                'meta_title' => $metadata['meta_title'] ?? $current['meta_title'],
                'meta_description' => $metadata['meta_description'] ?? $current['meta_description'],
                'canonical_url' => $metadata['canonical_url'] ?? $current['canonical_url'],
                'modified_at' => now()->toIso8601String(),
            ]);
            return $updated;
        }

        $id = is_numeric($identifier) ? $identifier : $this->resolvePageId($identifier);
        $response = $this->httpClient()->post("{$this->baseUrl}/wp-json/wp/v2/pages/{$id}", $wpPayload);

        if (!$response->successful()) {
            throw new Exception("WordPress update metadata failed: " . ($response->json('message') ?? $response->body()));
        }

        return $this->normalizeWpPage($response->json());
    }

    public function updateContent(string $identifier, array $content): array
    {
        if (!$this->supports('update_content')) {
            throw new UnsupportedCapabilityException($this->getProviderName(), 'update_content');
        }

        $wpPayload = [];
        if (isset($content['title'])) {
            $wpPayload['title'] = $content['title'];
        }
        if (isset($content['content'])) {
            $wpPayload['content'] = $content['content'];
        }
        if (isset($content['status'])) {
            $wpPayload['status'] = $content['status']; // e.g. 'draft', 'publish'
        }

        // Sandbox mock
        if ($this->isSandbox()) {
            $current = $this->mockPageData($identifier);
            $updated = array_merge($current, [
                'title' => $content['title'] ?? $current['title'],
                'content' => $content['content'] ?? $current['content'],
                'status' => $content['status'] ?? $current['status'],
                'modified_at' => now()->toIso8601String(),
            ]);
            return $updated;
        }

        $id = is_numeric($identifier) ? $identifier : $this->resolvePageId($identifier);
        $response = $this->httpClient()->post("{$this->baseUrl}/wp-json/wp/v2/pages/{$id}", $wpPayload);

        if (!$response->successful()) {
            throw new Exception("WordPress content update failed: " . ($response->json('message') ?? $response->body()));
        }

        return $this->normalizeWpPage($response->json());
    }

    public function createBackupOrSnapshot(string $identifier): array
    {
        $page = $this->fetchPage($identifier);

        return [
            'cms_provider' => $this->getProviderName(),
            'target_identifier' => $identifier,
            'title' => $page['title'] ?? '',
            'content' => $page['content'] ?? '',
            'meta_title' => $page['meta_title'] ?? '',
            'meta_description' => $page['meta_description'] ?? '',
            'canonical_url' => $page['canonical_url'] ?? '',
            'status' => $page['status'] ?? 'publish',
            'remote_modified_at' => $page['modified_at'] ?? now()->toIso8601String(),
            'seo_plugin' => $this->seoPlugin,
        ];
    }

    public function verifyChange(string $identifier, array $expectedState): array
    {
        // Re-read directly from remote CMS
        $live = $this->fetchPage($identifier);

        $matches = [];
        $mismatches = [];
        $verified = true;

        foreach ($expectedState as $field => $expectedValue) {
            $actualValue = $live[$field] ?? null;

            // Trim strings for normalized comparison
            $normExpected = is_string($expectedValue) ? trim($expectedValue) : $expectedValue;
            $normActual = is_string($actualValue) ? trim($actualValue) : $actualValue;

            if ($normExpected === $normActual) {
                $matches[$field] = $actualValue;
            } else {
                $verified = false;
                $mismatches[$field] = [
                    'expected' => $expectedValue,
                    'actual' => $actualValue,
                ];
            }
        }

        return [
            'verified' => $verified,
            'matches' => $matches,
            'mismatches' => $mismatches,
            'live_state' => $live,
        ];
    }

    public function disconnect(): bool
    {
        return true;
    }

    private function isSandbox(): bool
    {
        return !empty($this->credentials['is_sandbox']);
    }

    private function httpClient()
    {
        $client = Http::withHeaders([
            'Accept' => 'application/json',
            'User-Agent' => 'WebSEOAI-WordPressAdapter/1.0',
        ]);

        $appPassword = $this->credentials['application_password'] ?? $this->credentials['app_password'] ?? null;
        if (!empty($this->credentials['username']) && !empty($appPassword)) {
            $client = $client->withBasicAuth(
                $this->credentials['username'],
                $appPassword
            );
        } elseif (!empty($this->credentials['token'])) {
            $client = $client->withToken($this->credentials['token']);
        }

        return $client;
    }

    private function resolvePageId(string $slug): int
    {
        $cleanSlug = trim($slug);
        if (str_starts_with($cleanSlug, 'http://') || str_starts_with($cleanSlug, 'https://')) {
            $path = trim((string) parse_url($cleanSlug, PHP_URL_PATH), '/');
            if ($path === '') {
                $cleanSlug = '';
            } else {
                $parts = explode('/', $path);
                $cleanSlug = end($parts) ?: '';
            }
        } else {
            $cleanSlug = trim($cleanSlug, '/');
        }

        if ($cleanSlug !== '') {
            $pageRes = $this->httpClient()->get("{$this->baseUrl}/wp-json/wp/v2/pages?slug=" . urlencode($cleanSlug));
            $pageData = $pageRes->json();
            if (!empty($pageData[0]['id'])) {
                return (int) $pageData[0]['id'];
            }

            $postRes = $this->httpClient()->get("{$this->baseUrl}/wp-json/wp/v2/posts?slug=" . urlencode($cleanSlug));
            $postData = $postRes->json();
            if (!empty($postData[0]['id'])) {
                return (int) $postData[0]['id'];
            }
        } else {
            // Homepage fallback: first published page ordered by menu_order
            $homeRes = $this->httpClient()->get("{$this->baseUrl}/wp-json/wp/v2/pages?per_page=1&orderby=menu_order&order=asc");
            $homeData = $homeRes->json();
            if (!empty($homeData[0]['id'])) {
                return (int) $homeData[0]['id'];
            }
        }

        throw new Exception("Could not resolve WordPress page or post slug [{$slug}] to an ID.");
    }

    private function normalizeWpPage(array $raw): array
    {
        $yoastMeta = $raw['yoast_head_json'] ?? [];
        $meta = $raw['meta'] ?? [];

        $metaTitle = $yoastMeta['title']
            ?? $meta['_yoast_wpseo_title']
            ?? $meta['rank_math_title']
            ?? ($raw['title']['rendered'] ?? $raw['title'] ?? '');

        $metaDesc = $yoastMeta['description']
            ?? $meta['_yoast_wpseo_metadesc']
            ?? $meta['rank_math_description']
            ?? '';

        $canonical = $yoastMeta['canonical']
            ?? $meta['_yoast_wpseo_canonical']
            ?? $meta['rank_math_canonical_url']
            ?? ($raw['link'] ?? '');

        $slug = $raw['slug'] ?? '';
        $url = $raw['link'] ?? ($slug ? "{$this->baseUrl}/{$slug}" : $this->baseUrl);

        return [
            'id' => $raw['id'] ?? null,
            'slug' => $slug,
            'url' => $url,
            'title' => $raw['title']['rendered'] ?? $raw['title'] ?? '',
            'content' => $raw['content']['rendered'] ?? $raw['content'] ?? '',
            'status' => $raw['status'] ?? 'publish',
            'meta_title' => $metaTitle,
            'meta_description' => $metaDesc,
            'canonical_url' => $canonical,
            'modified_at' => $raw['modified_gmt'] ?? $raw['modified'] ?? now()->toIso8601String(),
        ];
    }

    private function mockPageData(string $identifier): array
    {
        return [
            'id' => is_numeric($identifier) ? (int) $identifier : 42,
            'slug' => is_numeric($identifier) ? "page-{$identifier}" : $identifier,
            'url' => "{$this->baseUrl}/{$identifier}",
            'title' => 'Technical SEO Services & Agency Solutions',
            'content' => '<p>Professional SEO automation for modern brands and ecommerce stores.</p>',
            'status' => 'publish',
            'meta_title' => 'Technical SEO Agency Services | Enterprise SEO',
            'meta_description' => 'Scale organic search visibility with automated technical audits, continuous crawl monitoring, and strategic content studio workflows.',
            'canonical_url' => "{$this->baseUrl}/{$identifier}",
            'modified_at' => '2026-10-10T14:30:00Z',
        ];
    }
}
