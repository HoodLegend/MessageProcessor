<?php

namespace App\Console\Commands;

use App\Services\MessageProcessorService;
use Carbon\Carbon;
use File;
use Illuminate\Console\Command;
use Log;

class CleanupExpiredMessages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'messages:cleanup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up expired messages and log files (older than 1 week)';


    public function handle()
{
    $deleted = 0;
    $logDir = storage_path('logs');

    // Recursively get all files in logs directory (including subfolders)
    $files = \File::allFiles($logDir);

    if (empty($files)) {
        $this->info("No log files found.");
        return;
    }

    foreach ($files as $file) {
        $filename = $file->getFilename();

        // Skip the main Laravel log file
        if ($filename === 'laravel.log') {
            $this->info("⏩ Skipping: $filename");
            continue;
        }

        try {
            File::delete($file->getRealPath());
            $this->info("✅ Deleted: {$file->getPathname()}");
            $deleted++;
        } catch (\Exception $e) {
            $this->error("❌ Could not delete {$file->getPathname()}: " . $e->getMessage());
        }
    }

    $this->info("🧹 Cleanup complete. Total deleted: $deleted file(s).");
}


}
