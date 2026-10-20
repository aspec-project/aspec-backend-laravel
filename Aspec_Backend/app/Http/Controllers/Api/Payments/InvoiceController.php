<?php

namespace App\Http\Controllers\Api\Payments;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class InvoiceController extends Controller
{
    /**
     * Lista as faturas do membro autenticado, mais recentes primeiro, 10 por página por omissão e
     * até 50. A consulta parte do próprio utilizador, por isso nunca inclui faturas de outro membro.
     */
    public function index(IndexInvoiceRequest $request): JsonResponse
    {
        $invoices = $request->user()->issuedInvoices()
            ->latest()
            ->orderByDesc('id')
            ->paginate($request->validated('per_page') ?? 10);

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
