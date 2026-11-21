<?php

namespace App\Domain\Crawler;

class RobotsTxtParser
{
    /** @var array<string, array{allows: string[], disallows: string[], crawl_delay: ?int}> */
    protected array $groups = [];
    protected array $sitemaps = [];
    protected bool $hasContent = false;
    protected string $rawContent = '';

    public function __construct(string $content = '')
    {
        $this->rawContent = $content;
        $this->hasContent = trim($content) !== '';
        $this->parse($content);
    }

    /**
     * Parse robots.txt textual content following RFC 9309 group semantics.
     */
    public function parse(string $content): void
    {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $activeAgents = [];
        $seenRuleInGroup = false;

        foreach ($lines as $line) {
            $line = trim(explode('#', $line)[0]);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }

            [$directive, $value] = array_map('trim', explode(':', $line, 2));
            $directive = strtolower($directive);

            if ($directive === 'sitemap' && $value !== '') {
                $this->sitemaps[] = $value;
                continue;
            }

            if ($directive === 'user-agent') {
                $agent = strtolower($value);
                if ($seenRuleInGroup) {
                    $activeAgents = [];
                    $seenRuleInGroup = false;
                }
                if ($agent !== '') {
                    $activeAgents[] = $agent;
                    if (!isset($this->groups[$agent])) {
                        $this->groups[$agent] = [
                            'allows' => [],
                            'disallows' => [],
                            'crawl_delay' => null,
                        ];
                    }
                }
                continue;
            }

            if (empty($activeAgents)) {
                $activeAgents = ['*'];
                $this->groups['*'] = $this->groups['*'] ?? [
                    'allows' => [],
                    'disallows' => [],
                    'crawl_delay' => null,
                ];
            }

            if ($directive === 'disallow') {
                $seenRuleInGroup = true;
                if ($value !== '') {
                    foreach ($activeAgents as $agent) {
                        $this->groups[$agent]['disallows'][] = $value;
                    }
                }
            } elseif ($directive === 'allow') {
                $seenRuleInGroup = true;
                if ($value !== '') {
                    foreach ($activeAgents as $agent) {
                        $this->groups[$agent]['allows'][] = $value;
                    }
                }
            } elseif ($directive === 'crawl-delay') {
                $seenRuleInGroup = true;
                $delay = (int) $value;
                foreach ($activeAgents as $agent) {
                    $this->groups[$agent]['crawl_delay'] = $delay;
                }
            }
        }
    }

    /**
     * Resolve applicable rule group: specific user-agent overrides wildcard '*'.
     */
    protected function getEffectiveRules(): array
    {
        foreach (['webseoai-bot', 'webseoaibot'] as $specific) {
            if (isset($this->groups[$specific])) {
                return $this->groups[$specific];
            }
        }

        return $this->groups['*'] ?? [
            'allows' => [],
            'disallows' => [],
            'crawl_delay' => null,
        ];
    }

    /**
     * Determine if a URL or path is allowed by robots.txt using RFC 9309 longest-match specificity.
     */
    public function isAllowed(string $urlOrPath): bool
    {
        $parsed = parse_url($urlOrPath);
        $path = $parsed['path'] ?? '/';
        if (!empty($parsed['query'])) {
            $path .= '?' . $parsed['query'];
        }

        $rules = $this->getEffectiveRules();
        $longestAllow = -1;
        $longestDisallow = -1;

        foreach ($rules['allows'] as $pattern) {
            if ($this->pathMatches($path, $pattern)) {
                $longestAllow = max($longestAllow, strlen($pattern));
            }
        }

        foreach ($rules['disallows'] as $pattern) {
            if ($this->pathMatches($path, $pattern)) {
                $longestDisallow = max($longestDisallow, strlen($pattern));
            }
        }

        if ($longestAllow === -1 && $longestDisallow === -1) {
            return true;
        }

        // Longest matching rule wins; ties favor Allow per RFC 9309
        return $longestAllow >= $longestDisallow;
    }

    /**
     * Check whether robots.txt blocks the entire root website.
     */
    public function blocksAll(): bool
    {
        return !$this->isAllowed('/');
    }

    public function exists(): bool
    {
        return $this->hasContent;
    }

    public function getSitemaps(): array
    {
        return array_values(array_unique($this->sitemaps));
    }

    public function getCrawlDelay(): ?int
    {
        return $this->getEffectiveRules()['crawl_delay'] ?? null;
    }

    public function getDisallowRules(): array
    {
        return $this->getEffectiveRules()['disallows'] ?? [];
    }

    protected function pathMatches(string $path, string $pattern): bool
    {
        $endAnchor = str_ends_with($pattern, '$');
        if ($endAnchor) {
            $pattern = substr($pattern, 0, -1);
        }

        $regex = preg_quote($pattern, '#');
        $regex = str_replace('\*', '.*', $regex);
        $suffix = $endAnchor ? '$' : '';

        return (bool) preg_match("#^{$regex}{$suffix}#i", $path);
    }
}
