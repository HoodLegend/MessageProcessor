<?php

namespace App\Http\Controllers;

use App\Services\RedisMessageRetriever;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ReceiptMessageController extends Controller
{
    protected RedisMessageRetriever $messageRetriever;

    public function __construct(RedisMessageRetriever $messageRetriever)
    {
        $this->messageRetriever = $messageRetriever;
    }

    public function index()
    {
        $messages = $this->messageRetriever->getAllMessages();

        return Inertia::render("Messages", [
            'success' => true,
            'data' => $messages,
            'total' => count($messages)
        ]);
    }

    public function getByPhoneNumber(string $phoneNumber): JsonResponse
    {
        $messages = $this->messageRetriever->getMessagesByPhoneNumber($phoneNumber);

        return response()->json([
            'success' => true,
            'data' => $messages,
            'total' => count($messages)
        ]);
    }

    public function getByReferenceId(string $referenceId): JsonResponse
    {
        $message = $this->messageRetriever->getMessageByReferenceId($referenceId);

        if (!$message) {
            return response()->json([
                'success' => false,
                'message' => 'Message not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $message
        ]);
    }
}
