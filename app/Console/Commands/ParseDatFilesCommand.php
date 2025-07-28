<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Collection;

class ParseDatFilesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'files:parse-dat {--output=table : Output format (table, json, csv)} {--save : Save results to a file} {--v|verbose : Show debug output}';

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

        // Check if dat_files directory exists
        if (!Storage::exists($datFilesPath)) {
            $this->error("DAT files directory does not exist: storage/app/{$datFilesPath}");
            $this->info("Please run 'php artisan files:move-dat' first to move DAT files to storage.");
            return Command::FAILURE;
        }

        // Get all .DAT files
        $datFiles = Storage::files($datFilesPath);
        $datFiles = array_filter($datFiles, function($file) {
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
                $results = $this->parseFileContentUpdated($content, $fileName);
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
            $this->saveResults($allResults, $outputFormat);
        }

        $this->info("\nSummary: Extracted {$totalRecords} transaction records from " . count($datFiles) . " file(s)");

        return Command::SUCCESS;
    }

/**
 * Parse the content of a DAT file - Fixed for your specific format
 */
// private function parseFileContent(string $content, string $fileName): Collection
// {
//     $results = collect();

//     // Split content into lines and process each line
//     $lines = explode("\n", $content);

//     $this->info("Debug: Processing {$fileName} with " . count($lines) . " lines");

//     foreach ($lines as $lineNumber => $line) {
//         $line = trim($line);

//         // Skip empty lines
//         if (empty($line)) {
//             continue;
//         }

//         // Debug: Show first few lines to understand the format
//         if ($lineNumber < 3) {
//             $this->line("Debug Line " . ($lineNumber + 1) . ": " . $line);
//         }

//         $record = $this->extractTransactionData($line, $fileName, $lineNumber);

//         if ($record) {
//             $results->push($record);
//             $this->line("Debug: Extracted - Date: {$record['date']}, Amount: {$record['amount']}, Mobile: {$record['mobile_number']}, TransID: {$record['transaction_id']}");
//         }
//     }

//     return $results;
// }

/**
 * Main parsing method that handles multiple transactions per line
 */
private function parseLineForTransactions(string $line, string $fileName, int $lineNumber): Collection
{
    $results = collect();

    // 1. Narrow the string to only the part after AUTH CANCELLED and before BIS XNN
    if (preg_match('/AUTH CANCELLED(.*?)BIS\s+XNN/', $line, $sectionMatch)) {
        $transactionSection = $sectionMatch[1];

        // 2. Now extract the relevant transaction data
        if (preg_match('/(\d{8})(0{10,}\d{3,})(\d{10})\s+(\d{8})(NAM[0-9A-Z]{11})/', $transactionSection, $matches)) {
            $date = $matches[1];               // e.g. 20250508
            $amountStr = $matches[2];          // e.g. 0000000000000000180000
            $mobileStr = $matches[3];          // e.g. 0816260547
            $transactionId = $matches[5];      // e.g. NAM03DWWTXQB

            // Parse and clean data
            $parsedDate = $this->parseDate($date);
            $amount = $this->parseAmountFromPaddedString($amountStr);
            $mobileNumber = ltrim($mobileStr, '0');

            $results->push([
                'file' => $fileName,
                'line' => $lineNumber + 1,
                'date' => $parsedDate,
                'amount' => $amount,
                'mobile_number' => $mobileNumber,
                'transaction_id' => $transactionId,
                'raw_line' => $line
            ]);

            $this->line("✓ Parsed transaction — Date: {$parsedDate}, Amount: {$amount}, Mobile: {$mobileNumber}, TxID: {$transactionId}");
        } else {
            $this->warn("⚠ No transaction match in trimmed section on line {$lineNumber}");
        }
    } else {
        $this->warn("⚠ Could not find AUTH CANCELLED → BIS XNN block on line {$lineNumber}");
    }

    return $results;
}


/**
 * Updated parseFileContent to use the new parsing method
 */
