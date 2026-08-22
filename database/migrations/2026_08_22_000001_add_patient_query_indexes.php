<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->index(['section', 'created_at'], 'patients_section_created_at_index');
            $table->index(['section', 'id_no', 'created_at'], 'patients_section_id_no_created_at_index');
        });

        Schema::table('patient_audit_logs', function (Blueprint $table) {
            $table->index(['action', 'patient_id'], 'patient_audit_logs_action_patient_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropIndex('patients_section_created_at_index');
            $table->dropIndex('patients_section_id_no_created_at_index');
        });

        Schema::table('patient_audit_logs', function (Blueprint $table) {
            $table->dropIndex('patient_audit_logs_action_patient_id_index');
        });
    }
};
