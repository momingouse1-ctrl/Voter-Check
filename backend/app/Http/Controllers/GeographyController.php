<?php

namespace App\Http\Controllers;

use App\Models\Assembly;
use App\Models\City;
use App\Models\District;
use App\Models\PollingStation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class GeographyController extends Controller
{
    /**
     * GET /api/filters/districts
     */
    public function districts()
    {
        $districts = Cache::remember('geo_districts', 3600, fn () =>
            District::where('status', true)
                ->orderBy('name_en')
                ->get(['id', 'name_en', 'name_te', 'code'])
        );

        return response()->json($districts);
    }

    /**
     * GET /api/filters/cities?district_id=
     */
    public function cities(Request $request)
    {
        $districtId = $request->query('district_id');

        $query = City::where('status', true)->orderBy('name_en');

        if ($districtId) {
            $query->where('district_id', $districtId);
        }

        return response()->json($query->get(['id', 'district_id', 'assembly_id', 'name_en', 'name_te', 'type']));
    }

    /**
     * GET /api/filters/assemblies?district_id=
     */
    public function assemblies(Request $request)
    {
        $districtId = $request->query('district_id');

        $query = Assembly::where('status', true)->orderBy('name_en');

        if ($districtId) {
            $query->where('district_id', $districtId);
        }

        return response()->json($query->get(['id', 'district_id', 'name_en', 'name_te', 'assembly_code']));
    }

    /**
     * GET /api/filters/polling-stations?district_id=&city_id=&assembly_id=
     */
    public function pollingStations(Request $request)
    {
        $query = PollingStation::where('status', true)->orderBy('station_number');

        if ($v = $request->query('district_id'))  $query->where('district_id', $v);
        if ($v = $request->query('city_id'))       $query->where('city_id', $v);
        if ($v = $request->query('assembly_id'))   $query->where('assembly_id', $v);

        return response()->json($query->get([
            'id', 'district_id', 'city_id', 'assembly_id',
            'station_number', 'station_name_en', 'station_name_te',
        ]));
    }

    // ─── Admin CRUD ────────────────────────────────────────────────────────────

    public function storeDistrict(Request $request)
    {
        $data = $request->validate([
            'name_en' => 'required|string|max:200',
            'name_te' => 'nullable|string|max:200',
            'code'    => 'nullable|string|max:50|unique:districts,code',
        ]);

        Cache::forget('geo_districts');
        $district = District::create($data);

        return response()->json($district, 201);
    }

    public function updateDistrict(Request $request, int $id)
    {
        $district = District::findOrFail($id);
        $data = $request->validate([
            'name_en' => 'sometimes|string|max:200',
            'name_te' => 'nullable|string|max:200',
            'code'    => 'nullable|string|max:50|unique:districts,code,' . $id,
            'status'  => 'sometimes|boolean',
        ]);
        $district->update($data);
        Cache::forget('geo_districts');

        return response()->json($district);
    }

    public function storeCity(Request $request)
    {
        $data = $request->validate([
            'district_id'  => 'required|exists:districts,id',
            'assembly_id'  => 'nullable|exists:assemblies,id',
            'name_en'      => 'required|string|max:200',
            'name_te'      => 'nullable|string|max:200',
            'type'         => 'nullable|in:city,town,mandal,village,urban,rural',
        ]);

        $city = City::create($data);
        return response()->json($city, 201);
    }

    public function updateCity(Request $request, int $id)
    {
        $city = City::findOrFail($id);
        $data = $request->validate([
            'name_en'     => 'sometimes|string|max:200',
            'name_te'     => 'nullable|string|max:200',
            'type'        => 'nullable|in:city,town,mandal,village,urban,rural',
            'assembly_id' => 'nullable|exists:assemblies,id',
            'status'      => 'sometimes|boolean',
        ]);
        $city->update($data);

        return response()->json($city);
    }

    public function storeAssembly(Request $request)
    {
        $data = $request->validate([
            'district_id'   => 'required|exists:districts,id',
            'name_en'       => 'required|string|max:200',
            'name_te'       => 'nullable|string|max:200',
            'assembly_code' => 'nullable|string|max:50',
        ]);

        $assembly = Assembly::create($data);
        return response()->json($assembly, 201);
    }

    public function storePollingStation(Request $request)
    {
        $data = $request->validate([
            'district_id'     => 'required|exists:districts,id',
            'city_id'         => 'nullable|exists:cities,id',
            'assembly_id'     => 'nullable|exists:assemblies,id',
            'station_number'  => 'nullable|string|max:50',
            'station_name_en' => 'nullable|string|max:300',
            'station_name_te' => 'nullable|string|max:300',
            'location'        => 'nullable|string|max:500',
        ]);

        $station = PollingStation::create($data);
        return response()->json($station, 201);
    }
}
