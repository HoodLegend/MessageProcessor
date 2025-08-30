<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
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

    private const BATCH_SIZE = 50; // Adjust based on your server capacity

    private const DELAY_BETWEEN_INDIVIDUAL = 0.3;

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
    //  */
    private function parseFileContent(string $content, string $fileName): Collection
    {
        $results = collect();

        // Pre-compile regex patterns for better performance
        // static $authRegex = '/AUTH\s+CANCELLED\s+(.*?)(\d{14})INTERNET/i';
        // static $primaryPattern = '/(\d{8})\s*(\d{20})\s*(\d{9,12})/';
        // static $alternativePattern = '/(\d{8})\s*(\d{15,25})\s*(\d{8,12})/';
        // Updated regex patterns for your data format
        static $authRegex = '/AUTH\s+CANCELLED\s+(.*?)(\d{14,16})INTERNET/i';

        // Primary pattern: Date (8) + Amount (20) + Mobile (9-12)
        static $primaryPattern = '/(\d{8})\s*(\d{20})\s*(\d{9,12})/';

        // Alternative pattern: Date (8) + Amount (15-25) + Mobile (8-12)
        static $alternativePattern = '/(\d{8})\s*(\d{15,25})\s*(\d{8,12})/';

        // NEW: Time-only pattern for cases like "2025070300065200INTERNET"
        static $timeOnlyPattern = '/(\d{8})(\d{6})INTERNET/i';
        static $transactionIdPattern = null;

       // Add the new time-only pattern to your static regex definitions
        static $timeOnlyPattern = '/(\d{8})(\d{6})INTERNET/i';



// Modified parsing method
// Statistics for monitoring (no verbose logging)
$stats = [
    'total_lines' => 0,
    'processed_lines' => 0,
    'successful_matches' => 0,
    'alternative_matches' => 0,
    'time_only_matches' => 0, // New stat for time-only matches
    'skipped_no_cpy' => 0,
    'skipped_no_auth' => 0,
    'processing_errors' => 0
];

// Stream processing instead of loading entire file into memory
$stream = fopen('php://temp', 'r+');
fwrite($stream, $content);
rewind($stream);

while (($line = fgets($stream)) !== false) {
    $stats['total_lines']++;
    $line = trim($line);

    // Quick filters first (fastest operations)
    if (empty($line) || strpos($line, 'CPY') === false) {
        $stats['skipped_no_cpy']++;
        continue;
    }

    // Check for time-only pattern first (most specific)
    if (preg_match($timeOnlyPattern, $line, $timeOnlyMatch)) {
        $result = $this->processTimeOnlyTransaction($timeOnlyMatch, $line);
        if ($result) {
            $results->push($result);
            $stats['time_only_matches']++;
        }
        $stats['processed_lines']++;
        continue;
    }

    // Single regex match for AUTH CANCELLED pattern
    if (!preg_match($authRegex, $line, $segmentMatch)) {
        $stats['skipped_no_auth']++;
        continue;
    }

if (!preg_match($authRegex, $line, $segmentMatch)) {
    $stats['skipped_no_auth']++;
    continue;
}

$segment = trim($segmentMatch[1]);
$fullTimestamp = $segmentMatch[2];

// FIXED: Extract timestamp components correctly based on actual length
if (strlen($fullTimestamp) >= 14) {
    $dateFromTimestamp = substr($fullTimestamp, 0, 8);        // YYYYMMDD
    $timeFromTimestamp = substr($fullTimestamp, 8, 6);        // HHMMSS
} else {
    // Fallback for shorter timestamps
    $dateFromTimestamp = substr($fullTimestamp, 0, 8);
    $timeFromTimestamp = '000000'; // Default time
}

$stats['processed_lines']++;

    // Try primary pattern first
    if (preg_match($primaryPattern, $segment, $matches)) {
        $result = $this->processTransaction($matches, $dateFromTimestamp, $timeFromTimestamp, $line);
        if ($result) {
            $results->push($result);
            $stats['successful_matches']++;
        }
    }
    // Try alternative pattern if primary fails
    elseif (preg_match($alternativePattern, $segment, $altMatches)) {
        $result = $this->processTransactionAlternative($altMatches, $dateFromTimestamp, $timeFromTimestamp, $line);
        if ($result) {
            $results->push($result);
            $stats['alternative_matches']++;
        }
    } else {
        $stats['processing_errors']++;
        // Only log first few errors to prevent log spam
        if ($stats['processing_errors'] <= 5) {
            \Log::warning("Parse error in {$fileName} line {$stats['total_lines']}: no pattern match");
        }
    }
}

fclose($stream);

// Single summary log entry
$this->logProcessingSummary($fileName, $stats, $results->count());
return $results;
    }

    /**
     * Process standard transaction pattern
     */
    private function processTransaction(array $matches, string $dateFromTimestamp, string $timeFromTimestamp, string $fullLine): ?array
    {
        try {
            $dateRaw = $matches[1];
            $amountRaw = $matches[2];
            $mobileRaw = $matches[3];

            $cleanAmount = ltrim($amountRaw, '0');
            $amount = number_format(((int) ($cleanAmount ?: '0')) / 100, 2, '.', '');

            // Extract transaction ID efficiently
            $transactionId = '';
            if (preg_match('/' . preg_quote($dateRaw, '/') . '([A-Z][A-Z0-9]{4,})/', $fullLine, $tm)) {
                $transactionId = trim($tm[1]);
            }

            // Use the transaction date from the segment data, not the timestamp
            // The timestamp is used for the time component only
            return [
                'transaction_date' => $this->parseDate($dateRaw),
                'transaction_time' => $this->formatTime($timeFromTimestamp),
                'amount' => $amount,
                'mobile_number' => $mobileRaw,
                'transaction_id' => $transactionId,
            ];
        } catch (\Exception $e) {
            \Log::error("Transaction processing error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Log processing summary (single entry per file)
     */
    private function logProcessingSummary(string $fileName, array $stats, int $resultCount): void
    {
        $summary = [
            'file' => $fileName,
            'lines_total' => $stats['total_lines'],
            'lines_processed' => $stats['processed_lines'],
            'successful_matches' => $stats['successful_matches'],
            'alternative_matches' => $stats['alternative_matches'],
            'final_results' => $resultCount,
            'skip_no_cpy' => $stats['skipped_no_cpy'],
            'skip_no_auth' => $stats['skipped_no_auth'],
            'errors' => $stats['processing_errors'],
            'memory_peak' => memory_get_peak_usage(true) / 1024 / 1024 . 'MB'
        ];

        // Single line summary for production monitoring
        $this->info("Parsed {$fileName}: {$resultCount} transactions from {$stats['total_lines']} lines" .
            ($stats['processing_errors'] > 0 ? " ({$stats['processing_errors']} errors)" : ""));

        // Detailed stats to Laravel log for debugging
        \Log::info("DAT parsing summary", $summary);
    }

    /**
     * Process alternative transaction pattern
     */
    private function processTransactionAlternative(array $matches, string $dateFromTimestamp, string $timeFromTimestamp, string $fullLine): ?array
    {
        try {
            $dateRaw = $matches[1];
            $amountRaw = $matches[2];
            $mobileRaw = $matches[3];

            // Handle different amount lengths for alternative pattern
            $cleanAmount = ltrim($amountRaw, '0');
            $amountValue = (int) ($cleanAmount ?: '0');

            // If amount string is longer than 20 digits, assume last 2 are cents
            if (strlen($amountRaw) >= 20) {
                $amount = number_format($amountValue / 100, 2, '.', '');
            } else {
                // For shorter amounts, might be in different format
                $amount = number_format($amountValue / 100, 2, '.', '');
            }

            // Extract transaction ID
            $transactionId = '';
            if (preg_match('/' . preg_quote($dateRaw, '/') . '([A-Z][A-Z0-9]{4,})/', $fullLine, $tm)) {
                $transactionId = trim($tm[1]);
            }

            return [
                'transaction_date' => $this->parseDate($dateRaw),
                'transaction_time' => $this->formatTime($timeFromTimestamp),
                'amount' => $amount,
                'mobile_number' => $mobileRaw,
                'transaction_id' => $transactionId,
            ];
        } catch (\Exception $e) {
            \Log::error("Alternative transaction processing error: " . $e->getMessage());
            return null;
        }
    }


    // Helper method to format time from HHMMSSS to HH:MM:SS
    private function formatTime(string $timeString): string
    {
        $timeString = str_pad($timeString, 6, '0', STR_PAD_LEFT);

        if (strlen($timeString) >= 6) {
            $hours = (int) substr($timeString, 0, 2);
            $minutes = (int) substr($timeString, 2, 2);
            $seconds = (int) substr($timeString, 4, 2);

            // Handle overflow
            $minutes += intval($seconds / 60);
            $seconds = $seconds % 60;

            $hours += intval($minutes / 60);
            $minutes = $minutes % 60;

            $hours = $hours % 24; // Keep within 24-hour format

            return sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds);
        }

        return $timeString;
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
     * Save results to a CSV file in storage/app/exports/ and sends the data to the server.
     */
    // private function saveResults(Collection $results): void
    // {
    //     $directory = 'exports';
    //     if (!Storage::exists($directory)) {
    //         Storage::makeDirectory($directory);
    //     }

    //     $groupedByDate = $results->groupBy('transaction_date');

    //     if ($groupedByDate->isEmpty()) {
    //         return;
    //     }

    //     $totalFiles = 0;
    //     $totalRecords = 0;
    //     $successfulSends = 0;
    //     $errors = [];

    //     foreach ($groupedByDate as $date => $dateRecords) {
    //         try {
    //             $filename = $this->createFilenameFromDate($date);
    //             $filePath = "{$directory}/{$filename}";

    //             // Prepare CSV data
    //             $csvData = [];
    //             if (Storage::exists($filePath)) {
    //                 $existingContent = Storage::get($filePath);
    //                 $existingLines = explode("\n", trim($existingContent));
    //                 if (!empty($existingLines) && strpos($existingLines[0], 'Transaction Date') !== false) {
    //                     array_shift($existingLines);
    //                 }
    //                 $csvData = array_filter(array_map('str_getcsv', $existingLines));
    //             } else {
    //                 $csvData[] = ['Transaction Date', 'Transaction Time', 'Amount', 'Mobile Number', 'Transaction ID'];
    //             }

    //             // Add records
    //             foreach ($dateRecords as $record) {
    //                 $csvData[] = [
    //                     $record['transaction_date'] ?? 'N/A',
    //                     $record['transaction_time'] ?? 'N/A',
    //                     $record['amount'] ?? 'N/A',
    //                     $record['mobile_number'] ?? 'N/A',
    //                     $record['transaction_id'] ?? 'N/A'
    //                 ];
    //             }

    //             $csvString = $this->arrayToCsv($csvData);

    //             if (Storage::put($filePath, $csvString)) {
    //                 $totalFiles++;
    //                 $totalRecords += $dateRecords->count();

    //                 $sendResult = $this->sendToAccountingSoftware($csvString, $filename, $dateRecords);
    //                 if ($sendResult['success']) {
    //                     $successfulSends++;
    //                 }
    //             }

    //         } catch (\Exception $e) {
    //             $errors[] = "Error processing {$date}: " . $e->getMessage();
    //         }
    //     }

    //     // Single line summary for production
    //     $this->info("Processed: {$totalFiles} files, {$totalRecords} records, {$successfulSends} sent successfully" .
    //         (count($errors) > 0 ? ", " . count($errors) . " errors" : ""));

    //     // Log errors to Laravel log instead of console
    //     foreach ($errors as $error) {
    //         \Log::error("DAT parsing error: " . $error);
    //     }
    // }

    private function saveResults(Collection $results): void
    {
        // directory to save the parsed dat files.
        $directory = 'exports';

        // check if directory exists.
        if (!Storage::exists($directory)) {
            Storage::makeDirectory($directory);
        }

        if ($results->isEmpty()) {
            $this->info("No transactions to process.");
            return;
        }

        $this->info("Starting to process {$results->count()} total transactions...");

        // Save ALL parsed data to CSV files (regardless of date)
        $this->saveAllDataToCsv($results, $directory);

        // Filter data for current/recent transactions only
        $filteredResults = $this->filterCurrentTransactions($results);

        if ($filteredResults->isEmpty()) {
            $this->info("No current transactions to send to accounting software after filtering.");
            return;
        }

        $this->info("Filtered from {$results->count()} to {$filteredResults->count()} transactions for transmission");
        $this->logFilteringStats($results, $filteredResults);

        // send filtered data as individual JSON transactions
        // $this->sendIndividualTransactions($filteredResults);
    }

    /**
     * Save ALL parsed data to CSV files (organized by date)
     */
    private function saveAllDataToCsv(Collection $results, string $directory): void
    {
        $this->info("SAVING ALL DATA TO CSV FILES");
        $groupedByDate = $results->groupBy('transaction_date');
        $totalFiles = 0;
        $totalRecords = 0;
        $duplicatesSkipped = 0;
        $startTime = microtime(true);

        foreach ($groupedByDate as $date => $dateRecords) {
            try {
                $filename = $this->createFilenameFromDate($date);
                $filePath = "{$directory}/{$filename}";

                // Prepare CSV data for this date
                $csvData = [];
                $existingTransactionHashes = [];

                // Check if file already exists and load existing data
                if (Storage::exists($filePath)) {
                    $existingContent = Storage::get($filePath);
                    $existingLines = explode("\n", trim($existingContent));

                    if (!empty($existingLines) && strpos($existingLines[0], 'Transaction Date') !== false) {
                        array_shift($existingLines); // Remove header
                    }

                    // Parse existing data and create hash lookup for duplicates
                    foreach ($existingLines as $line) {
                        if (!empty(trim($line))) {
                            $row = str_getcsv($line);
                            if (count($row) >= 5) { // Ensure we have all required columns
                                $csvData[] = $row;

                                // Create hash for duplicate detection: transaction_id + amount + date + time
                                $hash = md5($row[4] . '|' . $row[2] . '|' . $row[0] . '|' . $row[1]); // transaction_id|amount|date|time
                                $existingTransactionHashes[] = $hash;
                            }
                        }
                    }
                } else {
                    // Add header for new file
                    $csvData[] = ['Transaction Date', 'Transaction Time', 'Amount', 'Mobile Number', 'Transaction ID'];
                }

                // Track new records added for this file
                $newRecordsCount = 0;
                $fileStartRecordCount = count($csvData) - (empty($existingTransactionHashes) ? 1 : 0); // Subtract header if new file

                // Add new records to CSV data (only if not duplicates)
                foreach ($dateRecords as $record) {
                    // Create hash for this new record
                    $newRecordHash = md5(
                        ($record['transaction_id'] ?? 'N/A') . '|' .
                        ($record['amount'] ?? 'N/A') . '|' .
                        ($record['transaction_date'] ?? 'N/A') . '|' .
                        ($record['transaction_time'] ?? 'N/A')
                    );

                    // Check if this record already exists
                    if (in_array($newRecordHash, $existingTransactionHashes)) {
                        $duplicatesSkipped++;
                        continue; // Skip duplicate
                    }

                    // Add new record
                    $csvData[] = [
                        $record['transaction_date'] ?? 'N/A',
                        $record['transaction_time'] ?? 'N/A',
                        $record['amount'] ?? 'N/A',
                        $record['mobile_number'] ?? 'N/A',
                        $record['transaction_id'] ?? 'N/A'
                    ];

                    // Add to existing hashes to prevent duplicates within same batch
                    $existingTransactionHashes[] = $newRecordHash;
                    $newRecordsCount++;
                }

                // Only save if we have new records to add
                if ($newRecordsCount > 0) {
                    // Convert to CSV string and save
                    $csvString = $this->arrayToCsv($csvData);
                    if (Storage::put($filePath, $csvString)) {
                        $totalFiles++;
                        $totalRecords += $newRecordsCount;
                        $this->info("Saved {$newRecordsCount} new records to {$filename} (total in file: " . (count($csvData) - 1) . ")");
                    } else {
                        $this->error("Failed to save {$filename}");
                    }
                } else {
                    $this->line("No new records for {$filename} - all records already exist");
                }

            } catch (\Exception $e) {
                $this->error("Error saving {$date}: " . $e->getMessage());
            }
        }

        $processingTime = round(microtime(true) - $startTime, 2);

        $this->info("\n" . str_repeat('=', 50));
        $this->info("CSV SAVE SUMMARY");
        $this->info(str_repeat('=', 50));
        $this->info("Files updated: {$totalFiles}");
        $this->info("New records saved: {$totalRecords}");
        $this->info("Duplicate records skipped: {$duplicatesSkipped}");
        $this->info("Processing time: {$processingTime} seconds");
        $this->info(str_repeat('=', 50));
    }



    /**
     * Send filtered transactions as individual JSON requests
     */
    // private function sendIndividualTransactions(Collection $filteredResults): void
    // {
    //     $accountingUrl = config('accounting.endpoint_url', 'https://www.castlebet.darth.bond/api/fnb53nmb');
    //     $successfulSends = 0;
    //     $failedSends = 0;
    //     $errors = [];
    //     $startTime = microtime(true);

    //     foreach ($filteredResults as $index => $transaction) {
    //         try {
    //             // Prepare individual transaction payload with "username" instead of "msisdn"
    //             $payload = [
    //                 'username' => $transaction['mobile_number'] ?? null,
    //                 'amount' => floatval($transaction['amount'] ?? 0),
    //                 'transactionid' => $transaction['transaction_id'] ?? null,
    //                 'time' => $transaction['transaction_date'] . ' ' . ($transaction['transaction_time'] ?? '00:00:00')
    //             ];

    //             // Remove null/empty values
    //             $payload = array_filter($payload, function($value) {
    //                 return $value !== null && $value !== '';
    //             });

    //             $this->line("[($index + 1)/{$filteredResults->count()}] Sending {$transaction['transaction_id']}...");

    //             // Send individual transaction
    //             $response = Http::timeout(15)
    //                 ->connectTimeout(5)
    //                 ->retry(2, 500) // 2 retries with 500ms delay
    //                 ->withHeaders([
    //                     'Content-Type' => 'application/json',
    //                     'Accept' => 'application/json',
    //                     'X-Transaction-ID' => $transaction['transaction_id'],
    //                 ])
    //                 ->post($accountingUrl, $payload);

    //             if ($response->successful()) {
    //                 $successfulSends++;
    //                 $this->line("Success ({$response->status()})");

    //                 // Log successful transaction
    //                 $this->logIndividualTransaction('SUCCESS', $transaction, $payload, $response->json());
    //             } else {
    //                 $failedSends++;
    //                 $errorMsg = "HTTP {$response->status()}: " . $response->body();
    //                 $errors[] = "Transaction {$transaction['transaction_id']}: {$errorMsg}";
    //                 $this->line("Failed: {$errorMsg}");

    //                 // Log failed transaction
    //                 $this->logIndividualTransaction('FAILED', $transaction, $payload, null, $errorMsg);
    //             }

    //             // Small delay between individual transactions
    //             if ($index < $filteredResults->count() - 1) {
    //                 usleep(self::DELAY_BETWEEN_INDIVIDUAL * 1000000);
    //             }

    //         } catch (\Exception $e) {
    //             $failedSends++;
    //             $errorMsg = "Exception: " . $e->getMessage();
    //             $errors[] = "Transaction {$transaction['transaction_id']}: {$errorMsg}";
    //             $this->error("    ✗ {$errorMsg}");

    //             // Log exception
    //             $this->logIndividualTransaction('ERROR', $transaction, $payload ?? [], null, $errorMsg);
    //         }
    //     }

    //     $processingTime = round(microtime(true) - $startTime, 2);

    //     // Transmission Summary
    //     $this->info("=== TRANSMISSION COMPLETE ===");
    //     $this->info("Processing time: {$processingTime} seconds");
    //     $this->info("Successful sends: {$successfulSends}");
    //     $this->info("Failed sends: {$failedSends}");
    //     $this->info("Success rate: " . round(($successfulSends / $filteredResults->count()) * 100, 1) . "%");

    //     // Create transmission summary log entry
    //     $this->createTransmissionSummary([
    //         'total_filtered_transactions' => $filteredResults->count(),
    //         'successful_sends' => $successfulSends,
    //         'failed_sends' => $failedSends,
    //         'processing_time_seconds' => $processingTime,
    //         'success_rate_percent' => round(($successfulSends / $filteredResults->count()) * 100, 1),
    //         'errors' => $errors
    //     ]);

    //     // Log errors to Laravel log
    //     foreach ($errors as $error) {
    //         \Log::error("Individual transaction transmission error: " . $error);
    //     }
    // }
    private function sendIndividualTransactions(Collection $filteredResults): void
    {
        $accountingUrl = config('accounting.endpoint_url', 'https://www.castlebet.darth.bond/api/fnb53nmb');
        $successfulSends = 0;
        $failedSends = 0;
        $duplicateSkips = 0;
        $errors = [];
        $startTime = microtime(true);

        // Track sent transactions to prevent duplicates based on transaction_id + amount + datetime
        $sentTransactionHashes = [];

        foreach ($filteredResults as $index => $transaction) {
            try {
                $transactionId = $transaction['transaction_id'] ?? null;
                $amount = floatval($transaction['amount'] ?? 0);
                $transactionDate = $transaction['transaction_date'];
                $transactionTime = $transaction['transaction_time'] ?? '00:00:00';

                // Create a composite hash for transaction_id + amount + date + time combination
                $compositeHash = md5($transactionId . '|' . $amount . '|' . $transactionDate . '|' . $transactionTime);

                // Check for duplicate transaction_id + amount + date/time combination
                if (in_array($compositeHash, $sentTransactionHashes)) {
                    $duplicateSkips++;
                    $this->line("[" . ($index + 1) . "/{$filteredResults->count()}] Skipping duplicate: {$transactionId} - Amount: {$amount}, DateTime: {$transactionDate} {$transactionTime}");
                    continue;
                }

                // Add to sent transactions tracker
                $sentTransactionHashes[] = $compositeHash;

                // Format time properly (remove the extra space, ensure proper formatting)
                $formattedTime = $transactionDate . ' ' . $transactionTime;

                // Prepare individual transaction payload with "username" instead of "msisdn"
                $payload = [
                    'username' => $transaction['mobile_number'] ?? null,
                    'amount' => floatval($transaction['amount'] ?? 0),
                    'transactionid' => $transactionId,
                    'time' => $formattedTime
                ];

                // Remove null/empty values
                $payload = array_filter($payload, function ($value) {
                    return $value !== null && $value !== '';
                });

                $this->line("[" . ($index + 1) . "/{$filteredResults->count()}] Sending {$transactionId}...");

                // Send individual transaction
                $response = Http::timeout(15)
                    ->connectTimeout(5)
                    ->retry(2, 500) // 2 retries with 500ms delay
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'X-Transaction-ID' => $transactionId,
                    ])
                    ->post($accountingUrl, $payload);

                if ($response->successful()) {
                    $successfulSends++;
                    $this->line("Success ({$response->status()})");

                    // Log successful transaction
                    $this->logIndividualTransaction('SUCCESS', $transaction, $payload, $response->json());
                } else {
                    $failedSends++;
                    $errorMsg = "HTTP {$response->status()}: " . $response->body();
                    $errors[] = "Transaction {$transactionId}: {$errorMsg}";
                    $this->line("Failed: {$errorMsg}");

                    // Log failed transaction
                    $this->logIndividualTransaction('FAILED', $transaction, $payload, null, $errorMsg);
                }

                // Small delay between individual transactions
                if ($index < $filteredResults->count() - 1) {
                    usleep(self::DELAY_BETWEEN_INDIVIDUAL * 1000000);
                }

            } catch (\Exception $e) {
                $failedSends++;
                $errorMsg = "Exception: " . $e->getMessage();
                $errors[] = "Transaction {$transactionId}: {$errorMsg}";
                $this->error("    ✗ {$errorMsg}");

                // Log exception
                $this->logIndividualTransaction('ERROR', $transaction, $payload ?? [], null, $errorMsg);
            }
        }

        $processingTime = round(microtime(true) - $startTime, 2);
        $actualProcessed = $filteredResults->count() - $duplicateSkips;

        // Transmission Summary
        $this->info("=== TRANSMISSION COMPLETE ===");
        $this->info("Processing time: {$processingTime} seconds");
        $this->info("Total transactions: {$filteredResults->count()}");
        $this->info("Duplicate transactions skipped: {$duplicateSkips}");
        $this->info("Transactions processed: {$actualProcessed}");
        $this->info("Successful sends: {$successfulSends}");
        $this->info("Failed sends: {$failedSends}");

        if ($actualProcessed > 0) {
            $this->info("Success rate: " . round(($successfulSends / $actualProcessed) * 100, 1) . "%");
        }

        // Create transmission summary log entry
        $this->createTransmissionSummary([
            'total_filtered_transactions' => $filteredResults->count(),
            'duplicate_transactions_skipped' => $duplicateSkips,
            'transactions_processed' => $actualProcessed,
            'successful_sends' => $successfulSends,
            'failed_sends' => $failedSends,
            'processing_time_seconds' => $processingTime,
            'success_rate_percent' => $actualProcessed > 0 ? round(($successfulSends / $actualProcessed) * 100, 1) : 0,
            'errors' => $errors
        ]);

        // Log errors to Laravel log
        foreach ($errors as $error) {
            \Log::error("Individual transaction transmission error: " . $error);
        }
    }


    /**
     * Filter transactions for current/recent data only
     */
    private function filterCurrentTransactions(Collection $results): Collection
    {
        $today = now()->format('Y-m-d');
        $yesterday = now()->subDay()->format('Y-m-d');

        // Filter for today and yesterday only (adjust as needed)
        return $results->filter(function($record) use ($today, $yesterday) {
            $transactionDate = $record['transaction_date'] ?? '';
            return in_array($transactionDate, [$today, $yesterday]);
        });
    }

/**
     * Create comprehensive transmission summary
     */
    private function createTransmissionSummary(array $summaryData): void
    {
        $transmissionTime = now();
        Storage::makeDirectory('logs');

        $avgTime = $summaryData['total_filtered_transactions'] > 0
            ? round($summaryData['processing_time_seconds'] / $summaryData['total_filtered_transactions'], 3)
            : 0;

        $logEntry = "============================================================\n";
        $logEntry .= "INDIVIDUAL TRANSACTION TRANSMISSION SUMMARY\n";
        $logEntry .= "============================================================\n";
        $logEntry .= "Timestamp                   : {$transmissionTime->format('Y-m-d H:i:s')}\n";
        $logEntry .= "Total Filtered Transactions : {$summaryData['total_filtered_transactions']}\n";
        $logEntry .= "Successful Transmissions    : {$summaryData['successful_sends']}\n";
        $logEntry .= "Failed Transmissions        : {$summaryData['failed_sends']}\n";
        $logEntry .= "Success Rate                : " . number_format($summaryData['success_rate_percent'], 2) . "%\n";
        $logEntry .= "Processing Time             : {$summaryData['processing_time_seconds']} seconds\n";
        $logEntry .= "Average Time Per Transaction: {$avgTime} seconds\n";

        if (!empty($summaryData['errors'])) {
            $logEntry .= "\nERRORS ENCOUNTERED:\n";
            foreach (array_slice($summaryData['errors'], 0, 10) as $error) {
                $logEntry .= "  - {$error}\n";
            }
            if (count($summaryData['errors']) > 10) {
                $logEntry .= "  ... and " . (count($summaryData['errors']) - 10) . " more errors\n";
            }
        }

        $logEntry .= "============================================================\n\n";

        try {
            $logFile = 'logs/transactions_summary_' . $transmissionTime->format('Y-m-d') . '.log';
            Storage::append($logFile, $logEntry);
        } catch (\Exception $e) {
            \Log::error("Failed to write transmission summary: " . $e->getMessage());
        }

        \Log::info('Individual transaction transmission completed', $summaryData);
    }


  /**
     * Log filtering statistics
     */
    private function logFilteringStats(Collection $originalResults, Collection $filteredResults): void
    {
        $originalByDate = $originalResults->groupBy('transaction_date');
        $filteredByDate = $filteredResults->groupBy('transaction_date');

        $this->info("=== FILTERING STATISTICS ===");
        $this->info("Original data spans " . $originalByDate->count() . " dates:");

        foreach ($originalByDate as $date => $records) {
            $filteredCount = $filteredByDate->get($date, collect())->count();
            $status = $filteredCount > 0 ? "✓ SENT" : "✗ SKIPPED";
            $this->info("  {$date}: {$records->count()} records → {$filteredCount} sent ({$status})");
        }
    }

      /**
     * Log individual transaction details
     */
    private function logIndividualTransaction(string $status, array $transaction, array $payload, ?array $response, ?string $error = null): void
    {
        $logData = [
            'status' => $status,
            'transaction_id' => $transaction['transaction_id'],
            'amount' => $transaction['amount'],
            'mobile_number' => $transaction['mobile_number'],
            'transaction_date' => $transaction['transaction_date'],
            'transmission_time' => now()->toISOString(),
            'payload_sent' => $payload,
        ];

        if ($response) {
            $logData['server_response'] = $response;
        }

        if ($error) {
            $logData['error_message'] = $error;
        }

        \Log::info("Individual transaction transmission: {$status}", $logData);
    }




    private function processBatch(array $batch, string $directory): array
    {
        $files = 0;
        $records = 0;
        $successful = 0;
        $errors = [];

        foreach ($batch as $date => $dateRecords) {
            try {
                $filename = $this->createFilenameFromDate($date);
                $filePath = "{$directory}/{$filename}";

                $csvData = $this->prepareCsvData($filePath, $dateRecords);
                $csvString = $this->arrayToCsv($csvData);

                if (Storage::put($filePath, $csvString)) {
                    $files++;
                    $records += $dateRecords->count();

                    // $sendResult = $this->sendToAccountingSoftware($csvString, $filename, $dateRecords);
                    // if ($sendResult['success']) {
                    //     $successful++;
                    // }
                }
            } catch (\Exception $e) {
                $errors[] = "Error processing {$date}: " . $e->getMessage();
            }
        }

        return [
            'files' => $files,
            'records' => $records,
            'successful_sends' => $successful,
            'errors' => $errors
        ];
    }

    private function prepareCsvData(string $filePath, Collection $dateRecords): array
    {
        $csvData = [];

        if (Storage::exists($filePath)) {
            $existingContent = Storage::get($filePath);
            $existingLines = explode("\n", trim($existingContent));
            if (!empty($existingLines) && strpos($existingLines[0], 'Transaction Date') !== false) {
                array_shift($existingLines);
            }
            $csvData = array_filter(array_map('str_getcsv', $existingLines));
        } else {
            $csvData[] = ['Transaction Date', 'Transaction Time', 'Amount', 'Mobile Number', 'Transaction ID'];
        }

        foreach ($dateRecords as $record) {
            $csvData[] = [
                $record['transaction_date'] ?? 'N/A',
                $record['transaction_time'] ?? 'N/A',
                $record['amount'] ?? 'N/A',
                $record['mobile_number'] ?? 'N/A',
                $record['transaction_id'] ?? 'N/A'
            ];
        }

        return $csvData;
    }

    private function logResults(int $files, int $records, int $successful, array $errors): void
    {
        $this->info("Processed: {$files} files, {$records} records, {$successful} sent successfully" .
            (count($errors) > 0 ? ", " . count($errors) . " errors" : ""));

        foreach ($errors as $error) {
            \Log::error("DAT parsing error: " . $error);
        }
    }

    /**
     * Convert array to CSV string efficiently without verbose logging
     */
    private function arrayToCsv(array $data): string
    {
        $output = fopen('php://temp', 'w');

        foreach ($data as $row) {
            // Clean the row data
            $cleanRow = [];
            foreach ($row as $value) {
                if (is_array($value) || is_object($value)) {
                    $cleanRow[] = json_encode($value);
                } else {
                    $cleanRow[] = (string) ($value ?? 'N/A');
                }
            }

            fputcsv($output, $cleanRow);
        }

        rewind($output);
        $csvString = stream_get_contents($output);
        fclose($output);

        return rtrim($csvString, "\n");
    }

    /**
     * Send CSV data to accounting software url
     */
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
        $separator = str_repeat('=', 60);
        $timestamp = $data['transmission_time']->format('Y-m-d H:i:s');

        $log = "\n{$separator}\n";
        $log .= "TRANSMISSION LOG ENTRY\n";
        $log .= "{$separator}\n";
        $log .= "Timestamp       : {$timestamp}\n";
        $log .= "Status          : {$data['status']}\n";
        $log .= "Filename        : {$data['filename']}\n";
        $log .= "Transaction Date: {$data['transaction_date']}\n";
        $log .= "Record Count    : {$data['record_count']}\n";
        $log .= "Total Amount    : " . number_format($data['total_amount'], 2) . "\n";

        if (!empty($data['endpoint_url'])) {
            $log .= "Endpoint URL    : {$data['endpoint_url']}\n";
        }

        if ($data['status'] === 'SUCCESS') {
            $log .= "Response Status : {$data['response_status']}\n";
        }

        if (isset($data['error_message'])) {
            $log .= "Error           : {$data['error_message']}\n";
            if (isset($data['response_status'])) {
                $log .= "HTTP Status     : {$data['response_status']}\n";
            }
        }

        $log .= "{$separator}\n";
        return $log;
    }


    /**
     * Save transmission log to a daily log file.
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
