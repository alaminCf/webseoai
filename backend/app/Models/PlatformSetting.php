<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Throwable;

class PlatformSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    protected $casts = [
        'value' => 'array',
    ];

    public static function defaults(): array
    {
        return [
            'serp_quotas' => [
                'starter' => ['daily' => 10, 'monthly' => 50],
                'agency_pro' => ['daily' => 30, 'monthly' => 200],
                'enterprise' => ['daily' => 150, 'monthly' => 1500],
                'agency_internal' => ['daily' => 99999, 'monthly' => 999999],
            ],
            'serp_cache' => [
                'enabled' => true,
                'ttl_hours' => 12,
                'default_depth' => 30,
            ],
            'registration' => [
                'allow_public_registration' => (bool) config('app.allow_public_registration', true),
                'default_signup_plan' => (string) config('saas.default_signup_plan', 'agency_pro'),
            ],
        ];
    }

    public static function getValue(string $key, mixed $fallback = null): mixed
    {
        $defaults = self::defaults();
        $defaultVal = $fallback ?? ($defaults[$key] ?? null);

        try {
            $row = self::where('key', $key)->first();
            if (!$row || $row->value === null) {
                return $defaultVal;
            }

            if (is_array($defaultVal) && is_array($row->value)) {
                return array_replace_recursive($defaultVal, $row->value);
            }

            return $row->value;
        } catch (Throwable) {
            return $defaultVal;
        }
    }

    public static function setValue(string $key, mixed $value): self
    {
        return self::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }

    public static function getAllMerged(): array
    {
        $defaults = self::defaults();
        $result = [];
        foreach (array_keys($defaults) as $key) {
            $result[$key] = self::getValue($key, $defaults[$key]);
        }
        return $result;
    }
}
