<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voter_records', function (Blueprint $table) {
            $table->foreignId('district_id')->nullable()->after('id')->constrained('districts')->nullOnDelete();
            $table->foreignId('city_id')->nullable()->after('district_id')->constrained('cities')->nullOnDelete();
            $table->foreignId('assembly_id')->nullable()->after('city_id')->constrained('assemblies')->nullOnDelete();
            $table->foreignId('polling_station_id')->nullable()->after('assembly_id')->constrained('polling_stations')->nullOnDelete();
            $table->string('house_no_normalized')->nullable()->after('house_no');

            $table->index('district_id');
            $table->index('city_id');
            $table->index('assembly_id');
            $table->index('house_no_normalized');
        });
    }

    public function down(): void
    {
        Schema::table('voter_records', function (Blueprint $table) {
            $table->dropForeign(['district_id']);
            $table->dropForeign(['city_id']);
            $table->dropForeign(['assembly_id']);
            $table->dropForeign(['polling_station_id']);
            $table->dropColumn(['district_id', 'city_id', 'assembly_id', 'polling_station_id', 'house_no_normalized']);
        });
    }
};
