<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assembly extends Model
{
    protected $fillable = ['district_id', 'name_en', 'name_te', 'assembly_code', 'status'];

    protected $casts = ['status' => 'boolean'];

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function cities(): HasMany
    {
        return $this->hasMany(City::class);
    }

    public function pollingStations(): HasMany
    {
        return $this->hasMany(PollingStation::class);
    }
}
