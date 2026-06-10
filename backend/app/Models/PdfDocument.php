<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'district_id',
        'city_id',
        'assembly_id',
        'polling_station_id',
        'part_number',
        'batch_notes',
    ];

    protected $casts = [
        'file_size'   => 'integer',
        'total_pages' => 'integer',
    ];

    public function pages(): HasMany
    {
        return $this->hasMany(PdfPage::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function assembly(): BelongsTo
    {
        return $this->belongsTo(Assembly::class);
    }

    public function pollingStation(): BelongsTo
    {
        return $this->belongsTo(PollingStation::class);
    }

    public function getFileSizeFormattedAttribute(): string
    {
        $bytes = $this->file_size;
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }
}
