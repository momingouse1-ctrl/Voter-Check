<?php

namespace App\Services;

class HouseNumberNormalizer
{
    /**
     * Normalize a house number input for storage and search.
     *
     * Handles formats like:
     *   19/122, 19-122, 19 122, H.No 19/122, House No 19/122,
     *   Door No 19/122, 07/106, 7-106, 7 106, 19/122-A, 19/A/122
     *
     * Returns a canonical string like "7/106", "19/122", "19/122-A"
     */
    public function normalize(string $raw): string
    {
        // Remove common prefixes (case-insensitive)
        $cleaned = preg_replace('/^(h\.?\s*no\.?|house\s*no\.?|door\s*no\.?|d\.?\s*no\.?|plot\s*no\.?|flat\s*no\.?)\s*/i', '', trim($raw));

        // Remove leading zeros from segments
        // e.g. "07/106" → "7/106"
        $cleaned = preg_replace_callback('/\b0+(\d+)\b/', fn($m) => $m[1], $cleaned);

        // Replace hyphen/space between numeric parts with slash
        // e.g. "19 122" → "19/122", "19-122" → "19/122"
        // But keep trailing -A, -B suffixes
        $cleaned = preg_replace('/(\d)\s*[-]\s*(\d)/', '$1/$2', $cleaned);
        $cleaned = preg_replace('/(\d)\s+(\d)/', '$1/$2', $cleaned);

        return trim($cleaned);
    }

    /**
     * Generate multiple search variants for a given raw house number.
     * Used so we can match "07/106" when user types "7/106" and vice versa.
     */
    public function variants(string $raw): array
    {
        $normalized = $this->normalize($raw);
        $variants   = [$normalized, $raw];

        // Also add zero-padded first segment variant
        if (preg_match('/^(\d+)(.*)$/', $normalized, $m)) {
            $padded = str_pad($m[1], 2, '0', STR_PAD_LEFT) . $m[2];
            $variants[] = $padded;
        }

        // Also add slash → hyphen variant
        $variants[] = str_replace('/', '-', $normalized);
        $variants[] = str_replace('/', ' ', $normalized);

        return array_values(array_unique(array_filter($variants)));
    }

    /**
     * Extract just the numeric parts for fuzzy matching.
     * "19/122-A" → ["19", "122"]
     */
    public function numericParts(string $normalized): array
    {
        preg_match_all('/\d+/', $normalized, $matches);
        return $matches[0] ?? [];
    }
}
