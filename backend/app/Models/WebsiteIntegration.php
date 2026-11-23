<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class WebsiteIntegration extends Model
{
    use HasFactory;

    public const PROVIDER_WORDPRESS = 'wordpress_rest';
    public const PROVIDER_SHOPIFY = 'shopify_graphql';
    public const PROVIDER_CUSTOM_WEBHOOK = 'custom_webhook';
    public const PROVIDER_GSC = 'gsc_oauth';
    public const PROVIDER_GA4 = 'ga4_oauth';

    public const PROVIDERS = [
        self::PROVIDER_WORDPRESS,
        self::PROVIDER_SHOPIFY,
        self::PROVIDER_CUSTOM_WEBHOOK,
        self::PROVIDER_GSC,
        self::PROVIDER_GA4,
    ];

    public const STATUS_CONNECTED = 'connected';
    public const STATUS_DISCONNECTED = 'disconnected';
    public const STATUS_PENDING = 'pending';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'organization_id',
        'website_id',
        'provider',
        'encrypted_credentials',
        'status',
        'last_error',
        'last_synced_at',
        'metadata',
    ];

    /**
     * CRITICAL SECURITY: Never return encrypted credentials in serialization or API responses.
     */
    protected $hidden = [
        'encrypted_credentials',
    ];

    protected $casts = [
        'metadata' => 'array',
        'last_synced_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /**
     * Store credentials safely using AES-256 envelope encryption.
     */
    public function setEncryptedCredentialsAttribute(mixed $value): void
    {
        if (is_array($value)) {
            $this->attributes['encrypted_credentials'] = Crypt::encryptString(json_encode($value));
        } elseif (is_string($value)) {
            // Check if already looks encrypted or needs encryption
            try {
                Crypt::decryptString($value);
                $this->attributes['encrypted_credentials'] = $value;
            } catch (Throwable) {
                $this->attributes['encrypted_credentials'] = Crypt::encryptString($value);
            }
        }
    }

    /**
     * Decrypt credentials strictly for internal server-side API drivers.
     */
    public function getDecryptedCredentials(): ?array
    {
        if (empty($this->attributes['encrypted_credentials'])) {
            return null;
        }

        try {
            $decrypted = Crypt::decryptString($this->attributes['encrypted_credentials']);
            $decoded = json_decode($decrypted, true);
            return is_array($decoded) ? $decoded : ['token' => $decrypted];
        } catch (Throwable) {
            return null;
        }
    }
}
