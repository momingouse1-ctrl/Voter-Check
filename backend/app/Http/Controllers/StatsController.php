<?php

namespace App\Http\Controllers;

use App\Models\PdfDocument;
use App\Models\PdfPage;
use App\Models\SearchLog;

class StatsController extends Controller
{
    public function index()
    {
        return response()->json([
            'total_pdfs'     => PdfDocument::count(),
            'total_pages'    => PdfPage::count(),
            'indexed_pdfs'   => PdfDocument::where('status', 'indexed')->count(),
            'queued_pdfs'    => PdfDocument::where('status', 'queued')->count(),
            'processing_pdfs'=> PdfDocument::where('status', 'processing')->count(),
            'failed_pdfs'    => PdfDocument::where('status', 'failed')->count(),
            'total_searches' => SearchLog::count(),
            'today_searches' => SearchLog::whereDate('created_at', today())->count(),
        ]);
    }
}
