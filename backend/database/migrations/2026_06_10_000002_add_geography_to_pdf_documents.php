<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdf_documents', function (Blueprint $table) {
            $table->foreignId('district_id')->nullable()->after('uploaded_by_email')->constrained('districts')->nullOnDelete();
            $table->foreignId('city_id')->nullable()->after('district_id')->constrained('cities')->nullOnDelete();
            $table->foreignId('assembly_id')->nullable()->after('city_id')->constrained('assemblies')->nullOnDelete();
            $table->foreignId('polling_station_id')->nullable()->after('assembly_id')->constrained('polling_stations')->nullOnDelete();
            $table->string('part_number')->nullable()->after('polling_station_id');
            $table->text('batch_notes')->nullable()->after('part_number');

            $table->index('district_id');
            $table->index('city_id');
        });
    }

    public function down(): void
    {
        Schema::table('pdf_documents', function (Blueprint $table) {
            $table->dropForeign(['district_id']);
            $table->dropForeign(['city_id']);
            $table->dropForeign(['assembly_id']);
            $table->dropForeign(['polling_station_id']);
            $table->dropColumn(['district_id', 'city_id', 'assembly_id', 'polling_station_id', 'part_number', 'batch_notes']);
        });
    }
};
