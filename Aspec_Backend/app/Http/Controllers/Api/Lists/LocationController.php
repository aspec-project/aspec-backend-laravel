<?php

namespace App\Http\Controllers\Api\Lists;

use App\Http\Controllers\Controller;
use App\Models\Location;
use Collator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lista pública de distritos e regiões autónomas (núcleos regionais).
 */
class LocationController extends Controller
{
    /**
     * Lista os distritos e regiões autónomas por ordem alfabética portuguesa.
     *
     * @return JsonResponse
     */
    public function index()
    {
        $collator = new Collator('pt_PT');

        $locations = Location::query()
            ->get(['id', 'name'])
            ->sort(fn (Location $a, Location $b) => $collator->compare($a->name, $b->name))
            ->values();

        return $this->successResponse($locations, 'Núcleos obtidos com sucesso.');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
