<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('section', 20)->default('nurse')->after('age');
            $table->index('section');
        });

        // Safety backfill: all existing records belong to nursing section.
        DB::table('patients')->update(['section' => 'nurse']);
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropIndex(['section']);
            $table->dropColumn('section');
        });
    }
};
