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
    protected $signature = 'files:move-dat {--copy : Copy files instead of moving them} {--batch-size=100 : Number of files to process per batch}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Move .DAT files from /var/www/fnb/nam/ReceiptItClient/download/ to Laravel storage';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Updated source path to messages folder
    $sourcePath = '/var/www/fnb/nam/ReceiptItClient/messages/';
    $copyMode = $this->option('copy');
    $batchSize = $this->option('batch-size', 100); // Process files in batches

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

    $totalFiles = count($datFiles);
    $this->info("Found {$totalFiles} .DAT file(s) in messages folder");

    // Show warning for large number of files
    if ($totalFiles > 1000) {
        $this->warn("Large number of files detected ({$totalFiles}). This may take some time.");
        if (!$this->confirm('Do you want to continue?')) {
            return Command::SUCCESS;
        }
    }

    // Create a subdirectory in storage for DAT files
    $storageSubDir = 'dat_files';
    Storage::makeDirectory($storageSubDir);

    $successCount = 0;
    $failCount = 0;
    $processedCount = 0;

    // Process files in batches to avoid memory issues
    $batches = array_chunk($datFiles, $batchSize);
    $totalBatches = count($batches);

    $this->info("Processing files in {$totalBatches} batch(es) of {$batchSize} files each...");

    foreach ($batches as $batchIndex => $fileBatch) {
        $currentBatch = $batchIndex + 1;
        $this->info("Processing batch {$currentBatch}/{$totalBatches}...");

        foreach ($fileBatch as $filePath) {
            try {
                $fileName = basename($filePath);
                $destinationPath = $storageSubDir . '/' . $fileName;

                // Check if file already exists in storage
                if (Storage::exists($destinationPath)) {
                    $this->line("Skipped (already exists): {$fileName}");
                    $processedCount++;
                    continue;
                }

                // Read file content
                $fileContent = File::get($filePath);

                // Validate file content (basic check)
                if (empty($fileContent)) {
                    $this->warn("Skipped (empty file): {$fileName}");
                    continue;
                }

                // Store in Laravel storage
                if (Storage::put($destinationPath, $fileContent)) {
                    $successCount++;
                    $this->line("Processed: {$fileName}");

                    // Remove original file if not in copy mode
                    if (!$copyMode) {
                        File::delete($filePath);
                        $this->line("  └─ Original file removed");
                    }
                } else {
                    $failCount++;
                    $this->error("✗ Failed to store: {$fileName}");
                }

                $processedCount++;

                // Show progress every 50 files
                if ($processedCount % 50 === 0) {
                    $this->info("Progress: {$processedCount}/{$totalFiles} files processed");
                }

            } catch (\Exception $e) {
                $failCount++;
                $this->error("✗ Error processing {$fileName}: " . $e->getMessage());
                $processedCount++;
            }
        }

        // Small delay between batches to prevent overwhelming the system
        if ($currentBatch < $totalBatches) {
            usleep(100000); // 0.1 second delay
        }
    }

    // Summary
    $operation = $copyMode ? 'copied' : 'moved';
    $this->info("\n" . str_repeat('=', 50));
    $this->info("PROCESSING SUMMARY");
    $this->info(str_repeat('=', 50));
    $this->info("Total files found: {$totalFiles}");
    $this->info("Successfully {$operation}: {$successCount} files");
    $this->info("Failed: {$failCount} files");
    $this->info("Skipped (already exist): " . ($processedCount - $successCount - $failCount));

    if ($failCount > 0) {
        $this->error("Some files failed to process. Check the errors above.");
        return Command::FAILURE;
    }

    $this->info("All files processed successfully!");
    $this->info("Files stored in: " . Storage::path($storageSubDir));

    // Show disk usage if many files were processed
    if ($successCount > 100) {
        $totalSize = 0;
        foreach (Storage::files($storageSubDir) as $file) {
            $totalSize += Storage::size($file);
        }
        $this->info("Total storage used: " . $this->formatBytes($totalSize));
    }

    return Command::SUCCESS;

    }

    // Helper method to format bytes
private function formatBytes($bytes, $precision = 2)
{
    $units = array('B', 'KB', 'MB', 'GB', 'TB');

    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }

    return round($bytes, $precision) . ' ' . $units[$i];
}
}