private function parseFileContentUpdated(string $content, string $fileName): Collection
{
    $results = collect();
    $lines = explode("\n", $content);

    $this->info("Processing {$fileName} with " . count($lines) . " lines");

    foreach ($lines as $lineNumber => $line) {
        $line = trim($line);

        if (empty($line)) {
            continue;
        }

        // Debug: Show first few lines
        if ($lineNumber < 3) {
            $this->line("Line " . ($lineNumber + 1) . ": " . substr($line, 0, 100) . (strlen($line) > 100 ? '...' : ''));
        }

        // Parse this line for transactions
        $lineResults = $this->parseLineForTransactions($line, $fileName, $lineNumber);
        $results = $results->merge($lineResults);
    }

    return $results;
}


/**
 * Extract transaction data from the line based on your specific format
 */
private function extractTransactionData(string $line, string $fileName, int $lineNumber): ?array
{
    // Look for the pattern: YYYYMMDD followed by amount and mobile number
    // Pattern: 2025050800000000000000039000816111111
    // Where: 20250508 (date) + 00000000000000039000 (amount) + 816111111 (mobile)

    if (preg_match('/(\d{8})(\d{20})(\d{9,10})/', $line, $matches)) {
        $dateStr = $matches[1];
        $amountStr = $matches[2];
        $mobileStr = $matches[3];

        // Extract transaction ID - pattern: YYYYMMDDNAM01EXYTABC or similar
        $transactionId = '';
        if (preg_match('/(\d{8}[A-Z0-9]+)/', $line, $transMatches)) {
            // Get everything after the 8-digit date
            $fullMatch = $transMatches[1];
            $transactionId = substr($fullMatch, 8); // Remove the date part
        }

        // Parse date (YYYYMMDD to YYYY-MM-DD)
        $date = $this->parseDate($dateStr);

        // Parse amount - find the actual amount in the padded string
        $amount = $this->parseAmountFromPaddedString($amountStr);

        // Clean mobile number (remove leading zeros)
        $mobileNumber = ltrim($mobileStr, '0');

        return [
            'file' => $fileName,
            'line' => $lineNumber + 1,
            'date' => $date,
            'amount' => $amount,
            'mobile_number' => $mobileNumber,
            'transaction_id' => $transactionId,
            'raw_line' => $line
        ];
    }

    return null;
}


/**
 * Parse amount from the padded 20-digit string
 * Example: 00000000000000039000 should become 39.00
 */
private function parseAmountFromPaddedString(string $amountStr): string
{
    // Remove all leading zeros
    $amount = ltrim($amountStr, '0');

    if (empty($amount)) {
        return '0.00';
    }

    // Convert to integer
    $amountValue = (int)$amount;

    // The amount appears to be in cents format (3900 = 39.00)
    // So we need to divide by 100
    return number_format($amountValue / 100, 2, '.', '');
}


/**
 * Alternative parsing method if the above doesn't work perfectly
 * This looks for multiple patterns in the same line
 */
private function extractTransactionDataAlternative(string $line, string $fileName, int $lineNumber): ?array
{
    $results = [];

    // First, find all date-amount-mobile patterns: YYYYMMDDAAAAAAAAAAAAAAAAAAAAMMMMMMMMM
    if (preg_match_all('/(\d{8})(\d{20})(\d{9,10})/', $line, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $dateStr = $match[1];
            $amountStr = $match[2];
            $mobileStr = $match[3];

            // For each match, try to find a corresponding transaction ID
            $transactionId = '';

            // Look for transaction ID pattern that starts with the same date
            if (preg_match('/' . $dateStr . '([A-Z0-9]+)/', $line, $transMatches)) {
                $transactionId = $transMatches[1];
            }

            $results[] = [
                'file' => $fileName,
                'line' => $lineNumber + 1,
                'date' => $this->parseDate($dateStr),
                'amount' => $this->parseAmountFromPaddedString($amountStr),
                'mobile_number' => ltrim($mobileStr, '0'),
                'transaction_id' => $transactionId,
                'raw_line' => $line
            ];
        }
    }

    return empty($results) ? null : $results[0]; // Return first match for now
}

