<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class ProcessScheduledMessages extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'file:process-local {file_path? : Optional path to the input file}';

    /**
     * The description of the console command.
     */
    protected $description = 'Read data from local input file, store in Redis, and manage log files';

    /**
     * Path to the input file
     */
    private $inputFilePath;

    /**
     * Path to the logs directory
     */
    private $logsPath;

    public function __construct()
    {
        parent::__construct();
        $this->inputFilePath = storage_path('app/private/uploads/messages/2lOJrnCTdEBlMg1Iv85ANmCV9S5I3uPZcmmx4k0U.txt'); // or .json depending on your file
        $this->logsPath = storage_path('logs/message_logs/file_processing');
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            // Ensure logs directory exists
            if (!is_dir($this->logsPath)) {
                mkdir($this->logsPath, 0755, true);
            }

            // Clean up old log files (older than 1 week)
            $this->cleanupOldLogs();

            // Check if input file exists
            if (!file_exists($this->inputFilePath)) {
                $this->error("Input file not found: {$this->inputFilePath}");
                $this->logMessage("ERROR: Input file not found: {$this->inputFilePath}");
                return 1;
            }

            // Read data from the input file
            $fileData = file_get_contents($this->inputFilePath);

            if ($fileData === false) {
                $this->error('Failed to read data from the input file.');
                $this->logMessage('ERROR: Failed to read data from the input file.');
                return 1;
            }

            // Try to decode as JSON if it's JSON data
            $processedData = $this->processFileData($fileData);

            if ($processedData === null) {
                $this->error('Failed to process file data.');
                $this->logMessage('ERROR: Failed to process file data.');
                return 1;
            }

            // Store the data in Redis
            $redis = Redis::connection();
            $redis->setex('local-file-data', 600, json_encode($processedData)); // 10 minutes TTL

            $message = 'Local file data processed and stored in Redis successfully.';
            $this->info($message);
            $this->logMessage("SUCCESS: {$message}");

            // Log the data details
            $dataSize = strlen($fileData);
            $this->logMessage("Data size: {$dataSize} bytes");

            if (is_array($processedData)) {
                if (isset($processedData['transactions'])) {
                    $totalRecords = $processedData['total_lines'] ?? 0;
                    $parsedRecords = $processedData['parsed_records'] ?? 0;
                    $failedRecords = $processedData['failed_records'] ?? 0;

                    $this->logMessage("Total lines: {$totalRecords}");
                    $this->logMessage("Successfully parsed: {$parsedRecords}");
                    $this->logMessage("Failed to parse: {$failedRecords}");

                    $this->info("Processed {$parsedRecords} out of {$totalRecords} transaction records");

                    if ($failedRecords > 0) {
                        $this->warn("Warning: {$failedRecords} records failed to parse");
                    }
                } else {
                    $recordCount = count($processedData);
                    $this->logMessage("Records processed: {$recordCount}");
                }
            }

        } catch (\Exception $e) {
            $errorMessage = "Error processing file: " . $e->getMessage();
            $this->error($errorMessage);
            $this->logMessage("ERROR: {$errorMessage}");
            return 1;
        }

        return 0;
    }

    /**
     * Process the file data (parse transaction records)
     */
    private function processFileData($fileData)
    {
        // Try to decode as JSON first
        $jsonData = json_decode($fileData, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            // It's valid JSON
            return $jsonData;
        }

        // Parse as transaction records
        $lines = explode("\n", trim($fileData));
        $transactions = [];

        foreach ($lines as $lineNumber => $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            try {
                $parsedRecord = $this->parseTransactionRecord($line);
                if ($parsedRecord) {
                    $parsedRecord['line_number'] = $lineNumber + 1;
                    $parsedRecord['raw_data'] = $line;
                    $transactions[] = $parsedRecord;
                }
            } catch (\Exception $e) {
                $this->logMessage("ERROR parsing line " . ($lineNumber + 1) . ": " . $e->getMessage());
                // Still include the raw line for debugging
                $transactions[] = [
                    'line_number' => $lineNumber + 1,
                    'raw_data' => $line,
                    'parse_error' => $e->getMessage(),
                    'parsed' => false
                ];
            }
        }

        $processedData = [
            'type' => 'transaction_records',
            'processed_at' => Carbon::now()->toISOString(),
            'total_lines' => count($lines),
            'parsed_records' => count(array_filter($transactions, function($t) {
                return !isset($t['parse_error']);
            })),
            'failed_records' => count(array_filter($transactions, function($t) {
                return isset($t['parse_error']);
            })),
            'transactions' => $transactions
        ];

        return $processedData;
    }

    /**
     * Parse a single transaction record based on fixed-width format
     */
    private function parseTransactionRecord($line)
    {
        if (strlen($line) < 50) {
            throw new \Exception("Line too short to be a valid transaction record");
        }

        // Based on your example, here's the field mapping
        // Adjust positions based on your actual format
        $record = [
            'message_id' => trim(substr($line, 0, 20)),           // CORR0JNMSITD8GS00000
            'account_number' => trim(substr($line, 20, 20)),      // 000000012345678901
            'message_type' => trim(substr($line, 40, 10)),        // CRC
            'description' => trim(substr($line, 50, 30)),         // RECEIPTS
            'transaction_type' => trim(substr($line, 80, 10)),    // ELRC
            'receipt_type' => trim(substr($line, 90, 30)),        // ELECTRONIC RECEIPT
            'status' => trim(substr($line, 120, 20)),             // AUTH CANCELLED
            'parsed' => true
        ];

        // Try to extract additional fields if line is long enough
        if (strlen($line) > 200) {
            $record['date'] = trim(substr($line, 160, 8));        // 20100817
            $record['amount'] = trim(substr($line, 180, 15));     // Amount field
            $record['reference'] = trim(substr($line, 195, 20));  // Reference number
            $record['time'] = trim(substr($line, 215, 6));        // Time
            $record['channel'] = trim(substr($line, 230, 20));    // INTERNET
            $record['terminal'] = trim(substr($line, 250, 10));   // Terminal info
        }

        // Clean up empty fields
        $record = array_filter($record, function($value) {
            return $value !== '' && $value !== null;
        });

        return $record;
    }

    /**
     * Create a log entry with timestamp
     */
    private function logMessage($message)
    {
        $timestamp = Carbon::now()->format('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] {$message}" . PHP_EOL;

        // Create a daily log file
        $logFileName = 'file_processing_' . Carbon::now()->format('Y-m-d') . '.log';
        $logFilePath = $this->logsPath . '/' . $logFileName;

        file_put_contents($logFilePath, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Clean up log files older than 1 week
     */
    private function cleanupOldLogs()
    {
        $cutoffDate = Carbon::now()->subWeek();

        if (!is_dir($this->logsPath)) {
            return;
        }

        $files = glob($this->logsPath . '/file_processing_*.log');
        $deletedCount = 0;

        foreach ($files as $file) {
            $fileDate = Carbon::createFromTimestamp(filemtime($file));

            if ($fileDate->lt($cutoffDate)) {
                if (unlink($file)) {
                    $deletedCount++;
                }
            }
        }

        if ($deletedCount > 0) {
            $this->logMessage("Cleaned up {$deletedCount} old log files");
        }
    }
}
