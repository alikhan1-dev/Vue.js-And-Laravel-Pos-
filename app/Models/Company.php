<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'currency',
        'timezone',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** Company has many Branches (branch-to-company hierarchy). */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    /** Company has many Products (tenant-scoped). */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** Company has many Accounts (chart of accounts). */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }
}

