<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class ParseDatFilesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'files:parse-dat
        {--output=table : Output format (table, json, csv)}
        {--save : Save results to CSV file in storage}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Parse .DAT files and extract Amount, Date, Mobile Number, and Transaction ID';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $datFilesPath = 'dat_files';
        $outputFormat = $this->option('output');
        $saveResults = $this->option('save');

        if (!Storage::exists($datFilesPath)) {
            $this->error("DAT files directory does not exist: storage/app/{$datFilesPath}");
            $this->info("Please run 'php artisan files:move-dat' first to move DAT files to storage.");
            return Command::FAILURE;
        }

        $datFiles = Storage::files($datFilesPath);
        $datFiles = array_filter($datFiles, function ($file) {
            return pathinfo($file, PATHINFO_EXTENSION) === 'DAT';
        });

        if (empty($datFiles)) {
            $this->info('No .DAT files found in storage.');
            return Command::SUCCESS;
        }

        $this->info("Found " . count($datFiles) . " .DAT file(s) to parse");

        $allResults = collect();
        $totalRecords = 0;

        foreach ($datFiles as $filePath) {
            $fileName = basename($filePath);
            $this->line("Processing: {$fileName}");

            try {
                $content = Storage::get($filePath);
                $results = $this->parseFileContent($content, $fileName);
                $allResults = $allResults->merge($results);
                $totalRecords += count($results);

                $this->info("  └─ Extracted " . count($results) . " records");

            } catch (\Exception $e) {
                $this->error("  └─ Error parsing {$fileName}: " . $e->getMessage());
            }
        }

        if ($allResults->isEmpty()) {
            $this->warn('No transaction records found in any DAT files.');
            return Command::SUCCESS;
        }

        // Display results
        $this->displayResults($allResults, $outputFormat);

        // Save results if requested
        if ($saveResults) {
            $this->saveResults($allResults);
        }

        $this->info("\nSummary: Extracted {$totalRecords} transaction records from " . count($datFiles) . " file(s)");

        return Command::SUCCESS;
    }

    /**
     * Parse the content of a DAT file
     */
    private function parseFileContent(string $content, string $fileName): Collection
    {
        $results = collect();
        $lines = explode("\n", $content);

        foreach ($lines as $lineNumber => $line) {
            $line = trim($line);

            // Skip empty lines
            if (empty($line)) {
                continue;
            }

            // Check if line contains CPY (new requirement)
            if (!str_contains($line, 'CPY')) {
                $this->info("Skipping line " . ($lineNumber + 1) . ": no CPY found");
                continue;
            }

            // Match everything between "AUTH CANCELLED" and the next timestamp pattern (YYYYMMDDHHMMSSSINTERNET)
            if (preg_match('/AUTH\s+CANCELLED\s+(.*?)(\d{4}\d{2}\d{2}\d{2}\d{2}\d{2}\d{2})INTERNET/i', $line, $segmentMatch)) {
                $segment = trim($segmentMatch[1]);
                $fullTimestamp = $segmentMatch[2]; // This captures YYYYMMDDHHMMSSS (14 digits)

                // Extract date (YYYYMMDD) and time (HHMMSSS) from the full timestamp
                $dateFromTimestamp = substr($fullTimestamp, 0, 8); // YYYYMMDD
                $timeFromTimestamp = substr($fullTimestamp, 8, 6); // HHMMSSS (taking first 6 digits for HHMMSS)

                $this->info("Found timestamp: {$fullTimestamp}, Date: {$dateFromTimestamp}, Time: {$timeFromTimestamp}");

                // FIXED: Made mobile number pattern more flexible (9-12 digits instead of exactly 10)
                if (preg_match('/(\d{8})\s*(\d{20})\s*(\d{9,12})/', $segment, $matches)) {
                    $dateRaw = $matches[1];
                    $amountRaw = $matches[2];
                    $mobileRaw = $matches[3];

                    $cleanAmount = ltrim($amountRaw, '0');
                    $amount = number_format(((int) ($cleanAmount ?: '0')) / 100, 2, '.', '');

                    // FIXED: Look for transaction ID in the full line, not just the segment
                    $transactionId = '';
                    if (preg_match('/' . preg_quote($dateRaw, '/') . '([A-Z][A-Z0-9]{4,})/', $line, $tm)) {
                        $transactionId = trim($tm[1]);
                    }

                    $results->push([
                        'transaction_date' => $this->parseDate($dateRaw),
                        'transaction_time' => $this->formatTime($timeFromTimestamp),
                        'amount' => $amount,
                        'mobile_number' => $mobileRaw,
                        'transaction_id' => $transactionId,
                    ]);
                } else {
                    // Enhanced debug to show why it's not matching
                    $this->warn("No transaction match in scoped segment on line " . ($lineNumber + 1) . ": {$segment}");

                    // Try to extract parts individually for debugging
                    if (preg_match('/(\d{8})/', $segment, $dateMatch)) {
                        $this->line("  Found date: " . $dateMatch[1]);
                    }
                    if (preg_match('/(\d{20})/', $segment, $amountMatch)) {
                        $this->line("  Found 20-digit amount: " . $amountMatch[1]);
                    }
                    if (preg_match('/(\d{9,12})/', $segment, $mobileMatch)) {
                        $this->line("  Found mobile (9-12 digits): " . $mobileMatch[1]);
                    }

                    // Try alternative patterns
                    if (preg_match('/(\d{8})\s*(\d{15,25})\s*(\d{8,12})/', $segment, $altMatches)) {
                        $this->info("  Alternative pattern matched - trying to parse...");
                        $dateRaw = $altMatches[1];
                        $amountRaw = $altMatches[2];
                        $mobileRaw = $altMatches[3];

                        // Parse amount - handle different lengths
                        $cleanAmount = ltrim($amountRaw, '0');
                        $amountValue = (int) ($cleanAmount ?: '0');

                        // If amount string is longer than 20 digits, assume last 2 are cents
                        if (strlen($amountRaw) >= 20) {
                            $amount = number_format($amountValue / 100, 2, '.', '');
                        } else {
                            // For shorter amounts, might be in different format
                            $amount = number_format($amountValue / 100, 2, '.', '');
                        }

                        // Extract transaction ID from full line
                        $transactionId = '';
                        if (preg_match('/' . preg_quote($dateRaw, '/') . '([A-Z][A-Z0-9]{4,})/', $line, $tm)) {
                            $transactionId = trim($tm[1]);
                        }

                        $this->info("  Alternative parsing successful!");

                        $results->push([
                            'transaction_date' => $this->parseDate($dateRaw),
                            'transaction_time' => $this->formatTime($timeFromTimestamp),
                            'amount' => $amount,
                            'mobile_number' => $mobileRaw,
                            'transaction_id' => $transactionId,
                        ]);
                    }
                }
            } else {
                $this->warn("Skipping line " . ($lineNumber + 1) . ": missing AUTH CANCELLED → timestamp+INTERNET segment");
            }
        }

        return $results;
    }

    // Helper method to format time from HHMMSSS to HH:MM:SS
    private function formatTime(string $timeString): string
    {
        if (strlen($timeString) >= 6) {
            $hours = substr($timeString, 0, 2);
            $minutes = substr($timeString, 2, 2);
            $seconds = substr($timeString, 4, 2);
            return "{$hours}:{$minutes}:{$seconds}";
        }
        return $timeString; // Return as-is if format is unexpected
    }

    private function parseSegment(string $segment, string $fileName, int $lineNumber, string $fullLine): ?array
    {
        $this->line("  - Parsing segment: '{$segment}'");

        // Try different patterns for the segment content
        $segmentPatterns = [
            // Pattern 1: 8-digit date, 20-digit amount, 10-digit mobile (with spaces)
            '/(\d{8})\s*(\d{20})\s*(\d{10})/',

            // Pattern 2: More flexible digit matching
            '/(\d{8})\s*(\d{15,25})\s*(\d{9,12})/',

            // Pattern 3: Look for any sequence of digits
            '/(\d{8}).*?(\d{15,25}).*?(\d{9,12})/',

            // Pattern 4: Very flexible
            '/(\d{8})\D*(\d{15,25})\D*(\d{9,12})/',
        ];

        foreach ($segmentPatterns as $index => $pattern) {
            if (preg_match($pattern, $segment, $matches)) {
                $this->line("  - Segment pattern " . ($index + 1) . " matched!");
                $this->line("    Date: {$matches[1]}, Amount: {$matches[2]}, Mobile: {$matches[3]}");

                $dateRaw = $matches[1];
                $amountRaw = $matches[2];
                $mobileRaw = $matches[3];

                $cleanAmount = ltrim($amountRaw, '0');
                $amount = number_format(((int) ($cleanAmount ?: '0')) / 100, 2, '.', '');

                // Try to extract transaction ID
                $transactionId = $this->extractTransactionId($segment, $dateRaw);

                return [
                    'transaction_date' => $this->parseDate($dateRaw),
                    'amount' => $amount,
                    'mobile_number' => $mobileRaw,
                    'transaction_id' => $transactionId,
                ];
            } else {
                $this->line("  - Segment pattern " . ($index + 1) . " did not match");
            }
        }

        $this->warn("  - No segment patterns matched");
        return null;
    }

    private function extractTransactionId(string $segment, string $dateRaw): string
    {
        // Try multiple patterns for transaction ID
        $transactionPatterns = [
            // Pattern 1: Date followed by alphanumeric
            '/' . preg_quote($dateRaw, '/') . '\s*([A-Z0-9]{5,})/',

            // Pattern 2: Look for any alphanumeric sequence after the date
            '/' . preg_quote($dateRaw, '/') . '.*?([A-Z][A-Z0-9]{4,})/',

            // Pattern 3: Find NAM followed by alphanumeric
            '/NAM[A-Z0-9]{2,}/',

            // Pattern 4: Any sequence of letters and numbers (5+ chars)
            '/[A-Z][A-Z0-9]{4,}/',
        ];

        foreach ($transactionPatterns as $pattern) {
            if (preg_match($pattern, $segment, $tm)) {
                return trim($tm[1] ?? $tm[0]);
            }
        }

        return '';
    }

    private function tryManualExtraction(string $line, int $lineNumber): void
    {
        $this->line("  - Attempting manual extraction...");

        // Find positions of key markers
        $authPos = stripos($line, 'AUTH CANCELLED');
        $internetPos = stripos($line, 'INTERNET');

        if ($authPos !== false && $internetPos !== false) {
            $this->line("  - AUTH CANCELLED found at position: {$authPos}");
            $this->line("  - INTERNET found at position: {$internetPos}");

            // Look for 14-digit timestamp before INTERNET
            if (preg_match('/(\d{14}).*?INTERNET/i', $line, $matches)) {
                $timestamp = $matches[1];
                $timestampPos = strpos($line, $timestamp);
                $this->line("  - 14-digit timestamp '{$timestamp}' found at position: {$timestampPos}");

                // Extract content between AUTH CANCELLED and timestamp
                $startPos = $authPos + strlen('AUTH CANCELLED');
                $endPos = $timestampPos;

                if ($endPos > $startPos) {
                    $extractedContent = trim(substr($line, $startPos, $endPos - $startPos));
                    $this->line("  - Manually extracted content: '{$extractedContent}'");

                    // Try to parse this extracted content
                    if (preg_match('/(\d{8}).*?(\d{15,25}).*?(\d{9,12})/', $extractedContent, $dataMatches)) {
                        $this->line("  - Manual parsing successful!");
                        $this->line("    Date: {$dataMatches[1]}, Amount: {$dataMatches[2]}, Mobile: {$dataMatches[3]}");
                    }
                }
            }
        }
    }



    /**
     * Parse date from YYYYMMDD to YYYY-MM-DD
     */
    private function parseDate(string $dateStr): string
    {
        if (strlen($dateStr) === 8) {
            return substr($dateStr, 0, 4) . '-' . substr($dateStr, 4, 2) . '-' . substr($dateStr, 6, 2);
        }
        return $dateStr;
    }

    /**
     * Display results in the specified format
     */
    private function displayResults(Collection $results, string $format): void
    {
        switch ($format) {
            case 'json':
                $this->line(json_encode($results->toArray(), JSON_PRETTY_PRINT));
                break;
            case 'csv':
                // Updated CSV headers to include new fields
                $this->line('Transaction Date,Transaction Time,Amount,Mobile Number,Transaction ID');
                foreach ($results as $record) {
                    $this->line(sprintf(
                        '%s,%s,%s,%s,%s',
                        $record['transaction_date'] ?? 'N/A',
                        $record['transaction_time'] ?? 'N/A',
                        $record['amount'] ?? 'N/A',
                        $record['mobile_number'] ?? 'N/A',
                        $record['transaction_id'] ?? 'N/A'
                    ));
                }
                break;
            default: // table
                // Updated table headers to include new fields
                $headers = ['Transaction Date', 'Time', 'Amount', 'Mobile Number', 'Transaction ID'];
                $rows = $results->map(function ($record) {
                    return [
                        $record['transaction_date'] ?? 'N/A',
                        $record['transaction_time'] ?? 'N/A',
                        $record['amount'] ?? 'N/A',
                        $record['mobile_number'] ?? 'N/A',
                        $record['transaction_id'] ?? 'N/A'
                    ];
                })->toArray();
                $this->table($headers, $rows);
                break;
        }
    }

    /**
     * Save results to a CSV file in storage/app/exports/
     */
    private function saveResults(Collection $results): void
    {
        $directory = 'exports';
        if (!Storage::exists($directory)) {
            Storage::makeDirectory($directory);
        }

        // Group results by transaction date
        $groupedByDate = $results->groupBy('transaction_date');

        if ($groupedByDate->isEmpty()) {
            $this->warn("No results to save");
            return;
        }

        $this->info("Grouping transactions by date. Found " . $groupedByDate->count() . " different dates.");

        $totalFiles = 0;
        $totalRecords = 0;
        $successfulSends = 0;
        $failedSends = 0;

        foreach ($groupedByDate as $date => $dateRecords) {
            try {
                // Create filename based on the transaction date
                $filename = $this->createFilenameFromDate($date);
                $filePath = "{$directory}/{$filename}";

                $this->line("Processing date: {$date} ({$dateRecords->count()} records)");

                // Check if file already exists and decide how to handle it
                if (Storage::exists($filePath)) {
                    $this->warn("  File already exists: {$filename}");

                    // Option 1: Append to existing file
                    $existingContent = Storage::get($filePath);
                    $existingLines = explode("\n", trim($existingContent));

                    // Remove header if it exists in existing file
                    if (!empty($existingLines) && strpos($existingLines[0], 'Transaction Date') !== false) {
                        array_shift($existingLines);
                    }

                    $csvData = array_map('str_getcsv', $existingLines);
                } else {
                    $csvData = [];
                    // Add header for new file
                    $csvData[] = ['Transaction Date', 'Transaction Time', 'Amount', 'Mobile Number', 'Transaction ID'];
                }

                // Add data rows for this date
                foreach ($dateRecords as $record) {
                    $csvData[] = [
                        $record['transaction_date'] ?? 'N/A',
                        $record['transaction_time'] ?? 'N/A',
                        $record['amount'] ?? 'N/A',
                        $record['mobile_number'] ?? 'N/A',
                        $record['transaction_id'] ?? 'N/A'
                    ];
                }

                // Convert array to CSV string
                $csvString = '';
                foreach ($csvData as $rowIndex => $row) {
                    $this->line("Processing row {$rowIndex}: " . json_encode($row));

                    try {
                        if (!is_array($row)) {
                            $this->warn("  ⚠ Row {$rowIndex} is not an array — attempting to convert with str_getcsv.");
                            $row = str_getcsv($row);
                        }

                        $cleanRow = [];
                        foreach ($row as $columnIndex => $value) {
                            if (is_array($value)) {
                                $this->warn("Array in row {$rowIndex}, column {$columnIndex}: " . json_encode($value));
                                $cleanRow[] = json_encode($value);
                            } elseif (is_object($value)) {
                                $this->warn("Object in row {$rowIndex}, column {$columnIndex}");
                                $cleanRow[] = json_encode($value);
                            } else {
                                $cleanRow[] = (string) ($value ?? 'N/A');
                            }
                        }

                        $csvString .= implode(',', $cleanRow) . "\n";
                    } catch (\Exception $e) {
                        $this->error("Error in row {$rowIndex}: " . $e->getMessage());
                        $this->error("Row data: " . json_encode($row));
                        throw $e;
                    }
                }

                $csvString = rtrim($csvString, "\n");

                // Save the file locally
                if (Storage::put($filePath, $csvString)) {
                    $totalFiles++;
                    $totalRecords += $dateRecords->count();
                    $this->info("  ✓ Saved: storage/app/{$filePath} ({$dateRecords->count()} records)");

                    // Send to accounting software immediately after successful save
                    $sendResult = $this->sendToAccountingSoftware($csvString, $filename, $dateRecords);

                    if ($sendResult['success']) {
                        $successfulSends++;
                        $this->info("  ✓ Successfully sent to accounting software");
                    } else {
                        $failedSends++;
                        $this->error("  ✗ Failed to send to accounting software: " . $sendResult['error']);
                    }

                } else {
                    $this->error("  ✗ Failed to save: {$filename}");
                }

            } catch (\Exception $e) {
                $this->error("  ✗ Error saving data for date {$date}: " . $e->getMessage());
                $failedSends++;
            }
        }

        // Summary
        $this->info("\n" . str_repeat('=', 60));
        $this->info("EXPORT & TRANSMISSION SUMMARY");
        $this->info(str_repeat('=', 60));
        $this->info("Total CSV files created: {$totalFiles}");
        $this->info("Total records exported: {$totalRecords}");
        $this->info("Successfully sent to accounting: {$successfulSends}");
        $this->info("Failed transmissions: {$failedSends}");
        $this->info("Files saved in: storage/app/{$directory}/");
        $this->info("Transmission logs saved in: storage/app/transmission_logs/");

        // List all created files
        if ($totalFiles > 0) {
            $this->info("\nCreated files:");
            foreach ($groupedByDate->keys() as $date) {
                $filename = $this->createFilenameFromDate($date);
                $logFilename = "transmission_log_{$date}.log";
                $this->line("  - CSV: {$filename}");
                $this->line("    Log: {$logFilename}");
            }
        }

        // Show transmission log summary
        if ($successfulSends > 0 || $failedSends > 0) {
            $this->info("\nTransmission log files contain:");
            $this->line("  - Detailed transmission attempts with timestamps");
            $this->line("  - Complete CSV content that was sent");
            $this->line("  - Success/failure status with error details");
            $this->line("  - Response data from accounting software");
            $this->line("  - HTTP status codes and response headers");
        }
    }

    /**
 * Send CSV data to accounting software
 */
