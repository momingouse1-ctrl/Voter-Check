<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pdf_document_id')->constrained('pdf_documents')->onDelete('cascade');
            $table->unsignedInteger('page_number');
            $table->longText('raw_text')->nullable();
            $table->longText('normalized_text')->nullable();
            $table->string('extraction_method')->default('text');
            $table->timestamps();

            $table->index('pdf_document_id');
            $table->index(['pdf_document_id', 'page_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_pages');
    }
};
