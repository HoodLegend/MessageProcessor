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
    protected $signature = 'files:parse-dat {--output=table : Output format (table, json, csv)} {--save : Save results to a file}';

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
            $this->saveResults($allResults, $outputFormat);
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

        // Split content into lines and process each line
        $lines = explode("\n", $content);

        // foreach ($lines as $lineNumber => $line) {
        //     $line = trim($line);

        //     // Skip empty lines
        //     if (empty($line)) {
        //         continue;
        //     }

        //     // Look for the pattern: YYYYMMDD followed by amount and mobile number
        //     // Pattern: 20250710 00000000000000008800 812345678
        //     if (preg_match('/(\d{8})(\d{20})(\d{10})/', $line, $matches)) {
        //         $dateStr = $matches[1];
        //         $amountStr = $matches[2];
        //         $mobileStr = $matches[3];

        //         // Extract transaction ID - look for pattern like "20250710NAM0ABCDE1FG"
        //         $transactionId = '';
        //         if (preg_match('/(\d{8}[A-Z0-9]+)/', $line, $transMatches)) {
        //             $transactionId = trim($transMatches[1]);
        //         }

        //         // Parse date (YYYYMMDD to YYYY-MM-DD)
        //         $date = $this->parseDate($dateStr);

        //         // Parse amount (remove leading zeros and format as decimal)
        //         $amount = $this->parseAmount($amountStr);

        //         // Clean mobile number (remove leading zeros if any)
        //         $mobileNumber = ltrim($mobileStr, '0');

        //         $results->push([
        //             'file' => $fileName,
        //             'line' => $lineNumber + 1,
        //             'date' => $date,
        //             'amount' => $amount,
        //             'mobile_number' => $mobileNumber,
        //             'transaction_id' => $transactionId,
        //             'raw_line' => $line
        //         ]);
        //     }
        // }

        foreach ($lines as $lineNumber => $line) {
            $line = trim($line);

            // Match the amount, mobile number, and transaction ID pattern
            if (preg_match('/(\d{8}).*?(\d{20})(\d{10}).*?(\d{8})([A-Z0-9]{12})/', $line, $matches)) {
                $dateStr = $matches[1];            // first date
                $amountRaw = $matches[2];          // e.g., 0000000000000000030800
                $mobileRaw = $matches[3];          // e.g., 0811111111
                $transactionId = $matches[5];      // e.g., NAM0EPCD9AB

                $amount = number_format(((int) $amountRaw) / 100, 2, '.', '');
                $mobileNumber = ltrim($mobileRaw, '0'); // remove leading zeros

                $results->push([
                    'file' => $fileName,
                    'line' => $lineNumber + 1,
                    'date' => $this->parseDate($dateStr),
                    'amount' => $amount,
                    'mobile_number' => $mobileNumber,
                    'transaction_id' => $transactionId,
                    'raw_line' => $line
                ]);
            } else {
                $this->warn("No match in line {$lineNumber}: {$line}");
            }
        }

        return $results;
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
     * Parse amount from padded string to decimal format
     */
    private function parseAmount(string $amountStr): string
    {
        // Remove leading zeros and convert to decimal (divide by 100 for cents)
        $amount = ltrim($amountStr, '0');
        if (empty($amount)) {
            return '0.00';
        }

        // Convert to decimal (assuming last 2 digits are cents)
        $amountValue = (int)$amount;
        return number_format($amountValue / 100, 2, '.', '');
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
}

// foreach ($lines as $lineNumber => $line) {
//             $line = trim($line);

//             // Match the amount, mobile number, and transaction ID pattern
//             if (preg_match('/(\d{8}).*?(\d{20})(\d{10}).*?(\d{8})([A-Z0-9]{12})/', $line, $matches)) {
//                 $dateStr = $matches[1];            // first date
//                 $amountRaw = $matches[2];          // e.g., 0000000000000000030800
//                 $mobileRaw = $matches[3];          // e.g., 0811111111
//                 $transactionId = $matches[5];      // e.g., NAM0EPCD9AB

//                 $amount = number_format(((int) $amountRaw) / 100, 2, '.', '');
//                 $mobileNumber = ltrim($mobileRaw, '0'); // remove leading zeros

//                 $results->push([
//                     'file' => $fileName,
//                     'line' => $lineNumber + 1,
//                     'date' => $this->parseDate($dateStr),
//                     'amount' => $amount,
//                     'mobile_number' => $mobileNumber,
//                     'transaction_id' => $transactionId,
//                     'raw_line' => $line
//                 ]);
//             } else {
//                 $this->warn("No match in line {$lineNumber}: {$line}");
//             }
//         }
