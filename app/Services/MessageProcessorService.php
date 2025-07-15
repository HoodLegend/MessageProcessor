<?php

namespace App\Services;

use App\Models\Message;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class MessageProcessorService
{
        protected $logChannel = 'message_processing';

    public function parseFile($filePath)
    {
        if (!Storage::exists($filePath)) {
            throw new \Exception("File not found: {$filePath}");
        }

        $content = Storage::get($filePath);
        $lines = explode("\n", $content);
        $processedCount = 0;
        $errors = [];

        foreach ($lines as $lineNumber => $line) {
            $line = trim($line);
            if (empty($line)) continue;

            try {
                $parsedMessage = $this->parseMessage($line);
                $this->storeInRedis($parsedMessage);
                $this->createLogFile($parsedMessage, $lineNumber + 1);
                $processedCount++;
            } catch (\Exception $e) {
                $error = "Line {$lineNumber}: " . $e->getMessage();
                $errors[] = $error;
                Log::channel($this->logChannel)->error($error);
            }
        }

        return [
            'processed' => $processedCount,
            'errors' => $errors,
            'total_lines' => count($lines)
        ];
    }

    protected function parseMessage($line)
    {
        // Based on your example: 201008170000000000000004500039367306            20100417VODS2R8MMKSB   2010061705035500INTERNET  BIS     XNN

        if (strlen($line) < 50) {
            throw new \Exception("Message too short: {$line}");
        }

        // Parse the fixed-width format
        $parsed = [
            'timestamp' => substr($line, 0, 14), // 201008170000000000000004500039367306 (first part)
            'transaction_id' => trim(substr($line, 30, 20)), // 20100417VODS2R8MMKSB
            'date_time' => substr($line, 51, 14), // 2010061705035500
            'service_type' => trim(substr($line, 65, 10)), // INTERNET
            'provider' => trim(substr($line, 76, 8)), // BIS
            'status' => trim(substr($line, 85, 8)), // XNN
            'raw_message' => $line,
            'processed_at' => now()->toISOString(),
            'message_id' => uniqid('msg_', true)
        ];

        return $parsed;
    }

    protected function storeInRedis($parsedMessage)
    {
        $key = "message:{$parsedMessage['message_id']}";
        $expirationTime = time() + (86400 * 7); // 7 days from now

        // Store individual message
        Redis::hmset($key, $parsedMessage);
        Redis::expire($key, 86400 * 7); // Expire after 7 days

        // Add to sorted set for time-based queries
        Redis::zadd('messages:by_time', time(), $parsedMessage['message_id']);

        // Add to expiration tracking set
        Redis::zadd('messages:expiration', $expirationTime, $parsedMessage['message_id']);

        // Add to lists by service type
        Redis::lpush("messages:service:{$parsedMessage['service_type']}", $parsedMessage['message_id']);

        // Maintain counters
        Redis::incr('messages:total_count');
        Redis::incr("messages:count:{$parsedMessage['service_type']}");
    }

    protected function createLogFile($parsedMessage, $lineNumber)
    {
        $logData = [
            'line_number' => $lineNumber,
            'message_id' => $parsedMessage['message_id'],
            'parsed_data' => $parsedMessage,
            'processed_at' => now()->toISOString(),
            'expires_at' => now()->addDays(7)->toISOString()
        ];

        $fileName = "message_logs/message_{$parsedMessage['message_id']}.json";
        Storage::put($fileName, json_encode($logData, JSON_PRETTY_PRINT));

        // Store file path for cleanup later
        Redis::zadd('log_files:expiration', time() + (86400 * 7), $fileName);

        // Also log to Laravel log system
        Log::channel($this->logChannel)->info("Processed message", [
            'message_id' => $parsedMessage['message_id'],
            'line_number' => $lineNumber,
            'service_type' => $parsedMessage['service_type']
        ]);
    }

    public function getMessagesFromRedis($limit = 50, $serviceType = null)
    {
        if ($serviceType) {
            $messageIds = Redis::lrange("messages:service:{$serviceType}", 0, $limit - 1);
        } else {
            $messageIds = Redis::zrevrange('messages:by_time', 0, $limit - 1);
        }

        $messages = [];
        foreach ($messageIds as $messageId) {
            $message = Redis::hgetall("message:{$messageId}");
            if (!empty($message)) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    public function getStats()
    {
        return [
            'total_messages' => Redis::get('messages:total_count') ?: 0,
            'service_counts' => $this->getServiceCounts(),
            'recent_messages_count' => Redis::zcard('messages:by_time')
        ];
    }

    protected function getServiceCounts()
    {
        $keys = Redis::keys('messages:count:*');
        $counts = [];

        foreach ($keys as $key) {
            $service = str_replace('messages:count:', '', $key);
            $counts[$service] = Redis::get($key);
        }

        return $counts;
    }

    public function processFileFromPath($filePath)
    {
        // Track last processed position
        $positionKey = "file_position:" . md5($filePath);
        $lastPosition = Redis::get($positionKey) ?: 0;

        if (!Storage::exists($filePath)) {
            throw new \Exception("File not found: {$filePath}");
        }

        $content = Storage::get($filePath);
        $lines = explode("\n", $content);
        $totalLines = count($lines);

        // Only process new lines
        $newLines = array_slice($lines, $lastPosition);
        $processedCount = 0;
        $errors = [];

        foreach ($newLines as $index => $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $actualLineNumber = $lastPosition + $index + 1;

            try {
                $parsedMessage = $this->parseMessage($line);
                $this->storeInRedis($parsedMessage);
                $this->createLogFile($parsedMessage, $actualLineNumber);
                $processedCount++;
            } catch (\Exception $e) {
                $error = "Line {$actualLineNumber}: " . $e->getMessage();
                $errors[] = $error;
                Log::channel($this->logChannel)->error($error);
            }
        }

        // Update last processed position
        Redis::set($positionKey, $totalLines);

        return [
            'processed' => $processedCount,
            'errors' => $errors,
            'total_lines' => $totalLines,
            'new_lines' => count($newLines),
            'last_position' => $lastPosition
        ];
    }

    public function cleanupExpiredData()
    {
        $currentTime = time();
        $cleanupResults = [
            'redis_messages' => 0,
            'log_files' => 0,
            'errors' => []
        ];

        try {
            // Clean up expired Redis messages
            $expiredMessageIds = Redis::zrangebyscore('messages:expiration', 0, $currentTime);

            foreach ($expiredMessageIds as $messageId) {
                // Remove from all Redis structures
                Redis::del("message:{$messageId}");
                Redis::zrem('messages:by_time', $messageId);
                Redis::zrem('messages:expiration', $messageId);

                // Remove from service type lists (this is approximate since we don't know the service type)
                $serviceKeys = Redis::keys('messages:service:*');
                foreach ($serviceKeys as $serviceKey) {
                    Redis::lrem($serviceKey, 0, $messageId);
                }

                $cleanupResults['redis_messages']++;
            }

            // Clean up expired log files
            $expiredLogFiles = Redis::zrangebyscore('log_files:expiration', 0, $currentTime);

            foreach ($expiredLogFiles as $fileName) {
                if (Storage::exists($fileName)) {
                    Storage::delete($fileName);
                }
                Redis::zrem('log_files:expiration', $fileName);
                $cleanupResults['log_files']++;
            }

            Log::channel($this->logChannel)->info("Cleanup completed", $cleanupResults);

        } catch (\Exception $e) {
            $error = "Cleanup error: " . $e->getMessage();
            $cleanupResults['errors'][] = $error;
            Log::channel($this->logChannel)->error($error);
        }

        return $cleanupResults;
    }
}
