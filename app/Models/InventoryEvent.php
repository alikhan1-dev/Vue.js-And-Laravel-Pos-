<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Central event log for inventory: microservices, analytics, replay.
 * Written by listeners when inventory-related events are dispatched.
 */
class InventoryEvent extends Model
{
    protected $table = 'inventory_events';

    protected $fillable = [
        'event_type',
        'event_id',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public static function record(string $eventType, array $payload = []): self
    {
        return self::create([
            'event_type' => $eventType,
            'event_id' => (string) Str::uuid(),
            'payload' => $payload,
        ]);
    }
}
