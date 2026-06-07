<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdfPage extends Model
{
    protected $fillable = [
        'pdf_document_id',
        'page_number',
        'raw_text',
        'normalized_text',
        'extraction_method',
    ];

    public function pdfDocument()
    {
        return $this->belongsTo(PdfDocument::class);
    }
}
