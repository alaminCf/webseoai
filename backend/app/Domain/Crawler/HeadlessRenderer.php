<?php

namespace App\Domain\Crawler;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * Legitimate Headless Web Rendering Service (WRS) for Client-Side Rendered SPAs
 * (React, Vite, Vue, Angular) — similar to Googlebot's Web Rendering Service.
 *
 * Only invoked for HTTP 200 CSR SPA shells (never used to bypass anti-bot/WAF/CAPTCHA gates).
 */
class HeadlessRenderer
{
    /**
     * Detect if raw HTTP HTML looks like an unhydrated Client-Side Rendered SPA shell.
     */
    public static function looksLikeSpaShell(string $html, int $visibleWordCount, int $internalLinksCount): bool
    {
        if ($visibleWordCount >= 40 && $internalLinksCount >= 2) {
            return false;
        }

        $hasSpaMount = (bool) preg_match(
            '#<div[^>]+id=["\']?(root|app|__next|__nuxt|svelte|gatsby-focus-wrapper)["\']?[^>]*>#i',
            $html
        );

        $hasModuleBundle = (bool) preg_match(
            '#<script[^>]+(type=["\']module["\']|src=["\'][^"\']*(assets/index|bundle|chunk|_next/static|app\.)[^"\']*\.js)#i',
            $html
        );

        return ($hasSpaMount || $hasModuleBundle) && $visibleWordCount < 35;
    }

    /**
     * Locate a local Chromium / Google Chrome executable if available.
     */
    public static function findChromeBinary(): ?string
    {
        $envPath = env('CRAWLER_CHROME_BINARY');
        if ($envPath && is_executable($envPath)) {
            return $envPath;
        }

        $candidates = [
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
            '/Applications/Chromium.app/Contents/MacOS/Chromium',
            '/usr/bin/google-chrome',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
        ];

        foreach ($candidates as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    public static function isAvailable(): bool
    {
        return self::findChromeBinary() !== null;
    }

    /**
     * Render a validated public URL and return its hydrated DOM HTML.
     *
     * @return array{success: bool, html: ?string, render_time_ms: int, error: ?string}
     */
    public static function render(string $url, int $timeoutSeconds = 22): array
    {
        $binary = self::findChromeBinary();
        if (!$binary) {
            return [
                'success' => false,
                'html' => null,
                'render_time_ms' => 0,
                'error' => 'Headless Chrome binary not available on host.',
            ];
        }

        $startTime = microtime(true);

        try {
            $process = new Process([
                $binary,
                '--headless=new',
                '--dump-dom',
                '--disable-gpu',
                '--no-sandbox',
                '--disable-dev-shm-usage',
                '--disable-extensions',
                '--disable-background-networking',
                '--mute-audio',
                '--window-size=1440,900',
                '--virtual-time-budget=4000',
                $url,
            ]);

            $process->setTimeout($timeoutSeconds);
            $process->run();

            $renderTimeMs = (int) round((microtime(true) - $startTime) * 1000);

            if (!$process->isSuccessful()) {
                return [
                    'success' => false,
                    'html' => null,
                    'render_time_ms' => $renderTimeMs,
                    'error' => trim($process->getErrorOutput()) ?: 'Headless render exited with code ' . $process->getExitCode(),
                ];
            }

            $dom = $process->getOutput();
            if (empty(trim($dom)) || stripos($dom, '<html') === false) {
                return [
                    'success' => false,
                    'html' => null,
                    'render_time_ms' => $renderTimeMs,
                    'error' => 'Headless render produced empty or non-HTML output.',
                ];
            }

            return [
                'success' => true,
                'html' => $dom,
                'render_time_ms' => $renderTimeMs,
                'error' => null,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'html' => null,
                'render_time_ms' => (int) round((microtime(true) - $startTime) * 1000),
                'error' => $e->getMessage(),
            ];
        }
    }
}
