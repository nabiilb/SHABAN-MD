<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LessonTopic extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'name_so', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return app()->getLocale() === 'so' && $this->name_so ? $this->name_so : $this->name;
    }
}
