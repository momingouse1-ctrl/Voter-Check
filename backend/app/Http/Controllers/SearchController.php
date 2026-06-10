<?php

namespace App\Http\Controllers;

use App\Services\HouseNumberNormalizer;
use App\Services\SearchService;
use App\Models\SearchLog;
use App\Models\VoterRecord;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(
        protected SearchService $searchService,
        protected HouseNumberNormalizer $houseNormalizer,
    ) {}

    /**
     * POST /api/search
     *
     * Unified search endpoint. Supports:
     *  - name search (English + Telugu + fuzzy)
     *  - EPIC exact search
     *  - House number search
     *  - Geography filters (district, city, assembly, polling station)
     */
    public function search(Request $request)
    {
        $request->validate([
            'query'              => 'nullable|string|min:1|max:500',
            'name_te'            => 'nullable|string|min:1|max:500',
            'relative_query'     => 'nullable|string|min:1|max:500',
            'relative_name_te'   => 'nullable|string|min:1|max:500',
            'mode'               => 'nullable|in:exact,partial,fuzzy',
            'email'              => 'nullable|email',
            'epic_number'        => 'nullable|string|max:50',
            'house_number'       => 'nullable|string|max:100',
            'scope'              => 'nullable|in:all,district,city,assembly,polling_station',
            'district_id'        => 'nullable|integer|exists:districts,id',
            'city_id'            => 'nullable|integer|exists:cities,id',
            'assembly_id'        => 'nullable|integer|exists:assemblies,id',
            'polling_station_id' => 'nullable|integer|exists:polling_stations,id',
            'pdf_ids'            => 'nullable|array',
            'pdf_ids.*'          => 'integer',
            'limit'              => 'nullable|integer|min:1|max:500',
        ]);

        $email      = $request->input('email');
        $epicNumber = trim((string) $request->input('epic_number'));
        $houseNum   = trim((string) $request->input('house_number'));

        $geoFilters = [
            'district_id'        => $request->input('district_id'),
            'city_id'            => $request->input('city_id'),
            'assembly_id'        => $request->input('assembly_id'),
            'polling_station_id' => $request->input('polling_station_id'),
        ];

        // ── 1. EPIC exact search (highest priority) ─────────────────────────
        if ($epicNumber !== '') {
            $results = $this->searchService->epicSearch($epicNumber, $geoFilters);

            SearchLog::create([
                'email'         => $email,
                'query'         => 'EPIC: ' . $epicNumber,
                'mode'          => 'exact',
                'total_results' => count($results),
            ]);

            return response()->json([
                'query'          => $epicNumber,
                'search_type'    => 'epic',
                'total'          => count($results),
                'results'        => $results,
            ]);
        }

        // ── 2. House number search ───────────────────────────────────────────
        if ($houseNum !== '') {
            $query       = trim((string) $request->input('query', ''));
            $nameTe      = trim((string) $request->input('name_te', ''));
            $mode        = $request->input('mode', 'partial');
            $results     = $this->searchService->houseNumberSearch($houseNum, $geoFilters, $query ?: $nameTe, $mode);

            SearchLog::create([
                'email'         => $email,
                'query'         => 'House: ' . $houseNum . ($query ? ' + ' . $query : ''),
                'mode'          => $mode,
                'total_results' => count($results),
            ]);

            return response()->json([
                'query'          => $houseNum,
                'search_type'    => 'house_number',
                'total'          => count($results),
                'results'        => $results,
            ]);
        }

        // ── 3. Name/text search ──────────────────────────────────────────────
        $query         = trim((string) $request->input('query', ''));
        $nameTe        = trim((string) $request->input('name_te', ''));
        $relativeQuery = trim((string) $request->input('relative_query', ''));
        $relativeNameTe= trim((string) $request->input('relative_name_te', ''));
        $mode          = $request->input('mode', 'fuzzy');
        $pdfIds        = $request->input('pdf_ids', []);

        // Use Telugu name if provided (from transliteration confirmation), else English query
        $primaryQuery   = $nameTe ?: $query;
        $relativeSearch = $relativeNameTe ?: $relativeQuery;

        if (!$primaryQuery) {
            return response()->json(['error' => 'Please enter a name to search.'], 422);
        }

        $results = $this->searchService->search(
            $primaryQuery,
            $mode,
            $email,
            $pdfIds,
            $relativeSearch,
            $geoFilters,
        );

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