/**
 * Enhanced parsing that handles your specific example
 */
private function parseSpecificFormat(string $line): array
{
    $transactions = [];

    // Your example: 2025050800000000000000039000816111111 20250508NAM01EXYTABC
    // Pattern 1: Date + Amount + Mobile (continuous digits)
    preg_match_all('/(\d{8})(\d{20})(\d{9,10})/', $line, $dataMatches, PREG_SET_ORDER);

    // Pattern 2: Date + Transaction ID (date followed by alphanumeric)
    preg_match_all('/(\d{8})([A-Z0-9]+)/', $line, $idMatches, PREG_SET_ORDER);

    // Match them up by date
    foreach ($dataMatches as $dataMatch) {
        $date = $dataMatch[1];
        $amount = $this->parseAmountFromPaddedString($dataMatch[2]);
        $mobile = ltrim($dataMatch[3], '0');

        // Find corresponding transaction ID with same date
        $transactionId = '';
        foreach ($idMatches as $idMatch) {
            if ($idMatch[1] === $date) {
                $transactionId = $idMatch[2];
                break;
            }
        }

        $transactions[] = [
            'date' => $this->parseDate($date),
            'amount' => $amount,
            'mobile_number' => $mobile,
            'transaction_id' => $transactionId
        ];
    }

    return $transactions;
}

    /**
     * Parse date from YYYYMMDD format to YYYY-MM-DD
     */
    private function parseDate(string $dateStr): string
    {
        if (strlen($dateStr) === 8) {
            return substr($dateStr, 0, 4) . '-' . substr($dateStr, 4, 2) . '-' . substr($dateStr, 6, 2);
        }
        return $dateStr;
    }

    /**
 * Process a regex match and add to results
 */
private function processMatch(array $matches, string $line, string $fileName, int $lineNumber, Collection $results, string $patternName): void
{
    $dateStr = $matches[1];
    $amountStr = $matches[2];
    $mobileStr = $matches[3];

    $this->line("Debug: {$patternName} matched - Date: {$dateStr}, Amount: {$amountStr}, Mobile: {$mobileStr}");

    // Extract transaction ID - look for pattern like "20250710NAM0ABCDE1FG"
    $transactionId = '';
    if (preg_match('/(\d{8}[A-Z0-9]+)/', $line, $transMatches)) {
        $transactionId = trim($transMatches[1]);
    } else {
        // Try to extract any alphanumeric string that might be a transaction ID
        if (preg_match('/([A-Z0-9]{10,})/', $line, $transMatches)) {
            $transactionId = trim($transMatches[1]);
        }
    }

    // Parse date (YYYYMMDD to YYYY-MM-DD)
    $date = $this->parseDate($dateStr);

    // Parse amount (remove leading zeros and format as decimal)
    $amount = $this->parseAmount($amountStr);

    // Clean mobile number (remove leading zeros if any)
    $mobileNumber = ltrim($mobileStr, '0');

    $results->push([
        'file' => $fileName,
        'line' => $lineNumber + 1,
        'date' => $date,
        'amount' => $amount,
        'mobile_number' => $mobileNumber,
        'transaction_id' => $transactionId,
        'raw_line' => $line,
        'pattern_used' => $patternName
    ]);
}

    /**
 * Enhanced amount parsing with better handling
 */
