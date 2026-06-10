<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class District extends Model
{
    protected $fillable = ['name_en', 'name_te', 'code', 'status'];

    protected $casts = ['status' => 'boolean'];

    public function cities(): HasMany
    {
        return $this->hasMany(City::class);
    }

    public function assemblies(): HasMany
    {
        return $this->hasMany(Assembly::class);
    }

    public function pollingStations(): HasMany
    {
        return $this->hasMany(PollingStation::class);
    }

    public function pdfDocuments(): HasMany
    {
        return $this->hasMany(PdfDocument::class);
    }

    public function voterRecords(): HasMany
    {
        return $this->hasMany(VoterRecord::class);
    }
}
