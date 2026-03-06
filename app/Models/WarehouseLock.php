<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseLock extends Model
{
    protected $table = 'warehouse_locks';

    protected $fillable = [
        'warehouse_id',
        'locked_by',
        'reason',
        'locked_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'locked_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function isActive(): bool
    {
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }
        return true;
    }
}
