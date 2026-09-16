<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payment
 */
class PaymentAttemptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'status' => $this->status->value,
            'failure_code' => $this->failure_code,
            'provider_ref' => $this->provider_ref,
            'created_at' => $this->created_at?->utc()->toIso8601String(),
            'processed_at' => $this->processed_at?->utc()->toIso8601String(),
        ];
    }
}
