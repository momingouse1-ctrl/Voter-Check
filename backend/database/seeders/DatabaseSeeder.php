<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Insert default settings
        $defaults = [
            'enable_ocr'         => 'true',
            'enable_fuzzy'       => 'true',
            'max_upload_size_mb' => '100',
            'allowed_types'      => 'pdf',
        ];

        foreach ($defaults as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
