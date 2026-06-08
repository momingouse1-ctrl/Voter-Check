<?php

namespace App\Services;

class TeluguRomanizer
{
    public function romanize(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $consonants = [
            "\u{0C15}" => 'k', "\u{0C16}" => 'kh', "\u{0C17}" => 'g', "\u{0C18}" => 'gh', "\u{0C19}" => 'ng',
            "\u{0C1A}" => 'ch', "\u{0C1B}" => 'chh', "\u{0C1C}" => 'j', "\u{0C1D}" => 'jh', "\u{0C1E}" => 'ny',
            "\u{0C1F}" => 't', "\u{0C20}" => 'th', "\u{0C21}" => 'd', "\u{0C22}" => 'dh', "\u{0C23}" => 'n',
            "\u{0C24}" => 't', "\u{0C25}" => 'th', "\u{0C26}" => 'd', "\u{0C27}" => 'dh', "\u{0C28}" => 'n',
            "\u{0C2A}" => 'p', "\u{0C2B}" => 'ph', "\u{0C2C}" => 'b', "\u{0C2D}" => 'bh', "\u{0C2E}" => 'm',
            "\u{0C2F}" => 'y', "\u{0C30}" => 'r', "\u{0C31}" => 'r', "\u{0C32}" => 'l', "\u{0C33}" => 'l',
            "\u{0C35}" => 'v', "\u{0C36}" => 'sh', "\u{0C37}" => 'sh', "\u{0C38}" => 's', "\u{0C39}" => 'h',
        ];

        $vowels = [
            "\u{0C05}" => 'a', "\u{0C06}" => 'aa', "\u{0C07}" => 'i', "\u{0C08}" => 'ee',
            "\u{0C09}" => 'u', "\u{0C0A}" => 'oo', "\u{0C0B}" => 'ru',
            "\u{0C0E}" => 'e', "\u{0C0F}" => 'e', "\u{0C10}" => 'ai',
            "\u{0C12}" => 'o', "\u{0C13}" => 'o', "\u{0C14}" => 'au',
        ];

        $matras = [
            "\u{0C3E}" => 'aa', "\u{0C3F}" => 'i', "\u{0C40}" => 'ee',
            "\u{0C41}" => 'u', "\u{0C42}" => 'oo', "\u{0C43}" => 'ru',
            "\u{0C46}" => 'e', "\u{0C47}" => 'e', "\u{0C48}" => 'ai',
            "\u{0C4A}" => 'o', "\u{0C4B}" => 'o', "\u{0C4C}" => 'au',
        ];

        $marks = [
            "\u{0C01}" => 'n',
            "\u{0C02}" => 'm',
            "\u{0C03}" => 'h',
        ];

        $virama = "\u{0C4D}";
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = '';

        for ($i = 0, $count = count($chars); $i < $count; $i++) {
            $char = $chars[$i];

            if (isset($vowels[$char])) {
                $out .= $vowels[$char];
                continue;
            }

            if (isset($consonants[$char])) {
                $next = $chars[$i + 1] ?? '';

                if ($next === $virama) {
                    $out .= $consonants[$char];
                    $i++;
                    continue;
                }

                if (isset($matras[$next])) {
                    $out .= $consonants[$char] . $matras[$next];
                    $i++;
                    continue;
                }

                $out .= $consonants[$char] . 'a';
                continue;
            }

            if (isset($marks[$char])) {
                $out .= $marks[$char];
                continue;
            }

            if (isset($matras[$char]) || $char === $virama) {
                continue;
            }

            $out .= $char;
        }

        return $out;
    }

    public function normalize(string $text): string
    {
        $text = $this->romanize($text);
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^a-z0-9]+/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }
}
