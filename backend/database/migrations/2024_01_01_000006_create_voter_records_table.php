<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voter_records', function (Blueprint $table) {
            $table->id();
            $table->string('source_file');
            $table->string('source_sheet')->default('Voter Records');
            $table->unsignedInteger('source_row');
            $table->unsignedInteger('pdf_page')->nullable();
            $table->unsignedInteger('part_no')->nullable();
            $table->unsignedInteger('roll_page_no')->nullable();
            $table->unsignedInteger('serial_no')->nullable();
            $table->string('house_no')->nullable();
            $table->string('voter_name')->nullable();
            $table->string('relation_type')->nullable();
            $table->string('relation')->nullable();
            $table->string('relative_name')->nullable();
            $table->string('gender')->nullable();
            $table->string('gender_english')->nullable();
            $table->unsignedSmallInteger('age')->nullable();
            $table->string('voter_id')->nullable();
            $table->json('raw_data')->nullable();
            $table->longText('search_text')->nullable();
            $table->longText('normalized_text')->nullable();
            $table->longText('romanized_text')->nullable();
            $table->timestamps();

            $table->unique(['source_file', 'source_sheet', 'source_row']);
            $table->index('voter_id');
            $table->index('part_no');
            $table->index('serial_no');
            $table->index(['part_no', 'serial_no']);
            $table->index('pdf_page');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voter_records');
    }
};
