<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Http;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class SendDataToServerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'data:send-to-server {--url=https://www.example.com/api/receive : Target server URL} {--timeout=30 : Request timeout in seconds}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send Redis transaction data to external server and log transmissions';

        /**
     * Path to the transmission logs directory
     */
    private $logsPath;

    /**
     * Default server URL
     */
    private $serverUrl;

    /**
     * Request timeout
     */
    private $timeout;

    public function __construct()
    {
        parent::__construct();
        $this->logsPath = storage_path('logs/decoded_messages');
        $this->serverUrl = 'https://www.example.com/api/receive';
        $this->timeout = 30;
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

            // Get URL and timeout from options
            $this->serverUrl = $this->option('url');
            $this->timeout = (int) $this->option('timeout');

            // Fetch data from Redis
            $redisData = $this->fetchDataFromRedis();

            if (!$redisData) {
                $message = 'No data found in Redis to send';
                $this->info($message);
                $this->logTransmission('INFO', $message, null, null);
                return 0;
            }

            // Prepare data for transmission
            $transmissionData = $this->prepareTransmissionData($redisData);

            // Send data to server
            $response = $this->sendDataToServer($transmissionData);

            // Log the transmission
            $this->logSuccessfulTransmission($transmissionData, $response);

            $this->info("Data successfully sent to {$this->serverUrl}");
            return 0;

        } catch (\Exception $e) {
            $errorMessage = "Error sending data to server: " . $e->getMessage();
            $this->error($errorMessage);
            $this->logTransmission('ERROR', $errorMessage, null, $e);
            return 1;
        }
    }

    /**
     * Fetch transaction data from Redis
     */
    private function fetchDataFromRedis()
    {
        // $redis = Redis::connection();

        $data = Redis::get('local-file-data');
        // Try different Redis keys that might contain data
        $possibleKeys = [
            'transaction-records',
            'local-file-data',
        ];

        foreach ($possibleKeys as $key) {
            $data = Redis::get($key);
            if ($data) {
                $decodedData = json_decode($data, true);
                if ($decodedData && !empty($decodedData)) {
                    $this->logTransmission('INFO', "Data fetched from Redis key: {$key}", count($decodedData), null);
                    return $decodedData;
                }
            }
        }

        return null;
    }

    /**
     * Prepare data for transmission to external server
     */
    private function prepareTransmissionData($redisData)
    {
        $transmissionData = [
            'source' => 'transaction_processor',
            'timestamp' => Carbon::now()->toISOString(),
            'data_type' => $redisData['type'] ?? 'unknown',
            'transmission_id' => uniqid('tx_'),
            'server_info' => [
                'hostname' => gethostname(),
                'ip' => $_SERVER['SERVER_ADDR'] ?? 'unknown'
            ]
        ];

        // Include transaction data
        if (isset($redisData['transactions'])) {
            $transmissionData['transactions'] = $redisData['transactions'];
            $transmissionData['summary'] = [
                'total_records' => count($redisData['transactions']),
                'parsed_records' => $redisData['parsed_records'] ?? 0,
                'failed_records' => $redisData['failed_records'] ?? 0,
                'processed_at' => $redisData['processed_at'] ?? null
            ];
        } else {
            // Handle other data types
            $transmissionData['payload'] = $redisData;
        }

        return $transmissionData;
    }

    /**
     * Send data to external server
     */
    private function sendDataToServer($data)
    {
        $this->info("Sending data to: {$this->serverUrl}");

        $startTime = microtime(true);

        $response = Http::timeout($this->timeout)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'User-Agent' => 'TransactionProcessor/1.0',
                'X-Transmission-ID' => $data['transmission_id']
            ])
            ->post($this->serverUrl, $data);

        $responseTime = round(microtime(true) - $startTime, 3);

        if (!$response->successful()) {
            throw new \Exception(
                "HTTP {$response->status()}: {$response->body()}"
            );
        }

        return [
            'status_code' => $response->status(),
            'response_body' => $response->json() ?? $response->body(),
            'response_time' => $responseTime,
            'headers' => $response->headers()
        ];
    }

    /**
     * Log successful transmission
     */
    private function logSuccessfulTransmission($transmissionData, $response)
    {
        $recordCount = 0;
        if (isset($transmissionData['transactions'])) {
            $recordCount = count($transmissionData['transactions']);
        } elseif (isset($transmissionData['payload']) && is_array($transmissionData['payload'])) {
            $recordCount = count($transmissionData['payload']);
        }

        $logData = [
            'transmission_id' => $transmissionData['transmission_id'],
            'records_sent' => $recordCount,
            'response_status' => $response['status_code'],
            'response_time' => $response['response_time'] . 's',
            'server_response' => is_array($response['response_body']) ?
                json_encode($response['response_body']) :
                $response['response_body']
        ];

        $this->logTransmission('SUCCESS', 'Data transmission completed', $recordCount, null, $logData);
    }

    /**
     * Create a detailed log entry
     */
    private function logTransmission($level, $message, $recordCount = null, $exception = null, $additionalData = [])
    {
        $timestamp = Carbon::now()->format('Y-m-d H:i:s');
        $logEntry = [
            'timestamp' => $timestamp,
            'level' => $level,
            'message' => $message,
            'url' => $this->serverUrl,
            'record_count' => $recordCount
        ];

        if ($exception) {
            $logEntry['exception'] = [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine()
            ];
        }

        $logEntry = array_merge($logEntry, $additionalData);

        // Create formatted log entry
        $formattedLog = "[{$timestamp}] {$level}: {$message}";
        if ($recordCount !== null) {
            $formattedLog .= " (Records: {$recordCount})";
        }
        $formattedLog .= " | URL: {$this->serverUrl}";

        if (!empty($additionalData)) {
            $formattedLog .= " | Details: " . json_encode($additionalData);
        }

        if ($exception) {
            $formattedLog .= " | Exception: " . $exception->getMessage();
        }

        $formattedLog .= PHP_EOL;

        // Create a daily log file for transmissions
        $logFileName = 'data_transmission_' . Carbon::now()->format('Y-m-d') . '.log';
        $logFilePath = $this->logsPath . '/' . $logFileName;

        file_put_contents($logFilePath, $formattedLog, FILE_APPEND | LOCK_EX);

        // Also create a JSON log for structured data
        $jsonLogFileName = 'data_transmission_' . Carbon::now()->format('Y-m-d') . '.json';
        $jsonLogFilePath = $this->logsPath . '/' . $jsonLogFileName;

        $jsonLogEntry = json_encode($logEntry) . PHP_EOL;
        file_put_contents($jsonLogFilePath, $jsonLogEntry, FILE_APPEND | LOCK_EX);
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

        $patterns = [
            'data_transmission_*.log',
            'data_transmission_*.json'
        ];

        $deletedCount = 0;

        foreach ($patterns as $pattern) {
            $files = glob($this->logsPath . '/' . $pattern);

            foreach ($files as $file) {
                $fileDate = Carbon::createFromTimestamp(filemtime($file));

                if ($fileDate->lt($cutoffDate)) {
                    if (unlink($file)) {
                        $deletedCount++;
                    }
                }
            }
        }

        if ($deletedCount > 0) {
            $this->logTransmission('INFO', "Cleaned up {$deletedCount} old transmission log files", null, null);
        }
    }

    /**
     * Get transmission statistics
     */
    public function getTransmissionStats()
    {
        $stats = [
            'total_transmissions' => 0,
            'successful_transmissions' => 0,
            'failed_transmissions' => 0,
            'total_records_sent' => 0,
            'last_transmission' => null
        ];

        if (!is_dir($this->logsPath)) {
            return $stats;
        }

        $jsonFiles = glob($this->logsPath . '/data_transmission_*.json');

        foreach ($jsonFiles as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            foreach ($lines as $line) {
                $logEntry = json_decode($line, true);
                if ($logEntry) {
                    $stats['total_transmissions']++;

                    if ($logEntry['level'] === 'SUCCESS') {
                        $stats['successful_transmissions']++;
                        $stats['total_records_sent'] += $logEntry['record_count'] ?? 0;
                    } elseif ($logEntry['level'] === 'ERROR') {
                        $stats['failed_transmissions']++;
                    }

                    if (!$stats['last_transmission'] || $logEntry['timestamp'] > $stats['last_transmission']) {
                        $stats['last_transmission'] = $logEntry['timestamp'];
                    }
                }
            }
        }

        return $stats;
    }
}
