<?php

namespace App\Domain\AI\Drivers;

use App\Domain\AI\Contracts\AiProviderInterface;
use App\Domain\AI\SecurityPromptWrapper;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class GeminiDriver implements AiProviderInterface
{
    protected string $apiKey;
    protected string $model;
    protected string $baseUrl;
    protected int $timeout;
    protected array $lastUsage = [
        'prompt_tokens' => 0,
        'completion_tokens' => 0,
        'total_tokens' => 0,
    ];

    public function __construct(?string $apiKey = null, ?string $model = null, int $timeout = 30)
    {
        $this->apiKey = $apiKey ?? (string) config('ai.gemini.api_key', env('GEMINI_API_KEY', ''));
        $this->model = $model ?? (string) config('ai.gemini.model', 'gemini-3.8-flash');
        $this->baseUrl = 'https://generativelanguage.googleapis.com/v1beta';
        $this->timeout = $timeout;
    }

    public function generateStructured(string $systemPrompt, string $userPrompt, array $jsonSchema, array $options = []): array
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('Gemini API key is not configured.');
        }

        $systemSecurity = SecurityPromptWrapper::getSystemSecurityBoundary() . "\n\n" . $systemPrompt;
        $endpoint = "{$this->baseUrl}/models/{$this->model}:generateContent?key={$this->apiKey}";

        $payload = [
            'system_instruction' => [
                'parts' => [
                    ['text' => $systemSecurity],
                ],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $userPrompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'response_mime_type' => 'application/json',
                'temperature' => $options['temperature'] ?? 0.2,
                'max_output_tokens' => $options['max_tokens'] ?? 4096,
            ],
        ];

        if (!empty($jsonSchema)) {
            $payload['generationConfig']['response_schema'] = $jsonSchema;
        }

        $response = $this->executeWithRetry($endpoint, $payload);
        $candidates = $response['candidates'] ?? [];

        if (empty($candidates)) {
            throw new RuntimeException('Gemini returned zero response candidates.');
        }

        $rawText = $candidates[0]['content']['parts'][0]['text'] ?? '';
        $this->extractUsage($response);

        $decoded = json_decode($rawText, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Attempt markdown fence strip fallback
            if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $rawText, $matches)) {
                $decoded = json_decode($matches[1], true);
            }
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Failed to decode structured JSON output from Gemini response: ' . substr($rawText, 0, 100));
        }

        return $decoded;
    }

    public function generateText(string $systemPrompt, string $userPrompt, array $options = []): string
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('Gemini API key is not configured.');
        }

        $systemSecurity = SecurityPromptWrapper::getSystemSecurityBoundary() . "\n\n" . $systemPrompt;
        $endpoint = "{$this->baseUrl}/models/{$this->model}:generateContent?key={$this->apiKey}";

        $payload = [
            'system_instruction' => [
                'parts' => [
                    ['text' => $systemSecurity],
                ],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $userPrompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => $options['temperature'] ?? 0.7,
            ],
        ];

        $response = $this->executeWithRetry($endpoint, $payload);
        $this->extractUsage($response);

        return $response['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    public function getProviderName(): string
    {
        return 'gemini';
    }

    public function getModelName(): string
    {
        return $this->model;
    }

    public function getLastUsage(): array
    {
        return $this->lastUsage;
    }

    protected function executeWithRetry(string $endpoint, array $payload, int $maxAttempts = 3): array
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxAttempts) {
            $attempt++;
            try {
                $res = Http::timeout($this->timeout)
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->post($endpoint, $payload);

                if ($res->successful()) {
                    return $res->json();
                }

                // If rate limited or transient server error, retry with exponential backoff
                $status = $res->status();
                if (in_array($status, [429, 500, 502, 503, 504], true) && $attempt < $maxAttempts) {
                    usleep((int) (pow(2, $attempt) * 250000)); // 500ms, 1000ms
                    continue;
                }

                $errorBody = SecurityPromptWrapper::scrubSecrets($res->body());
                throw new RuntimeException("Gemini API error (HTTP {$status}): {$errorBody}");

            } catch (Throwable $e) {
                $lastException = $e;
                if ($attempt < $maxAttempts) {
                    usleep((int) (pow(2, $attempt) * 250000));
                    continue;
                }
            }
        }

        throw new RuntimeException("Gemini API request failed after {$maxAttempts} attempts: " . $lastException?->getMessage(), 0, $lastException);
    }

    protected function extractUsage(array $response): void
    {
        $meta = $response['usageMetadata'] ?? [];
        $prompt = (int) ($meta['promptTokenCount'] ?? 0);
        $completion = (int) ($meta['candidatesTokenCount'] ?? 0);
        $total = (int) ($meta['totalTokenCount'] ?? ($prompt + $completion));

        $this->lastUsage = [
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $total,
        ];
    }
}
