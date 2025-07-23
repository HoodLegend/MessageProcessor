<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageTransactionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
               return [
            'transaction_id' => $this->resource['transaction_id'],
            'date' => $this->resource['date'],
            'amount' => [
                'value' => (float) $this->resource['amount'],
                'formatted' => '$' . number_format((float) $this->resource['amount'], 2)
            ],
            'mobile_number' => $this->resource['mobile_number'],
            'source' => [
                'file' => $this->resource['file'],
                'line' => $this->resource['line']
            ],
            'raw_data' => $this->when(
                $request->get('include_raw', false),
                $this->resource['raw_line'] ?? null
            ),
            'processed_at' => $this->when(
                isset($this->resource['processed_at']),
                $this->resource['processed_at']
            )
        ];
    }
}
