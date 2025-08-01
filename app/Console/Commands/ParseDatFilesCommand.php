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

        // Match everything between "AUTH CANCELLED" and the next 14-digit timestamp + INTERNET
         if (preg_match('/AUTH\s+CANCELLED\s+(.*?)\d{14}INTERNET/i', $line, $segmentMatch))
{
            $segment = trim($segmentMatch[1]);

            // FIXED: Made mobile number pattern more flexible (9-12 digits instead of exactly 10)
            if (preg_match('/(\d{8})\s*(\d{20})\s*(\d{9,12})/', $segment, $matches)) {
                $dateRaw = $matches[1];
                $amountRaw = $matches[2];
                $mobileRaw = $matches[3];

                $cleanAmount = ltrim($amountRaw, '0');
                $amount = number_format(((int)($cleanAmount ?: '0')) / 100, 2, '.', '');

                // FIXED: Look for transaction ID in the full line, not just the segment
                $transactionId = '';
                if (preg_match('/' . preg_quote($dateRaw, '/') . '([A-Z][A-Z0-9]{4,})/', $line, $tm)) {
                    $transactionId = trim($tm[1]);
                }

                $results->push([
                    'date' => $this->parseDate($dateRaw),
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
                    $amountValue = (int)($cleanAmount ?: '0');

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
                        'date' => $this->parseDate($dateRaw),
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
            $amount = number_format(((int)($cleanAmount ?: '0')) / 100, 2, '.', '');

            // Try to extract transaction ID
            $transactionId = $this->extractTransactionId($segment, $dateRaw);

            return [
                'date' => $this->parseDate($dateRaw),
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
                $this->line('Date,Amount,Mobile Number,Transaction ID');
                foreach ($results as $record) {
                    $this->line(sprintf(
                        '%s,%d,%s,%s',
                        $record['date'],
                        $record['amount'],
                        $record['mobile_number'],
                        $record['transaction_id']
                    ));
                }
                break;

            default: // table
                $headers = ['Date', 'Amount', 'Mobile Number', 'Transaction ID'];
                $rows = $results->map(function ($record) {
                    return [
                        $record['date'],
                        $record['amount'],
                        $record['mobile_number'],
                        $record['transaction_id']
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

        $dataDate = $this->extractDateFromResults($results);

        // $filename = 'transactions_' . now()->format('Ymd_His') . '.csv';
         $filename = $dataDate . '.csv';
        $filePath = "{$directory}/{$filename}";

        $csvData = [];

        // Header
        $csvData[] = ['Date', 'Amount', 'Mobile Number', 'Transaction ID'];

        // Data rows
        foreach ($results as $record) {
            $csvData[] = [
                $record['date'],
                $record['amount'],
                $record['mobile_number'],
                $record['transaction_id']
            ];
        }

        // Convert array to CSV string
        $csvString = collect($csvData)->map(function ($row) {
            return implode(',', $row);
        })->implode("\n");

        Storage::put($filePath, $csvString);

        $this->info("✓ Results saved to storage/app/{$filePath}");
    }

    private function extractDateFromResults(Collection $results): string
{
    if ($results->isEmpty()) {
        return now()->format('Ymd');
    }

    // Count occurrences of each date
    $dateCounts = $results->groupBy('date')->map->count();

    // Get the most frequent date
    $mostCommonDate = $dateCounts->keys()->sortByDesc(function($date) use ($dateCounts) {
        return $dateCounts[$date];
    })->first();

    if (!$mostCommonDate) {
        return now()->format('Ymd');
    }

    try {
        $date = \Carbon\Carbon::parse($mostCommonDate);
        return $date->format('Ymd');
    } catch (\Exception $e) {
        $this->warn("Could not parse date '{$mostCommonDate}', using current date");
        return now()->format('Ymd');
    }
}
}
