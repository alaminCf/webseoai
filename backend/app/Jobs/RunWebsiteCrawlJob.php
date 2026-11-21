<?php

namespace App\Jobs;

use App\Domain\Crawler\CrawlEngine;
use App\Models\CrawlJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RunWebsiteCrawlJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 1;

    public function __construct(
        public int $crawlJobId,
        public bool $allowLocalhost = false
    ) {}

    public function handle(): void
    {
        $crawlJob = CrawlJob::with('website')->find($this->crawlJobId);
        if (!$crawlJob || $crawlJob->status === CrawlJob::STATUS_CANCELLED) {
            return;
        }

        $engine = new CrawlEngine($crawlJob, $this->allowLocalhost);
        $engine->run();
    }

    public function failed(Throwable $exception): void
    {
        $crawlJob = CrawlJob::find($this->crawlJobId);
        if ($crawlJob && in_array($crawlJob->status, [CrawlJob::STATUS_QUEUED, CrawlJob::STATUS_CRAWLING], true)) {
            $crawlJob->update([
                'status' => CrawlJob::STATUS_FAILED,
                'finished_at' => now(),
                'error_message' => $exception->getMessage(),
            ]);
            $crawlJob->logStep('Background crawl worker failed', [
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
