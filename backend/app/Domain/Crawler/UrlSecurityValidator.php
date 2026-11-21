<?php

namespace App\Domain\Crawler;

use RuntimeException;

class UrlSecurityValidator
{
    /**
     * Validate an untrusted target URL against SSRF, dangerous IP ranges, and unauthorized domain boundaries.
     *
     * @throws RuntimeException if the URL fails security requirements
     */
    public static function validate(string $url, ?string $authorizedDomain = null, bool $allowLocalhostForTesting = false): string
    {
        $parts = parse_url($url);

        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            throw new RuntimeException("Malformed URL: [{$url}]");
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException("Unsupported protocol [{$scheme}]. Only HTTP and HTTPS are permitted.");
        }

        $host = strtolower($parts['host']);

        // Check authorized domain scope if provided (handles www <-> apex cleanly)
        if ($authorizedDomain) {
            $normalizedApex = UrlNormalizer::apexDomain($authorizedDomain);
            $inScope = ($host === $normalizedApex || str_ends_with($host, '.' . $normalizedApex));

            if (!$inScope) {
                if (!($allowLocalhostForTesting && ($host === 'localhost' || $host === '127.0.0.1'))) {
                    throw new RuntimeException("Target host [{$host}] is outside the authorized domain boundary [{$authorizedDomain}].");
                }
            }
        }

        // Test environment hook for local fixtures
        if ($allowLocalhostForTesting && ($host === 'localhost' || $host === '127.0.0.1')) {
            return $url;
        }

        // Reject raw IP literals that are dangerous immediately
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (self::isDangerousIp($host)) {
                throw new RuntimeException("Direct IP host [{$host}] is blacklisted/private. Request blocked by SSRF defense.");
            }
            return $url;
        }

        // Resolve DNS to IP to prevent loopback, RFC1918 private subnets, and cloud metadata (SSRF)
        $ips = @dns_get_record($host, DNS_A + DNS_AAAA);
        $resolvedIps = [];

        if (is_array($ips)) {
            foreach ($ips as $record) {
                if (!empty($record['ip'])) {
                    $resolvedIps[] = $record['ip'];
                }
                if (!empty($record['ipv6'])) {
                    $resolvedIps[] = $record['ipv6'];
                }
            }
        }

        if (empty($resolvedIps)) {
            $fallbackIp = @gethostbyname($host);
            if ($fallbackIp && $fallbackIp !== $host) {
                $resolvedIps[] = $fallbackIp;
            }
        }

        if (empty($resolvedIps)) {
            throw new RuntimeException("Could not resolve DNS records for host [{$host}].");
        }

        foreach ($resolvedIps as $ip) {
            if (self::isDangerousIp($ip)) {
                throw new RuntimeException("Host [{$host}] resolved to blacklisted/private IP [{$ip}]. Request blocked by SSRF defense.");
            }
        }

        return $url;
    }

    /**
     * Check if an IP address belongs to loopback, private, link-local, or cloud metadata ranges.
     */
    public static function isDangerousIp(string $ip): bool
    {
        if ($ip === '127.0.0.1' || str_starts_with($ip, '127.')) {
            return true;
        }

        if ($ip === '0.0.0.0' || str_starts_with($ip, '0.')) {
            return true;
        }

        if (str_starts_with($ip, '169.254.')) {
            return true;
        }

        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (!filter_var($ip, FILTER_VALIDATE_IP, $flags)) {
            return true;
        }

        $lower = strtolower($ip);
        if ($lower === '::1' || str_starts_with($lower, 'fe80:') || str_starts_with($lower, 'fc00:') || str_starts_with($lower, 'fd00:')) {
            return true;
        }

        return false;
    }
}
