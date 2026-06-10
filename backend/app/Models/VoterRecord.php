<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoterRecord extends Model
{
    protected $fillable = [
        'district_id',
        'city_id',
        'assembly_id',
        'polling_station_id',
        'source_file',
        'source_sheet',
        'source_row',
        'pdf_page',
        'part_no',
        'roll_page_no',
        'serial_no',
        'house_no',
        'house_no_normalized',
        'voter_name',
        'relation_type',
        'relation',
        'relative_name',
        'gender',
        'gender_english',
        'age',
        'voter_id',
        'raw_data',
        'search_text',
        'normalized_text',
        'romanized_text',
    ];

    protected $casts = [
        'raw_data'    => 'array',
        'source_row'  => 'integer',
        'pdf_page'    => 'integer',
        'part_no'     => 'integer',
        'roll_page_no'=> 'integer',
        'serial_no'   => 'integer',
        'age'         => 'integer',
    ];

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
}
