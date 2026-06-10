<?php

namespace Database\Seeders;

use App\Models\Assembly;
use App\Models\City;
use App\Models\District;
use App\Models\VoterRecord;
use App\Services\HouseNumberNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GeographySeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Seeding Kadapa District...');

        // Create Andhra Pradesh → Kadapa District
        $kadapa = District::firstOrCreate(
            ['code' => 'AP-KDP'],
            [
                'name_en' => 'Kadapa',
                'name_te' => 'కడప',
                'status'  => true,
            ]
        );

        // Create Kadapa assembly constituency
        $kadapaAssembly = Assembly::firstOrCreate(
            ['district_id' => $kadapa->id, 'name_en' => 'Kadapa'],
            [
                'name_te'       => 'కడప',
                'assembly_code' => 'KDP-001',
                'status'        => true,
            ]
        );

        // Create Kadapa Urban city (the main city where existing data belongs)
        $kadapaUrban = City::firstOrCreate(
            ['district_id' => $kadapa->id, 'name_en' => 'Kadapa Urban'],
            [
                'assembly_id' => $kadapaAssembly->id,
                'name_te'     => 'కడప అర్బన్',
                'type'        => 'urban',
                'status'      => true,
            ]
        );

        $this->command->info("Created: Kadapa District (id={$kadapa->id}), Kadapa Urban (id={$kadapaUrban->id})");

        // ── Link existing voter_records to Kadapa District + Kadapa Urban ──────
        $count = VoterRecord::whereNull('district_id')->count();
        if ($count > 0) {
            $this->command->info("Linking {$count} existing voter records to Kadapa Urban...");

            VoterRecord::whereNull('district_id')->update([
                'district_id' => $kadapa->id,
                'city_id'     => $kadapaUrban->id,
                'assembly_id' => $kadapaAssembly->id,
            ]);

            $this->command->info("Linked {$count} voter records to Kadapa District.");
        }

        // ── Normalize house numbers for existing records ───────────────────────
        $this->command->info('Normalizing house numbers...');
        $normalizer = app(HouseNumberNormalizer::class);

        VoterRecord::whereNotNull('house_no')
            ->whereNull('house_no_normalized')
            ->chunkById(1000, function ($records) use ($normalizer) {
                foreach ($records as $record) {
                    $record->update([
                        'house_no_normalized' => $normalizer->normalize((string) $record->house_no),
                    ]);
                }
            });

        $this->command->info('House number normalization complete.');
        $this->command->info('GeographySeeder done!');
    }
}
