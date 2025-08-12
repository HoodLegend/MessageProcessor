<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Http;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

class SendDataToServerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'files:send-to-accounting
                            {--batch-size=15 : Number of files to send per batch}
                            {--max-runtime=120 : Maximum runtime in seconds}
                            {--retry-failed : Retry previously failed transmissions}
                            {--max-retries=3 : Maximum retry attempts per file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send parsed CSV files to accounting software';


    /**
     * Execute the console command.
     */
    public function handle()
    {
        $batchSize = (int) $this->option('batch-size');
        $maxRuntime = (int) $this->option('max-runtime');
        $retryFailed = $this->option('retry-failed');
        $maxRetries = (int) $this->option('max-retries');
        $startTime = time();

        $stats = [
            'files_sent' => 0,
            'files_failed' => 0,
            'total_requests' => 0,
            'retry_attempts' => 0,
            'total_records' => 0,
            'errors' => []
        ];

        $this->info("Starting transmission batch: max {$batchSize} files, {$maxRuntime}s limit");

        // Ensure directories exist
        $this->ensureDirectoriesExist();

        while (time() - $startTime < $maxRuntime) {
            $pendingFiles = $this->getPendingTransmissions($batchSize, $retryFailed, $maxRetries);

            if ($pendingFiles->isEmpty()) {
                $this->info("No pending transmissions found");
                break;
            }

            foreach ($pendingFiles as $fileInfo) {
                if (time() - $startTime >= $maxRuntime) {
                    $this->info("Runtime limit reached, stopping gracefully");
                    break 2;
                }

                try {
                    $filename = $fileInfo['filename'];
                    $isRetry = $fileInfo['is_retry'] ?? false;

                    if ($isRetry) {
                        $stats['retry_attempts']++;
                        $this->line("↻ Retrying: {$filename}");
                    }

                    $result = $this->processTransmission($filename);
                    $stats['total_requests']++;

                    if ($result['success']) {
                        $stats['files_sent']++;
                        $stats['total_records'] += $result['record_count'] ?? 0;
                        $this->markTransmissionComplete($filename);
                        $this->info("✓ Sent: {$filename} ({$result['record_count']} records)");
                    } else {
                        $stats['files_failed']++;
                        $this->markTransmissionFailed($filename, $result['error']);
                        $this->error("✗ Failed: {$filename} - " . substr($result['error'], 0, 100));
                        $stats['errors'][] = "{$filename}: {$result['error']}";
                    }

                } catch (\Exception $e) {
                    $stats['files_failed']++;
                    $this->markTransmissionFailed($filename ?? 'unknown', $e->getMessage());
                    \Log::error("Transmission exception: " . $e->getMessage());
                    $stats['errors'][] = "Exception: {$e->getMessage()}";
                }

                // Brief pause between requests to avoid overwhelming the server
                usleep(750000); // 0.75 second pause
            }

            // Short break between batches
            if (time() - $startTime < $maxRuntime - 5) {
                sleep(1);
            }
        }

        $this->outputSummary($stats, time() - $startTime);
    }

    /**
     * Process a single file transmission
     */
    private function processTransmission(string $filename): array
    {
        try {
            // Load CSV content and metadata
            $csvContent = Storage::get("exports/pending_transmission/{$filename}");
            if (!$csvContent) {
                return ['success' => false, 'error' => 'CSV file not found'];
            }

            // Parse CSV to get records for metadata
            $records = $this->parseCsvForMetadata($csvContent);

            if ($records->isEmpty()) {
                return ['success' => false, 'error' => 'No valid records in CSV'];
            }

            // Send to accounting software using your existing method
            $result = $this->sendToAccountingSoftware($csvContent, $filename, $records);

            // Add record count to result
            $result['record_count'] = $records->count();

            return $result;

        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function sendToAccountingSoftware(string $csvContent, string $filename, Collection $records): array
    {
        $transmissionTime = now();
        $transactionDate = $records->first()['transaction_date'] ?? $transmissionTime->format('Y-m-d');

        try {
            $accountingUrl = config('accounting.endpoint_url', 'https://www.castlebet.darth.bond/api/fnb53nmb');

            // Prepare the data payload
            $payload = [
                'filename' => $filename,
                'date' => $transactionDate,
                'record_count' => $records->count(),
                'csv_data' => $csvContent,
                'metadata' => [
                    'source' => 'transaction_processor',
                    'generated_at' => $transmissionTime->toISOString(),
                    'total_amount' => $records->sum('amount') ?? 0,
                ]
            ];

            // Send HTTP request with timeout and retry logic
            $response = Http::timeout(45) // Increased timeout for production
                ->retry(3, 2000) // Retry 3 times with 2 second delay
                ->post($accountingUrl, $payload);

            if ($response->successful()) {
                $responseData = $response->json();

                // Create detailed log entry for success
                $logEntry = $this->createLogEntry([
                    'status' => 'SUCCESS',
                    'filename' => $filename,
                    'transaction_date' => $transactionDate,
                    'transmission_time' => $transmissionTime,
                    'record_count' => $records->count(),
                    'total_amount' => $records->sum('amount') ?? 0,
                    'endpoint_url' => $accountingUrl,
                    'response_data' => $responseData,
                    'response_status' => $response->status(),
                    'response_time' => $response->transferStats->getTransferTime() ?? 0,
                ]);

                // Save to date-specific log file
                $this->saveTransmissionLog($transactionDate, $logEntry);

                return [
                    'success' => true,
                    'response' => $responseData
                ];

            } else {
                $errorMessage = "HTTP {$response->status()}: " . $response->body();

                // Create detailed log entry for failure
                $logEntry = $this->createLogEntry([
                    'status' => 'FAILED',
                    'filename' => $filename,
                    'transaction_date' => $transactionDate,
                    'transmission_time' => $transmissionTime,
                    'record_count' => $records->count(),
                    'total_amount' => $records->sum('amount') ?? 0,
                    'endpoint_url' => $accountingUrl,
                    'error_message' => $errorMessage,
                    'response_status' => $response->status(),
                    'response_body' => substr($response->body(), 0, 500), // Limit log size
                ]);

                $this->saveTransmissionLog($transactionDate, $logEntry);

                return [
                    'success' => false,
                    'error' => $errorMessage
                ];
            }

        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();

            // Create detailed log entry for exception
            $logEntry = $this->createLogEntry([
                'status' => 'ERROR',
                'filename' => $filename,
                'transaction_date' => $transactionDate,
                'transmission_time' => $transmissionTime,
                'record_count' => $records->count(),
                'total_amount' => $records->sum('amount') ?? 0,
                'endpoint_url' => $accountingUrl ?? 'N/A',
                'error_message' => $errorMessage,
                'exception_class' => get_class($e),
            ]);

            $this->saveTransmissionLog($transactionDate, $logEntry);

            return [
                'success' => false,
                'error' => $errorMessage
            ];
        }
    }

    /**
     * Parse CSV content to extract metadata for transmission
     */
    private function parseCsvForMetadata(string $csvContent): Collection
    {
        $lines = explode("\n", trim($csvContent));
        $records = collect();

        // Skip header line
        $dataLines = array_slice($lines, 1);

        foreach ($dataLines as $line) {
            $line = trim($line);
            if (empty($line))
                continue;

            $row = str_getcsv($line);
            if (count($row) >= 5) {
                $records->push([
                    'transaction_date' => $row[0] ?? '',
                    'transaction_time' => $row[1] ?? '',
                    'amount' => (float) ($row[2] ?? 0),
                    'mobile_number' => $row[3] ?? '',
                    'transaction_id' => $row[4] ?? '',
                ]);
            }
        }

        return $records;
    }

    /**
     * Get pending transmissions with retry logic
     */
    private function getPendingTransmissions(int $limit, bool $includeRetries, int $maxRetries): Collection
    {
        $files = collect();

        // Get new pending files
        $pendingFiles = collect(Storage::files('transmission_queue'))
            ->filter(fn($file) => str_ends_with($file, '.pending'))
            ->map(fn($file) => [
                'filename' => str_replace(['transmission_queue/', '.pending'], '', $file),
                'is_retry' => false,
                'priority' => 1
            ]);

        $files = $files->merge($pendingFiles);

        // Get retry candidates if requested
        if ($includeRetries) {
            $retryFiles = collect(Storage::files('transmission_queue'))
                ->filter(function ($file) use ($maxRetries) {
                    if (!str_ends_with($file, '.failed'))
                        return false;

                    // Check retry count
                    $content = Storage::get($file);
                    $data = json_decode($content, true);
                    if (($data['retry_count'] ?? 0) >= $maxRetries)
                        return false;

                    // Check if enough time has passed (exponential backoff)
                    $failedAt = Carbon::parse($data['failed_at'] ?? now());
                    $retryCount = $data['retry_count'] ?? 0;
                    $backoffMinutes = min(60, pow(2, $retryCount) * 5); // 5, 10, 20, 40, 60 minutes max

                    return $failedAt->addMinutes($backoffMinutes)->isPast();
                })
                ->map(fn($file) => [
                    'filename' => str_replace(['transmission_queue/', '.failed'], '', $file),
                    'is_retry' => true,
                    'priority' => 2
                ]);

            $files = $files->merge($retryFiles);
        }

        // Sort by priority (new files first, then retries) and limit
        return $files->sortBy('priority')->take($limit);
    }

    /**
     * Mark transmission as completed
     */
    private function markTransmissionComplete(string $filename): void
    {
        // Remove from queues
        Storage::delete("transmission_queue/{$filename}.pending");
        Storage::delete("transmission_queue/{$filename}.failed");
        Storage::delete("exports/pending_transmission/{$filename}");

        // Mark as completed with timestamp
        $completedPath = "transmission_queue/{$filename}.completed";
        Storage::put($completedPath, json_encode([
            'completed_at' => now()->toISOString(),
            'processed_by' => 'send-to-accounting-command'
        ]));
    }

    /**
     * Mark transmission as failed with retry logic
     */
    private function markTransmissionFailed(string $filename, string $error): void
    {
        // Remove pending status
        Storage::delete("transmission_queue/{$filename}.pending");

        // Get current retry count
        $failedPath = "transmission_queue/{$filename}.failed";
        $retryCount = 0;

        if (Storage::exists($failedPath)) {
            $existingData = json_decode(Storage::get($failedPath), true);
            $retryCount = ($existingData['retry_count'] ?? 0) + 1;
        }

        // Save failure info
        $failureData = [
            'failed_at' => now()->toISOString(),
            'error' => $error,
            'retry_count' => $retryCount,
            'last_attempt' => now()->toISOString()
        ];

        Storage::put($failedPath, json_encode($failureData));

        \Log::warning("Transmission failed for {$filename} (attempt {$retryCount}): {$error}");
    }

    /**
     * Create standardized log entry
     */
    private function createLogEntry(array $data): string
    {
        $entry = [
            'timestamp' => now()->toISOString(),
            'command' => 'send-to-accounting',
            'data' => $data
        ];

        return json_encode($entry, JSON_PRETTY_PRINT);
    }

    /**
     * Save transmission log to date-specific file
     */
    private function saveTransmissionLog(string $transactionDate, string $logEntry): void
    {
        try {
            $logDirectory = 'transmission_to_server_logs';

            // Ensure the directory exists
            if (!Storage::exists($logDirectory)) {
                Storage::makeDirectory($logDirectory);
            }

            // Use current date for log file name
            $today = now()->format('Y-m-d');
            $logFilename = "transmission_log_{$today}.log";
            $logFilePath = "{$logDirectory}/{$logFilename}";

            // Prepend timestamp to log entry
            $timestamp = now()->format('Y-m-d H:i:s');
            $formattedEntry = "[{$timestamp}] Transaction Date: {$transactionDate}\n";
            $formattedEntry .= $logEntry . "\n";

            // Append the log entry
            Storage::append($logFilePath, $formattedEntry);

            // Optional: log to Laravel logs (info level)
            \Log::info("Transmission log saved", [
                'file' => $logFilePath,
                'transaction_date' => $transactionDate
            ]);

        } catch (\Exception $e) {
            \Log::error("✗ Failed to save transmission log", [
                'transaction_date' => $transactionDate,
                'error' => $e->getMessage()
            ]);
        }
    }


    /**
     * Ensure required directories exist
     */
    private function ensureDirectoriesExist(): void
    {
        $directories = [
            'transmission_queue',
            'transmission_logs',
            'exports/pending_transmission'
        ];

        foreach ($directories as $dir) {
            if (!Storage::exists($dir)) {
                Storage::makeDirectory($dir);
            }
        }
    }

    /**
     * Output comprehensive summary
     */
    private function outputSummary(array $stats, int $runtime): void
    {
        $this->info("\n" . str_repeat('=', 60));
        $this->info("TRANSMISSION SUMMARY");
        $this->info(str_repeat('=', 60));
        $this->info("Runtime: {$runtime}s");
        $this->info("Files sent successfully: {$stats['files_sent']}");
        $this->info("Files failed: {$stats['files_failed']}");
        $this->info("Total HTTP requests: {$stats['total_requests']}");
        $this->info("Retry attempts: {$stats['retry_attempts']}");
        $this->info("Total records transmitted: {$stats['total_records']}");

        if (!empty($stats['errors'])) {
            $errorCount = count($stats['errors']);
            $this->error("\nErrors encountered ({$errorCount}):");
            foreach (array_slice($stats['errors'], 0, 5) as $error) {
                $this->error("  • " . $error);
            }
            if ($errorCount > 5) {
                $this->error("  ... and " . ($errorCount - 5) . " more errors (check logs)");
            }
        }

        // Show pending queue status
        $pendingCount = count(Storage::files('transmission_queue/*.pending'));
        $failedCount = count(Storage::files('transmission_queue/*.failed'));

        if ($pendingCount > 0 || $failedCount > 0) {
            $this->info("\nQueue Status:");
            $this->info("  Pending: {$pendingCount} files");
            $this->info("  Failed: {$failedCount} files");
        }
    }


}
