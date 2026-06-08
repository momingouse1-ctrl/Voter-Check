<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoterRecord extends Model
{
    protected $fillable = [
        'source_file',
        'source_sheet',
        'source_row',
        'pdf_page',
        'part_no',
        'roll_page_no',
        'serial_no',
        'house_no',
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
        'raw_data' => 'array',
        'source_row' => 'integer',
        'pdf_page' => 'integer',
        'part_no' => 'integer',
        'roll_page_no' => 'integer',
        'serial_no' => 'integer',
        'age' => 'integer',
    ];
}
