<?php

namespace App\Http\Controllers\Api\Lists;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Sector;
use App\Traits\ApiResponse;

class SectorController extends Controller
{
    use ApiResponse;
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $sectors = Sector::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        return $this->successResponse($sectors, 'Setores obtidos com sucesso.');
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