private function parseAmount(string $amountStr): string
{
    // Remove leading zeros
    $amount = ltrim($amountStr, '0');
    if (empty($amount)) {
        return '0.00';
    }

    // Handle different amount formats
    $amountValue = (int)$amount;

    // If the amount seems too large (more than 6 digits), assume last 2 digits are cents
    if ($amountValue > 999999) {
        return number_format($amountValue / 100, 2, '.', '');
    } else {
        // Otherwise, treat as whole currency units
        return number_format($amountValue, 2, '.', '');
    }
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
                $this->line('File,Line,Date,Amount,Mobile Number,Transaction ID');
                foreach ($results as $record) {
                    $this->line(sprintf(
                        '%s,%d,%s,%s,%s,%s',
                        $record['file'],
                        $record['line'],
                        $record['date'],
                        $record['amount'],
                        $record['mobile_number'],
                        $record['transaction_id']
                    ));
                }
                break;

            default: // table
                $headers = ['File', 'Line', 'Date', 'Amount', 'Mobile Number', 'Transaction ID'];
                $rows = $results->map(function($record) {
                    return [
                        $record['file'],
                        $record['line'],
                        $record['date'],
                        '$' . $record['amount'],
                        $record['mobile_number'],
                        $record['transaction_id']
                    ];
                })->toArray();

                $this->table($headers, $rows);
                break;
        }
    }

    /**
     * Save results to CSV file (always executed)
     */
    private function saveToCsv(Collection $results): string
    {
        $timestamp = now()->format('Y-m-d_H-i-s');
        $fileName = "dat_transactions_{$timestamp}.csv";
        $filePath = "csv_exports/{$fileName}";

        // Create the directory if it doesn't exist
        Storage::makeDirectory('csv_exports');

        // Prepare CSV content
        $csvContent = "File,Line,Date,Amount,Mobile Number,Transaction ID\n";

        foreach ($results as $record) {
            $csvContent .= sprintf(
                "%s,%d,%s,%s,%s,%s\n",
                $this->escapeCsvField($record['file']),
                $record['line'],
                $record['date'],
                $record['amount'],
                $record['mobile_number'],
                $this->escapeCsvField($record['transaction_id'])
            );
        }

        Storage::put($filePath, $csvContent);

        return Storage::path($filePath);
    }

    /**
     * Escape CSV field if it contains commas, quotes, or newlines
     */
    private function escapeCsvField(string $field): string
    {
        // If field contains comma, quote, or newline, wrap in quotes and escape internal quotes
        if (strpos($field, ',') !== false || strpos($field, '"') !== false || strpos($field, "\n") !== false) {
            return '"' . str_replace('"', '""', $field) . '"';
        }
        return $field;
    }

    /**
     * Save results to a file
     */
    private function saveResults(Collection $results, string $format): void
    {
        $timestamp = now()->format('Y-m-d_H-i-s');
        $fileName = "dat_parse_results_{$timestamp}";

        switch ($format) {
            case 'json':
                $content = json_encode($results->toArray(), JSON_PRETTY_PRINT);
                $fileName .= '.json';
                break;

            case 'csv':
                $content = "File,Line,Date,Amount,Mobile Number,Transaction ID\n";
                foreach ($results as $record) {
                    $content .= sprintf(
                        "%s,%d,%s,%s,%s,%s\n",
                        $record['file'],
                        $record['line'],
                        $record['date'],
                        $record['amount'],
                        $record['mobile_number'],
                        $record['transaction_id']
                    );
                }
                $fileName .= '.csv';
                break;

            default:
                $content = $results->toJson();
                $fileName .= '.json';
                break;
        }

        Storage::put("parsed_results/{$fileName}", $content);
        $this->info("Results saved to: storage/app/parsed_results/{$fileName}");
    }

    /**
 * Add a method to show file content sample for debugging
 */
private function debugFileContent(string $content, string $fileName): void
{
    $this->info("=== DEBUG: Content sample from {$fileName} ===");

    // Show file size
    $this->line("File size: " . strlen($content) . " bytes");

    // Show first 500 characters
    $sample = substr($content, 0, 500);
    $this->line("First 500 characters:");
    $this->line($sample);

    // Show line count and first few lines
    $lines = explode("\n", $content);
    $this->line("Total lines: " . count($lines));

    $this->line("First 5 lines:");
    for ($i = 0; $i < min(5, count($lines)); $i++) {
        $this->line("Line " . ($i + 1) . ": " . trim($lines[$i]));
    }

    $this->line("=== END DEBUG ===\n");
}
}
