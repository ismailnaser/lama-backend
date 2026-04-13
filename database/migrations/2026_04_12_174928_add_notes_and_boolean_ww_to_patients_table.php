<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('age');
        });

        DB::table('patients')->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                $ww = $row->ww ?? null;
                if ($ww !== null && trim((string) $ww) !== '') {
                    DB::table('patients')->where('id', $row->id)->update(['notes' => (string) $ww]);
                }
            }
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn('ww');
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->boolean('ww')->default(false)->after('age');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn(['notes', 'ww']);
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->string('ww')->nullable()->after('age');
        });
    }
};
