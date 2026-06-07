<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index()
    {
        return response()->json(Setting::allAsArray());
    }

    public function update(Request $request)
    {
        $request->validate([
            'settings'   => 'required|array',
            'settings.*' => 'nullable|string|max:500',
        ]);

        foreach ($request->input('settings') as $key => $value) {
            Setting::setValue($key, $value);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Start a background queue worker so queued PDFs begin indexing.
     */
    public function processQueue()
    {
        $php = PHP_BINARY;
        $artisan = base_path('artisan');
        $command = 'start /B "" ' . escapeshellarg($php) . ' ' . escapeshellarg($artisan)
            . ' queue:work --timeout=300 --tries=2 --sleep=1 --stop-when-empty';

        if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
            pclose(popen($command, 'r'));
        } else {
            $command = escapeshellarg($php) . ' ' . escapeshellarg($artisan)
                . ' queue:work --timeout=300 --tries=2 --sleep=1 --stop-when-empty > /dev/null 2>&1 &';
            exec($command);
        }

        return response()->json([
            'success' => true,
            'message' => 'Queue worker started in the background.',
            'queued_jobs' => \DB::table('jobs')->count(),
        ]);
    }

    /**
     * Delete all indexed text (keeps PDFs, removes pdf_pages).
     */
    public function clearIndex()
    {
        \App\Models\PdfPage::truncate();
        \App\Models\PdfDocument::query()->update([
            'status'            => 'uploaded',
            'total_pages'       => 0,
            'extraction_method' => null,
            'error_message'     => null,
        ]);

        return response()->json(['success' => true, 'message' => 'Index cleared.']);
    }

    /**
     * Reprocess all PDFs.
     */
    public function reprocessAll()
    {
        $docs = \App\Models\PdfDocument::all();

        foreach ($docs as $doc) {
            \App\Models\PdfPage::where('pdf_document_id', $doc->id)->delete();
            $doc->update(['status' => 'queued', 'error_message' => null]);
            \App\Jobs\ProcessPdfJob::dispatch($doc->id);
        }

        return response()->json([
            'success' => true,
            'queued'  => $docs->count(),
            'message' => 'All PDFs queued for reprocessing.',
        ]);
    }
}
