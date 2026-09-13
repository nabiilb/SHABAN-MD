<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseCategory extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'name_so', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(CompanyExpense::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return app()->getLocale() === 'so' && $this->name_so ? $this->name_so : $this->name;
    }
}
