<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessPdfJob;
use App\Models\PdfDocument;
use App\Models\PdfPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PdfController extends Controller
{
    /**
     * Upload PDFs.
     * POST /api/pdfs/upload
     */
    public function upload(Request $request)
    {
        $request->validate([
            'files'   => 'required|array|min:1',
            'files.*' => 'required|file|mimes:pdf|max:102400', // 100MB per file
            'email'   => 'nullable|email',
        ]);

        $email     = $request->input('email');
        $uploaded  = 0;
        $errors    = [];

        foreach ($request->file('files') as $file) {
            try {
                // Sanitize filename
                $originalName = $file->getClientOriginalName();
                $safeName     = preg_replace('/[^a-zA-Z0-9._\-\(\) ]/', '_', $originalName);
                $storedName   = Str::uuid() . '_' . $safeName;

                // Store file
                $file->storeAs('pdfs', $storedName);

                // Create DB record
                $doc = PdfDocument::create([
                    'original_name'     => $originalName,
                    'stored_path'       => $storedName,
                    'file_size'         => $file->getSize(),
                    'status'            => 'queued',
                    'uploaded_by_email' => $email,
                ]);

                // Dispatch processing job
                ProcessPdfJob::dispatch($doc->id);

                $uploaded++;
            } catch (\Throwable $e) {
                $errors[] = [
                    'file'    => $file->getClientOriginalName(),
                    'error'   => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'success'  => true,
            'uploaded' => $uploaded,
            'errors'   => $errors,
        ]);
    }

    /**
     * List all PDFs.
     * GET /api/pdfs
     */
    public function index(Request $request)
    {
        $query = PdfDocument::query();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->query('search')) {
            $query->where('original_name', 'like', '%' . $search . '%');
        }

        $pdfs = $query->orderByDesc('created_at')
                      ->paginate($request->query('per_page', 25));

        return response()->json($pdfs);
    }

    /**
     * Get single PDF info.
     * GET /api/pdfs/{id}
     */
    public function show(int $id)
    {
        $doc = PdfDocument::findOrFail($id);
        return response()->json($doc);
    }

    /**
     * Delete a PDF and its indexed pages.
     * DELETE /api/pdfs/{id}
     */
    public function destroy(int $id)
    {
        $doc = PdfDocument::findOrFail($id);

        // Delete file from storage
        Storage::delete('pdfs/' . $doc->stored_path);

        // Delete all indexed pages (cascaded in DB but explicit for safety)
        PdfPage::where('pdf_document_id', $id)->delete();

        $doc->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Reprocess a PDF.
     * POST /api/pdfs/{id}/reprocess
     */
    public function reprocess(int $id)
    {
        $doc = PdfDocument::findOrFail($id);

        $doc->update([
            'status'        => 'queued',
            'error_message' => null,
        ]);

        // Remove old pages
        PdfPage::where('pdf_document_id', $id)->delete();

        ProcessPdfJob::dispatch($id);

        return response()->json(['success' => true, 'message' => 'Reprocessing queued.']);
    }

    /**
     * Serve PDF file securely.
     * GET /api/pdfs/{id}/file
     */
    public function serve(int $id)
    {
        $doc  = PdfDocument::findOrFail($id);
        $path = storage_path('app/private/pdfs/' . $doc->stored_path);

        if (!file_exists($path)) {
            return response()->json(['error' => 'File not found.'], 404);
        }

        return response()->file($path, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $doc->original_name . '"',
        ]);
    }

    /**
     * Download PDF file.
     * GET /api/pdfs/{id}/download
     */
    public function download(int $id)
    {
        $doc  = PdfDocument::findOrFail($id);
        $path = storage_path('app/private/pdfs/' . $doc->stored_path);

        if (!file_exists($path)) {
            return response()->json(['error' => 'File not found.'], 404);
        }

        return response()->download($path, $doc->original_name);
    }
}
