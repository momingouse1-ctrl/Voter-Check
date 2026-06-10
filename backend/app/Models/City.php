<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class City extends Model
{
    protected $fillable = ['district_id', 'assembly_id', 'name_en', 'name_te', 'type', 'status'];

    protected $casts = ['status' => 'boolean'];

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function assembly(): BelongsTo
    {
        return $this->belongsTo(Assembly::class);
    }

    public function pollingStations(): HasMany
    {
        return $this->hasMany(PollingStation::class);
    }

    public function voterRecords(): HasMany
    {
        return $this->hasMany(VoterRecord::class);
    }
}
