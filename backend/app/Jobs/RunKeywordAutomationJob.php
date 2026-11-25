<?php

namespace App\Jobs;

use App\Domain\KeywordIntelligence\Services\KeywordAutomationService;
use App\Models\KiAutomationWorkflow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RunKeywordAutomationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 180;

    /**
     * Exponential retry backoff in seconds.
     *
     * @var array<int, int>
     */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public int $workflowId,
        public string $triggerType = 'scheduled',
        public array $options = []
    ) {
    }

    public function handle(KeywordAutomationService $automationService): void
    {
        $workflow = KiAutomationWorkflow::with('website')->find($this->workflowId);
        if (!$workflow || !$workflow->website) {
            return;
        }

        if ($this->triggerType === 'scheduled' && $workflow->status !== KiAutomationWorkflow::STATUS_ACTIVE) {
            return;
        }

        $automationService->executeWorkflow($workflow, $this->triggerType, $this->options);
    }

    public function failed(?Throwable $exception): void
    {
        $workflow = KiAutomationWorkflow::find($this->workflowId);
        if ($workflow && $exception) {
            $workflow->update([
                'last_error' => mb_substr($exception->getMessage(), 0, 1000),
            ]);
        }
    }
}
