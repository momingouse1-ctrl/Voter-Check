<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_documents', function (Blueprint $table) {
            $table->id();
            $table->string('original_name');
            $table->string('stored_path');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->unsignedInteger('total_pages')->default(0);
            $table->enum('status', ['uploaded', 'queued', 'processing', 'indexed', 'failed'])->default('uploaded');
            $table->string('extraction_method')->nullable();
            $table->text('error_message')->nullable();
            $table->string('uploaded_by_email')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('uploaded_by_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_documents');
    }
};
