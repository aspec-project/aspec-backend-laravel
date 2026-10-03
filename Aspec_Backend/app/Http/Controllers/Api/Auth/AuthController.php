<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterMemberRequest;
use App\Models\User;
use App\Models\MemberProfile;
use App\Models\Role;
use App\Models\AccountStatus;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;


class AuthController extends Controller
{
    /**
     * Regista um novo membro no sistema através de submissão de candidatura.
     * 
     * Atribui automaticamente o papel de 'Member' e o estado 'Pending' à nova conta.
     * Envolve a criação do utilizador e do respetivo perfil numa transação de base de dados
     * para garantir a integridade dos dados.
     *
     * @param RegisterMemberRequest $request Dados validados do formulário de registo.
     * @return \Illuminate\Http\JsonResponse Resposta formatada de sucesso com o utilizador e perfil criados (HTTP 201).
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Se o papel 'Member' ou o estado 'Pending' não forem encontrados.
     */
    public function register(RegisterMemberRequest $request)
    {
        $memberRole = Role::where('name', 'Member')->firstOrFail();
        $pendingStatus = AccountStatus::where('name', 'Pending')->firstOrFail();

        $result = DB::transaction(function () use ($request, $memberRole, $pendingStatus) {

            $user = User::create([
                'email'             => $request->email,
                'password'          => Hash::make($request->password),
                'phone'             => $request->phone,
                'role_id'           => $memberRole->id,
                'account_status_id' => $pendingStatus->id,
            ]);

            $profile = MemberProfile::create([
                'user_id'              => $user->id,
                'sector_id'            => $request->sector_id,
                'location_id'          => $request->location_id,
                'name'                 => $request->name,
                'business_name'        => $request->business_name,
                'congregation'         => $request->congregation,
                'role_in_congregation' => $request->role_in_congregation,
                'description'          => $request->description,
                'website_url'          => $request->website_url,
                'address'              => $request->address,
            ]);

            return compact('user', 'profile');
        });

        return $this->successResponse(
            $result, 
            'Candidatura submetida com sucesso. A conta aguarda aprovação do administrador.', 
            Response::HTTP_CREATED
        );
    }




    /**
     * Autentica um utilizador existente no sistema e emite um token de acesso via Laravel Sanctum.
     *
     * Valida as credenciais enviadas pelo frontend, verifica a autenticação,
     * carrega as relações essenciais (role, accountStatus, memberProfile) e gera o token Bearer.
     *
     * @param Request $request Pedido HTTP contendo 'email' e 'password'.
     * @return \Illuminate\Http\JsonResponse Token de acesso e dados do utilizador em caso de sucesso (HTTP 200),
     *                                      ou mensagem de erro de credenciais inválidas (HTTP 401).
     * @throws \Illuminate\Validation\ValidationException Se a validação básica dos campos falhar.
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        if (!Auth::attempt($credentials)) {
            return $this->errorResponse('Credenciais inválidas. Verifique o seu email e password.', Response::HTTP_UNAUTHORIZED);
        }

        /** @var User $user */ 
        $user = Auth::user();
        $user->load(['role', 'accountStatus', 'memberProfile']);


        if ($user->accountStatus?->name === 'Inactive') {
            return $this->errorResponse(
            'A sua conta está inativa.',        
            Response::HTTP_FORBIDDEN
        );
        }

        if ($user->accountStatus?->name === 'Pending') {
            return $this->errorResponse(
                'A sua conta está pendente de aprovação pelo administrador.',
                Response::HTTP_FORBIDDEN
            );
        }
        
        $token = $user->createToken('aspec_auth_token')->plainTextToken;

        return $this->successResponse([
            'token' => $token,
            'user'  => $user
        ], 'Autenticação efetuada com sucesso.');
    }



    /**
     * Termina a sessão de um utilizador autenticado.
     *
     * Revoga o token de acesso atual, invalidando a autenticação do utilizador.
     *
     * @param Request $request Pedido HTTP contendo o utilizador autenticado.
     * @return \Illuminate\Http\JsonResponse Mensagem de sucesso (HTTP 200).
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return $this->successResponse(
            null, 
            'Sessão terminada com sucesso.'
        );
    }



}