private function sendToAccountingSoftware(string $csvContent, string $filename, Collection $records): array
{
    $transmissionTime = now();
    $transactionDate = $records->first()['transaction_date'] ?? $transmissionTime->format('Y-m-d');

    try {
        $accountingUrl = config('accounting.endpoint_url', 'https://www.example.com/send');

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

        $this->line("  → Sending {$records->count()} records to accounting software...");

        // Send HTTP request with timeout and retry logic
        $response = Http::timeout(30)
            ->retry(3, 1000) // Retry 3 times with 1 second delay
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
                'csv_content' => $csvContent,
                'response_data' => $responseData,
                'response_status' => $response->status(),
                'response_headers' => $response->headers(),
            ]);

            // Save to date-specific log file
            $this->saveTransmissionLog($transactionDate, $logEntry);

            // Laravel log
            \Log::info("Successfully sent CSV to accounting software", [
                'filename' => $filename,
                'records' => $records->count(),
                'response' => $responseData
            ]);

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
                'csv_content' => $csvContent,
                'error_message' => $errorMessage,
                'response_status' => $response->status(),
                'response_body' => $response->body(),
                'response_headers' => $response->headers(),
            ]);

            $this->saveTransmissionLog($transactionDate, $logEntry);

            \Log::error("Failed to send CSV to accounting software", [
                'filename' => $filename,
                'error' => $errorMessage,
                'status' => $response->status()
            ]);

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
            'csv_content' => $csvContent,
            'error_message' => $errorMessage,
            'exception_trace' => $e->getTraceAsString(),
        ]);

        $this->saveTransmissionLog($transactionDate, $logEntry);

        \Log::error("Exception when sending CSV to accounting software", [
            'filename' => $filename,
            'error' => $errorMessage,
            'trace' => $e->getTraceAsString()
        ]);

        return [
            'success' => false,
            'error' => $errorMessage
        ];
    }
}

