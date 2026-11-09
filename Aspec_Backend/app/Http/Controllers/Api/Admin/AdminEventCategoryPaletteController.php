<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class AdminEventCategoryPaletteController extends Controller
{
    /**
     * Devolve as cores que uma categoria de eventos pode usar, pela ordem da paleta.
     * O backoffice lê esta lista para não haver uma cópia da paleta no frontend.
     */
    public function index(): JsonResponse
    {
        $palette = collect(config('event_categories.palette'))
            ->map(fn (string $name, string $color): array => ['color' => $color, 'name' => $name])
            ->values();

        return $this->successResponse($palette, 'Paleta de cores obtida com sucesso.');
    }
}
