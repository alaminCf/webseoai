<?php

namespace App\Domain\AI\Contracts;

interface AiProviderInterface
{
    /**
     * Generate structured JSON output strictly conforming to a JSON schema.
     *
     * @param string $systemPrompt
     * @param string $userPrompt
     * @param array $jsonSchema
     * @param array $options (temperature, max_tokens, etc.)
     * @return array Decoded associative array matching the requested schema
     */
    public function generateStructured(string $systemPrompt, string $userPrompt, array $jsonSchema, array $options = []): array;

    /**
     * Generate freeform text output.
     *
     * @param string $systemPrompt
     * @param string $userPrompt
     * @param array $options
     * @return string
     */
    public function generateText(string $systemPrompt, string $userPrompt, array $options = []): string;

    /**
     * Return provider identifier (e.g. 'gemini', 'openai', 'deterministic_studio').
     */
    public function getProviderName(): string;

    /**
     * Return model name.
     */
    public function getModelName(): string;

    /**
     * Get token usage metrics from the last generation run.
     *
     * @return array [prompt_tokens => int, completion_tokens => int, total_tokens => int]
     */
    public function getLastUsage(): array;
}
