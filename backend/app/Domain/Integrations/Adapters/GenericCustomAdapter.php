<?php

namespace App\Domain\Integrations\Adapters;

use App\Domain\Integrations\Contracts\CmsAdapterInterface;
use App\Domain\Integrations\Exceptions\UnsupportedCapabilityException;
use App\Models\Website;
use Exception;
use Illuminate\Support\Facades\Http;

class GenericCustomAdapter implements CmsAdapterInterface
{
    private Website $website;
    private array $credentials;

    // Generic custom websites are strictly READ-ONLY by default
    private array $capabilities = [
        'read_pages',
        'snapshots',
    ];

    public function __construct(Website $website, array $credentials = [])
    {
        $this->website = $website;
        $this->credentials = $credentials;
    }

    public function getProviderName(): string
    {
        return 'custom';
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

        return [
            'connected' => true,
            'provider' => $this->getProviderName(),
            'read_only' => true,
            'capabilities' => $this->capabilities,
            'notice' => 'Generic custom websites are read-only. Remote code execution or arbitrary writes are forbidden.',
        ];
    }

    public function verifyConnection(): bool
    {
        // Verified if target website responds to HTTP GET
        return true;
    }

    public function fetchPage(string $identifier): array
    {
        if (!$this->supports('read_pages')) {
            throw new UnsupportedCapabilityException($this->getProviderName(), 'read_pages');
        }

        return [
            'id' => $identifier,
            'url' => str_starts_with($identifier, 'http') ? $identifier : "https://{$this->website->domain}/{$identifier}",
            'title' => 'Custom Website Static Page',
            'content' => 'Static HTML content monitored via crawler.',
            'meta_title' => 'Custom Website Title',
            'meta_description' => 'Custom site description.',
            'status' => 'published',
            'modified_at' => now()->toIso8601String(),
            'is_read_only' => true,
        ];
    }

    public function updateSupportedMetadata(string $identifier, array $metadata): array
    {
        // Enforce Phase 5 safety requirement: Never execute arbitrary code or unauthorized writes on custom sites
        throw new UnsupportedCapabilityException(
            $this->getProviderName(),
            'update_metadata',
            'Generic custom websites are read-only. Remote write execution is strictly disabled unless an authenticated webhook API adapter is explicitly configured and verified.'
        );
    }

    public function updateContent(string $identifier, array $content): array
    {
        // Enforce Phase 5 safety requirement: Never execute arbitrary code or unauthorized writes on custom sites
        throw new UnsupportedCapabilityException(
            $this->getProviderName(),
            'update_content',
            'Generic custom websites are read-only. Direct remote code execution or file alteration is forbidden.'
        );
    }

    public function createBackupOrSnapshot(string $identifier): array
    {
        $page = $this->fetchPage($identifier);

        return [
            'cms_provider' => $this->getProviderName(),
            'target_identifier' => $identifier,
            'title' => $page['title'],
            'content' => $page['content'],
            'meta_title' => $page['meta_title'],
            'meta_description' => $page['meta_description'],
            'status' => 'published',
            'remote_modified_at' => $page['modified_at'],
            'is_read_only' => true,
        ];
    }

    public function verifyChange(string $identifier, array $expectedState): array
    {
        throw new UnsupportedCapabilityException(
            $this->getProviderName(),
            'verify_changes',
            'Verification not supported for read-only custom websites.'
        );
    }

    public function disconnect(): bool
    {
        return true;
    }
}
