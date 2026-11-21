<?php

namespace App\Domain\AI\Drivers;

use App\Domain\AI\Contracts\AiProviderInterface;
use App\Domain\AI\SecurityPromptWrapper;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class OpenAiDriver implements AiProviderInterface
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
        $this->apiKey = $apiKey ?? (string) config('ai.openai.api_key', env('OPENAI_API_KEY', ''));
        $this->model = $model ?? (string) config('ai.openai.model', 'gpt-4o-mini');
        $this->baseUrl = 'https://api.openai.com/v1';
        $this->timeout = $timeout;
    }

    public function generateStructured(string $systemPrompt, string $userPrompt, array $jsonSchema, array $options = []): array
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('OpenAI API key is not configured.');
        }

        $systemSecurity = SecurityPromptWrapper::getSystemSecurityBoundary() . "\n\n" . $systemPrompt . "\n\nYou MUST respond with valid JSON matching the requested structure.";

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemSecurity],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'response_format' => ['type' => 'json_object'],
            'temperature' => $options['temperature'] ?? 0.2,
        ];

        $response = $this->executeWithRetry("{$this->baseUrl}/chat/completions", $payload);
        $raw = $response['choices'][0]['message']['content'] ?? '{}';
        $this->extractUsage($response);

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Failed to parse structured JSON from OpenAI: ' . substr($raw, 0, 100));
        }

        return $decoded;
    }

    public function generateText(string $systemPrompt, string $userPrompt, array $options = []): string
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('OpenAI API key is not configured.');
        }

        $systemSecurity = SecurityPromptWrapper::getSystemSecurityBoundary() . "\n\n" . $systemPrompt;

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemSecurity],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => $options['temperature'] ?? 0.7,
        ];

        $response = $this->executeWithRetry("{$this->baseUrl}/chat/completions", $payload);
        $this->extractUsage($response);

        return $response['choices'][0]['message']['content'] ?? '';
    }

    public function getProviderName(): string
    {
        return 'openai';
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
                    ->withToken($this->apiKey)
                    ->post($endpoint, $payload);

                if ($res->successful()) {
                    return $res->json();
                }

                $status = $res->status();
                if (in_array($status, [429, 500, 502, 503, 504], true) && $attempt < $maxAttempts) {
                    usleep((int) (pow(2, $attempt) * 250000));
                    continue;
                }

                $errorBody = SecurityPromptWrapper::scrubSecrets($res->body());
                throw new RuntimeException("OpenAI API error (HTTP {$status}): {$errorBody}");

            } catch (Throwable $e) {
                $lastException = $e;
                if ($attempt < $maxAttempts) {
                    usleep((int) (pow(2, $attempt) * 250000));
                    continue;
                }
            }
        }

        throw new RuntimeException("OpenAI API request failed: " . $lastException?->getMessage(), 0, $lastException);
    }

    protected function extractUsage(array $response): void
    {
        $usage = $response['usage'] ?? [];
        $prompt = (int) ($usage['prompt_tokens'] ?? 0);
        $completion = (int) ($usage['completion_tokens'] ?? 0);
        $total = (int) ($usage['total_tokens'] ?? ($prompt + $completion));

        $this->lastUsage = [
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $total,
        ];
    }
}
