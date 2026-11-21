<?php

namespace App\Domain\Integrations\Contracts;

interface CmsAdapterInterface
{
    /**
     * Connect or authenticate with the target CMS using encrypted credentials.
     */
    public function connect(array $credentials): array;

    /**
     * Verify that the stored credentials are valid and the remote CMS is reachable.
     */
    public function verifyConnection(): bool;

    /**
     * Get list of explicit supported operations.
     * E.g. ['read_pages', 'write_draft', 'update_metadata', 'update_content', 'snapshots', 'verify_changes']
     */
    public function getCapabilities(): array;

    /**
     * Check if a specific capability is supported by this adapter.
     */
    public function supports(string $capability): bool;

    /**
     * Fetch the current state of a page/post from the remote CMS.
     */
    public function fetchPage(string $identifier): array;

    /**
     * Update SEO metadata (title, meta description, robots, canonical) on the remote CMS.
     */
    public function updateSupportedMetadata(string $identifier, array $metadata): array;

    /**
     * Update body content (HTML or markdown) on the remote CMS.
     */
    public function updateContent(string $identifier, array $content): array;

    /**
     * Create a structured snapshot of the current state of the page/post.
     */
    public function createBackupOrSnapshot(string $identifier): array;

    /**
     * Re-fetch the live state and verify that applied changes actually match expected state.
     * Returns verification result array: ['verified' => bool, 'matches' => array, 'mismatches' => array]
     */
    public function verifyChange(string $identifier, array $expectedState): array;

    /**
     * Disconnect and revoke credentials.
     */
    public function disconnect(): bool;

    /**
     * Provider unique identifier (wordpress_rest, shopify_graphql, custom).
     */
    public function getProviderName(): string;
}
