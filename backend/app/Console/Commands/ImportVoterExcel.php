<?php

namespace App\Console\Commands;

use App\Services\TeluguRomanizer;
use App\Services\TextNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use SimpleXMLElement;
use XMLReader;
use ZipArchive;

class ImportVoterExcel extends Command
{
    protected $signature = 'voters:import-excel {path : Full path to the XLSX file} {--truncate : Delete existing voter records before import}';

    protected $description = 'Import structured voter records from the generated Kadapa XLSX workbook';

    public function __construct(
        protected TextNormalizer $normalizer,
        protected TeluguRomanizer $romanizer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        if (!is_file($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            $this->error("Could not open XLSX: {$path}");
            return self::FAILURE;
        }

        $sheetPath = $this->sheetPath($zip, 'Voter Records');
        if (!$sheetPath) {
            $this->error('Could not find the Voter Records sheet.');
            $zip->close();
            return self::FAILURE;
        }

        if ($this->option('truncate')) {
            DB::table('voter_records')->delete();
        }

        DB::disableQueryLog();
        $sharedStrings = $this->sharedStrings($zip);
        $sourceFile = basename(str_replace('\\', '/', $path));
        $inserted = 0;
        $batch = [];
        $now = now();

        foreach ($this->rows($zip, $sheetPath, $sharedStrings) as $sourceRow => $row) {
            if ($sourceRow === 1) {
                continue;
            }

            $record = $this->buildRecord($sourceFile, 'Voter Records', $sourceRow, $row, $now);
            if (!$record) {
                continue;
            }

            $batch[] = $record;
            if (count($batch) >= 500) {
                DB::table('voter_records')->insertOrIgnore($batch);
                $inserted += count($batch);
                $batch = [];

                if ($inserted % 10000 === 0) {
                    $this->line("Imported {$inserted} rows...");
                }
            }
        }

        if (!empty($batch)) {
            DB::table('voter_records')->insertOrIgnore($batch);
            $inserted += count($batch);
        }

        $zip->close();
        $count = DB::table('voter_records')->count();
        $this->info("Import finished. Processed {$inserted} rows. Database now has {$count} voter records.");

        return self::SUCCESS;
    }

    protected function buildRecord(string $sourceFile, string $sheet, int $sourceRow, array $row, mixed $now): ?array
    {
        $data = [
            'pdf_page' => $this->toInt($row[0] ?? null),
            'part_no' => $this->toInt($row[1] ?? null),
            'roll_page_no' => $this->toInt($row[2] ?? null),
            'serial_no' => $this->toInt($row[3] ?? null),
            'house_no' => $this->clean($row[4] ?? null),
            'voter_name' => $this->clean($row[5] ?? null),
            'relation_type' => $this->clean($row[6] ?? null),
            'relation' => $this->clean($row[7] ?? null),
            'relative_name' => $this->clean($row[8] ?? null),
            'gender' => $this->clean($row[9] ?? null),
            'gender_english' => $this->clean($row[10] ?? null),
            'age' => $this->toInt($row[11] ?? null),
            'voter_id' => $this->clean($row[12] ?? null),
        ];

        if (($data['voter_name'] ?? '') === '' && ($data['relative_name'] ?? '') === '' && ($data['voter_id'] ?? '') === '') {
            return null;
        }

        $rawData = [
            'PDF Page' => $data['pdf_page'],
            'Part No' => $data['part_no'],
            'Roll Page No' => $data['roll_page_no'],
            'Serial No' => $data['serial_no'],
            'House No' => $data['house_no'],
            'Voter Name' => $data['voter_name'],
            'Relation Type' => $data['relation_type'],
            'Relation' => $data['relation'],
            'Relative Name' => $data['relative_name'],
            'Gender' => $data['gender'],
            'Gender English' => $data['gender_english'],
            'Age' => $data['age'],
            'Voter ID' => $data['voter_id'],
        ];

        $romanized = $this->romanizer->normalize(implode(' ', [
            $data['voter_name'],
            $data['relative_name'],
            $data['house_no'],
            $data['relation_type'],
            $data['gender'],
        ]));
        $romanAliases = $this->romanAliases($romanized);
        $searchText = trim(implode(' ', array_filter([
            $data['pdf_page'] ? 'pdf page ' . $data['pdf_page'] : null,
            $data['part_no'] ? 'part ' . $data['part_no'] : null,
            $data['roll_page_no'] ? 'roll page ' . $data['roll_page_no'] : null,
            $data['serial_no'] ? 'serial ' . $data['serial_no'] : null,
            $data['house_no'],
            $data['voter_name'],
            $data['relation_type'],
            $data['relation'],
            $data['relative_name'],
            $data['gender'],
            $data['gender_english'],
            $data['age'],
            $data['voter_id'],
            $romanized,
            $romanAliases,
        ], fn($value) => $value !== null && $value !== '')));

        return [
            'source_file' => $sourceFile,
            'source_sheet' => $sheet,
            'source_row' => $sourceRow,
            ...$data,
            'raw_data' => json_encode($rawData, JSON_UNESCAPED_UNICODE),
            'search_text' => $searchText,
            'normalized_text' => $this->normalizer->fullNormalize($searchText),
            'romanized_text' => trim($romanized . ' ' . $romanAliases),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    protected function romanAliases(string $romanized): string
    {
        $aliases = [];
        foreach (preg_split('/\s+/u', $romanized, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $aliases[] = $token;
            $aliases[] = str_replace(['ee', 'oo'], ['i', 'u'], $token);

            if (str_contains($token, 'shek')) {
                array_push($aliases, 'shaik', 'sheikh', 'shaikh', 'sheik');
            }

            if (str_starts_with($token, 'moh') || str_starts_with($token, 'mah') || str_starts_with($token, 'muh')) {
                array_push($aliases, 'mohammed', 'mohammad', 'mahammed', 'mahammad', 'muhammad');
            }

            if (str_contains($token, 'gous') || str_contains($token, 'gaus')) {
                array_push($aliases, 'gouse', 'ghouse', 'gaus', 'gous', 'gars', 'garsu', 'gavs');
            }

            if (str_contains($token, 'gars') || str_contains($token, 'gavs')) {
                array_push($aliases, 'gouse', 'ghouse', 'gaus', 'gous', 'gars', 'garsu', 'gavs');
            }

            if (str_contains($token, 'salim') || str_contains($token, 'saleem')) {
                array_push($aliases, 'saleema', 'salima');
            }

            if (preg_match('/^(saleema+|salima+)$/', $token)) {
                array_push($aliases, 'saleemabee', 'salimabee');
            }

            if (str_contains($token, 'kasim') || str_contains($token, 'khasim')) {
                array_push($aliases, 'khasim', 'kasim', 'khasimbee', 'kasimbee');
            }

            if (str_contains($token, 'gandlur') || str_contains($token, 'gamdlur')) {
                array_push($aliases, 'gandluru', 'gandlur', 'gamdluru', 'gamdlur');
            }

            if (str_contains($token, 'jahir') || str_contains($token, 'jaher') || str_contains($token, 'zaeer') || str_contains($token, 'zaher')) {
                array_push($aliases, 'zaeera', 'zaera', 'zahera', 'zaheera', 'jahira', 'jahera', 'jaheera');
            }

            if (str_contains($token, 'begam') || str_contains($token, 'begum')) {
                array_push($aliases, 'begum', 'begam');
            }
        }

        return implode(' ', array_values(array_unique(array_filter($aliases))));
    }

    protected function clean(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }

    protected function toInt(mixed $value): ?int
    {
        $value = trim((string) ($value ?? ''));
        return preg_match('/^-?\d+$/', $value) ? (int) $value : null;
    }

    protected function rows(ZipArchive $zip, string $sheetPath, array $sharedStrings): iterable
    {
        $content = $zip->getFromName($sheetPath);
        if ($content === false) {
            return;
        }

        $reader = new XMLReader();
        $reader->XML($content);

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'row') {
                continue;
            }

            $rowNode = simplexml_load_string($reader->readOuterXML());
            if (!$rowNode instanceof SimpleXMLElement) {
                continue;
            }

            $values = array_fill(0, 13, '');
            foreach ($rowNode->c as $cell) {
                $ref = (string) ($cell['r'] ?? '');
                $index = $this->columnIndex($ref);
                if ($index >= 0 && $index < 13) {
                    $values[$index] = $this->cellText($cell, $sharedStrings);
                }
            }

            yield (int) ($rowNode['r'] ?? 0) => $values;
        }

        $reader->close();
    }

    protected function sharedStrings(ZipArchive $zip): array
    {
        $content = $zip->getFromName('xl/sharedStrings.xml');
        if ($content === false) {
            return [];
        }

        $strings = [];
        $reader = new XMLReader();
        $reader->XML($content);

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'si') {
                continue;
            }

            $node = simplexml_load_string($reader->readOuterXML());
            $text = '';
            if ($node instanceof SimpleXMLElement) {
                $node->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                foreach ($node->xpath('.//x:t') ?: [] as $part) {
                    $text .= (string) $part;
                }
            }
            $strings[] = $text;
        }

        $reader->close();

        return $strings;
    }

