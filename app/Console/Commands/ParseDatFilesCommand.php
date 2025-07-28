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

        // Scope to between AUTH CANCELLED and the timestamp+INTERNET marker
        if (preg_match('/AUTH CANCELLED\s+(.*?)\s+\d{14}INTERNET/', $line, $segmentMatch)) {
            $segment = trim($segmentMatch[1]);

            if (preg_match('/(\d{8})(\d{20})(\d{10})/', $segment, $matches)) {
                $dateRaw = $matches[1];
                $amountRaw = $matches[2];
                $mobileRaw = $matches[3];

                $cleanAmount = ltrim($amountRaw, '0');
                $amount = number_format(((int)($cleanAmount ?: '0')) / 100, 2, '.', '');

                $transactionId = '';
                if (preg_match('/' . preg_quote($dateRaw, '/') . '([A-Z0-9]{5,})/', $segment, $tm)) {
                    $transactionId = trim($tm[1]);
                }

                $results->push([
                    'file' => $fileName,
                    'line' => $lineNumber + 1,
                    'date' => $this->parseDate($dateRaw),
                    'amount' => $amount,
                    'mobile_number' => $mobileRaw,
                    'transaction_id' => $transactionId,
                    'raw_line' => $line
                ]);
            } else {
                $this->warn("No transaction match in scoped segment on line {$lineNumber}: {$segment}");
            }
        } else {
            $this->warn("Skipping line {$lineNumber}: missing AUTH CANCELLED → timestamp+INTERNET segment");
        }
    }

    return $results;
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
                $rows = $results->map(function ($record) {
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
     * Save results to a CSV file in storage/app/exports/
     */
    private function saveResults(Collection $results): void
    {
        $directory = 'exports';

        if (!Storage::exists($directory)) {
            Storage::makeDirectory($directory);
        }

        $filename = 'transactions_' . now()->format('Ymd_His') . '.csv';
        $filePath = "{$directory}/{$filename}";

        $csvData = [];

        // Header
        $csvData[] = ['File', 'Line', 'Date', 'Amount', 'Mobile Number', 'Transaction ID'];

        // Data rows
        foreach ($results as $record) {
            $csvData[] = [
                $record['file'],
                $record['line'],
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
}
