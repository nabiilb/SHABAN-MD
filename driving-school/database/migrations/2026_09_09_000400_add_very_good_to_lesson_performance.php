<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Adds the "Very Good" rating between Excellent and Good. */
return new class extends Migration
{
    private const WITH_VERY_GOOD = "'excellent','very_good','good','average','needs_improvement','poor'";

    private const WITHOUT_VERY_GOOD = "'excellent','good','average','needs_improvement','poor'";

    public function up(): void
    {
        DB::statement('ALTER TABLE lessons MODIFY performance ENUM('.self::WITH_VERY_GOOD.') NULL');
    }

    public function down(): void
    {
        DB::table('lessons')->where('performance', 'very_good')->update(['performance' => 'good']);

        DB::statement('ALTER TABLE lessons MODIFY performance ENUM('.self::WITHOUT_VERY_GOOD.') NULL');
    }
};
