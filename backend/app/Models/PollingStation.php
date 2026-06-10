<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PollingStation extends Model
{
    protected $fillable = [
        'district_id', 'city_id', 'assembly_id',
        'station_number', 'station_name_en', 'station_name_te', 'location', 'status',
    ];

    protected $casts = ['status' => 'boolean'];

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
}
