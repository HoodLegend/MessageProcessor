<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessDataBatch implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public array $batch, public string $directory)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        app(\App\Console\Commands\ParseDatFilesCommand::class)
            ->processBatch($this->batch, $this->directory);
    }
}
