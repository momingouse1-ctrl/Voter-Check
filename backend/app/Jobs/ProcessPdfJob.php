<?php

namespace App\Jobs;

use App\Models\PdfDocument;
use App\Services\PdfIndexer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Bus\Queueable as BusQueueable;

class ProcessPdfJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 300; // 5 minutes per PDF

    public function __construct(
        public readonly int $pdfDocumentId
    ) {}

    public function handle(PdfIndexer $indexer): void
    {
        $document = PdfDocument::find($this->pdfDocumentId);

        if (!$document) {
            return; // Document was deleted before processing
        }

        if ($document->status === 'indexed') {
            return; // Already indexed, skip
        }

        $indexer->index($document);
    }

    public function failed(\Throwable $exception): void
    {
        $document = PdfDocument::find($this->pdfDocumentId);
        if ($document) {
            $document->update([
                'status'        => 'failed',
                'error_message' => 'Job failed: ' . $exception->getMessage(),
            ]);
        }
    }
}
