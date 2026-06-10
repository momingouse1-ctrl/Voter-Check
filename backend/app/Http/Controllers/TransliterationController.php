<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TransliterationController extends Controller
{
    /**
     * POST /api/search/transliterate
     *
     * Converts English text to Telugu transliteration suggestions.
     * Uses the free Google Input Tools API.
     *
     * Request:
     *   { "text": "Abdul Rafi Shaik", "count": 3 }
     *
     * Response:
     *   { "suggestions": ["అబ్దుల్ రఫీ షేక్", ...], "input": "Abdul Rafi Shaik" }
     */
    public function transliterate(Request $request)
    {
        $request->validate([
            'text'  => 'required|string|min:1|max:500',
            'count' => 'nullable|integer|min:1|max:8',
        ]);

        $text  = trim($request->input('text'));
        $count = (int) $request->input('count', 3);

        // If text is already Telugu (contains Telugu Unicode block), return as-is
        if (preg_match('/[\x{0C00}-\x{0C7F}]/u', $text)) {
            return response()->json([
                'input'       => $text,
                'is_telugu'   => true,
                'suggestions' => [$text],
            ]);
        }

        $suggestions = [];

        // Step 1: Try Google Input Tools API (primary)
        try {
            $url = 'https://inputtools.google.com/request?text=' . urlencode($text)
                . '&itc=te-t-i0-und&num=' . $count . '&cp=0&cs=1&ie=utf-8&oe=utf-8&app=diacritics';

            $response = Http::timeout(5)->withHeaders([
                'User-Agent' => 'Mozilla/5.0',
                'Accept'     => 'application/json',
            ])->get($url);

            if ($response->successful()) {
                $data = $response->json();
                // Response: ["SUCCESS", [["text", ["sug1","sug2",...], [], {"query":"text"}]]]
                if (isset($data[1][0][1]) && is_array($data[1][0][1])) {
                    $suggestions = array_slice($data[1][0][1], 0, $count);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Google Input Tools API failed: ' . $e->getMessage());
        }

        // Step 2: Try word-by-word if full-phrase didn't work
        if (empty($suggestions) && str_contains($text, ' ')) {
            $words      = explode(' ', $text);
            $wordSuggestions = [];
            $success = true;

            foreach ($words as $word) {
                if (empty(trim($word))) continue;
                try {
                    $url = 'https://inputtools.google.com/request?text=' . urlencode($word)
                        . '&itc=te-t-i0-und&num=3&cp=0&cs=1&ie=utf-8&oe=utf-8';
                    $resp = Http::timeout(4)->get($url);
                    if ($resp->successful()) {
                        $d = $resp->json();
                        if (isset($d[1][0][1][0])) {
                            $wordSuggestions[] = $d[1][0][1];
                        } else {
                            $wordSuggestions[] = [$word];
                        }
                    } else {
                        $success = false;
                        break;
                    }
                } catch (\Throwable $e) {
                    $success = false;
                    break;
                }
            }

            if ($success && !empty($wordSuggestions)) {
                // Generate combinations: pick primary suggestion for each word
                $primaryCombo = implode(' ', array_map(fn ($ws) => $ws[0] ?? '', $wordSuggestions));
                $suggestions[] = $primaryCombo;

                // Secondary combo using second suggestions where available
                $secondCombo = implode(' ', array_map(fn ($ws) => $ws[1] ?? $ws[0] ?? '', $wordSuggestions));
                if ($secondCombo !== $primaryCombo) {
                    $suggestions[] = $secondCombo;
                }
            }
        }

        // Step 3: Fallback — try local dictionary variants
        if (empty($suggestions)) {
            $suggestions = $this->localFallback($text);
        }

        // Deduplicate
        $suggestions = array_values(array_unique(array_filter($suggestions)));

        return response()->json([
            'input'       => $text,
            'is_telugu'   => false,
            'suggestions' => $suggestions,
        ]);
    }

    /**
     * Very simple local fallback dictionary for common Telugu names/words.
     * These are transliterations, NOT translations.
     */
    protected function localFallback(string $text): array
    {
        $map = [
            'shaik'    => ['షేక్', 'షైక్', 'శేక్'],
            'sheik'    => ['షేక్', 'షైక్'],
            'ghouse'   => ['ఘౌస్', 'గౌస్'],
            'gouse'    => ['గౌస్', 'ఘౌస్'],
            'begum'    => ['బేగం', 'బెగం'],
            'begam'    => ['బేగం'],
            'mohammed' => ['మహమ్మద్', 'మొహమ్మద్'],
            'mohammad' => ['మహమ్మద్'],
            'khaja'    => ['ఖాజా'],
            'khajapeer'=> ['ఖాజాపీర్'],
            'peer'     => ['పీర్'],
            'mastan'   => ['మస్తాన్'],
            'saleema'  => ['సలీమా'],
            'naseema'  => ['నసీమా'],
            'khanam'   => ['ఖానం'],
            'gori'     => ['గోరి', 'గౌరి'],
            'nawaz'    => ['నవాజ్'],
            'ali'      => ['అలీ'],
            'khan'     => ['ఖాన్'],
            'abdul'    => ['అబ్దుల్'],
            'rafi'     => ['రఫీ'],
        ];

        $lower = strtolower(trim($text));
        if (isset($map[$lower])) {
            return $map[$lower];
        }

        // Try word by word
        $words = explode(' ', $lower);
        $parts = [];
        foreach ($words as $word) {
            $parts[] = $map[$word][0] ?? $word;
        }

        return [implode(' ', $parts)];
    }
}