    protected function cellText(SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string) ($cell['t'] ?? '');
        if ($type === 's') {
            $index = (int) ($cell->v ?? -1);
            return trim($sharedStrings[$index] ?? '');
        }

        if ($type === 'inlineStr') {
            $cell->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $parts = [];
            foreach ($cell->xpath('.//x:t') ?: [] as $part) {
                $parts[] = (string) $part;
            }
            return trim(implode('', $parts));
        }

        return trim((string) ($cell->v ?? ''));
    }

    protected function sheetPath(ZipArchive $zip, string $wantedName): ?string
    {
        $workbook = simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
        $rels = simplexml_load_string((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));
        if (!$workbook instanceof SimpleXMLElement || !$rels instanceof SimpleXMLElement) {
            return null;
        }

        $relMap = [];
        foreach ($rels->Relationship as $rel) {
            $relMap[(string) $rel['Id']] = $this->resolveTarget((string) $rel['Target']);
        }

        $workbook->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $workbook->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        foreach ($workbook->xpath('//main:sheet') ?: [] as $sheet) {
            $attrs = $sheet->attributes();
            if ((string) ($attrs['name'] ?? '') !== $wantedName) {
                continue;
            }

            $rattrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $relId = (string) ($rattrs['id'] ?? '');
            return $relMap[$relId] ?? null;
        }

        return null;
    }

    protected function resolveTarget(string $target): string
    {
        $target = ltrim($target, '/');
        return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
    }

    protected function columnIndex(string $cellRef): int
    {
        if (!preg_match('/^[A-Z]+/i', $cellRef, $matches)) {
            return -1;
        }

        $letters = strtoupper($matches[0]);
        $index = 0;
        for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
            $index = ($index * 26) + (ord($letters[$i]) - 64);
        }

        return $index - 1;
    }
}
