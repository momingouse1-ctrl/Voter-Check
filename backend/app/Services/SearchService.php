<?php

namespace App\Services;

use App\Models\PdfPage;
use App\Models\SearchLog;
use App\Models\VoterRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SearchService
{
    protected TextNormalizer $normalizer;
    protected TeluguRomanizer $romanizer;

    public function __construct(TextNormalizer $normalizer, TeluguRomanizer $romanizer)
    {
        $this->normalizer = $normalizer;
        $this->romanizer = $romanizer;
    }

    /**
     * Main search entry point.
     */
    public function search(string $query, string $mode = 'fuzzy', ?string $email = null, array $pdfIds = [], ?string $relativeQuery = null): array
    {
        $normalized = $this->normalizer->normalizeQuery($query);
        $results = collect();
        $queriesToSearch = $this->buildQueryVariants($query, $normalized);

        foreach ($queriesToSearch as $currentNormalized) {
            $exactResults = $this->exactSearch($currentNormalized, $pdfIds);
            foreach ($exactResults as $r) {
                $this->addOrUpgradeResult($results, $r, 'exact', 100);
            }

            if ($mode !== 'exact') {
                $tokens = $this->normalizer->tokenize($currentNormalized);
                if (count($tokens) > 1) {
                    $tokenResults = $this->tokenSearch($tokens, $pdfIds);
                    foreach ($tokenResults as $r) {
                        $this->addOrUpgradeResult($results, $r, 'high', 80);
                    }
                }
            }

            if ($mode === 'fuzzy' || $mode === 'partial') {
                $tokens = $this->normalizer->tokenize($currentNormalized);
                if (count($tokens) > 1) {
                    continue;
                }

                foreach ($tokens as $token) {
                    if (mb_strlen($token) < 3) {
                        continue;
                    }

                    $partialResults = $this->partialSearch($token, $pdfIds);
                    foreach ($partialResults as $r) {
                        $this->addOrUpgradeResult(
                            $results,
                            $r,
                            count($tokens) === 1 ? 'high' : 'medium',
                            count($tokens) === 1 ? 80 : 60
                        );
                    }
                }
            }

            if ($mode === 'fuzzy') {
                $tokens = $this->normalizer->tokenize($currentNormalized);
                if (count($tokens) > 1) {
                    continue;
                }

                usort($tokens, fn($a, $b) => mb_strlen($b) - mb_strlen($a));
                $longestToken = $tokens[0] ?? null;

                if ($longestToken && mb_strlen($longestToken) >= 4) {
                    $fuzzyResults = $this->fuzzySearch($longestToken, $currentNormalized, $pdfIds);
                    foreach ($fuzzyResults as $r) {
                        $this->addOrUpgradeResult($results, $r, 'low', 40);
                    }
                }
            }
        }

        if ($this->isRomanQuery($query) && ($mode === 'fuzzy' || $mode === 'partial')) {
            foreach ($this->romanizedTeluguSearch($normalized, $pdfIds) as $r) {
                $this->addOrUpgradeResult($results, $r, 'high', 85);
            }
        }

        $relativeQuery = trim((string) $relativeQuery);
        foreach ($this->voterRecordSearch($query, $mode, $queriesToSearch, $pdfIds, $relativeQuery) as $r) {
            $this->addOrUpgradeResult(
                $results,
                $r,
                $r['confidence'] ?? 'high',
                (int) ($r['confidence_score'] ?? 110)
            );
        }

        if ($relativeQuery !== '') {
            $relativeVariants = $this->buildQueryVariants($relativeQuery, $this->normalizer->normalizeQuery($relativeQuery));
            $results = $results->filter(fn($result) => $this->resultMatchesRelative($result, $relativeVariants));
        }

        $sorted = $results->sortByDesc('confidence_score')->values();

        SearchLog::create([
            'email' => $email,
            'query' => $query,
            'mode' => $mode,
            'total_results' => $sorted->count(),
        ]);

        return [
            'query' => $query,
            'relative_query' => $relativeQuery ?: null,
            'total' => $sorted->count(),
            'results' => $sorted->toArray(),
        ];
    }

    protected function resultMatchesRelative(array $result, array $relativeVariants): bool
    {
        $text = trim(implode("\n", array_filter([
            (string) ($result['relative_text'] ?? ''),
            (string) ($result['matched_text'] ?? ''),
            (string) ($result['context'] ?? ''),
        ], fn($value) => trim($value) !== '')));

        if ($text === '') {
            return false;
        }

        foreach (preg_split('/\n/u', $text) as $line) {
            $normalizedLine = $this->normalizer->normalizeQuery($line);

            foreach ($relativeVariants as $variant) {
                $normalizedVariant = $this->normalizer->normalizeQuery($variant);
                if ($normalizedVariant === '') {
                    continue;
                }

                if (mb_stripos($normalizedLine, $normalizedVariant) !== false) {
                    return true;
                }

                $tokens = $this->normalizer->tokenize($normalizedVariant);
                if (!empty($tokens) && $this->lineContainsAllTokens($normalizedLine, $tokens)) {
                    return true;
                }

                if ($this->isRomanQuery($normalizedVariant)) {
                    $groups = $this->romanQueryTokenGroups($normalizedVariant);
                    if (!empty($groups) && $this->romanAliasScore($groups, $normalizedLine) === 100) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    protected function addOrUpgradeResult(Collection $results, array $candidate, string $confidence, int $score): void
    {
        $candidate['confidence'] = $confidence;
        $candidate['confidence_score'] = $score;

        foreach ($results as $index => $existing) {
            if ($this->resultKey($existing) !== $this->resultKey($candidate)) {
                continue;
            }

            $existingScore = (int) ($existing['confidence_score'] ?? 0);
            $existingHasRow = $this->isLikelyVoterRow($existing['matched_text'] ?? '');
            $candidateHasRow = $this->isLikelyVoterRow($candidate['matched_text'] ?? '');

            if ($score > $existingScore || (!$existingHasRow && $candidateHasRow)) {
                $results->put($index, $candidate);
            }

            return;
        }

        $results->push($candidate);
    }

    protected function resultKey(array $result): string
    {
        if (isset($result['result_key'])) {
            return (string) $result['result_key'];
        }

        $sourceType = (string) ($result['source_type'] ?? 'pdf');
        return $sourceType . ':' . ($result['pdf_id'] ?? '') . ':' . ($result['page_number'] ?? '') . ':' . ($result['page_id'] ?? '');
    }

    protected function isLikelyVoterRow(string $line): bool
    {
        return preg_match('/^\s*\d{1,5}\s+\S+/u', $line) === 1;
    }

    protected function lineContainsAllTokens(string $normalizedLine, array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (mb_strlen($token) < 2) {
                continue;
            }

            if (mb_stripos($normalizedLine, $token) === false) {
                return false;
            }
        }

        return true;
    }

    protected function voterRecordSearch(string $query, string $mode, array $queryVariants, array $pdfIds, string $relativeQuery = ''): array
    {
        if (!empty($pdfIds)) {
            return [];
        }

        $matches = collect();
        $relativeVariants = $relativeQuery !== ''
            ? $this->buildQueryVariants($relativeQuery, $this->normalizer->normalizeQuery($relativeQuery))
            : [];

        foreach ($queryVariants as $variant) {
            foreach ($this->voterRecordDirectSearch($variant, $mode) as $record) {
                if (!$this->voterRecordMatchesRelative($record, $relativeVariants)) {
                    continue;
                }

                $score = count($this->normalizer->tokenize($variant)) > 1 ? 125 : 115;
                $this->addOrUpgradeResult($matches, $this->buildVoterRecordResult($record, 'exact', $score), 'exact', $score);
            }
        }

        if ($this->isRomanQuery($query) && ($mode === 'fuzzy' || $mode === 'partial')) {
            foreach ($this->voterRecordRomanSearch($query, $relativeVariants) as $recordAndScore) {
                [$record, $score] = $recordAndScore;
                $this->addOrUpgradeResult($matches, $this->buildVoterRecordResult($record, 'high', $score), 'high', $score);
            }
        }

        return $matches->sortByDesc('confidence_score')->take(500)->values()->toArray();
    }

    protected function voterRecordDirectSearch(string $variant, string $mode): Collection
    {
        $variant = $this->normalizer->normalizeQuery($variant);
        if ($variant === '') {
            return collect();
        }

        $tokens = array_values($this->normalizer->tokenize($variant));
        if (empty($tokens)) {
            return collect();
        }

        $query = VoterRecord::query();

        if ($mode === 'exact' && count($tokens) > 1) {
            $query->where('normalized_text', 'like', '%' . $variant . '%');
        } else {
            foreach ($tokens as $token) {
                $query->where('normalized_text', 'like', '%' . $token . '%');
            }
        }

        return $query
            ->orderBy('part_no')
            ->orderBy('serial_no')
            ->limit(500)
            ->get();
    }

    protected function voterRecordRomanSearch(string $query, array $relativeVariants = []): array
    {
        $groups = $this->romanQueryTokenGroups($query);
        if (empty($groups)) {
            return [];
        }

        $prefixes = array_values(array_unique(array_filter(array_map(
            fn($token) => mb_substr($token, 0, 3),
            $groups[0],
        ), fn($prefix) => mb_strlen($prefix) >= 3)));

        if (empty($prefixes)) {
            return [];
        }

        $matches = [];
        VoterRecord::query()
            ->where(function ($q) use ($prefixes) {
                foreach ($prefixes as $prefix) {
                    $q->orWhere('romanized_text', 'like', '%' . $prefix . '%');
                }
            })
            ->orderBy('part_no')
            ->orderBy('serial_no')
            ->chunk(1000, function ($records) use (&$matches, $groups, $relativeVariants) {
                foreach ($records as $record) {
                    $score = $this->romanAliasScore($groups, (string) $record->romanized_text);
                    $threshold = count($groups) > 1 ? 78 : 72;
                    if ($score >= $threshold) {
                        if (!$this->voterRecordMatchesRelative($record, $relativeVariants)) {
                            continue;
                        }

                        $matches[] = [$record, min(120, $score + 15)];
                    }

                    if (count($matches) >= 500) {
                        return false;
                    }
                }

                return true;
            });

        usort($matches, fn($a, $b) => $b[1] <=> $a[1]);

        return $matches;
    }

    protected function voterRecordMatchesRelative(VoterRecord $record, array $relativeVariants): bool
    {
        if (empty($relativeVariants)) {
            return true;
        }

        $relativeRoman = $this->romanizer->normalize((string) $record->relative_name);
        $relativeText = trim((string) $record->relative_name . ' ' . $relativeRoman . ' ' . $this->romanAliasesFromText($relativeRoman));
        if ($relativeText === '') {
            return false;
        }

        $normalizedLine = $this->normalizer->normalizeQuery($relativeText);
        foreach ($relativeVariants as $variant) {
            $normalizedVariant = $this->normalizer->normalizeQuery($variant);
            if ($normalizedVariant === '') {
                continue;
            }

            if (mb_stripos($normalizedLine, $normalizedVariant) !== false) {
                return true;
            }

            $tokens = $this->normalizer->tokenize($normalizedVariant);
            if (!empty($tokens) && $this->lineContainsAllTokens($normalizedLine, $tokens)) {
                return true;
            }

            if ($this->isRomanQuery($normalizedVariant)) {
                $groups = $this->romanQueryTokenGroups($normalizedVariant);
                if (!empty($groups) && $this->romanAliasScore($groups, $normalizedLine) === 100) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function romanQueryTokenGroups(string $query): array
    {
        $normalized = $this->romanNormalize($query);
        $tokens = array_values($this->normalizer->tokenize($normalized));
        $groups = [];

        foreach ($tokens as $token) {
            $aliases = $this->romanTokenAliases($token);
            if (!empty($aliases)) {
                $groups[] = $aliases;
            }
        }

        return $groups;
    }

    protected function romanTokenAliases(string $token): array
    {
        $token = $this->romanNormalize($token);
        if ($token === '' || mb_strlen($token) < 2) {
            return [];
        }

        $aliases = [$token];
        $aliases[] = str_replace(['ee', 'oo'], ['i', 'u'], $token);
        $aliases[] = str_replace(['i', 'u'], ['ee', 'oo'], $token);

        $common = [
            'shaik' => ['shaik', 'sheik', 'sheikh', 'shaikh', 'shek'],
            'sheik' => ['shaik', 'sheik', 'sheikh', 'shaikh', 'shek'],
            'sheikh' => ['shaik', 'sheik', 'sheikh', 'shaikh', 'shek'],
            'shaikh' => ['shaik', 'sheik', 'sheikh', 'shaikh', 'shek'],
            'mohammed' => ['mohammed', 'mohammad', 'mahammed', 'mahammad', 'muhammad', 'mahammad'],
            'mohammad' => ['mohammed', 'mohammad', 'mahammed', 'mahammad', 'muhammad', 'mahammad'],
            'mahammed' => ['mohammed', 'mohammad', 'mahammed', 'mahammad', 'muhammad', 'mahammad'],
            'mahammad' => ['mohammed', 'mohammad', 'mahammed', 'mahammad', 'muhammad', 'mahammad'],
            'gouse' => ['gouse', 'ghouse', 'gous', 'gaus', 'gousu', 'gars', 'garsu', 'gavs'],
            'ghouse' => ['gouse', 'ghouse', 'gous', 'gaus', 'gousu', 'gars', 'garsu', 'gavs'],
            'gous' => ['gouse', 'ghouse', 'gous', 'gaus', 'gousu', 'gars', 'garsu', 'gavs'],
            'gaus' => ['gouse', 'ghouse', 'gous', 'gaus', 'gousu', 'gars', 'garsu', 'gavs'],
            'gars' => ['gouse', 'ghouse', 'gous', 'gaus', 'gousu', 'gars', 'garsu', 'gavs'],
            'garsu' => ['gouse', 'ghouse', 'gous', 'gaus', 'gousu', 'gars', 'garsu', 'gavs'],
            'gavs' => ['gouse', 'ghouse', 'gous', 'gaus', 'gousu', 'gars', 'garsu', 'gavs'],
            'gandluru' => ['gandluru', 'gandlur', 'gamdluru', 'gamdlur'],
            'gandlur' => ['gandluru', 'gandlur', 'gamdluru', 'gamdlur'],
            'gamdluru' => ['gandluru', 'gandlur', 'gamdluru', 'gamdlur'],
            'gamdlur' => ['gandluru', 'gandlur', 'gamdluru', 'gamdlur'],
            'zaeera' => ['zaeera', 'zaera', 'zahera', 'zaheera', 'jahira', 'jahera', 'jaheera'],
            'zaera' => ['zaeera', 'zaera', 'zahera', 'zaheera', 'jahira', 'jahera', 'jaheera'],
            'zahera' => ['zaeera', 'zaera', 'zahera', 'zaheera', 'jahira', 'jahera', 'jaheera'],
            'zaheera' => ['zaeera', 'zaera', 'zahera', 'zaheera', 'jahira', 'jahera', 'jaheera'],
            'jahira' => ['zaeera', 'zaera', 'zahera', 'zaheera', 'jahira', 'jahera', 'jaheera'],
            'jahera' => ['zaeera', 'zaera', 'zahera', 'zaheera', 'jahira', 'jahera', 'jaheera'],
            'jaheera' => ['zaeera', 'zaera', 'zahera', 'zaheera', 'jahira', 'jahera', 'jaheera'],
            'begum' => ['begum', 'begam'],
            'begam' => ['begum', 'begam'],
            'mastan' => ['mastan', 'masthan', 'mastaan'],
        ];

        if (isset($common[$token])) {
            array_push($aliases, ...$common[$token]);
        }

        foreach ($common as $needle => $replacements) {
            if (!str_contains($token, $needle)) {
                continue;
            }

            foreach ($replacements as $replacement) {
                $aliases[] = str_replace($needle, $replacement, $token);
            }
        }

        $hasBeeSuffix = false;
        foreach (['bee', 'bi'] as $suffix) {
            if (str_ends_with($token, $suffix) && mb_strlen($token) > mb_strlen($suffix) + 2) {
                $hasBeeSuffix = true;
                $base = mb_substr($token, 0, -mb_strlen($suffix));
                array_push($aliases, $base, $base . 'a', $base . 'aa', str_replace('ee', 'i', $base), str_replace('i', 'ee', $base));
            }
        }

        if (str_contains($token, 'saleem') || str_contains($token, 'salim')) {
            array_push($aliases, 'saleema', 'salima');
            if (!$hasBeeSuffix) {
                array_push($aliases, 'saleem', 'salim');
            }
        }

        if (str_contains($token, 'khasim') || str_contains($token, 'kasim')) {
            array_push($aliases, 'khasim', 'kasim', 'khasimbee', 'kasimbee');
        }

        return array_values(array_unique(array_filter($aliases, fn($alias) => mb_strlen($alias) >= 2)));
    }

    protected function romanAliasScore(array $queryGroups, string $romanText): int
    {
        $romanText = $this->romanNormalize($romanText);
        if ($romanText === '') {
            return 0;
        }

        $words = $this->normalizer->tokenize($romanText);
        $hits = 0;

        foreach ($queryGroups as $aliases) {
            $matched = false;
            foreach ($aliases as $alias) {
                if ($this->romanAliasMatches($alias, $romanText, $words)) {
                    $matched = true;
                    break;
                }
            }

            if ($matched) {
                $hits++;
            }
        }

        return (int) round(($hits / max(1, count($queryGroups))) * 100);
    }

    protected function romanAliasMatches(string $alias, string $romanText, array $words): bool
    {
        if (mb_strlen($alias) < 2) {
            return false;
        }

        if (str_contains($romanText, $alias)) {
            return true;
        }

        $foldedAlias = $this->foldRomanVowels($alias);
        foreach ($words as $word) {
            if (mb_strlen($word) < 2) {
                continue;
            }

            $wordIsUsefulPartOfAlias = str_contains($alias, $word)
                && mb_strlen($word) >= (int) floor(mb_strlen($alias) * 0.65);

            if (str_contains($word, $alias) || $wordIsUsefulPartOfAlias) {
                return true;
            }

            if ($foldedAlias !== '' && $foldedAlias === $this->foldRomanVowels($word)) {
                return true;
            }

            similar_text($alias, $word, $pct);
            if ($pct >= 74) {
                return true;
            }
        }

        return false;
    }

    protected function foldRomanVowels(string $text): string
    {
        return preg_replace('/[aeiou]+/u', '', $this->romanNormalize($text)) ?: '';
    }

    protected function buildQueryVariants(string $query, string $normalized): array
    {
        $variants = [$normalized];

        if ($this->isRomanQuery($query)) {
            foreach ($this->localTeluguVariants($normalized) as $variant) {
                $variant = $this->normalizer->normalizeQuery($variant);
                if ($variant !== '' && !in_array($variant, $variants, true)) {
                    $variants[] = $variant;
                }
            }

            try {
                $url = 'https://inputtools.google.com/request?text=' . urlencode($query) . '&itc=te-t-i0-und&num=1';
                $response = Http::timeout(3)->get($url);
                if ($response->successful()) {
                    $data = $response->json();
                    if (isset($data[1][0][1][0])) {
                        $transliterated = $this->normalizer->normalizeQuery($data[1][0][1][0]);
                        if ($transliterated !== '' && !in_array($transliterated, $variants, true)) {
                            $variants[] = $transliterated;
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Transliteration failed: ' . $e->getMessage());
            }
        }

        return $variants;
    }

    protected function isRomanQuery(string $query): bool
    {
        return preg_match('/^[a-zA-Z0-9\s\.\-\']+$/', trim($query)) === 1 && preg_match('/[a-zA-Z]/', $query) === 1;
    }

    protected function localTeluguVariants(string $normalized): array
    {
        $dictionary = [
            'gandluru' => ["\u{0C17}\u{0C02}\u{0C21}\u{0C4D}\u{0C32}\u{0C41}\u{0C30}\u{0C41}", "\u{0C17}\u{0C02}\u{0C21}\u{0C4D}\u{0C32}\u{0C42}\u{0C30}\u{0C41}", "\u{0C17}\u{0C02}\u{0C21}\u{0C4D}\u{0C32}\u{0C41}\u{0C30}", "\u{0C17}\u{0C02}\u{0C21}\u{0C4D}\u{0C32}\u{0C42}\u{0C30}"],
            'gandlur' => ["\u{0C17}\u{0C02}\u{0C21}\u{0C4D}\u{0C32}\u{0C41}\u{0C30}", "\u{0C17}\u{0C02}\u{0C21}\u{0C4D}\u{0C32}\u{0C42}\u{0C30}\u{0C41}", "\u{0C17}\u{0C02}\u{0C21}\u{0C4D}\u{0C32}\u{0C41}\u{0C30}\u{0C41}"],
            'khajapeer' => ["\u{0C16}\u{0C3E}\u{0C1C}\u{0C3E}\u{0C2A}\u{0C40}\u{0C30}", "\u{0C16}\u{0C3E}\u{0C1C}\u{0C3E}\u{0C2A}\u{0C40}\u{0C30}\u{0C4D}", "\u{0C16}\u{0C3E}\u{0C1C}\u{0C3E} \u{0C2A}\u{0C40}\u{0C30}", "\u{0C16}\u{0C3E}\u{0C1C}\u{0C3E} \u{0C2A}\u{0C40}\u{0C30}\u{0C4D}"],
            'khaja' => ["\u{0C16}\u{0C3E}\u{0C1C}\u{0C3E}"],
            'peer' => ["\u{0C2A}\u{0C40}\u{0C30}", "\u{0C2A}\u{0C40}\u{0C30}\u{0C4D}"],
            'pir' => ["\u{0C2A}\u{0C40}\u{0C30}", "\u{0C2A}\u{0C40}\u{0C30}\u{0C4D}"],
            'mohammed' => ["\u{0C2E}\u{0C39}\u{0C2E}\u{0C4D}\u{0C2E}\u{0C26}", "\u{0C2E}\u{0C39}\u{0C2E}\u{0C4D}\u{0C2E}\u{0C26}\u{0C4D}", "\u{0C2E}\u{0C4A}\u{0C39}\u{0C2E}\u{0C4D}\u{0C2E}\u{0C26}\u{0C4D}"],
            'mohammad' => ["\u{0C2E}\u{0C39}\u{0C2E}\u{0C4D}\u{0C2E}\u{0C26}", "\u{0C2E}\u{0C39}\u{0C2E}\u{0C4D}\u{0C2E}\u{0C26}\u{0C4D}", "\u{0C2E}\u{0C4A}\u{0C39}\u{0C2E}\u{0C4D}\u{0C2E}\u{0C26}\u{0C4D}"],
            'mahammed' => ["\u{0C2E}\u{0C39}\u{0C2E}\u{0C4D}\u{0C2E}\u{0C26}", "\u{0C2E}\u{0C39}\u{0C2E}\u{0C4D}\u{0C2E}\u{0C26}\u{0C4D}"],
            'mahammad' => ["\u{0C2E}\u{0C39}\u{0C2E}\u{0C4D}\u{0C2E}\u{0C26}", "\u{0C2E}\u{0C39}\u{0C2E}\u{0C4D}\u{0C2E}\u{0C26}\u{0C4D}"],
            'gouse' => ["\u{0C17}\u{0C4C}\u{0C38}\u{0C4D}", "\u{0C17}\u{0C4C}\u{0C38}\u{0C4D}\u{0C38}", "\u{0C17}\u{0C4C}\u{0C38}\u{0C41}"],
            'ghouse' => ["\u{0C17}\u{0C4C}\u{0C38}\u{0C4D}", "\u{0C17}\u{0C4C}\u{0C38}\u{0C4D}\u{0C38}", "\u{0C17}\u{0C4C}\u{0C38}\u{0C41}"],
            'gaus' => ["\u{0C17}\u{0C4C}\u{0C38}\u{0C4D}", "\u{0C17}\u{0C4C}\u{0C38}\u{0C4D}\u{0C38}"],
            'saab' => ["\u{0C38}\u{0C3E}\u{0C2C}", "\u{0C38}\u{0C3E}\u{0C2C}\u{0C4D}", "\u{0C38}\u{0C3E}\u{0C39}\u{0C46}\u{0C2C}\u{0C4D}"],
            'sab' => ["\u{0C38}\u{0C3E}\u{0C2C}", "\u{0C38}\u{0C3E}\u{0C2C}\u{0C4D}"],
            'sahab' => ["\u{0C38}\u{0C3E}\u{0C39}\u{0C46}\u{0C2C}\u{0C4D}", "\u{0C38}\u{0C3E}\u{0C2C}\u{0C4D}"],
            'saleema' => ["\u{0C38}\u{0C32}\u{0C40}\u{0C2E}\u{0C3E}", "\u{0C38}\u{0C32}\u{0C40}\u{0C2E}"],
            'salima' => ["\u{0C38}\u{0C32}\u{0C40}\u{0C2E}\u{0C3E}", "\u{0C38}\u{0C32}\u{0C40}\u{0C2E}"],
            'saleemabee' => ["\u{0C38}\u{0C32}\u{0C40}\u{0C2E}\u{0C3E}\u{0C2C}\u{0C40}", "\u{0C38}\u{0C32}\u{0C40}\u{0C2E}\u{0C3E} \u{0C2C}\u{0C40}", "\u{0C38}\u{0C32}\u{0C40}\u{0C2E}\u{0C2C}\u{0C40}", "\u{0C38}\u{0C32}\u{0C40}\u{0C2E}\u{0C3E}", "\u{0C38}\u{0C32}\u{0C40}\u{0C2E}"],
            'salimabee' => ["\u{0C38}\u{0C32}\u{0C40}\u{0C2E}\u{0C3E}\u{0C2C}\u{0C40}", "\u{0C38}\u{0C32}\u{0C40}\u{0C2E}\u{0C3E} \u{0C2C}\u{0C40}", "\u{0C38}\u{0C32}\u{0C40}\u{0C2E}\u{0C2C}\u{0C40}", "\u{0C38}\u{0C32}\u{0C40}\u{0C2E}\u{0C3E}", "\u{0C38}\u{0C32}\u{0C40}\u{0C2E}"],
            'bee' => ["\u{0C2C}\u{0C40}", "\u{0C2C}\u{0C3F}"],
            'bi' => ["\u{0C2C}\u{0C40}", "\u{0C2C}\u{0C3F}"],
            'jahera' => ["\u{0C1C}\u{0C39}\u{0C3F}\u{0C30}\u{0C3E}", "\u{0C1C}\u{0C39}\u{0C40}\u{0C30}\u{0C3E}", "\u{0C1C}\u{0C39}\u{0C46}\u{0C30}\u{0C3E}"],
            'jahira' => ["\u{0C1C}\u{0C39}\u{0C3F}\u{0C30}\u{0C3E}", "\u{0C1C}\u{0C39}\u{0C40}\u{0C30}\u{0C3E}"],
            'zaeera' => ["\u{0C1C}\u{0C39}\u{0C40}\u{0C30}\u{0C3E}", "\u{0C1C}\u{0C39}\u{0C3F}\u{0C30}\u{0C3E}"],
            'zaera' => ["\u{0C1C}\u{0C39}\u{0C40}\u{0C30}\u{0C3E}", "\u{0C1C}\u{0C39}\u{0C3F}\u{0C30}\u{0C3E}"],
            'zeera' => ["\u{0C1C}\u{0C39}\u{0C40}\u{0C30}\u{0C3E}", "\u{0C1C}\u{0C39}\u{0C3F}\u{0C30}\u{0C3E}"],
            'zaheera' => ["\u{0C1C}\u{0C39}\u{0C40}\u{0C30}\u{0C3E}", "\u{0C1C}\u{0C39}\u{0C3F}\u{0C30}\u{0C3E}"],
            'zahera' => ["\u{0C1C}\u{0C39}\u{0C40}\u{0C30}\u{0C3E}", "\u{0C1C}\u{0C39}\u{0C3F}\u{0C30}\u{0C3E}"],
            'begum' => ["\u{0C2C}\u{0C47}\u{0C17}\u{0C02}", "\u{0C2C}\u{0C46}\u{0C17}\u{0C02}"],
            'jaherabegum' => ["\u{0C1C}\u{0C39}\u{0C3F}\u{0C30}\u{0C3E}\u{0C2C}\u{0C47}\u{0C17}\u{0C02}", "\u{0C1C}\u{0C39}\u{0C40}\u{0C30}\u{0C3E}\u{0C2C}\u{0C47}\u{0C17}\u{0C02}", "\u{0C1C}\u{0C39}\u{0C3F}\u{0C30}\u{0C3E} \u{0C2C}\u{0C47}\u{0C17}\u{0C02}", "\u{0C1C}\u{0C39}\u{0C40}\u{0C30}\u{0C3E} \u{0C2C}\u{0C47}\u{0C17}\u{0C02}"],
            'zaherabegum' => ["\u{0C1C}\u{0C39}\u{0C40}\u{0C30}\u{0C3E}\u{0C2C}\u{0C47}\u{0C17}\u{0C02}", "\u{0C1C}\u{0C39}\u{0C3F}\u{0C30}\u{0C3E}\u{0C2C}\u{0C47}\u{0C17}\u{0C02}"],
        ];

        $tokens = array_values($this->normalizer->tokenize($normalized));
        if (empty($tokens)) {
            return [];
        }

        $phrase = implode('', $tokens);
        $variants = $dictionary[$phrase] ?? [];

        if (count($tokens) === 1) {
            return array_values(array_unique(array_merge($variants, $dictionary[$tokens[0]] ?? [])));
        }

        $lists = [];
        foreach ($tokens as $token) {
            if (!isset($dictionary[$token])) {
                return array_values(array_unique($variants));
            }
            $lists[] = $dictionary[$token];
        }

        $phrases = [''];
        foreach ($lists as $list) {
            $next = [];
            foreach ($phrases as $prefix) {
                foreach ($list as $word) {
                    $next[] = trim($prefix . ' ' . $word);
                }
            }
            $phrases = $next;
        }

        foreach ($phrases as $candidate) {
            $variants[] = $candidate;
            $variants[] = str_replace(' ', '', $candidate);
        }

        return array_values(array_unique($variants));
    }

    protected function exactSearch(string $normalized, array $pdfIds): array
    {
        $query = PdfPage::query()
            ->with('pdfDocument')
            ->whereHas('pdfDocument', fn($q) => $q->where('status', 'indexed'))
            ->where('normalized_text', 'like', '%' . $normalized . '%');

        if (!empty($pdfIds)) {
            $query->whereIn('pdf_document_id', $pdfIds);
        }

        return $query->get()->map(fn($page) => $this->buildResult($page, $normalized))->toArray();
    }

    protected function tokenSearch(array $tokens, array $pdfIds): array
    {
        $query = PdfPage::query()
            ->with('pdfDocument')
            ->whereHas('pdfDocument', fn($q) => $q->where('status', 'indexed'));

        foreach ($tokens as $token) {
            $query->where('normalized_text', 'like', '%' . $token . '%');
        }

        if (!empty($pdfIds)) {
            $query->whereIn('pdf_document_id', $pdfIds);
        }

        return $query->get()->map(fn($page) => $this->buildResult($page, implode(' ', $tokens)))->toArray();
    }

    protected function partialSearch(string $token, array $pdfIds): array
    {
        $query = PdfPage::query()
            ->with('pdfDocument')
            ->whereHas('pdfDocument', fn($q) => $q->where('status', 'indexed'))
            ->where('normalized_text', 'like', '%' . $token . '%');

        if (!empty($pdfIds)) {
            $query->whereIn('pdf_document_id', $pdfIds);
        }

        return $query->get()->map(fn($page) => $this->buildResult($page, $token))->toArray();
    }

    protected function fuzzySearch(string $token, string $originalQuery, array $pdfIds): array
    {
        $prefix = mb_substr($token, 0, 3);

        $query = PdfPage::query()
            ->with('pdfDocument')
            ->whereHas('pdfDocument', fn($q) => $q->where('status', 'indexed'))
            ->where('normalized_text', 'like', '%' . $prefix . '%');

        if (!empty($pdfIds)) {
            $query->whereIn('pdf_document_id', $pdfIds);
        }

        $candidates = $query->get();

        $scored = $candidates->filter(function ($page) use ($token) {
            $words = $this->normalizer->tokenize($page->normalized_text ?? '');
            foreach ($words as $word) {
                similar_text($token, $word, $pct);
                if ($pct >= 70) {
                    return true;
                }
            }
            return false;
        });

        return $scored->map(fn($page) => $this->buildResult($page, $originalQuery))->toArray();
    }

    protected function romanizedTeluguSearch(string $normalizedQuery, array $pdfIds): array
    {
        $queryTokens = $this->normalizer->tokenize($this->romanNormalize($normalizedQuery));
        if (empty($queryTokens)) {
            return [];
        }

        $pages = PdfPage::query()
            ->with('pdfDocument')
            ->whereHas('pdfDocument', fn($q) => $q->where('status', 'indexed'));

        if (!empty($pdfIds)) {
            $pages->whereIn('pdf_document_id', $pdfIds);
        }

        $matches = [];
        $pages->orderBy('pdf_document_id')->orderBy('page_number')->chunk(200, function ($chunk) use (&$matches, $queryTokens, $normalizedQuery) {
            foreach ($chunk as $page) {
                $romanText = $this->romanNormalize($this->teluguToRoman($page->normalized_text ?? ''));
                if ($romanText === '') {
                    continue;
                }

                $score = $this->romanTokenScore($queryTokens, $romanText);
                if ($score >= 70) {
                    $matchedLine = $this->findBestRomanizedLine($page->raw_text ?? '', $queryTokens);
                    $lineScore = $this->romanTokenScore($queryTokens, $this->romanNormalize($this->teluguToRoman($matchedLine)));
                    if ($lineScore < 85) {
                        continue;
                    }

                    $result = $this->buildResult($page, $normalizedQuery);
                    $result['matched_text'] = $matchedLine;
                    $result['context'] = $this->extractRomanizedContext($page->raw_text ?? '', $queryTokens);
                    $result['confidence_score'] = min(95, $lineScore);
                    $matches[] = $result;
                }
            }
        });

        return $matches;
    }

    protected function romanTokenScore(array $queryTokens, string $romanText): int
    {
        $hits = 0;
        foreach ($queryTokens as $token) {
            if (mb_strlen($token) < 3) {
                continue;
            }

            if (str_contains($romanText, $token)) {
                $hits++;
                continue;
            }

            foreach ($this->normalizer->tokenize($romanText) as $word) {
                similar_text($token, $word, $pct);
                $wordIsUsefulPrefix = mb_strlen($word) >= 4
                    && mb_strlen($word) >= (int) floor(mb_strlen($token) * 0.65)
                    && str_contains($token, $word);

                if ($pct >= 72 || str_contains($word, $token) || $wordIsUsefulPrefix) {
                    $hits++;
                    break;
                }
            }
        }

        $usable = count(array_filter($queryTokens, fn($t) => mb_strlen($t) >= 3));
        if ($usable === 0) {
            return 0;
        }

        return (int) round(($hits / $usable) * 100);
    }

    protected function teluguToRoman(string $text): string
    {
        if (preg_match('/[\x{0C00}-\x{0C7F}]/u', $text)) {
            return $this->transliterateTeluguUtf8($text);
        }

        $replacements = [
            'ÃƒÂ Ã‚Â°Ã¢â‚¬Â¢ÃƒÂ Ã‚Â±Ã‚ÂÃƒÂ Ã‚Â°Ã‚Â·' => 'ksha', 'ÃƒÂ Ã‚Â°Ã‚Â¶ÃƒÂ Ã‚Â±Ã‚ÂÃƒÂ Ã‚Â°Ã‚Â°ÃƒÂ Ã‚Â±Ã¢â€šÂ¬' => 'sri',
            'ÃƒÂ Ã‚Â°Ã¢â‚¬â€œÃƒÂ Ã‚Â°Ã‚Â¾' => 'kha', 'ÃƒÂ Ã‚Â°Ã¢â‚¬â€ÃƒÂ Ã‚Â°Ã‚Â¾' => 'ga', 'ÃƒÂ Ã‚Â°Ã‹Å“ÃƒÂ Ã‚Â°Ã‚Â¾' => 'gha', 'ÃƒÂ Ã‚Â°Ã…Â¡ÃƒÂ Ã‚Â°Ã‚Â¾' => 'cha', 'ÃƒÂ Ã‚Â°Ã…â€œÃƒÂ Ã‚Â°Ã‚Â¾' => 'ja', 'ÃƒÂ Ã‚Â°Ã‚ÂÃƒÂ Ã‚Â°Ã‚Â¾' => 'jha', 'ÃƒÂ Ã‚Â°Ã…Â¸ÃƒÂ Ã‚Â°Ã‚Â¾' => 'ta', 'ÃƒÂ Ã‚Â°Ã‚Â¡ÃƒÂ Ã‚Â°Ã‚Â¾' => 'da', 'ÃƒÂ Ã‚Â°Ã‚Â¤ÃƒÂ Ã‚Â°Ã‚Â¾' => 'ta', 'ÃƒÂ Ã‚Â°Ã‚Â¦ÃƒÂ Ã‚Â°Ã‚Â¾' => 'da', 'ÃƒÂ Ã‚Â°Ã‚Â§ÃƒÂ Ã‚Â°Ã‚Â¾' => 'dha', 'ÃƒÂ Ã‚Â°Ã‚Â¨ÃƒÂ Ã‚Â°Ã‚Â¾' => 'na', 'ÃƒÂ Ã‚Â°Ã‚ÂªÃƒÂ Ã‚Â°Ã‚Â¾' => 'pa', 'ÃƒÂ Ã‚Â°Ã‚Â«ÃƒÂ Ã‚Â°Ã‚Â¾' => 'pha', 'ÃƒÂ Ã‚Â°Ã‚Â¬ÃƒÂ Ã‚Â°Ã‚Â¾' => 'ba', 'ÃƒÂ Ã‚Â°Ã‚Â­ÃƒÂ Ã‚Â°Ã‚Â¾' => 'bha', 'ÃƒÂ Ã‚Â°Ã‚Â®ÃƒÂ Ã‚Â°Ã‚Â¾' => 'ma', 'ÃƒÂ Ã‚Â°Ã‚Â¯ÃƒÂ Ã‚Â°Ã‚Â¾' => 'ya', 'ÃƒÂ Ã‚Â°Ã‚Â°ÃƒÂ Ã‚Â°Ã‚Â¾' => 'ra', 'ÃƒÂ Ã‚Â°Ã‚Â²ÃƒÂ Ã‚Â°Ã‚Â¾' => 'la', 'ÃƒÂ Ã‚Â°Ã‚ÂµÃƒÂ Ã‚Â°Ã‚Â¾' => 'va', 'ÃƒÂ Ã‚Â°Ã‚Â¶ÃƒÂ Ã‚Â°Ã‚Â¾' => 'sha', 'ÃƒÂ Ã‚Â°Ã‚Â¸ÃƒÂ Ã‚Â°Ã‚Â¾' => 'sa', 'ÃƒÂ Ã‚Â°Ã‚Â¹ÃƒÂ Ã‚Â°Ã‚Â¾' => 'ha',
            'ÃƒÂ Ã‚Â°Ã¢â‚¬Â¢ÃƒÂ Ã‚Â°Ã‚Â¿' => 'ki', 'ÃƒÂ Ã‚Â°Ã¢â‚¬â€ÃƒÂ Ã‚Â°Ã‚Â¿' => 'gi', 'ÃƒÂ Ã‚Â°Ã…Â¡ÃƒÂ Ã‚Â°Ã‚Â¿' => 'chi', 'ÃƒÂ Ã‚Â°Ã…â€œÃƒÂ Ã‚Â°Ã‚Â¿' => 'ji', 'ÃƒÂ Ã‚Â°Ã…Â¸ÃƒÂ Ã‚Â°Ã‚Â¿' => 'ti', 'ÃƒÂ Ã‚Â°Ã‚Â¡ÃƒÂ Ã‚Â°Ã‚Â¿' => 'di', 'ÃƒÂ Ã‚Â°Ã‚Â¤ÃƒÂ Ã‚Â°Ã‚Â¿' => 'ti', 'ÃƒÂ Ã‚Â°Ã‚Â¦ÃƒÂ Ã‚Â°Ã‚Â¿' => 'di', 'ÃƒÂ Ã‚Â°Ã‚Â¨ÃƒÂ Ã‚Â°Ã‚Â¿' => 'ni', 'ÃƒÂ Ã‚Â°Ã‚ÂªÃƒÂ Ã‚Â°Ã‚Â¿' => 'pi', 'ÃƒÂ Ã‚Â°Ã‚Â¬ÃƒÂ Ã‚Â°Ã‚Â¿' => 'bi', 'ÃƒÂ Ã‚Â°Ã‚Â®ÃƒÂ Ã‚Â°Ã‚Â¿' => 'mi', 'ÃƒÂ Ã‚Â°Ã‚Â¯ÃƒÂ Ã‚Â°Ã‚Â¿' => 'yi', 'ÃƒÂ Ã‚Â°Ã‚Â°ÃƒÂ Ã‚Â°Ã‚Â¿' => 'ri', 'ÃƒÂ Ã‚Â°Ã‚Â²ÃƒÂ Ã‚Â°Ã‚Â¿' => 'li', 'ÃƒÂ Ã‚Â°Ã‚ÂµÃƒÂ Ã‚Â°Ã‚Â¿' => 'vi', 'ÃƒÂ Ã‚Â°Ã‚Â¶ÃƒÂ Ã‚Â°Ã‚Â¿' => 'shi', 'ÃƒÂ Ã‚Â°Ã‚Â¸ÃƒÂ Ã‚Â°Ã‚Â¿' => 'si', 'ÃƒÂ Ã‚Â°Ã‚Â¹ÃƒÂ Ã‚Â°Ã‚Â¿' => 'hi',
            'ÃƒÂ Ã‚Â°Ã¢â‚¬Â¢ÃƒÂ Ã‚Â±Ã¢â€šÂ¬' => 'ki', 'ÃƒÂ Ã‚Â°Ã¢â‚¬â€ÃƒÂ Ã‚Â±Ã¢â€šÂ¬' => 'gi', 'ÃƒÂ Ã‚Â°Ã…Â¡ÃƒÂ Ã‚Â±Ã¢â€šÂ¬' => 'chi', 'ÃƒÂ Ã‚Â°Ã…â€œÃƒÂ Ã‚Â±Ã¢â€šÂ¬' => 'ji', 'ÃƒÂ Ã‚Â°Ã…Â¸ÃƒÂ Ã‚Â±Ã¢â€šÂ¬' => 'ti', 'ÃƒÂ Ã‚Â°Ã‚Â¡ÃƒÂ Ã‚Â±Ã¢â€šÂ¬' => 'di', 'ÃƒÂ Ã‚Â°Ã‚Â¤ÃƒÂ Ã‚Â±Ã¢â€šÂ¬' => 'ti', 'ÃƒÂ Ã‚Â°Ã‚Â¦ÃƒÂ Ã‚Â±Ã¢â€šÂ¬' => 'di', 'ÃƒÂ Ã‚Â°Ã‚Â¨ÃƒÂ Ã‚Â±Ã¢â€šÂ¬' => 'ni', 'ÃƒÂ Ã‚Â°Ã‚ÂªÃƒÂ Ã‚Â±Ã¢â€šÂ¬' => 'pi', 'ÃƒÂ Ã‚Â°Ã‚Â¬ÃƒÂ Ã‚Â±Ã¢â€šÂ¬' => 'bi', 'ÃƒÂ Ã‚Â°Ã‚Â®ÃƒÂ Ã‚Â±Ã¢â€šÂ¬' => 'mi', 'ÃƒÂ Ã‚Â°Ã‚Â¯ÃƒÂ Ã‚Â±Ã¢â€šÂ¬' => 'yi', 'ÃƒÂ Ã‚Â°Ã‚Â°ÃƒÂ Ã‚Â±Ã¢â€šÂ¬' => 'ri', 'ÃƒÂ Ã‚Â°Ã‚Â²ÃƒÂ Ã‚Â±Ã¢â€šÂ¬' => 'li', 'ÃƒÂ Ã‚Â°Ã‚ÂµÃƒÂ Ã‚Â±Ã¢â€šÂ¬' => 'vi', 'ÃƒÂ Ã‚Â°Ã‚Â¶ÃƒÂ Ã‚Â±Ã¢â€šÂ¬' => 'shi', 'ÃƒÂ Ã‚Â°Ã‚Â¸ÃƒÂ Ã‚Â±Ã¢â€šÂ¬' => 'si', 'ÃƒÂ Ã‚Â°Ã‚Â¹ÃƒÂ Ã‚Â±Ã¢â€šÂ¬' => 'hi',
            'ÃƒÂ Ã‚Â°Ã¢â‚¬Â¢ÃƒÂ Ã‚Â±Ã‚Â' => 'ku', 'ÃƒÂ Ã‚Â°Ã¢â‚¬â€ÃƒÂ Ã‚Â±Ã‚Â' => 'gu', 'ÃƒÂ Ã‚Â°Ã…Â¡ÃƒÂ Ã‚Â±Ã‚Â' => 'chu', 'ÃƒÂ Ã‚Â°Ã…â€œÃƒÂ Ã‚Â±Ã‚Â' => 'ju', 'ÃƒÂ Ã‚Â°Ã…Â¸ÃƒÂ Ã‚Â±Ã‚Â' => 'tu', 'ÃƒÂ Ã‚Â°Ã‚Â¡ÃƒÂ Ã‚Â±Ã‚Â' => 'du', 'ÃƒÂ Ã‚Â°Ã‚Â¤ÃƒÂ Ã‚Â±Ã‚Â' => 'tu', 'ÃƒÂ Ã‚Â°Ã‚Â¦ÃƒÂ Ã‚Â±Ã‚Â' => 'du', 'ÃƒÂ Ã‚Â°Ã‚Â¨ÃƒÂ Ã‚Â±Ã‚Â' => 'nu', 'ÃƒÂ Ã‚Â°Ã‚ÂªÃƒÂ Ã‚Â±Ã‚Â' => 'pu', 'ÃƒÂ Ã‚Â°Ã‚Â¬ÃƒÂ Ã‚Â±Ã‚Â' => 'bu', 'ÃƒÂ Ã‚Â°Ã‚Â®ÃƒÂ Ã‚Â±Ã‚Â' => 'mu', 'ÃƒÂ Ã‚Â°Ã‚Â¯ÃƒÂ Ã‚Â±Ã‚Â' => 'yu', 'ÃƒÂ Ã‚Â°Ã‚Â°ÃƒÂ Ã‚Â±Ã‚Â' => 'ru', 'ÃƒÂ Ã‚Â°Ã‚Â²ÃƒÂ Ã‚Â±Ã‚Â' => 'lu', 'ÃƒÂ Ã‚Â°Ã‚ÂµÃƒÂ Ã‚Â±Ã‚Â' => 'vu', 'ÃƒÂ Ã‚Â°Ã‚Â¶ÃƒÂ Ã‚Â±Ã‚Â' => 'shu', 'ÃƒÂ Ã‚Â°Ã‚Â¸ÃƒÂ Ã‚Â±Ã‚Â' => 'su', 'ÃƒÂ Ã‚Â°Ã‚Â¹ÃƒÂ Ã‚Â±Ã‚Â' => 'hu',
            'ÃƒÂ Ã‚Â°Ã¢â‚¬Â¢ÃƒÂ Ã‚Â±Ã¢â‚¬Å¡' => 'ku', 'ÃƒÂ Ã‚Â°Ã¢â‚¬â€ÃƒÂ Ã‚Â±Ã¢â‚¬Å¡' => 'gu', 'ÃƒÂ Ã‚Â°Ã…Â¡ÃƒÂ Ã‚Â±Ã¢â‚¬Å¡' => 'chu', 'ÃƒÂ Ã‚Â°Ã…â€œÃƒÂ Ã‚Â±Ã¢â‚¬Å¡' => 'ju', 'ÃƒÂ Ã‚Â°Ã…Â¸ÃƒÂ Ã‚Â±Ã¢â‚¬Å¡' => 'tu', 'ÃƒÂ Ã‚Â°Ã‚Â¡ÃƒÂ Ã‚Â±Ã¢â‚¬Å¡' => 'du', 'ÃƒÂ Ã‚Â°Ã‚Â¤ÃƒÂ Ã‚Â±Ã¢â‚¬Å¡' => 'tu', 'ÃƒÂ Ã‚Â°Ã‚Â¦ÃƒÂ Ã‚Â±Ã¢â‚¬Å¡' => 'du', 'ÃƒÂ Ã‚Â°Ã‚Â¨ÃƒÂ Ã‚Â±Ã¢â‚¬Å¡' => 'nu', 'ÃƒÂ Ã‚Â°Ã‚ÂªÃƒÂ Ã‚Â±Ã¢â‚¬Å¡' => 'pu', 'ÃƒÂ Ã‚Â°Ã‚Â¬ÃƒÂ Ã‚Â±Ã¢â‚¬Å¡' => 'bu', 'ÃƒÂ Ã‚Â°Ã‚Â®ÃƒÂ Ã‚Â±Ã¢â‚¬Å¡' => 'mu', 'ÃƒÂ Ã‚Â°Ã‚Â¯ÃƒÂ Ã‚Â±Ã¢â‚¬Å¡' => 'yu', 'ÃƒÂ Ã‚Â°Ã‚Â°ÃƒÂ Ã‚Â±Ã¢â‚¬Å¡' => 'ru', 'ÃƒÂ Ã‚Â°Ã‚Â²ÃƒÂ Ã‚Â±Ã¢â‚¬Å¡' => 'lu', 'ÃƒÂ Ã‚Â°Ã‚ÂµÃƒÂ Ã‚Â±Ã¢â‚¬Å¡' => 'vu', 'ÃƒÂ Ã‚Â°Ã‚Â¶ÃƒÂ Ã‚Â±Ã¢â‚¬Å¡' => 'shu', 'ÃƒÂ Ã‚Â°Ã‚Â¸ÃƒÂ Ã‚Â±Ã¢â‚¬Å¡' => 'su', 'ÃƒÂ Ã‚Â°Ã‚Â¹ÃƒÂ Ã‚Â±Ã¢â‚¬Å¡' => 'hu',
            'ÃƒÂ Ã‚Â°Ã¢â‚¬Â¢ÃƒÂ Ã‚Â±Ã¢â‚¬Â ' => 'ke', 'ÃƒÂ Ã‚Â°Ã¢â‚¬â€ÃƒÂ Ã‚Â±Ã¢â‚¬Â ' => 'ge', 'ÃƒÂ Ã‚Â°Ã…Â¡ÃƒÂ Ã‚Â±Ã¢â‚¬Â ' => 'che', 'ÃƒÂ Ã‚Â°Ã…â€œÃƒÂ Ã‚Â±Ã¢â‚¬Â ' => 'je', 'ÃƒÂ Ã‚Â°Ã…Â¸ÃƒÂ Ã‚Â±Ã¢â‚¬Â ' => 'te', 'ÃƒÂ Ã‚Â°Ã‚Â¡ÃƒÂ Ã‚Â±Ã¢â‚¬Â ' => 'de', 'ÃƒÂ Ã‚Â°Ã‚Â¤ÃƒÂ Ã‚Â±Ã¢â‚¬Â ' => 'te', 'ÃƒÂ Ã‚Â°Ã‚Â¦ÃƒÂ Ã‚Â±Ã¢â‚¬Â ' => 'de', 'ÃƒÂ Ã‚Â°Ã‚Â¨ÃƒÂ Ã‚Â±Ã¢â‚¬Â ' => 'ne', 'ÃƒÂ Ã‚Â°Ã‚ÂªÃƒÂ Ã‚Â±Ã¢â‚¬Â ' => 'pe', 'ÃƒÂ Ã‚Â°Ã‚Â¬ÃƒÂ Ã‚Â±Ã¢â‚¬Â ' => 'be', 'ÃƒÂ Ã‚Â°Ã‚Â®ÃƒÂ Ã‚Â±Ã¢â‚¬Â ' => 'me', 'ÃƒÂ Ã‚Â°Ã‚Â¯ÃƒÂ Ã‚Â±Ã¢â‚¬Â ' => 'ye', 'ÃƒÂ Ã‚Â°Ã‚Â°ÃƒÂ Ã‚Â±Ã¢â‚¬Â ' => 're', 'ÃƒÂ Ã‚Â°Ã‚Â²ÃƒÂ Ã‚Â±Ã¢â‚¬Â ' => 'le', 'ÃƒÂ Ã‚Â°Ã‚ÂµÃƒÂ Ã‚Â±Ã¢â‚¬Â ' => 've', 'ÃƒÂ Ã‚Â°Ã‚Â¶ÃƒÂ Ã‚Â±Ã¢â‚¬Â ' => 'she', 'ÃƒÂ Ã‚Â°Ã‚Â¸ÃƒÂ Ã‚Â±Ã¢â‚¬Â ' => 'se', 'ÃƒÂ Ã‚Â°Ã‚Â¹ÃƒÂ Ã‚Â±Ã¢â‚¬Â ' => 'he',
            'ÃƒÂ Ã‚Â°Ã¢â‚¬Â¢ÃƒÂ Ã‚Â±Ã¢â‚¬Â¡' => 'ke', 'ÃƒÂ Ã‚Â°Ã¢â‚¬â€ÃƒÂ Ã‚Â±Ã¢â‚¬Â¡' => 'ge', 'ÃƒÂ Ã‚Â°Ã…Â¡ÃƒÂ Ã‚Â±Ã¢â‚¬Â¡' => 'che', 'ÃƒÂ Ã‚Â°Ã…â€œÃƒÂ Ã‚Â±Ã¢â‚¬Â¡' => 'je', 'ÃƒÂ Ã‚Â°Ã…Â¸ÃƒÂ Ã‚Â±Ã¢â‚¬Â¡' => 'te', 'ÃƒÂ Ã‚Â°Ã‚Â¡ÃƒÂ Ã‚Â±Ã¢â‚¬Â¡' => 'de', 'ÃƒÂ Ã‚Â°Ã‚Â¤ÃƒÂ Ã‚Â±Ã¢â‚¬Â¡' => 'te', 'ÃƒÂ Ã‚Â°Ã‚Â¦ÃƒÂ Ã‚Â±Ã¢â‚¬Â¡' => 'de', 'ÃƒÂ Ã‚Â°Ã‚Â¨ÃƒÂ Ã‚Â±Ã¢â‚¬Â¡' => 'ne', 'ÃƒÂ Ã‚Â°Ã‚ÂªÃƒÂ Ã‚Â±Ã¢â‚¬Â¡' => 'pe', 'ÃƒÂ Ã‚Â°Ã‚Â¬ÃƒÂ Ã‚Â±Ã¢â‚¬Â¡' => 'be', 'ÃƒÂ Ã‚Â°Ã‚Â®ÃƒÂ Ã‚Â±Ã¢â‚¬Â¡' => 'me', 'ÃƒÂ Ã‚Â°Ã‚Â¯ÃƒÂ Ã‚Â±Ã¢â‚¬Â¡' => 'ye', 'ÃƒÂ Ã‚Â°Ã‚Â°ÃƒÂ Ã‚Â±Ã¢â‚¬Â¡' => 're', 'ÃƒÂ Ã‚Â°Ã‚Â²ÃƒÂ Ã‚Â±Ã¢â‚¬Â¡' => 'le', 'ÃƒÂ Ã‚Â°Ã‚ÂµÃƒÂ Ã‚Â±Ã¢â‚¬Â¡' => 've', 'ÃƒÂ Ã‚Â°Ã‚Â¶ÃƒÂ Ã‚Â±Ã¢â‚¬Â¡' => 'she', 'ÃƒÂ Ã‚Â°Ã‚Â¸ÃƒÂ Ã‚Â±Ã¢â‚¬Â¡' => 'se', 'ÃƒÂ Ã‚Â°Ã‚Â¹ÃƒÂ Ã‚Â±Ã¢â‚¬Â¡' => 'he',
            'ÃƒÂ Ã‚Â°Ã¢â‚¬Â¢ÃƒÂ Ã‚Â±Ã…Â ' => 'ko', 'ÃƒÂ Ã‚Â°Ã¢â‚¬â€ÃƒÂ Ã‚Â±Ã…Â ' => 'go', 'ÃƒÂ Ã‚Â°Ã…Â¡ÃƒÂ Ã‚Â±Ã…Â ' => 'cho', 'ÃƒÂ Ã‚Â°Ã…â€œÃƒÂ Ã‚Â±Ã…Â ' => 'jo', 'ÃƒÂ Ã‚Â°Ã…Â¸ÃƒÂ Ã‚Â±Ã…Â ' => 'to', 'ÃƒÂ Ã‚Â°Ã‚Â¡ÃƒÂ Ã‚Â±Ã…Â ' => 'do', 'ÃƒÂ Ã‚Â°Ã‚Â¤ÃƒÂ Ã‚Â±Ã…Â ' => 'to', 'ÃƒÂ Ã‚Â°Ã‚Â¦ÃƒÂ Ã‚Â±Ã…Â ' => 'do', 'ÃƒÂ Ã‚Â°Ã‚Â¨ÃƒÂ Ã‚Â±Ã…Â ' => 'no', 'ÃƒÂ Ã‚Â°Ã‚ÂªÃƒÂ Ã‚Â±Ã…Â ' => 'po', 'ÃƒÂ Ã‚Â°Ã‚Â¬ÃƒÂ Ã‚Â±Ã…Â ' => 'bo', 'ÃƒÂ Ã‚Â°Ã‚Â®ÃƒÂ Ã‚Â±Ã…Â ' => 'mo', 'ÃƒÂ Ã‚Â°Ã‚Â¯ÃƒÂ Ã‚Â±Ã…Â ' => 'yo', 'ÃƒÂ Ã‚Â°Ã‚Â°ÃƒÂ Ã‚Â±Ã…Â ' => 'ro', 'ÃƒÂ Ã‚Â°Ã‚Â²ÃƒÂ Ã‚Â±Ã…Â ' => 'lo', 'ÃƒÂ Ã‚Â°Ã‚ÂµÃƒÂ Ã‚Â±Ã…Â ' => 'vo', 'ÃƒÂ Ã‚Â°Ã‚Â¶ÃƒÂ Ã‚Â±Ã…Â ' => 'sho', 'ÃƒÂ Ã‚Â°Ã‚Â¸ÃƒÂ Ã‚Â±Ã…Â ' => 'so', 'ÃƒÂ Ã‚Â°Ã‚Â¹ÃƒÂ Ã‚Â±Ã…Â ' => 'ho',
            'ÃƒÂ Ã‚Â°Ã¢â‚¬Â¢ÃƒÂ Ã‚Â±Ã¢â‚¬Â¹' => 'ko', 'ÃƒÂ Ã‚Â°Ã¢â‚¬â€ÃƒÂ Ã‚Â±Ã¢â‚¬Â¹' => 'go', 'ÃƒÂ Ã‚Â°Ã…Â¡ÃƒÂ Ã‚Â±Ã¢â‚¬Â¹' => 'cho', 'ÃƒÂ Ã‚Â°Ã…â€œÃƒÂ Ã‚Â±Ã¢â‚¬Â¹' => 'jo', 'ÃƒÂ Ã‚Â°Ã…Â¸ÃƒÂ Ã‚Â±Ã¢â‚¬Â¹' => 'to', 'ÃƒÂ Ã‚Â°Ã‚Â¡ÃƒÂ Ã‚Â±Ã¢â‚¬Â¹' => 'do', 'ÃƒÂ Ã‚Â°Ã‚Â¤ÃƒÂ Ã‚Â±Ã¢â‚¬Â¹' => 'to', 'ÃƒÂ Ã‚Â°Ã‚Â¦ÃƒÂ Ã‚Â±Ã¢â‚¬Â¹' => 'do', 'ÃƒÂ Ã‚Â°Ã‚Â¨ÃƒÂ Ã‚Â±Ã¢â‚¬Â¹' => 'no', 'ÃƒÂ Ã‚Â°Ã‚ÂªÃƒÂ Ã‚Â±Ã¢â‚¬Â¹' => 'po', 'ÃƒÂ Ã‚Â°Ã‚Â¬ÃƒÂ Ã‚Â±Ã¢â‚¬Â¹' => 'bo', 'ÃƒÂ Ã‚Â°Ã‚Â®ÃƒÂ Ã‚Â±Ã¢â‚¬Â¹' => 'mo', 'ÃƒÂ Ã‚Â°Ã‚Â¯ÃƒÂ Ã‚Â±Ã¢â‚¬Â¹' => 'yo', 'ÃƒÂ Ã‚Â°Ã‚Â°ÃƒÂ Ã‚Â±Ã¢â‚¬Â¹' => 'ro', 'ÃƒÂ Ã‚Â°Ã‚Â²ÃƒÂ Ã‚Â±Ã¢â‚¬Â¹' => 'lo', 'ÃƒÂ Ã‚Â°Ã‚ÂµÃƒÂ Ã‚Â±Ã¢â‚¬Â¹' => 'vo', 'ÃƒÂ Ã‚Â°Ã‚Â¶ÃƒÂ Ã‚Â±Ã¢â‚¬Â¹' => 'sho', 'ÃƒÂ Ã‚Â°Ã‚Â¸ÃƒÂ Ã‚Â±Ã¢â‚¬Â¹' => 'so', 'ÃƒÂ Ã‚Â°Ã‚Â¹ÃƒÂ Ã‚Â±Ã¢â‚¬Â¹' => 'ho',
            'ÃƒÂ Ã‚Â°Ã¢â‚¬Â¢ÃƒÂ Ã‚Â±Ã‚Â' => 'k', 'ÃƒÂ Ã‚Â°Ã¢â‚¬â€œÃƒÂ Ã‚Â±Ã‚Â' => 'kh', 'ÃƒÂ Ã‚Â°Ã¢â‚¬â€ÃƒÂ Ã‚Â±Ã‚Â' => 'g', 'ÃƒÂ Ã‚Â°Ã‹Å“ÃƒÂ Ã‚Â±Ã‚Â' => 'gh', 'ÃƒÂ Ã‚Â°Ã…Â¡ÃƒÂ Ã‚Â±Ã‚Â' => 'ch', 'ÃƒÂ Ã‚Â°Ã…â€œÃƒÂ Ã‚Â±Ã‚Â' => 'j', 'ÃƒÂ Ã‚Â°Ã‚ÂÃƒÂ Ã‚Â±Ã‚Â' => 'jh', 'ÃƒÂ Ã‚Â°Ã…Â¸ÃƒÂ Ã‚Â±Ã‚Â' => 't', 'ÃƒÂ Ã‚Â°Ã‚Â¡ÃƒÂ Ã‚Â±Ã‚Â' => 'd', 'ÃƒÂ Ã‚Â°Ã‚Â¤ÃƒÂ Ã‚Â±Ã‚Â' => 't', 'ÃƒÂ Ã‚Â°Ã‚Â¦ÃƒÂ Ã‚Â±Ã‚Â' => 'd', 'ÃƒÂ Ã‚Â°Ã‚Â§ÃƒÂ Ã‚Â±Ã‚Â' => 'dh', 'ÃƒÂ Ã‚Â°Ã‚Â¨ÃƒÂ Ã‚Â±Ã‚Â' => 'n', 'ÃƒÂ Ã‚Â°Ã‚ÂªÃƒÂ Ã‚Â±Ã‚Â' => 'p', 'ÃƒÂ Ã‚Â°Ã‚Â«ÃƒÂ Ã‚Â±Ã‚Â' => 'ph', 'ÃƒÂ Ã‚Â°Ã‚Â¬ÃƒÂ Ã‚Â±Ã‚Â' => 'b', 'ÃƒÂ Ã‚Â°Ã‚Â­ÃƒÂ Ã‚Â±Ã‚Â' => 'bh', 'ÃƒÂ Ã‚Â°Ã‚Â®ÃƒÂ Ã‚Â±Ã‚Â' => 'm', 'ÃƒÂ Ã‚Â°Ã‚Â¯ÃƒÂ Ã‚Â±Ã‚Â' => 'y', 'ÃƒÂ Ã‚Â°Ã‚Â°ÃƒÂ Ã‚Â±Ã‚Â' => 'r', 'ÃƒÂ Ã‚Â°Ã‚Â²ÃƒÂ Ã‚Â±Ã‚Â' => 'l', 'ÃƒÂ Ã‚Â°Ã‚Â³ÃƒÂ Ã‚Â±Ã‚Â' => 'l', 'ÃƒÂ Ã‚Â°Ã‚ÂµÃƒÂ Ã‚Â±Ã‚Â' => 'v', 'ÃƒÂ Ã‚Â°Ã‚Â¶ÃƒÂ Ã‚Â±Ã‚Â' => 'sh', 'ÃƒÂ Ã‚Â°Ã‚Â·ÃƒÂ Ã‚Â±Ã‚Â' => 'sh', 'ÃƒÂ Ã‚Â°Ã‚Â¸ÃƒÂ Ã‚Â±Ã‚Â' => 's', 'ÃƒÂ Ã‚Â°Ã‚Â¹ÃƒÂ Ã‚Â±Ã‚Â' => 'h',
            'ÃƒÂ Ã‚Â°Ã¢â‚¬Â¢' => 'ka', 'ÃƒÂ Ã‚Â°Ã¢â‚¬â€œ' => 'kha', 'ÃƒÂ Ã‚Â°Ã¢â‚¬â€' => 'ga', 'ÃƒÂ Ã‚Â°Ã‹Å“' => 'gha', 'ÃƒÂ Ã‚Â°Ã…Â¡' => 'cha', 'ÃƒÂ Ã‚Â°Ã…â€œ' => 'ja', 'ÃƒÂ Ã‚Â°Ã‚Â' => 'jha', 'ÃƒÂ Ã‚Â°Ã…Â¸' => 'ta', 'ÃƒÂ Ã‚Â°Ã‚Â¡' => 'da', 'ÃƒÂ Ã‚Â°Ã‚Â¤' => 'ta', 'ÃƒÂ Ã‚Â°Ã‚Â¦' => 'da', 'ÃƒÂ Ã‚Â°Ã‚Â§' => 'dha', 'ÃƒÂ Ã‚Â°Ã‚Â¨' => 'na', 'ÃƒÂ Ã‚Â°Ã‚Âª' => 'pa', 'ÃƒÂ Ã‚Â°Ã‚Â«' => 'pha', 'ÃƒÂ Ã‚Â°Ã‚Â¬' => 'ba', 'ÃƒÂ Ã‚Â°Ã‚Â­' => 'bha', 'ÃƒÂ Ã‚Â°Ã‚Â®' => 'ma', 'ÃƒÂ Ã‚Â°Ã‚Â¯' => 'ya', 'ÃƒÂ Ã‚Â°Ã‚Â°' => 'ra', 'ÃƒÂ Ã‚Â°Ã‚Â²' => 'la', 'ÃƒÂ Ã‚Â°Ã‚Â³' => 'la', 'ÃƒÂ Ã‚Â°Ã‚Âµ' => 'va', 'ÃƒÂ Ã‚Â°Ã‚Â¶' => 'sha', 'ÃƒÂ Ã‚Â°Ã‚Â·' => 'sha', 'ÃƒÂ Ã‚Â°Ã‚Â¸' => 'sa', 'ÃƒÂ Ã‚Â°Ã‚Â¹' => 'ha',
            'ÃƒÂ Ã‚Â°Ã¢â‚¬Â¦' => 'a', 'ÃƒÂ Ã‚Â°Ã¢â‚¬Â ' => 'a', 'ÃƒÂ Ã‚Â°Ã¢â‚¬Â¡' => 'i', 'ÃƒÂ Ã‚Â°Ã‹â€ ' => 'i', 'ÃƒÂ Ã‚Â°Ã¢â‚¬Â°' => 'u', 'ÃƒÂ Ã‚Â°Ã…Â ' => 'u', 'ÃƒÂ Ã‚Â°Ã…Â½' => 'e', 'ÃƒÂ Ã‚Â°Ã‚Â' => 'e', 'ÃƒÂ Ã‚Â°Ã‚Â' => 'ai', 'ÃƒÂ Ã‚Â°Ã¢â‚¬â„¢' => 'o', 'ÃƒÂ Ã‚Â°Ã¢â‚¬Å“' => 'o', 'ÃƒÂ Ã‚Â°Ã¢â‚¬Â' => 'au', 'ÃƒÂ Ã‚Â°Ã¢â‚¬Å¡' => 'm', 'ÃƒÂ Ã‚Â°Ã†â€™' => 'h', 'ÃƒÂ Ã‚Â°Ã‚Â¾' => 'a', 'ÃƒÂ Ã‚Â°Ã‚Â¿' => 'i', 'ÃƒÂ Ã‚Â±Ã¢â€šÂ¬' => 'i', 'ÃƒÂ Ã‚Â±Ã‚Â' => 'u', 'ÃƒÂ Ã‚Â±Ã¢â‚¬Å¡' => 'u', 'ÃƒÂ Ã‚Â±Ã¢â‚¬Â ' => 'e', 'ÃƒÂ Ã‚Â±Ã¢â‚¬Â¡' => 'e', 'ÃƒÂ Ã‚Â±Ã‹â€ ' => 'ai', 'ÃƒÂ Ã‚Â±Ã…Â ' => 'o', 'ÃƒÂ Ã‚Â±Ã¢â‚¬Â¹' => 'o', 'ÃƒÂ Ã‚Â±Ã…â€™' => 'au', 'ÃƒÂ Ã‚Â±Ã†â€™' => 'ru', 'ÃƒÂ Ã‚Â±Ã‚Â' => '',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $text);
    }

    protected function transliterateTeluguUtf8(string $text): string
    {
        $consonants = [
            'క' => 'k', 'ఖ' => 'kh', 'గ' => 'g', 'ఘ' => 'gh', 'ఙ' => 'ng',
            'చ' => 'ch', 'ఛ' => 'chh', 'జ' => 'j', 'ఝ' => 'jh', 'ఞ' => 'ny',
            'ట' => 't', 'ఠ' => 'th', 'డ' => 'd', 'ఢ' => 'dh', 'ణ' => 'n',
            'త' => 't', 'థ' => 'th', 'ద' => 'd', 'ధ' => 'dh', 'న' => 'n',
            'ప' => 'p', 'ఫ' => 'ph', 'బ' => 'b', 'భ' => 'bh', 'మ' => 'm',
            'య' => 'y', 'ర' => 'r', 'ఱ' => 'r', 'ల' => 'l', 'ళ' => 'l',
            'వ' => 'v', 'శ' => 'sh', 'ష' => 'sh', 'స' => 's', 'హ' => 'h',
        ];

        $vowels = [
            'అ' => 'a', 'ఆ' => 'aa', 'ఇ' => 'i', 'ఈ' => 'ee', 'ఉ' => 'u', 'ఊ' => 'oo',
            'ఋ' => 'ru', 'ఎ' => 'e', 'ఏ' => 'e', 'ఐ' => 'ai', 'ఒ' => 'o', 'ఓ' => 'o', 'ఔ' => 'au',
        ];

        $matras = [
            'ా' => 'aa', 'ి' => 'i', 'ీ' => 'ee', 'ు' => 'u', 'ూ' => 'oo', 'ృ' => 'ru',
            'ె' => 'e', 'ే' => 'e', 'ై' => 'ai', 'ొ' => 'o', 'ో' => 'o', 'ౌ' => 'au',
        ];

        $marks = ['ం' => 'm', 'ః' => 'h', 'ఁ' => 'n'];
        $virama = '్';
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $out = '';

        for ($i = 0; $i < count($chars); $i++) {
            $ch = $chars[$i];

            if (isset($vowels[$ch])) {
                $out .= $vowels[$ch];
                continue;
            }

            if (isset($consonants[$ch])) {
                $next = $chars[$i + 1] ?? '';

                if ($next === $virama) {
                    $out .= $consonants[$ch];
                    $i++;
                    continue;
                }

                if (isset($matras[$next])) {
                    $out .= $consonants[$ch] . $matras[$next];
                    $i++;
                    continue;
                }

                $out .= $consonants[$ch] . 'a';
                continue;
            }

            if (isset($marks[$ch])) {
                $out .= $marks[$ch];
                continue;
            }

            if (isset($matras[$ch]) || $ch === $virama) {
                continue;
            }

            $out .= $ch;
        }

        return $out;
    }

    protected function romanNormalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^a-z0-9]+/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    protected function findBestRomanizedLine(string $rawText, array $queryTokens): string
    {
        $bestLine = '';
        $bestScore = -1;
        foreach (preg_split('/\n/u', $rawText) as $line) {
            $score = $this->romanTokenScore($queryTokens, $this->romanNormalize($this->teluguToRoman($line)));
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestLine = trim($line);
            }
        }
        return $bestLine;
    }

    protected function extractRomanizedContext(string $rawText, array $queryTokens): string
    {
        $lines = preg_split('/\n/u', $rawText);
        $bestIdx = 0;
        $bestScore = -1;

        foreach ($lines as $i => $line) {
            $score = $this->romanTokenScore($queryTokens, $this->romanNormalize($this->teluguToRoman($line)));
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIdx = $i;
            }
        }

        $start = max(0, $bestIdx - 2);
        $end = min(count($lines) - 1, $bestIdx + 2);
        return implode("\n", array_map('trim', array_slice($lines, $start, $end - $start + 1)));
    }

    protected function buildResult(PdfPage $page, string $query): array
    {
        $doc = $page->pdfDocument;
        $context = $this->extractContext($page->raw_text ?? '', $query);

        return [
            'source_type' => 'pdf',
            'result_key' => 'pdf:' . $doc->id . ':' . $page->page_number,
            'pdf_id' => $doc->id,
            'pdf_name' => $doc->original_name,
            'page_number' => $page->page_number,
            'matched_text' => $this->findMatchedLine($page->raw_text ?? $page->normalized_text ?? '', $query),
            'context' => $context,
            'confidence' => 'medium',
            'confidence_score' => 60,
            'page_id' => $page->id,
        ];
    }

    protected function buildVoterRecordResult(VoterRecord $record, string $confidence, int $score): array
    {
        $relativeRoman = $this->romanizer->normalize((string) $record->relative_name);
        $relativeAliases = $this->romanAliasesFromText($relativeRoman);
        $matched = trim(implode(' ', array_filter([
            $record->serial_no,
            $record->house_no,
            $record->voter_name,
            $record->relation_type,
            $record->relative_name,
            $record->gender,
            $record->age,
            $record->voter_id,
        ], fn($value) => $value !== null && $value !== '')));

        $context = implode("\n", array_filter([
            'Name: ' . ($record->voter_name ?: '-'),
            'Relative (' . ($record->relation ?: $record->relation_type ?: '-') . '): ' . ($record->relative_name ?: '-'),
            'Part No: ' . ($record->part_no ?: '-') . ' | Serial No: ' . ($record->serial_no ?: '-') . ' | Roll Page: ' . ($record->roll_page_no ?: '-') . ' | PDF Page: ' . ($record->pdf_page ?: '-'),
            'House No: ' . ($record->house_no ?: '-') . ' | Age: ' . ($record->age ?: '-') . ' | Gender: ' . ($record->gender_english ?: $record->gender ?: '-'),
            'Voter ID: ' . ($record->voter_id ?: '-'),
        ]));

        return [
            'source_type' => 'excel',
            'result_key' => 'excel:' . $record->id,
            'record_id' => $record->id,
            'pdf_id' => null,
            'pdf_name' => $record->source_file ?: 'Excel Voter Records',
            'page_number' => $record->pdf_page,
            'part_no' => $record->part_no,
            'roll_page_no' => $record->roll_page_no,
            'serial_no' => $record->serial_no,
            'source_row' => $record->source_row,
            'voter_id' => $record->voter_id,
            'matched_text' => $matched,
            'relative_text' => trim((string) $record->relative_name . ' ' . $relativeRoman . ' ' . $relativeAliases),
            'context' => $context,
            'confidence' => $confidence,
            'confidence_score' => $score,
            'page_id' => 'voter-record-' . $record->id,
        ];
    }

    protected function romanAliasesFromText(string $text): string
    {
        $aliases = [];
        foreach ($this->normalizer->tokenize($this->romanNormalize($text)) as $token) {
            array_push($aliases, ...$this->romanTokenAliases($token));
        }

        return implode(' ', array_values(array_unique($aliases)));
    }

    protected function findMatchedLine(string $text, string $query): string
    {
        $lines = preg_split('/\n/u', $text);
        $tokens = $this->normalizer->tokenize($this->normalizer->normalize($query));

        foreach ($lines as $line) {
            $normalizedLine = $this->normalizer->normalize($line);
            if ($query !== '' && mb_stripos($normalizedLine, $this->normalizer->normalize($query)) !== false) {
                return trim($line);
            }

            $matchCount = 0;
            foreach ($tokens as $token) {
                if (mb_stripos($normalizedLine, $token) !== false) {
                    $matchCount++;
                }
            }

            if (!empty($tokens) && $matchCount >= max(1, ceil(count($tokens) * 0.7))) {
                return trim($line);
            }
        }

        return trim(mb_substr($text, 0, 120));
    }
    protected function extractContext(string $rawText, string $query): string
    {
        $lines = preg_split('/\n/u', $rawText);
        $total = count($lines);
        $normalizedQuery = $this->normalizer->normalize($query);
        $tokens = $this->normalizer->tokenize($normalizedQuery);
        $bestIdx = 0;
        $bestScore = -1;

        foreach ($lines as $i => $line) {
            $normalizedLine = $this->normalizer->normalize($line);
            $score = 0;

            if ($normalizedQuery !== '' && mb_stripos($normalizedLine, $normalizedQuery) !== false) {
                $score = 100;
            } else {
                foreach ($tokens as $token) {
                    if (mb_stripos($normalizedLine, $token) !== false) {
                        $score += 30;
                    }
                }
                similar_text($normalizedLine, $normalizedQuery, $pct);
                $score += (int) round($pct / 3);
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIdx = $i;
            }
        }

        $start = max(0, $bestIdx - 2);
        $end = min($total - 1, $bestIdx + 2);
        $context = array_slice($lines, $start, $end - $start + 1);

        return implode("\n", array_map('trim', $context));
    }
    protected function alreadyFound(Collection $results, array $candidate): bool
    {
        return $results->contains(function ($r) use ($candidate) {
            return $r['pdf_id'] === $candidate['pdf_id']
                && $r['page_number'] === $candidate['page_number'];
        });
    }
}
