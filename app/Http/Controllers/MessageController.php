<?php

namespace App\Http\Controllers;

use App\Services\MessageProcessorService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class MessageController extends Controller
{
    protected $messageParser;

    public function __construct(MessageProcessorService $messageParser)
    {
        $this->messageParser = $messageParser;
    }

    public function index()
    {
        return Inertia::render('MessageIndex');
    }

    public function uploadForm(){
        return Inertia::render('Upload');
    }

    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:txt,log|max:10240' // 10MB max
        ]);

        try {
            $file = $request->file('file');
            $fileName = 'uploads/' . uniqid() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('', $fileName);

            // Process the uploaded file
            $result = $this->processTransactionFile($path);

            // Clean up uploaded file
            Storage::delete($path);

            return response()->json([
                'success' => true,
                'message' => "File processed successfully",
                'data' => [
                    'summary' => [
                        'total_lines' => $result['total_lines'],
                        'parsed_records' => $result['parsed_records'],
                        'failed_records' => $result['failed_records'],
                        'processing_time' => $result['processing_time']
                    ],
                    'transactions' => $result['transactions']
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error processing file: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getMessages(Request $request)
    {
        $limit = $request->get('limit', 50);
        $messageType = $request->get('message_type'); // Changed from service_type
        $status = $request->get('status');
        $dateFrom = $request->get('date_from');
        $dateTo = $request->get('date_to');

        try {
            $messages = $this->getTransactionsFromRedis($limit, $messageType, $status, $dateFrom, $dateTo);

            return Inertia::render("Messages", [
                'success' => true,
                'data' => $messages,
                'count' => count($messages),
                'filters_applied' => [
                    'limit' => $limit,
                    'message_type' => $messageType,
                    'status' => $status,
                    'date_range' => $dateFrom && $dateTo ? "{$dateFrom} to {$dateTo}" : null
                ]
            ]);
        } catch (\Exception $e) {
            return Inertia::render("Messages", [
                'success' => false,
                'messages' => 'Error retrieving messages: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getStats()
    {
        try {
            $stats = $this->calculateTransactionStats();

            return Inertia::render("TransactionStats", [
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return Inertia::render("TransactionStats", [
                'success' => false,
                'message' => 'Error retrieving stats: ' . $e->getMessage()
            ], 500);
        }
    }

    // public function processFromPath(Request $request)
    // {
    //     $request->validate([
    //         'file_path' => 'required|string'
    //     ]);

    //     try {
    //         // Check if file exists
    //         if (!Storage::exists($request->file_path) && !file_exists($request->file_path)) {
    //             throw new \Exception("File not found: {$request->file_path}");
    //         }

    //         $result = $this->processTransactionFile($request->file_path);

    //         return response()->json([
    //             'success' => true,
    //             'message' => "File processed successfully from path",
    //             'data' => [
    //                 'file_path' => $request->file_path,
    //                 'summary' => [
    //                     'total_lines' => $result['total_lines'],
    //                     'parsed_records' => $result['parsed_records'],
    //                     'failed_records' => $result['failed_records'],
    //                     'processing_time' => $result['processing_time']
    //                 ]
    //             ]
    //         ]);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'success' => false,
    //             'messages' => 'Error processing file: ' . $e->getMessage()
    //         ], 500);
    //     }
    // }

    public function processFromPath(Request $request)
{
    $request->validate([
        'file' => 'required|file|mimes:txt,log,dat|max:10240', // 10MB limit
    ]);

    try {
    $storedPath = $request->file('file')->store('uploads/messages');
    $fullPath = storage_path("app/private/{$storedPath}"); // Correct and dynamic

        \Log::info("Stored relative path: {$storedPath}");
        \Log::info("Full storage path: {$fullPath}");
        \Log::info("File exists? " . (file_exists($fullPath) ? 'YES' : 'NO'));


        if (!file_exists($fullPath)) {
    throw new \Exception("File not found immediately after storing: {$fullPath}");
}

        // Process the file
        $result = $this->processTransactionFile($fullPath);

        return response()->json([
            'success' => true,
            'message' => "File uploaded and processed successfully",
            'data' => [
                'file_path' => $storedPath,
                'summary' => [
                    'total_lines' => $result['total_lines'],
                    'parsed_records' => $result['parsed_records'],
                    'failed_records' => $result['failed_records'],
                    'processing_time' => $result['processing_time']
                ]
            ]
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error processing uploaded file: ' . $e->getMessage()
        ], 500);
    }
}


    public function cleanup()
    {
        try {
            $result = $this->cleanupExpiredData();

            return response()->json([
                'success' => true,
                'message' => "Cleanup completed successfully",
                'data' => $result
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'messages' => 'Error during cleanup: ' . $e->getMessage()
            ], 500);
        }
    }

       public function show($id)
    {
        $message = Redis::hgetall("message:{$id}");

        if (empty($message)) {
            abort(404, 'Message not found');
        }

        return Inertia::render('MessageDetails', [
            'message' => $message
        ]);
    }

    /**
     * Process transaction file and store in Redis
     */
    private function processTransactionFile($filePath)
    {
        $startTime = microtime(true);

        // Read file content
        if (Storage::exists($filePath)) {
            $fileData = Storage::get($filePath);
        } else {
            $fileData = file_get_contents($filePath);
        }

        if ($fileData === false) {
            throw new \Exception("Failed to read file: {$filePath}");
        }

        // Parse transaction records
        $lines = explode("\n", trim($fileData));
        $transactions = [];
        $failedCount = 0;

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
                $failedCount++;
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
            'parsed_records' => count($transactions) - $failedCount,
            'failed_records' => $failedCount,
            'processing_time' => round(microtime(true) - $startTime, 3),
            'transactions' => $transactions
        ];

        // Store in Redis
        $redis = Redis::connection();
        $redis->setex('transaction-records', 3600, json_encode($processedData)); // 1 hour TTL

        return $processedData;
    }

    /**
     * Parse a single transaction record
     */
    private function parseTransactionRecord($line)
    {
        if (strlen($line) < 50) {
            throw new \Exception("Line too short to be a valid transaction record");
        }

        $record = [
            'message_id' => trim(substr($line, 0, 20)),
            'account_number' => trim(substr($line, 20, 20)),
            'message_type' => trim(substr($line, 40, 10)),
            'description' => trim(substr($line, 50, 30)),
            'transaction_type' => trim(substr($line, 80, 10)),
            'receipt_type' => trim(substr($line, 90, 30)),
            'status' => trim(substr($line, 120, 20)),
            'parsed' => true
        ];

        // Extract additional fields for longer records
        if (strlen($line) > 200) {
            $record['date'] = trim(substr($line, 160, 8));
            $record['amount'] = trim(substr($line, 180, 15));
            $record['reference'] = trim(substr($line, 195, 20));
            $record['time'] = trim(substr($line, 215, 6));
            $record['channel'] = trim(substr($line, 230, 20));
            $record['terminal'] = trim(substr($line, 250, 10));
        }

        // Clean up empty fields
        $record = array_filter($record, function($value) {
            return $value !== '' && $value !== null;
        });

        return $record;
    }

    /**
     * Get transactions from Redis with filtering
     */
    private function getTransactionsFromRedis($limit = 50, $messageType = null, $status = null, $dateFrom = null, $dateTo = null)
    {
        // $redis = Redis::connection();
        $data = Redis::get('local-file-data');

        if (!$data) {
            return [];
        }

        $decodedData = json_decode($data, true);
        if (!isset($decodedData['transactions'])) {
            return [];
        }

        $transactions = $decodedData['transactions'];

        // Apply filters
        if ($messageType) {
            $transactions = array_filter($transactions, function($transaction) use ($messageType) {
                return isset($transaction['message_type']) &&
                       stripos($transaction['message_type'], $messageType) !== false;
            });
        }

        if ($status) {
            $transactions = array_filter($transactions, function($transaction) use ($status) {
                return isset($transaction['status']) &&
                       stripos($transaction['status'], $status) !== false;
            });
        }

        if ($dateFrom && $dateTo) {
            $transactions = array_filter($transactions, function($transaction) use ($dateFrom, $dateTo) {
                if (!isset($transaction['date'])) return true;
                $transactionDate = $transaction['date'];
                return $transactionDate >= $dateFrom && $transactionDate <= $dateTo;
            });
        }

        // Apply limit
        return array_slice(array_values($transactions), 0, $limit);
    }

    /**
     * Calculate transaction statistics
     */
    private function calculateTransactionStats()
    {
        // $redis = Redis::connection();
        $data = Redis::get('local-file-data');

        if (!$data) {
            return [
                'total_records' => 0,
                'parsed_records' => 0,
                'failed_records' => 0,
                'last_processed' => null
            ];
        }

        $decodedData = json_decode($data, true);
        $transactions = $decodedData['transactions'] ?? [];

        // Calculate stats
        $totalRecords = count($transactions);
        $parsedRecords = count(array_filter($transactions, function($t) {
            return isset($t['parsed']) && $t['parsed'] === true;
        }));
        $failedRecords = $totalRecords - $parsedRecords;

        // Group by message type
        $messageTypes = [];
        $statuses = [];

        foreach ($transactions as $transaction) {
            if (isset($transaction['message_type'])) {
                $type = $transaction['message_type'];
                $messageTypes[$type] = ($messageTypes[$type] ?? 0) + 1;
            }

            if (isset($transaction['status'])) {
                $status = $transaction['status'];
                $statuses[$status] = ($statuses[$status] ?? 0) + 1;
            }
        }

        return [
            'total_records' => $totalRecords,
            'parsed_records' => $parsedRecords,
            'failed_records' => $failedRecords,
            'last_processed' => $decodedData['processed_at'] ?? null,
            'processing_time' => $decodedData['processing_time'] ?? null,
            'message_types' => $messageTypes,
            'statuses' => $statuses
        ];
    }

    /**
     * Cleanup expired data and logs
     */
    private function cleanupExpiredData()
    {
        // $redis = Redis::connection();
        $cleanedKeys = 0;

        // Clean up old Redis keys (if you have multiple keys with timestamps)
        $keys = Redis::keys('local-file-data-*');
        $cutoffTime = Carbon::now()->subHours(24)->timestamp;

        foreach ($keys as $key) {
            // Extract timestamp from key if it follows a pattern
            if (preg_match('/transaction-records-(\d+)/', $key, $matches)) {
                $keyTimestamp = $matches[1];
                if ($keyTimestamp < $cutoffTime) {
                    Redis::del($key);
                    $cleanedKeys++;
                }
            }
        }

        // Clean up old log files
        $logsPath = storage_path('logs/file_processing');
        $cleanedLogs = 0;

        if (is_dir($logsPath)) {
            $files = glob($logsPath . '/file_processing_*.log');
            $cutoffDate = Carbon::now()->subWeek();

            foreach ($files as $file) {
                $fileDate = Carbon::createFromTimestamp(filemtime($file));
                if ($fileDate->lt($cutoffDate)) {
                    if (unlink($file)) {
                        $cleanedLogs++;
                    }
                }
            }
        }

        return [
            'cleaned_redis_keys' => $cleanedKeys,
            'cleaned_log_files' => $cleanedLogs,
            'cleanup_time' => Carbon::now()->toISOString()
        ];
    }


}