/**
 * Create a detailed log entry for transmission attempts
 */
private function createLogEntry(array $data): string
{
    $separator = str_repeat('=', 80);
    $timestamp = $data['transmission_time']->format('Y-m-d H:i:s T');

    $logContent = "\n{$separator}\n";
    $logContent .= "TRANSMISSION LOG ENTRY\n";
    $logContent .= "{$separator}\n";
    $logContent .= "Status: {$data['status']}\n";
    $logContent .= "Timestamp: {$timestamp}\n";
    $logContent .= "Filename: {$data['filename']}\n";
    $logContent .= "Transaction Date: {$data['transaction_date']}\n";
    $logContent .= "Record Count: {$data['record_count']}\n";
    $logContent .= "Total Amount: " . number_format($data['total_amount'], 2) . "\n";
    $logContent .= "Endpoint URL: {$data['endpoint_url']}\n";

    // Add response information for successful transmissions
    if ($data['status'] === 'SUCCESS') {
        $logContent .= "Response Status: {$data['response_status']}\n";
        $logContent .= "Response Data: " . json_encode($data['response_data'], JSON_PRETTY_PRINT) . "\n";
        if (!empty($data['response_headers'])) {
            $logContent .= "Response Headers: " . json_encode($data['response_headers'], JSON_PRETTY_PRINT) . "\n";
        }
    }

    // Add error information for failed transmissions
    if (isset($data['error_message'])) {
        $logContent .= "Error Message: {$data['error_message']}\n";
        if (isset($data['response_status'])) {
            $logContent .= "HTTP Status: {$data['response_status']}\n";
        }
        if (isset($data['response_body'])) {
            $logContent .= "Response Body: {$data['response_body']}\n";
        }
        if (isset($data['exception_trace'])) {
            $logContent .= "Stack Trace:\n{$data['exception_trace']}\n";
        }
    }

    $logContent .= "\nCSV CONTENT:\n";
    $logContent .= str_repeat('-', 40) . "\n";
    $logContent .= $data['csv_content'] . "\n";
    $logContent .= str_repeat('-', 40) . "\n";

    $logContent .= "{$separator}\n\n";

    return $logContent;
}

