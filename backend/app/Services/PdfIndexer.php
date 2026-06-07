<?php

namespace App\Services;

use App\Models\PdfDocument;
use App\Models\PdfPage;
use Illuminate\Support\Facades\Log;

class PdfIndexer
{
    protected PdfTextExtractor $extractor;
    protected TextNormalizer   $normalizer;

    public function __construct(PdfTextExtractor $extractor, TextNormalizer $normalizer)
    {
        $this->extractor  = $extractor;
        $this->normalizer = $normalizer;
    }

    /**
     * Full indexing pipeline for a single PDF document.
     */
    public function index(PdfDocument $document): void
    {
        $filePath = storage_path('app/private/pdfs/' . $document->stored_path);

        if (!file_exists($filePath)) {
            $document->update([
                'status'        => 'failed',
                'error_message' => "File not found on disk: {$filePath}",
            ]);
            return;
        }

        $document->update(['status' => 'processing']);

        try {
            // Extract text page by page
            $pages = $this->extractor->extract($filePath);

            if (empty($pages)) {
                $document->update([
                    'status'        => 'failed',
                    'error_message' => 'No text could be extracted from this PDF.',
                ]);
                return;
            }

            // Determine extraction method used
            $method = $pages[0]['method'] ?? 'unknown';

            // Delete previously indexed pages
            PdfPage::where('pdf_document_id', $document->id)->delete();

            // Store page by page
            foreach ($pages as $pageData) {
                $rawText        = $pageData['text'] ?? '';
                $normalizedText = $this->normalizer->fullNormalize($rawText);

                PdfPage::create([
                    'pdf_document_id'   => $document->id,
                    'page_number'       => $pageData['page'],
                    'raw_text'          => $rawText,
                    'normalized_text'   => $normalizedText,
                    'extraction_method' => $pageData['method'] ?? $method,
                ]);
            }

            $document->update([
                'status'            => 'indexed',
                'total_pages'       => count($pages),
                'extraction_method' => $method,
                'error_message'     => null,
            ]);

            Log::info("Indexed PDF #{$document->id}: {$document->original_name} ({$document->total_pages} pages, method: {$method})");

        } catch (\Throwable $e) {
            Log::error("Failed to index PDF #{$document->id}: " . $e->getMessage());

            $document->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }
    }
}
