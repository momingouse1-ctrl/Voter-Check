<?php

namespace App\Services;

class TextNormalizer
{
    /**
     * Normalize text for storage and searching.
     * Handles both English and Telugu text.
     */
    public function normalize(string $text): string
    {
        // Collapse multiple whitespace characters into single space
        $text = preg_replace('/\s+/u', ' ', $text);

        // Trim
        $text = trim($text);

        // Lowercase for English characters
        $text = mb_strtolower($text, 'UTF-8');

        // Remove common punctuation that doesn't affect name meaning
        $text = preg_replace('/[,;:!?\"\'\(\)\[\]\{\}\/\\\\@#\$%\^&\*~`=+<>|]/u', ' ', $text);

        // Replace hyphens and underscores with spaces (khaja-peer → khaja peer)
        $text = str_replace(['-', '_', '.'], ' ', $text);

        // Collapse again after replacements
        $text = preg_replace('/\s+/u', ' ', $text);

        // Final trim
        return trim($text);
    }

    /**
     * Normalize a search query specifically.
     */
    public function normalizeQuery(string $query): string
    {
        return $this->normalize($query);
    }

    /**
     * Split normalized text into searchable tokens.
     */
    public function tokenize(string $normalized): array
    {
        $tokens = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);
        return array_filter($tokens, fn($t) => mb_strlen($t) > 1);
    }

    /**
     * Normalize Telugu text spacing specifically.
     * Telugu script often has inconsistent zero-width joiners and spaces.
     */
    public function normalizeTeluguSpacing(string $text): string
    {
        // Remove zero-width non-joiners and joiners
        $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text);

        // Collapse spaces
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    /**
     * Full normalization pipeline: Telugu cleanup + general normalization.
     */
    public function fullNormalize(string $text): string
    {
        $text = $this->normalizeTeluguSpacing($text);
        return $this->normalize($text);
    }
}
