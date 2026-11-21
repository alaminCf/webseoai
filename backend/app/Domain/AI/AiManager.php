<?php

namespace App\Domain\AI;

use App\Domain\AI\Contracts\AiProviderInterface;
use App\Domain\AI\Drivers\DeterministicStudioDriver;
use App\Domain\AI\Drivers\GeminiDriver;
use App\Domain\AI\Drivers\OpenAiDriver;
use App\Models\AiUsageLog;
use App\Models\Organization;
use App\Models\Website;
use RuntimeException;
use Throwable;

class AiManager
{
    protected ?AiProviderInterface $driver = null;
    protected ?Organization $organization = null;
    protected ?Website $website = null;
    protected ?int $userId = null;
    protected bool $enableFallback = true;

    public function __construct(
        ?string $driverName = null,
        ?Organization $org = null,
        ?Website $website = null,
        ?int $userId = null
    ) {
        $this->organization = $org;
        $this->website = $website;
        $this->userId = $userId;
        $this->driver = $this->resolveDriver($driverName ?? config('ai.default_driver', 'deterministic_studio'));
    }

    public function setFallback(bool $enable): self
    {
        $this->enableFallback = $enable;
        return $this;
    }

    public function generateStructured(
        string $systemPrompt,
        string $userPrompt,
        array $jsonSchema,
        string $feature = 'general',
        array $options = []
    ): array {
        $startTime = microtime(true);
        $options['feature'] = $feature;

        try {
            $result = $this->driver->generateStructured($systemPrompt, $userPrompt, $jsonSchema, $options);
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            $this->logUsage($this->driver, $feature, $durationMs, 'success');
            return $result;

        } catch (Throwable $e) {
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);
            $this->logUsage($this->driver, $feature, $durationMs, 'failed', $e->getMessage());

            // If fallback is enabled and we weren't already using deterministic_studio, fallback
            if ($this->enableFallback && !($this->driver instanceof DeterministicStudioDriver)) {
                $fallbackDriver = new DeterministicStudioDriver();
                $fallbackStartTime = microtime(true);
                $fallbackResult = $fallbackDriver->generateStructured($systemPrompt, $userPrompt, $jsonSchema, $options);
                $fallbackDuration = (int) round((microtime(true) - $fallbackStartTime) * 1000);

                $this->logUsage($fallbackDriver, "{$feature}_fallback", $fallbackDuration, 'success');
                return $fallbackResult;
            }

            throw new RuntimeException("AI Generation failed: " . SecurityPromptWrapper::scrubSecrets($e->getMessage()), 0, $e);
        }
    }

    public function generateText(
        string $systemPrompt,
        string $userPrompt,
        string $feature = 'general',
        array $options = []
    ): string {
        $startTime = microtime(true);
        $options['feature'] = $feature;

        try {
            $result = $this->driver->generateText($systemPrompt, $userPrompt, $options);
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            $this->logUsage($this->driver, $feature, $durationMs, 'success');
            return $result;

        } catch (Throwable $e) {
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);
            $this->logUsage($this->driver, $feature, $durationMs, 'failed', $e->getMessage());

            if ($this->enableFallback && !($this->driver instanceof DeterministicStudioDriver)) {
                $fallbackDriver = new DeterministicStudioDriver();
                $fallbackStartTime = microtime(true);
                $fallbackResult = $fallbackDriver->generateText($systemPrompt, $userPrompt, $options);
                $fallbackDuration = (int) round((microtime(true) - $fallbackStartTime) * 1000);

                $this->logUsage($fallbackDriver, "{$feature}_fallback", $fallbackDuration, 'success');
                return $fallbackResult;
            }

            throw new RuntimeException("AI Text Generation failed: " . SecurityPromptWrapper::scrubSecrets($e->getMessage()), 0, $e);
        }
    }

    protected function resolveDriver(string $name): AiProviderInterface
    {
        return match (strtolower($name)) {
            'gemini' => new GeminiDriver(),
            'openai' => new OpenAiDriver(),
            'deterministic_studio', 'mock' => new DeterministicStudioDriver(),
            default => new DeterministicStudioDriver(),
        };
    }

    protected function logUsage(
        AiProviderInterface $driver,
        string $feature,
        int $durationMs,
        string $status,
        ?string $errorMessage = null
    ): void {
        if (!$this->organization) {
            return;
        }

        $usage = $driver->getLastUsage();
        $promptTokens = $usage['prompt_tokens'] ?? 0;
        $completionTokens = $usage['completion_tokens'] ?? 0;
        $totalTokens = $usage['total_tokens'] ?? 0;

        $cost = $this->estimateCost($driver->getProviderName(), $driver->getModelName(), $promptTokens, $completionTokens);

        AiUsageLog::create([
            'organization_id' => $this->organization->id,
            'website_id' => $this->website?->id,
            'user_id' => $this->userId,
            'provider' => $driver->getProviderName(),
            'model' => $driver->getModelName(),
            'feature' => $feature,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens,
            'estimated_cost' => $cost,
            'duration_ms' => $durationMs,
            'status' => $status,
            'error_message' => $errorMessage ? SecurityPromptWrapper::scrubSecrets($errorMessage) : null,
        ]);
    }

    protected function estimateCost(string $provider, string $model, int $promptTokens, int $completionTokens): float
    {
        if ($provider === 'deterministic_studio') {
            return 0.0;
        }

        if ($provider === 'gemini') {
            // $0.10 per 1M prompt, $0.40 per 1M completion
            $promptCost = ($promptTokens / 1_000_000) * 0.10;
            $compCost = ($completionTokens / 1_000_000) * 0.40;
            return round($promptCost + $compCost, 6);
        }

        if ($provider === 'openai') {
            // $0.15 per 1M prompt, $0.60 per 1M completion
            $promptCost = ($promptTokens / 1_000_000) * 0.15;
            $compCost = ($completionTokens / 1_000_000) * 0.60;
            return round($promptCost + $compCost, 6);
        }

        return 0.0;
    }
}