/**
 * Save transmission log to date-specific file
 */
private function saveTransmissionLog(string $transactionDate, string $logEntry): void
{
    try {
        $logDirectory = 'transmission_to_server_logs';

        // Create directory if it doesn't exist
        if (!Storage::exists($logDirectory)) {
            Storage::makeDirectory($logDirectory);
        }

        // Create filename based on transaction date
        $logFilename = "transmission_log_{$transactionDate}.log";
        $logFilePath = "{$logDirectory}/{$logFilename}";

        // Append to existing log file or create new one
        if (Storage::exists($logFilePath)) {
            $existingContent = Storage::get($logFilePath);
            $newContent = $existingContent . $logEntry;
        } else {
            // Create header for new log file
            $header = "ACCOUNTING SOFTWARE TRANSMISSION LOG\n";
            $header .= "Generated: " . now()->format('Y-m-d H:i:s T') . "\n";
            $header .= "Transaction Date: {$transactionDate}\n";
            $header .= str_repeat('=', 80) . "\n\n";

            $newContent = $header . $logEntry;
        }

        Storage::put($logFilePath, $newContent);

        $this->line("  → Log saved to: storage/app/{$logFilePath}");

    } catch (\Exception $e) {
        $this->error("  ✗ Failed to save transmission log: " . $e->getMessage());
        \Log::error("Failed to save transmission log", [
            'transaction_date' => $transactionDate,
            'error' => $e->getMessage()
        ]);
    }
}



    /**
     * Create a filename from a date string
     */
    private function createFilenameFromDate(string $date): string
    {
        try {
            // Handle different date formats
            if (strpos($date, '-') !== false) {
                // Format: 2025-07-10 or similar
                $cleanDate = str_replace('-', '', $date);
            } else {
                // Format: 20250710 or similar
                $cleanDate = $date;
            }

            // Ensure we have a valid date format (YYYYMMDD)
            if (preg_match('/^(\d{4})(\d{2})(\d{2})/', $cleanDate, $matches)) {
                $year = $matches[1];
                $month = $matches[2];
                $day = $matches[3];
                return "{$year}{$month}{$day}.csv";
            }

            // Fallback if date format is unexpected
            $cleanDate = preg_replace('/[^0-9]/', '', $date);
            return "{$cleanDate}.csv";

        } catch (\Exception $e) {
            // Ultimate fallback
            $timestamp = now()->format('Ymd_His');
            return "{$timestamp}.csv";
        }
    }
}
