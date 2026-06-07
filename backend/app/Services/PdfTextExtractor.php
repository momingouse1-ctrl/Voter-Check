<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;

class PdfTextExtractor
{
    protected TextNormalizer $normalizer;

    public function __construct(TextNormalizer $normalizer)
    {
        $this->normalizer = $normalizer;
    }

    /**
     * Extract text page by page from a PDF file.
     *
     * Returns array of:
     * [
     *   ['page' => 1, 'text' => '...', 'method' => 'text|python|ocr'],
     *   ...
     * ]
     */
    public function extract(string $filePath): array
    {
        // Stage 1: OCR (Primary for Voter Lists if enabled)
        $ocrEnabled = \App\Models\Setting::getValue('enable_ocr', 'true') === 'true';
        if ($ocrEnabled) {
            try {
                Log::info("Attempting OCR extraction for {$filePath}");
                $result = $this->extractWithPython($filePath, 'ocr');
                if (!empty($result)) {
                    return $result;
                }
            } catch (\Throwable $e) {
                Log::warning("OCR extraction failed for {$filePath}: " . $e->getMessage());
            }
        }

        // Stage 2: smalot/pdfparser (Fallback)
        try {
            $result = $this->extractWithPdfParser($filePath);
            if ($this->hasUsableText($result)) {
                return $result;
            }
        } catch (\Throwable $e) {
            Log::warning("PdfParser failed for {$filePath}: " . $e->getMessage());
        }

        // Stage 3: Try Python helper text extraction (pymupdf / pdfplumber)
        try {
            $result = $this->extractWithPython($filePath, 'text');
            if ($this->hasUsableText($result)) {
                return $result;
            }
        } catch (\Throwable $e) {
            Log::warning("Python text extraction failed for {$filePath}: " . $e->getMessage());
        }

        // Return empty pages if all methods fail
        return [];
    }

    /**
     * Stage 1: Use smalot/pdfparser.
     */
    protected function extractWithPdfParser(string $filePath): array
    {
        $parser = new Parser();
        $pdf = $parser->parseFile($filePath);
        $pages = $pdf->getPages();

        $result = [];
        foreach ($pages as $index => $page) {
            $text = '';
            try {
                $text = $page->getText();
            } catch (\Throwable $e) {
                // Some pages may fail individually — continue
                Log::debug("Page " . ($index + 1) . " extraction failed: " . $e->getMessage());
            }

            $result[] = [
                'page'   => $index + 1,
                'text'   => $text ?? '',
                'method' => 'pdfparser',
            ];
        }

        return $result;
    }

    /**
     * Stage 2 & 3: Call Python extraction script.
     * Mode: 'text' for normal extraction, 'ocr' for Tesseract OCR.
     */
    protected function extractWithPython(string $filePath, string $mode = 'text'): array
    {
        $scriptPath = base_path('scripts/extract_pdf_text.py');

        if (!file_exists($scriptPath)) {
            throw new \RuntimeException("Python extraction script not found at: {$scriptPath}");
        }

        $escapedScript = escapeshellarg($scriptPath);
        $escapedPath = escapeshellarg($filePath);
        $escapedMode = escapeshellarg($mode);
        $pythonExe = 'C:\\Users\\User\\AppData\\Local\\Programs\\Python\\Python312\\python.exe';
        $command = "set PYTHONIOENCODING=utf8 && {$pythonExe} {$escapedScript} {$escapedPath} {$escapedMode} 2>&1";

        $output = shell_exec($command);

        if (empty($output)) {
            throw new \RuntimeException("Python script returned empty output.");
        }

        // Find JSON start (script may print debug to stderr which gets mixed in)
        $jsonStart = strpos($output, '[');
        if ($jsonStart === false) {
            throw new \RuntimeException("No JSON found in Python output: " . substr($output, 0, 200));
        }

        $json = substr($output, $jsonStart);
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON from Python script: " . json_last_error_msg());
        }

        // Tag each page with the mode used
        return array_map(function ($item) use ($mode) {
            $item['method'] = $mode === 'ocr' ? 'ocr' : 'python';
            return $item;
        }, $data);
    }

    /**
     * Check if extracted text has meaningful content.
     * Filters out PDFs that return mostly garbage/empty.
     */
    protected function hasUsableText(array $pages): bool
    {
        if (empty($pages)) {
            return false;
        }

        $totalChars = 0;
        $pageCount  = 0;

        foreach ($pages as $page) {
            $text = $page['text'] ?? '';
            $totalChars += mb_strlen(trim($text));
            if (!empty(trim($text))) {
                $pageCount++;
            }
        }

        // Consider usable if: at least 30% of pages have text, and avg > 10 chars/page
        $avgChars      = $pageCount > 0 ? $totalChars / count($pages) : 0;
        $pageRatio     = count($pages) > 0 ? $pageCount / count($pages) : 0;

        return $avgChars >= 10 && $pageRatio >= 0.3;
    }

    /**
     * Count pages in a PDF without full extraction.
     */
    public function countPages(string $filePath): int
    {
        try {
            $parser = new Parser();
            $pdf    = $parser->parseFile($filePath);
            return count($pdf->getPages());
        } catch (\Throwable $e) {
            // Fallback: count via Python
            try {
                $scriptPath   = base_path('scripts/extract_pdf_text.py');
                $escapedScript = escapeshellarg($scriptPath);
                $escapedPath  = escapeshellarg($filePath);
                $pythonExe = 'C:\\Users\\User\\AppData\\Local\\Programs\\Python\\Python312\\python.exe';
                $output       = shell_exec("set PYTHONIOENCODING=utf8 && {$pythonExe} {$escapedScript} {$escapedPath} count 2>&1");
                $data         = json_decode($output, true);
                return (int) ($data['pages'] ?? 0);
            } catch (\Throwable $e2) {
                return 0;
            }
        }
    }
}
