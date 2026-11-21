<?php

namespace App\Domain\Integrations\Adapters;

use App\Domain\Integrations\Contracts\CmsAdapterInterface;
use App\Domain\Integrations\Exceptions\UnsupportedCapabilityException;
use App\Models\Website;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ShopifyAdapter implements CmsAdapterInterface
{
    private Website $website;
    private array $credentials;
    private string $shopDomain;
    private string $accessToken;
    private string $apiVersion = '2025-01';

    private array $capabilities = [
        'read_products',
        'read_pages',
        'read_articles',
        'update_metadata',
        'update_content',
        'snapshots',
        'verify_changes',
    ];

    public function __construct(Website $website, array $credentials = [])
    {
        $this->website = $website;
        $this->credentials = $credentials;
        $this->shopDomain = $credentials['shop_domain'] ?? "{$website->domain}";
        $this->accessToken = $credentials['access_token'] ?? '';
        $this->apiVersion = $credentials['api_version'] ?? '2025-01';
    }

    public function getProviderName(): string
    {
        return 'shopify_graphql';
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
        $this->shopDomain = $credentials['shop_domain'] ?? "{$this->website->domain}";
        $this->accessToken = $credentials['access_token'] ?? '';

        $verified = $this->verifyConnection();
        if (!$verified) {
            throw new Exception("Could not verify Shopify store connection for [{$this->shopDomain}].");
        }

        return [
            'connected' => true,
            'provider' => $this->getProviderName(),
            'shop_domain' => $this->shopDomain,
            'api_version' => $this->apiVersion,
            'capabilities' => $this->capabilities,
        ];
    }

    public function verifyConnection(): bool
    {
        // Sandbox mock
        if ($this->isSandbox()) {
            return true;
        }

        try {
            $response = $this->httpClient()
                ->timeout(10)
                ->get("https://{$this->shopDomain}/admin/api/{$this->apiVersion}/shop.json");

            return $response->successful();
        } catch (Throwable $e) {
            Log::warning('Shopify verification failed', ['error' => $e->getMessage()]);
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
            return $this->mockShopifyPage($identifier);
        }

        $response = $this->httpClient()->get(
            "https://{$this->shopDomain}/admin/api/{$this->apiVersion}/pages/{$identifier}.json"
        );

        if (!$response->successful()) {
            throw new Exception("Shopify page [{$identifier}] not found: HTTP {$response->status()}");
        }

        $page = $response->json('page');
        if (!$page) {
            throw new Exception("Shopify page [{$identifier}] data empty.");
        }

        return $this->normalizeShopifyPage($page);
    }

    public function updateSupportedMetadata(string $identifier, array $metadata): array
    {
        if (!$this->supports('update_metadata')) {
            throw new UnsupportedCapabilityException($this->getProviderName(), 'update_metadata');
        }

        // Shopify page SEO fields mapping
        $pagePayload = [];
        if (isset($metadata['meta_title'])) {
            $pagePayload['metafield'] = [
                'key' => 'title_tag',
                'value' => $metadata['meta_title'],
                'type' => 'single_line_text_field',
                'namespace' => 'global',
            ];
        }

        if ($this->isSandbox()) {
            $current = $this->mockShopifyPage($identifier);
            return array_merge($current, [
                'meta_title' => $metadata['meta_title'] ?? $current['meta_title'],
                'meta_description' => $metadata['meta_description'] ?? $current['meta_description'],
                'modified_at' => now()->toIso8601String(),
            ]);
        }

        $response = $this->httpClient()->put(
            "https://{$this->shopDomain}/admin/api/{$this->apiVersion}/pages/{$identifier}.json",
            ['page' => $pagePayload]
        );

        if (!$response->successful()) {
            throw new Exception("Shopify update metadata failed: " . ($response->json('errors') ?? $response->body()));
        }

        return $this->normalizeShopifyPage($response->json('page'));
    }

    public function updateContent(string $identifier, array $content): array
    {
        if (!$this->supports('update_content')) {
            throw new UnsupportedCapabilityException($this->getProviderName(), 'update_content');
        }

        $pagePayload = [];
        if (isset($content['title'])) $pagePayload['title'] = $content['title'];
        if (isset($content['content'])) $pagePayload['body_html'] = $content['content'];

        if ($this->isSandbox()) {
            $current = $this->mockShopifyPage($identifier);
            return array_merge($current, [
                'title' => $content['title'] ?? $current['title'],
                'content' => $content['content'] ?? $current['content'],
                'modified_at' => now()->toIso8601String(),
            ]);
        }

        $response = $this->httpClient()->put(
            "https://{$this->shopDomain}/admin/api/{$this->apiVersion}/pages/{$identifier}.json",
            ['page' => $pagePayload]
        );

        if (!$response->successful()) {
            throw new Exception("Shopify update content failed: " . ($response->json('errors') ?? $response->body()));
        }

        return $this->normalizeShopifyPage($response->json('page'));
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
            'status' => $page['status'] ?? 'published',
            'remote_modified_at' => $page['modified_at'] ?? now()->toIso8601String(),
            'api_version' => $this->apiVersion,
        ];
    }

    public function verifyChange(string $identifier, array $expectedState): array
    {
        $live = $this->fetchPage($identifier);

        $matches = [];
        $mismatches = [];
        $verified = true;

        foreach ($expectedState as $field => $expectedValue) {
            $actualValue = $live[$field] ?? null;

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
        return Http::withHeaders([
            'X-Shopify-Access-Token' => $this->accessToken,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'WebSEOAI-ShopifyAdapter/1.0',
        ])->retry(3, 1000, function ($exception, $request) {
            // Respect Shopify rate limit response (HTTP 429)
            return $exception->response?->status() === 429;
        });
    }

    private function normalizeShopifyPage(array $raw): array
    {
        return [
            'id' => (string) ($raw['id'] ?? ''),
            'handle' => $raw['handle'] ?? '',
            'url' => "https://{$this->shopDomain}/pages/{$raw['handle']}",
            'title' => $raw['title'] ?? '',
            'content' => $raw['body_html'] ?? '',
            'meta_title' => $raw['metafield_title'] ?? $raw['title'] ?? '',
            'meta_description' => $raw['metafield_description'] ?? '',
            'status' => 'published',
            'modified_at' => $raw['updated_at'] ?? now()->toIso8601String(),
        ];
    }

    private function mockShopifyPage(string $identifier): array
    {
        return [
            'id' => $identifier,
            'handle' => 'ecommerce-seo-collection',
            'url' => "https://{$this->shopDomain}/pages/ecommerce-seo-collection",
            'title' => 'Ecommerce SEO Collection & Shopify Optimization',
            'content' => '<p>Boost Shopify conversion rates and search rankings with automated SEO workflows.</p>',
            'meta_title' => 'Shopify SEO Collection | High Growth Ecommerce Store',
            'meta_description' => 'Drive qualified organic traffic to your Shopify catalog with automated product schema, structured collections, and fast page speed.',
            'status' => 'published',
            'modified_at' => '2026-10-11T12:00:00Z',
        ];
    }
}
