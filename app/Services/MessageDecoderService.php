<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class MessageDecoderService
{
    /**
     * Decode a message string and extract components
     * Format: YYYYMMDDHHMMSSAMOUNT+REFERENCEID+PHONENUMBER
     * Example: 2025071000000000000000015040812308818NAM0SVFGY9QB
     */
    public function decodeMessage(string $message): array
    {
        try {
            // Extract date (first 10 characters: YYYYMMDDHH)
            $dateString = substr($message, 0, 10);
            $year = substr($dateString, 0, 4);
            $month = substr($dateString, 4, 2);
            $day = substr($dateString, 6, 2);
            $hour = substr($dateString, 8, 2);

            // Create date object
            $date = Carbon::createFromFormat('Y-m-d H:i:s', "$year-$month-$day $hour:00:00");

            // Extract phone number (last 10 digits before reference ID)
            // First, find the reference ID (assuming it starts after the amount)
            // Look for alphabetic characters which indicate start of reference ID
            preg_match('/([A-Z0-9]+)$/', $message, $matches);
            $referenceId = $matches[1] ?? '';

            // Remove date and reference ID to get amount + phone number
            $remainingString = substr($message, 10); // Remove date
            $remainingString = str_replace($referenceId, '', $remainingString); // Remove reference ID

            // Phone number is last 10 digits
            $phoneNumber = substr($remainingString, -10);

            // Amount is everything between date and phone number
            $amountString = substr($remainingString, 0, -10);

            // Remove leading zeros and convert to decimal format
            $amountInt = (int) $amountString;
            $amount = number_format($amountInt / 100, 2, '.', '');

            return [
                'date' => $date->toDateTimeString(),
                'amount' => $amount,
                'phone_number' => $phoneNumber,
                'reference_id' => $referenceId,
                'original_message' => $message,
                'processed_at' => now()->toDateTimeString()
            ];

        } catch (\Exception $e) {
            Log::error('Error decoding message: ' . $e->getMessage(), [
                'message' => $message
            ]);

            return [
                'error' => 'Failed to decode message',
                'original_message' => $message,
                'processed_at' => now()->toDateTimeString()
            ];
        }
    }

    /**
     * Store decoded message in Redis
     */
    public function storeInRedis(array $decodedMessage): void
    {
        try {
            $key = 'receipt_message:' . ($decodedMessage['reference_id'] ?? uniqid());

            Redis::setex($key, 86400 * 7, json_encode($decodedMessage)); // Store for 7 days

            // Also add to a list for easier retrieval
            Redis::lpush('receipt_messages:list', $key);

            // Keep only latest 1000 messages in the list
            Redis::ltrim('receipt_messages:list', 0, 999);

            Log::info('Message stored in Redis', ['key' => $key]);

        } catch (\Exception $e) {
            Log::error('Error storing message in Redis: ' . $e->getMessage(), [
                'decoded_message' => $decodedMessage
            ]);
        }
    }

    /**
     * Process a single message: decode and store
     */
    public function processMessage(string $message): array
    {
        $decodedMessage = $this->decodeMessage($message);
        $this->storeInRedis($decodedMessage);

        return $decodedMessage;
    }
}
