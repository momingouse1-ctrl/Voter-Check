<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('districts', function (Blueprint $table) {
            $table->id();
            $table->string('name_en');
            $table->string('name_te')->nullable();
            $table->string('code')->nullable()->unique();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('assemblies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('district_id')->constrained('districts')->cascadeOnDelete();
            $table->string('name_en');
            $table->string('name_te')->nullable();
            $table->string('assembly_code')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->index('district_id');
        });

        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('district_id')->constrained('districts')->cascadeOnDelete();
            $table->foreignId('assembly_id')->nullable()->constrained('assemblies')->nullOnDelete();
            $table->string('name_en');
            $table->string('name_te')->nullable();
            $table->enum('type', ['city', 'town', 'mandal', 'village', 'urban', 'rural'])->default('city');
            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->index('district_id');
            $table->index('assembly_id');
        });

        Schema::create('polling_stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('district_id')->constrained('districts')->cascadeOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->foreignId('assembly_id')->nullable()->constrained('assemblies')->nullOnDelete();
            $table->string('station_number')->nullable();
            $table->string('station_name_en')->nullable();
            $table->string('station_name_te')->nullable();
            $table->string('location')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->index('district_id');
            $table->index('city_id');
            $table->index('assembly_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('polling_stations');
        Schema::dropIfExists('cities');
        Schema::dropIfExists('assemblies');
        Schema::dropIfExists('districts');
    }
};
