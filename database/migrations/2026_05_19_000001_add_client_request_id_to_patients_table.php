<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('client_request_id', 64)->nullable()->after('section');
            $table->unique(['section', 'client_request_id'], 'patients_section_client_request_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropUnique('patients_section_client_request_id_unique');
            $table->dropColumn('client_request_id');
        });
    }
};
