<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class DownloadDatFiles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'files:move-dat {--copy : Copy files instead of moving them}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Move .DAT files from /var/www/fnb/nam/ReceitptItClient/download/ to Laravel storage';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $sourcePath = '/var/www/fnb/nam/ReceitptItClient/download/';
        $copyMode = $this->option('copy');

        // Check if source directory exists
        if (!File::exists($sourcePath)) {
            $this->error("Source directory does not exist: {$sourcePath}");
            return Command::FAILURE;
        }

        // Get all .DAT files from the source directory
        $datFiles = File::glob($sourcePath . '*.DAT');

        if (empty($datFiles)) {
            $this->info('No .DAT files found in the source directory.');
            return Command::SUCCESS;
        }

        $this->info("Found " . count($datFiles) . " .DAT file(s)");

        // Create a subdirectory in storage for DAT files
        $storageSubDir = 'dat_files';
        Storage::makeDirectory($storageSubDir);

        $successCount = 0;
        $failCount = 0;

        foreach ($datFiles as $filePath) {
            try {
                $fileName = basename($filePath);
                $destinationPath = $storageSubDir . '/' . $fileName;

                // Read file content
                $fileContent = File::get($filePath);

                // Store in Laravel storage
                if (Storage::put($destinationPath, $fileContent)) {
                    $successCount++;
                    $this->line("✓ Processed: {$fileName}");

                    // Remove original file if not in copy mode
                    if (!$copyMode) {
                        File::delete($filePath);
                        $this->line("  └─ Original file removed");
                    }
                } else {
                    $failCount++;
                    $this->error("✗ Failed to store: {$fileName}");
                }

            } catch (\Exception $e) {
                $failCount++;
                $this->error("✗ Error processing {$fileName}: " . $e->getMessage());
            }
        }

        // Summary
        $operation = $copyMode ? 'copied' : 'moved';
        $this->info("\nSummary:");
        $this->info("Successfully {$operation}: {$successCount} files");

        if ($failCount > 0) {
            $this->error("Failed: {$failCount} files");
            return Command::FAILURE;
        }

        $this->info("All files processed successfully!");
        $this->info("Files stored in: " . Storage::path($storageSubDir));

        return Command::SUCCESS;

    }
}
