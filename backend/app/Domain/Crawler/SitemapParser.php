<?php

namespace App\Domain\Crawler;

use SimpleXMLElement;
use Throwable;

class SitemapParser
{
    /**
     * Parse sitemap XML content and distinguish between page URLs and nested child sitemaps.
     *
     * @return array{urls: string[], child_sitemaps: string[], is_index: bool}
     */
    public static function parseDetailed(string $xmlContent): array
    {
        $urls = [];
        $childSitemaps = [];
        $isIndex = false;

        if (empty(trim($xmlContent))) {
            return ['urls' => [], 'child_sitemaps' => [], 'is_index' => false];
        }

        // Transparently handle gzipped sitemap payloads
        if (str_starts_with($xmlContent, "\x1f\x8b")) {
            $decoded = @gzdecode($xmlContent);
            if ($decoded !== false) {
                $xmlContent = $decoded;
            }
        }

        // Strip UTF-8 BOM if present
        $xmlContent = preg_replace('/^\xEF\xBB\xBF/', '', $xmlContent);

        // Quick rejection if server returned HTML instead of XML
        if (stripos($xmlContent, '<html') !== false && stripos($xmlContent, '<urlset') === false && stripos($xmlContent, '<sitemapindex') === false) {
            return ['urls' => [], 'child_sitemaps' => [], 'is_index' => false];
        }

        try {
            $xml = @simplexml_load_string(
                $xmlContent,
                SimpleXMLElement::class,
                LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR
            );

            if ($xml === false) {
                return self::regexFallback($xmlContent);
            }

            $rootName = strtolower($xml->getName());

            // Sitemap Index (<sitemapindex><sitemap><loc>...</loc></sitemap></sitemapindex>)
            if ($rootName === 'sitemapindex' || isset($xml->sitemap)) {
                $isIndex = true;
                foreach ($xml->sitemap as $sitemapNode) {
                    if (!empty($sitemapNode->loc)) {
                        $childSitemaps[] = trim((string) $sitemapNode->loc);
                    }
                }
            }

            // Standard URL Set (<urlset><url><loc>...</loc></url></urlset>)
            if (isset($xml->url)) {
                foreach ($xml->url as $urlNode) {
                    if (!empty($urlNode->loc)) {
                        $urls[] = trim((string) $urlNode->loc);
                    }
                }
            }

            // Handle namespaced elements when default SimpleXML children don't match
            if (empty($urls) && empty($childSitemaps)) {
                $namespaces = $xml->getDocNamespaces(true);
                foreach ($namespaces as $ns) {
                    $children = $xml->children($ns);
                    if (isset($children->sitemap)) {
                        $isIndex = true;
                        foreach ($children->sitemap as $sitemapNode) {
                            $loc = trim((string) $sitemapNode->children($ns)->loc);
                            if ($loc !== '') {
                                $childSitemaps[] = $loc;
                            }
                        }
                    }
                    if (isset($children->url)) {
                        foreach ($children->url as $urlNode) {
                            $loc = trim((string) $urlNode->children($ns)->loc);
                            if ($loc !== '') {
                                $urls[] = $loc;
                            }
                        }
                    }
                }
            }
        } catch (Throwable) {
            return self::regexFallback($xmlContent);
        }

        return [
            'urls' => array_values(array_unique(array_filter($urls))),
            'child_sitemaps' => array_values(array_unique(array_filter($childSitemaps))),
            'is_index' => $isIndex,
        ];
    }

    /**
     * Backwards-compatible flat list of all <loc> URLs.
     *
     * @return string[]
     */
    public static function parse(string $xmlContent): array
    {
        $parsed = self::parseDetailed($xmlContent);
        return array_values(array_unique(array_merge($parsed['urls'], $parsed['child_sitemaps'])));
    }

    protected static function regexFallback(string $xmlContent): array
    {
        $isIndex = stripos($xmlContent, '<sitemapindex') !== false;
        preg_match_all('#<loc>\s*(https?://[^\s<]+)\s*</loc>#i', $xmlContent, $matches);
        $found = array_values(array_unique(array_filter($matches[1] ?? [])));

        return [
            'urls' => $isIndex ? [] : $found,
            'child_sitemaps' => $isIndex ? $found : [],
            'is_index' => $isIndex,
        ];
    }
}
