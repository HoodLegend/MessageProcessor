<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class DecodeBankMessages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bank:decode-messages {--file=} {--all}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Decode bank .DAT message files and store in Redis and database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            $messagesDir = '/var/www/bank/nam/ReceiptIt-client/messages';

            if (!is_dir($messagesDir)) {
                $this->error("Messages directory not found: {$messagesDir}");
                return 1;
            }

            $specificFile = $this->option('file');
            $processAll = $this->option('all');

            if ($specificFile) {
                // Process specific file
                $filePath = $messagesDir . '/' . $specificFile;
                if (file_exists($filePath)) {
                    $this->processFile($filePath);
                } else {
                    $this->error("File not found: {$filePath}");
                    return 1;
                }
            } else {
                // Process new or all DAT files
                $this->processNewDATFiles($messagesDir, $processAll);
            }

        } catch (\Exception $e) {
            $this->error('Error decoding bank messages: ' . $e->getMessage());
            Log::error('Bank decode exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }

        return 0;
    }

    /**
     * Process DAT files from messages directory
     */
    private function processNewDATFiles($messagesDir, $processAll = false)
    {
        try {
            $datFiles = glob($messagesDir . '/*.DAT');
            $processedCount = 0;

            foreach ($datFiles as $datFile) {
                $fileName = basename($datFile);

                // Skip already processed files unless --all option is used
                if (!$processAll && Redis::sismember('bank:processed_files', $fileName)) {
                    $this->line("Skipping already processed file: {$fileName}");
                    continue;
                }

                $this->info("Processing file: {$fileName}");

                if ($this->processFile($datFile)) {
                    $processedCount++;
                    // Mark file as processed in Redis
                    Redis::sadd('bank:processed_files', $fileName);
                }
            }

            $this->info("Processed {$processedCount} files");

        } catch (\Exception $e) {
            Log::error('Error processing .DAT files', [
                'message' => $e->getMessage(),
                'directory' => $messagesDir
            ]);
            throw $e;
        }
    }

    /**
     * Process individual DAT file
     */
    private function processFile($filePath)
    {
        try {
            $fileName = basename($filePath);
            $content = file_get_contents($filePath);

            if (!$content) {
                Log::warning("Empty or unreadable .DAT file: {$fileName}");
                return false;
            }

            // Split content into lines for processing
            $lines = explode("\n", trim($content));
            $transactionCount = 0;

            foreach ($lines as $lineNumber => $line) {
                if (empty(trim($line))) continue;

                // Parse the transaction line
                $transactionData = $this->parseTransactionLine($line);

                if ($transactionData) {
                    // Store in Redis
                    $this->storeInRedis($transactionData, $fileName);

                    // Store in database
                    $this->storeTransactionData($transactionData, $fileName);

                    $transactionCount++;

                    Log::info("Parsed bank transaction", [
                        'file' => $fileName,
                        'line' => $lineNumber + 1,
                        'transaction_id' => $transactionData['transaction_id'],
                        'mobile_number' => $transactionData['mobile_number'],
                        'amount' => $transactionData['amount'],
                        'status' => $transactionData['status']
                    ]);
                }
            }

            $this->info("  → Found {$transactionCount} transactions in {$fileName}");
            return true;

        } catch (\Exception $e) {
            Log::error('Error processing file', [
                'file' => $filePath,
                'message' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Parse individual transaction line based on your format
     */
    private function parseTransactionLine($line)
    {
        try {
            // Look for AUTH CANCELLED or other status indicators
            if (!preg_match('/(AUTH CANCELLED|AUTH APPROVED|DECLINED)/i', $line, $statusMatches)) {
                return null; // Not a transaction line
            }

            $status = trim($statusMatches[1]);

            // Find the position after the status
            $statusPos = strpos($line, $status);
            $afterStatus = substr($line, $statusPos + strlen($status));

            // Remove leading whitespace
            $afterStatus = ltrim($afterStatus);

            // Pattern: 8 digits (date) + variable zeros + amount + mobile(10 digits) + transaction_id
            if (preg_match('/^(\d{8})0+(\d+)(\d{10})(.+)/', $afterStatus, $matches)) {
                $dateExtracted = $matches[1];
                $amountRaw = $matches[2];
                $mobileNumber = $matches[3];
                $transactionId = trim($matches[4]);

                // Format amount (divide by 100 to get decimal places)
                $amount = number_format($amountRaw / 100, 2);

                // Format date (YYYYMMDD to Y-m-d)
                $formattedDate = date('Y-m-d', strtotime($dateExtracted));

                // Extract main transaction reference from beginning of line
                $reference = trim(substr($line, 0, strpos($line, 'PAYMENTS')));

                return [
                    'transaction_id' => $transactionId,
                    'mobile_number' => $mobileNumber,
                    'amount' => $amount,
                    'currency' => 'NAM',
                    'date' => $formattedDate,
                    'date_raw' => $dateExtracted,
                    'status' => $status,
                    'reference' => $reference,
                    'amount_raw' => $amountRaw,
                    'parsed_at' => Carbon::now()->toISOString()
                ];
            }

            return null;

        } catch (\Exception $e) {
            Log::error('Error parsing transaction line', [
                'line' => $line,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Store transaction data in Redis
     */
    private function storeInRedis($transactionData, $fileName)
    {
        try {
            $key = "bank:transaction:{$transactionData['transaction_id']}";

            // Store transaction data as hash
            Redis::hmset($key, [
                'transaction_id' => $transactionData['transaction_id'],
                'mobile_number' => $transactionData['mobile_number'],
                'amount' => $transactionData['amount'],
                'currency' => $transactionData['currency'],
                'date' => $transactionData['date'],
                'status' => $transactionData['status'],
                'reference' => $transactionData['reference'],
                'source_file' => $fileName,
                'parsed_at' => $transactionData['parsed_at']
            ]);

            // Set expiration (30 days)
            Redis::expire($key, 30 * 24 * 60 * 60);

            // Add to mobile number index
            Redis::sadd("bank:mobile:{$transactionData['mobile_number']}", $transactionData['transaction_id']);
            Redis::expire("bank:mobile:{$transactionData['mobile_number']}", 30 * 24 * 60 * 60);

            // Add to date index
            Redis::sadd("bank:date:{$transactionData['date']}", $transactionData['transaction_id']);
            Redis::expire("bank:date:{$transactionData['date']}", 30 * 24 * 60 * 60);

            // Add to status index
            Redis::sadd("bank:status:{$transactionData['status']}", $transactionData['transaction_id']);
            Redis::expire("bank:status:{$transactionData['status']}", 30 * 24 * 60 * 60);

        } catch (\Exception $e) {
            Log::error('Error storing in Redis', [
                'transaction_data' => $transactionData,
                'error' => $e->getMessage()
            ]);
        }
    }
}


