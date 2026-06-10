<?php

namespace App\Http\Controllers;

use App\Models\District;
use App\Models\City;
use App\Models\VoterRecord;
use App\Services\HouseNumberNormalizer;
use App\Services\TeluguRomanizer;
use App\Services\TextNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ExcelImportController extends Controller
{
    public function __construct(
        protected HouseNumberNormalizer $houseNormalizer,
        protected TextNormalizer $textNormalizer,
        protected TeluguRomanizer $romanizer,
    ) {}

    /**
     * POST /api/admin/upload-excel
     *
     * Accepts an Excel/CSV file with column mapping JSON and geography metadata.
     * Parses and imports records into voter_records table.
     */
    public function import(Request $request)
    {
        $request->validate([
            'file'               => 'required|file|mimes:xlsx,xls,csv|max:102400',
            'column_map'         => 'nullable|string', // JSON: { "A": "voter_name", "B": "relative_name", ... }
            'district_id'        => 'nullable|integer|exists:districts,id',
            'city_id'            => 'nullable|integer|exists:cities,id',
            'assembly_id'        => 'nullable|integer|exists:assemblies,id',
            'polling_station_id' => 'nullable|integer|exists:polling_stations,id',
            'skip_first_row'     => 'nullable|boolean',
        ]);

        $file            = $request->file('file');
        $columnMap       = json_decode($request->input('column_map', '{}'), true) ?? [];
        $districtId      = $request->input('district_id');
        $cityId          = $request->input('city_id');
        $assemblyId      = $request->input('assembly_id');
        $pollingStationId= $request->input('polling_station_id');
        $skipFirst       = (bool) $request->input('skip_first_row', true);
        $originalName    = $file->getClientOriginalName();

        // Parse the file
        try {
            $rows = $this->parseFile($file->getRealPath(), $file->getClientOriginalExtension());
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Failed to parse file: ' . $e->getMessage()], 422);
        }

        if ($skipFirst && !empty($rows)) {
            array_shift($rows);
        }

        $totalRows    = count($rows);
        $imported     = 0;
        $skipped      = 0;
        $failed       = 0;
        $failedRows   = [];

        foreach ($rows as $rowIndex => $row) {
            try {
                $mapped = $this->mapRow($row, $columnMap);

                if (empty($mapped['voter_name']) && empty($mapped['relative_name'])) {
                    $skipped++;
                    continue;
                }

                $houseNo           = $mapped['house_no'] ?? null;
                $houseNoNormalized = $houseNo ? $this->houseNormalizer->normalize($houseNo) : null;
                $voterName         = $mapped['voter_name'] ?? null;
                $relativeName      = $mapped['relative_name'] ?? null;

                $searchText  = implode(' ', array_filter([$voterName, $relativeName, $houseNo, $mapped['voter_id'] ?? null]));
                $normalizedText = $this->textNormalizer->normalize($searchText);
                $romanizedText  = $this->romanizer->normalize($searchText);

                // Upsert based on source_file + source_row
                $record = VoterRecord::updateOrCreate(
                    [
                        'source_file'  => $originalName,
                        'source_sheet' => $mapped['source_sheet'] ?? 'Sheet1',
                        'source_row'   => $rowIndex + ($skipFirst ? 2 : 1),
                    ],
                    [
                        'district_id'        => $districtId,
                        'city_id'            => $cityId,
                        'assembly_id'        => $assemblyId,
                        'polling_station_id' => $pollingStationId,
                        'part_no'            => isset($mapped['part_no']) ? (int) $mapped['part_no'] : null,
                        'roll_page_no'       => isset($mapped['roll_page_no']) ? (int) $mapped['roll_page_no'] : null,
                        'serial_no'          => isset($mapped['serial_no']) ? (int) $mapped['serial_no'] : null,
                        'pdf_page'           => isset($mapped['pdf_page']) ? (int) $mapped['pdf_page'] : null,
                        'house_no'           => $houseNo,
                        'house_no_normalized'=> $houseNoNormalized,
                        'voter_name'         => $voterName,
                        'relation_type'      => $mapped['relation_type'] ?? null,
                        'relation'           => $mapped['relation'] ?? null,
                        'relative_name'      => $relativeName,
                        'gender'             => $mapped['gender'] ?? null,
                        'gender_english'     => $this->normalizeGender($mapped['gender'] ?? null, $mapped['gender_english'] ?? null),
                        'age'                => isset($mapped['age']) ? (int) $mapped['age'] : null,
                        'voter_id'           => isset($mapped['voter_id']) ? strtoupper(trim($mapped['voter_id'])) : null,
                        'search_text'        => $searchText,
                        'normalized_text'    => $normalizedText,
                        'romanized_text'     => $romanizedText,
                        'raw_data'           => $row,
                    ]
                );

                $imported++;
            } catch (\Throwable $e) {
                $failed++;
                $failedRows[] = [
                    'row'   => $rowIndex + ($skipFirst ? 2 : 1),
                    'error' => $e->getMessage(),
                    'data'  => array_slice($row, 0, 5), // first 5 cols for reference
                ];
                Log::warning("Excel import row failed: row=" . ($rowIndex + 2) . " err=" . $e->getMessage());
            }
        }

        return response()->json([
            'success'      => true,
            'file'         => $originalName,
            'total_rows'   => $totalRows,
            'imported'     => $imported,
            'skipped'      => $skipped,
            'failed'       => $failed,
            'failed_rows'  => array_slice($failedRows, 0, 50), // return at most 50 failure samples
        ]);
    }

    /**
     * GET /api/admin/import-history
     */
    public function history()
    {
        $sources = VoterRecord::select('source_file')
            ->distinct()
            ->orderBy('source_file')
            ->get()
            ->map(fn ($r) => [
                'source_file' => $r->source_file,
                'count'       => VoterRecord::where('source_file', $r->source_file)->count(),
            ]);

        return response()->json($sources);
    }

    /**
     * Parse Excel/CSV file into array of rows.
     * Each row is an array: [colIndex => value, ...]
     * Uses PHP's built-in CSV parsing for CSV files.
     * Uses a simple XLSX reader for Excel files.
     */
    protected function parseFile(string $path, string $ext): array
    {
        $ext = strtolower($ext);

        if ($ext === 'csv') {
            return $this->parseCsv($path);
        }

        // For xlsx/xls we use a simple XML-based reader if available
        return $this->parseXlsx($path);
    }

    protected function parseCsv(string $path): array
    {
        $rows = [];
        if (($handle = fopen($path, 'r')) !== false) {
            while (($data = fgetcsv($handle)) !== false) {
                $rows[] = array_values($data);
            }
            fclose($handle);
        }
        return $rows;
    }

    protected function parseXlsx(string $path): array
    {
        // Minimal XLSX reader using ZipArchive + SimpleXML (no external deps required)
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Cannot open XLSX file.');
        }

        // Read shared strings
        $sharedStrings = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml) {
            $ss = simplexml_load_string($ssXml);
            foreach ($ss->si as $si) {
                // Collect all text nodes
                $text = '';
                foreach ($si->xpath('.//t') as $t) {
                    $text .= (string) $t;
                }
                $sharedStrings[] = $text;
            }
        }

        // Read first sheet
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if (!$sheetXml) {
            throw new \RuntimeException('No sheet1.xml found in XLSX.');
        }

        $sheet = simplexml_load_string($sheetXml);
        $rows  = [];

        foreach ($sheet->sheetData->row as $row) {
            $rowData = [];
            foreach ($row->c as $cell) {
                $type  = (string) ($cell['t'] ?? '');
                $value = (string) $cell->v;

                if ($type === 's') {
                    // Shared string
                    $value = $sharedStrings[(int) $value] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = (string) $cell->is->t;
                }

                $rowData[] = $value;
            }
            $rows[] = $rowData;
        }

        return $rows;
    }

    /**
     * Map a raw row array to field names using the column_map.
     * column_map format: { "0": "voter_name", "1": "relative_name", "2": "house_no", ... }
     * (keys are column indexes, 0-based)
     */
    protected function mapRow(array $row, array $columnMap): array
    {
        if (empty($columnMap)) {
            // Auto-map: try to guess from position
            return $this->autoMapRow($row);
        }

        $mapped = [];
        foreach ($columnMap as $colIndex => $fieldName) {
            if ($fieldName && isset($row[(int) $colIndex])) {
                $mapped[$fieldName] = trim((string) $row[(int) $colIndex]);
            }
        }

        return $mapped;
    }

    /**
     * Auto-map based on common Excel column orders for Telugu voter lists.
     * Expected order: serial_no, house_no, voter_name, relation_type, relative_name, gender, age, voter_id
     */
    protected function autoMapRow(array $row): array
    {
        $fields = ['serial_no', 'house_no', 'voter_name', 'relation_type', 'relative_name', 'gender', 'age', 'voter_id'];
        $mapped = [];
        foreach ($fields as $i => $field) {
            if (isset($row[$i])) {
                $mapped[$field] = trim((string) $row[$i]);
            }
        }
        return $mapped;
    }

    protected function normalizeGender(?string $gender, ?string $genderEnglish): ?string
    {
        if ($genderEnglish) return $genderEnglish;
        if (!$gender) return null;

        // Common Telugu gender values
        $lower = mb_strtolower($gender);
        if (in_array($lower, ['male', 'm', 'పురుషుడు', 'man'])) return 'Male';
        if (in_array($lower, ['female', 'f', 'మహిళ', 'స్త్రీ', 'woman'])) return 'Female';
        if (in_array($lower, ['other', 'o', 'ఇతరులు'])) return 'Other';

        return $gender;
    }
}
