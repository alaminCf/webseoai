<?php

namespace App\Console\Commands;

use App\Jobs\RunKeywordAutomationJob;
use App\Models\KiAutomationWorkflow;
use Illuminate\Console\Command;

class RunScheduledKeywordAutomations extends Command
{
    protected $signature = 'keywords:run-scheduled-automations {--sync : Run due workflows synchronously instead of queueing}';

    protected $description = 'Dispatch due active Keyword Intelligence automation workflows.';

    public function handle(): int
    {
        $dueWorkflows = KiAutomationWorkflow::where('status', KiAutomationWorkflow::STATUS_ACTIVE)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->orderBy('next_run_at')
            ->limit(50)
            ->get();

        if ($dueWorkflows->isEmpty()) {
            $this->info('No scheduled keyword automation workflows are currently due.');
            return self::SUCCESS;
        }

        $dispatched = 0;
        foreach ($dueWorkflows as $workflow) {
            // Advance next_run_at optimistically to prevent double-dispatch on overlapping ticks
            $workflow->update(['next_run_at' => $workflow->computeNextRunAt(now())]);

            if ($this->option('sync')) {
                RunKeywordAutomationJob::dispatchSync($workflow->id, 'scheduled');
            } else {
                RunKeywordAutomationJob::dispatch($workflow->id, 'scheduled');
            }
            $dispatched++;
        }

        $this->info("Dispatched {$dispatched} scheduled keyword automation workflow(s).");
        return self::SUCCESS;
    }
}
