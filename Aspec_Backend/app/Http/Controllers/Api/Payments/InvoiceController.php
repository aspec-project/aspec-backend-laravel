<?php

namespace App\Http\Controllers\Api\Payments;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InvoiceController extends Controller
{
    /**
     * Lista as faturas do membro autenticado, mais recentes primeiro, 10 por página (fixo).
     * A consulta parte do próprio utilizador, por isso nunca inclui faturas de outro membro.
     */
    public function index(Request $request): JsonResponse
    {
        $invoices = $request->user()->issuedInvoices()
            ->latest()
            ->orderByDesc('id')
            ->paginate(10);

        return $this->successResponse([
            'items' => InvoiceResource::collection($invoices->getCollection())->resolve($request),
            'pagination' => [
                'current_page' => $invoices->currentPage(),
                'per_page' => $invoices->perPage(),
                'total' => $invoices->total(),
                'last_page' => $invoices->lastPage(),
            ],
        ], 'Faturas obtidas com sucesso.', Response::HTTP_OK);
    }
}
