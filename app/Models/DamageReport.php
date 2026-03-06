<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DamageReport extends Model
{
    protected $table = 'damage_reports';

    protected $fillable = [
        'company_id',
        'warehouse_id',
        'report_number',
        'report_date',
        'reason',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
