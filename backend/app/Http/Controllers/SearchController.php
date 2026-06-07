<?php

namespace App\Http\Controllers;

use App\Services\SearchService;
use Illuminate\Http\Request;
use App\Models\SearchLog;

class SearchController extends Controller
{
    public function __construct(protected SearchService $searchService) {}

    /**
     * POST /api/search
     */
    public function search(Request $request)
    {
        $request->validate([
            'query'   => 'required|string|min:2|max:500',
            'mode'    => 'nullable|in:exact,partial,fuzzy',
            'email'   => 'nullable|email',
            'relative_query' => 'nullable|string|min:2|max:500',
            'pdf_ids' => 'nullable|array',
            'pdf_ids.*' => 'integer',
        ]);

        $query  = $request->input('query');
        $mode   = $request->input('mode', 'fuzzy');
        $email  = $request->input('email');
        $relativeQuery = $request->input('relative_query');
        $pdfIds = $request->input('pdf_ids', []);

        $results = $this->searchService->search($query, $mode, $email, $pdfIds, $relativeQuery);

        return response()->json($results);
    }

    /**
     * GET /api/search/recent
     */
    public function recent(Request $request)
    {
        $email = $request->query('email');

        $query = SearchLog::query()->orderByDesc('created_at')->limit(20);

        if ($email) {
            $query->where('email', $email);
        }

        return response()->json($query->get());
    }
}
