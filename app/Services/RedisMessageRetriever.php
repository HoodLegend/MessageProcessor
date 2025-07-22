<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

class RedisMessageRetriever
{
    /**
     * Get all processed messages
     */
    public function getAllMessages(): array
    {
        $messageKeys = Redis::lrange('receipt_messages:list', 0, -1);
        $messages = [];

        foreach ($messageKeys as $key) {
            $messageData = Redis::get($key);
            if ($messageData) {
                $messages[] = json_decode($messageData, true);
            }
        }

        return $messages;
    }

    /**
     * Get messages by phone number
     */
    public function getMessagesByPhoneNumber(string $phoneNumber): array
    {
        $allMessages = $this->getAllMessages();

        return array_filter($allMessages, function($message) use ($phoneNumber) {
            return isset($message['phone_number']) && $message['phone_number'] === $phoneNumber;
        });
    }

    /**
     * Get messages by date range
     */
    public function getMessagesByDateRange(string $startDate, string $endDate): array
    {
        $allMessages = $this->getAllMessages();

        return array_filter($allMessages, function($message) use ($startDate, $endDate) {
            if (!isset($message['date'])) return false;

            $messageDate = $message['date'];
            return $messageDate >= $startDate && $messageDate <= $endDate;
        });
    }

    /**
     * Get message by reference ID
     */
    public function getMessageByReferenceId(string $referenceId): ?array
    {
        $messageData = Redis::get("receipt_message:{$referenceId}");
        return $messageData ? json_decode($messageData, true) : null;
    }

    /**
     * Get total message count
     */
    public function getTotalMessageCount(): int
    {
        return Redis::llen('receipt_messages:list');
    }
}
