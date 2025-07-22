<?php

namespace App\Console\Commands;

use App\Services\MessageDecoderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class ProcessReceiptFiles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'receipts:process';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process new .DAT receipt files and store messages in Redis';


     /**
     * Directory to monitor for new files
     */
    protected string $downloadDirectory = '/var/www/nam/ReceiptItClient/download';

    protected MessageDecoderService $messageDecoder;

        public function __construct(MessageDecoderService $messageDecoder)
    {
        parent::__construct();
        $this->messageDecoder = $messageDecoder;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
                $this->info('Starting receipt file processing...');

        try {
            // Check if directory exists
            if (!File::exists($this->downloadDirectory)) {
                $this->error("Directory does not exist: {$this->downloadDirectory}");
                return 1;
            }

            // Get all .DAT files
            $datFiles = File::glob($this->downloadDirectory . '/*.DAT');

            if (empty($datFiles)) {
                $this->info('No .DAT files found to process.');
                return 0;
            }

            $this->info('Found ' . count($datFiles) . ' .DAT file(s) to process.');

            $processedFiles = 0;
            $totalMessages = 0;

            foreach ($datFiles as $filePath) {
                $fileName = basename($filePath);

                // Check if file has already been processed
                if ($this->isFileProcessed($fileName)) {
                    $this->line("Skipping already processed file: {$fileName}");
                    continue;
                }

                $this->info("Processing file: {$fileName}");

                $messagesProcessed = $this->processFile($filePath);

                if ($messagesProcessed > 0) {
                    $this->markFileAsProcessed($fileName);
                    $processedFiles++;
                    $totalMessages += $messagesProcessed;

                    $this->info("Processed {$messagesProcessed} messages from {$fileName}");

                    // Optional: Move processed file to archive directory
                    $this->archiveFile($filePath);
                }
            }

            $this->info("Processing complete. Processed {$processedFiles} files with {$totalMessages} total messages.");

            Log::info('Receipt files processing completed', [
                'files_processed' => $processedFiles,
                'total_messages' => $totalMessages
            ]);

        } catch (\Exception $e) {
            $this->error('Error processing receipt files: ' . $e->getMessage());
            Log::error('Receipt files processing error: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    /**
     * Process a single .DAT file
     */
    protected function processFile(string $filePath): int
    {
        try {
            $content = File::get($filePath);

            // Split content into lines and filter out empty lines
            $lines = array_filter(
                explode("\n", $content),
                fn($line) => !empty(trim($line))
            );

            $messagesProcessed = 0;

            foreach ($lines as $line) {
                $message = trim($line);

                // Skip if line doesn't look like a message (basic validation)
                if (strlen($message) < 20) {
                    continue;
                }

                // Process the message
                $decodedMessage = $this->messageDecoder->processMessage($message);

                if (!isset($decodedMessage['error'])) {
                    $messagesProcessed++;

                    // Optional: Display progress for large files
                    if ($messagesProcessed % 100 == 0) {
                        $this->line("Processed {$messagesProcessed} messages...");
                    }
                }
            }

            return $messagesProcessed;

        } catch (\Exception $e) {
            $this->error("Error processing file {$filePath}: " . $e->getMessage());
            Log::error("Error processing file {$filePath}: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Check if file has already been processed
     */
    protected function isFileProcessed(string $fileName): bool
    {
        return Redis::sismember('processed_receipt_files', $fileName);
    }

    /**
     * Mark file as processed
     */
    protected function markFileAsProcessed(string $fileName): void
    {
        Redis::sadd('processed_receipt_files', $fileName);
    }

    /**
     * Archive processed file
     */
    protected function archiveFile(string $filePath): void
    {
        try {
            $archiveDirectory = $this->downloadDirectory . '/processed';

            // Create archive directory if it doesn't exist
            if (!File::exists($archiveDirectory)) {
                File::makeDirectory($archiveDirectory, 0755, true);
            }

            $fileName = basename($filePath);
            $archivePath = $archiveDirectory . '/' . date('Y-m-d_H-i-s') . '_' . $fileName;

            File::move($filePath, $archivePath);

            $this->line("Archived file to: {$archivePath}");

        } catch (\Exception $e) {
            $this->error("Error archiving file {$filePath}: " . $e->getMessage());
            Log::error("Error archiving file {$filePath}: " . $e->getMessage());
        }
    }
}
