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
   private string $redisKey = "dat_transactions";



    /**
     * Get all transactions
     */
    public function index(Request $request)
    {
        try {
            $redisKey = $request->get('redis_key', $this->redisKey);
            $data = Redis::get($redisKey);

            if (!$data) {
                return response()->json([
                    'success' => false,
                    'message' => 'No transaction data found',
                    'data' => []
                ], 404);
            }

            $transactions = json_decode($data, true);

            // Apply pagination if requested
            $perPage = $request->get('per_page', null);
            $page = $request->get('page', 1);

            if ($perPage) {
                $total = count($transactions);
                $offset = ($page - 1) * $perPage;
                $paginatedTransactions = array_slice($transactions, $offset, $perPage);

                return response()->json([
                    'success' => true,
                    'data' => $paginatedTransactions,
                    'pagination' => [
                        'current_page' => (int) $page,
                        'per_page' => (int) $perPage,
                        'total' => $total,
                        'last_page' => ceil($total / $perPage)
                    ]
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => $transactions,
                'total' => count($transactions)
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving transactions: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific transaction by transaction ID
     */
    public function show(Request $request, string $transactionId)
    {
        try {
            $redisKey = $request->get('redis_key', $this->redisKey);
            $transactionKey = "{$redisKey}:transaction:{$transactionId}";
            $data = Redis::get($transactionKey);

            if (!$data) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction not found',
                    'transaction_id' => $transactionId
                ], 404);
            }

            $transaction = json_decode($data, true);

            return response()->json([
                'success' => true,
                'data' => $transaction
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving transaction: ' . $e->getMessage()
            ], 500);
        }
    }

     /**
     * Search transactions by various criteria
     */
    public function search(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'mobile_number' => 'nullable|string',
            'date' => 'nullable|date_format:Y-m-d',
            'date_from' => 'nullable|date_format:Y-m-d',
            'date_to' => 'nullable|date_format:Y-m-d',
            'amount' => 'nullable|numeric',
            'amount_min' => 'nullable|numeric',
            'amount_max' => 'nullable|numeric',
            'file' => 'nullable|string',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
            'redis_key' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $redisKey = $request->get('redis_key', $this->redisKey);
            $data = Redis::get($redisKey);

            if (!$data) {
                return response()->json([
                    'success' => false,
                    'message' => 'No transaction data found',
                    'data' => []
                ], 404);
            }

            $transactions = json_decode($data, true);
            $filtered = $this->filterTransactions($transactions, $request);

            // Apply pagination if requested
            $perPage = $request->get('per_page', null);
            $page = $request->get('page', 1);

            if ($perPage) {
                $total = count($filtered);
                $offset = ($page - 1) * $perPage;
                $paginatedTransactions = array_slice($filtered, $offset, $perPage);

                return response()->json([
                    'success' => true,
                    'data' => $paginatedTransactions,
                    'pagination' => [
                        'current_page' => (int) $page,
                        'per_page' => (int) $perPage,
                        'total' => $total,
                        'last_page' => ceil($total / $perPage)
                    ]
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => $filtered,
                'total' => count($filtered)
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error searching transactions: ' . $e->getMessage()
            ], 500);
        }
    }

        /**
     * Get transactions by mobile number
     */
    public function getByMobile(Request $request, string $mobileNumber): JsonResponse
    {
        try {
            $redisKey = $request->get('redis_key', $this->redisKey);
            $data = Redis::get($redisKey);

            if (!$data) {
                return response()->json([
                    'success' => false,
                    'message' => 'No transaction data found',
                    'data' => []
                ], 404);
            }

            $transactions = json_decode($data, true);
            $filtered = array_filter($transactions, function($transaction) use ($mobileNumber) {
                return $transaction['mobile_number'] === $mobileNumber;
            });

            return response()->json([
                'success' => true,
                'data' => array_values($filtered),
                'total' => count($filtered),
                'mobile_number' => $mobileNumber
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving transactions: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get transactions by date
     */
    public function getByDate(Request $request, string $date): JsonResponse
    {
        $validator = Validator::make(['date' => $date], [
            'date' => 'required|date_format:Y-m-d'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid date format. Use YYYY-MM-DD',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $redisKey = $request->get('redis_key', $this->redisKey);
            $data = Redis::get($redisKey);

            if (!$data) {
                return response()->json([
                    'success' => false,
                    'message' => 'No transaction data found',
                    'data' => []
                ], 404);
            }

            $transactions = json_decode($data, true);
            $filtered = array_filter($transactions, function($transaction) use ($date) {
                return $transaction['date'] === $date;
            });

            return response()->json([
                'success' => true,
                'data' => array_values($filtered),
                'total' => count($filtered),
                'date' => $date
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving transactions: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get transaction statistics
     */
    public function stats(Request $request): JsonResponse
    {
        try {
            $redisKey = $request->get('redis_key', $this->redisKey);

            // Get metadata
            $metadataKey = "{$redisKey}:metadata";
            $metadataData = Redis::get($metadataKey);
            $metadata = $metadataData ? json_decode($metadataData, true) : null;

            // Get main data for additional calculations
            $data = Redis::get($redisKey);

            if (!$data) {
                return response()->json([
                    'success' => false,
                    'message' => 'No transaction data found'
                ], 404);
            }

            $transactions = json_decode($data, true);

            // Calculate statistics
            $amounts = array_column($transactions, 'amount');
            $totalAmount = array_sum($amounts);
            $averageAmount = count($amounts) > 0 ? $totalAmount / count($amounts) : 0;

            $stats = [
                'total_transactions' => count($transactions),
                'total_amount' => round($totalAmount, 2),
                'average_amount' => round($averageAmount, 2),
                'min_amount' => count($amounts) > 0 ? min($amounts) : 0,
                'max_amount' => count($amounts) > 0 ? max($amounts) : 0,
                'unique_mobile_numbers' => count(array_unique(array_column($transactions, 'mobile_number'))),
                'unique_dates' => count(array_unique(array_column($transactions, 'date'))),
                'unique_files' => count(array_unique(array_column($transactions, 'file'))),
                'date_range' => [
                    'from' => min(array_column($transactions, 'date')),
                    'to' => max(array_column($transactions, 'date'))
                ]
            ];

            // Add metadata if available
            if ($metadata) {
                $stats['processed_at'] = $metadata['processed_at'];
                $stats['files_processed'] = $metadata['files_processed'];
            }

            // Add Redis info
            $ttl = Redis::ttl($redisKey);
            $stats['redis_info'] = [
                'key' => $redisKey,
                'ttl' => $ttl > 0 ? $ttl : 'No expiration',
                'memory_usage' => strlen($data) . ' bytes'
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving statistics: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Clear all transaction data
     */
    public function clear(Request $request): JsonResponse
    {
        try {
            $redisKey = $request->get('redis_key', $this->redisKey);

            // Get all keys related to this data
            $keys = Redis::keys("{$redisKey}*");

            if (empty($keys)) {
                return response()->json([
                    'success' => true,
                    'message' => 'No data found to clear'
                ]);
            }

            $deletedCount = Redis::del($keys);

            return response()->json([
                'success' => true,
                'message' => 'All transaction data cleared successfully',
                'deleted_keys' => $deletedCount
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error clearing data: ' . $e->getMessage()
            ], 500);
        }
    }



      /**
     * Remove transaction from main data array
     */
    private function removeFromMainData(string $redisKey, string $transactionId): void
    {
        $data = Redis::get($redisKey);
        if ($data) {
            $transactions = json_decode($data, true);
            $filtered = array_filter($transactions, function($transaction) use ($transactionId) {
                return $transaction['transaction_id'] !== $transactionId;
            });

            Redis::set($redisKey, json_encode(array_values($filtered)));
        }
    }
}
