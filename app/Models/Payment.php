<?php

namespace App\Models;

use App\Domain\Payments\PaymentStatus;
use App\Exceptions\Domain\IllegalTransitionException;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

/**
 * A retained payment attempt.
 *
 * Rows are never deleted. Identity fields (provider, amount, currency) never
 * change; lifecycle fields (status, provider_ref, attempts, processed_at) move
 * forward through the guarded machine.
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'reservation_id',
        'provider',
        'provider_ref',
        'amount',
        'currency',
        'status',
        'failure_code',
        'raw_payload',
        'processed_at',
        'attempts',
        'next_retry_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'raw_payload' => 'array',
            'processed_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'amount' => 'integer',
            'attempts' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Reservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * @param  array<string, mixed>  $attrs  Lifecycle fields written in the same save.
     *
     * @throws IllegalTransitionException
     */
    public function transitionTo(PaymentStatus $to, array $attrs = []): static
    {
        Log::withContext([
            'payment_id' => $this->getKey(),
            'reservation_id' => $this->reservation_id,
        ]);

        $from = $this->status;

        if (! $from->canTransitionTo($to)) {
            Log::warning('Refused illegal payment transition.', [
                'from' => $from->value,
                'to' => $to->value,
            ]);

            throw IllegalTransitionException::for('Payment', $this->getKey(), $from, $to);
        }

        $this->fill($attrs);
        $this->status = $to;
        $this->save();

        Log::info('Payment transitioned.', [
            'from' => $from->value,
            'to' => $to->value,
        ]);

        return $this;
    }
}
