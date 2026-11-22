<?php

namespace App\Domain\Integrations;

use App\Domain\Integrations\Adapters\GenericCustomAdapter;
use App\Domain\Integrations\Adapters\ShopifyAdapter;
use App\Domain\Integrations\Adapters\WordPressAdapter;
use App\Domain\Integrations\Contracts\CmsAdapterInterface;
use App\Models\Website;
use App\Models\WebsiteIntegration;
use InvalidArgumentException;

class CmsAdapterManager
{
    /**
     * Resolve CMS adapter for a given website.
     */
    public function resolveAdapter(Website $website): CmsAdapterInterface
    {
        $platform = strtolower($website->platform);

        if ($platform === Website::PLATFORM_WORDPRESS) {
            $integration = WebsiteIntegration::where('website_id', $website->id)
                ->where('provider', WebsiteIntegration::PROVIDER_WORDPRESS)
                ->first();

            $credentials = $integration ? ($integration->getDecryptedCredentials() ?? []) : [];
            return new WordPressAdapter($website, $credentials);
        }

        if ($platform === Website::PLATFORM_SHOPIFY) {
            $integration = WebsiteIntegration::where('website_id', $website->id)
                ->where('provider', WebsiteIntegration::PROVIDER_SHOPIFY)
                ->first();

            $credentials = $integration ? ($integration->getDecryptedCredentials() ?? []) : [];
            return new ShopifyAdapter($website, $credentials);
        }

        if ($platform === Website::PLATFORM_CUSTOM) {
            $integration = WebsiteIntegration::where('website_id', $website->id)
                ->where('provider', WebsiteIntegration::PROVIDER_CUSTOM_WEBHOOK)
                ->where('status', WebsiteIntegration::STATUS_CONNECTED)
                ->first();

            $credentials = $integration ? ($integration->getDecryptedCredentials() ?? []) : [];
            return new GenericCustomAdapter($website, $credentials);
        }

        throw new InvalidArgumentException("Unsupported platform [{$platform}].");
    }
}
