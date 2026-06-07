<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdfDocument extends Model
{
    protected $fillable = [
        'original_name',
        'stored_path',
        'file_size',
        'total_pages',
        'status',
        'extraction_method',
        'error_message',
        'uploaded_by_email',
    ];

    protected $casts = [
        'file_size'   => 'integer',
        'total_pages' => 'integer',
    ];

    public function pages()
    {
        return $this->hasMany(PdfPage::class);
    }

    public function getFileSizeFormattedAttribute(): string
    {
        $bytes = $this->file_size;
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }
}
