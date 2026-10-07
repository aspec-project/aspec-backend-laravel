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
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;


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
     * Autentica um utilizador com base nas credenciais fornecidas.
     *
     * Verifica se o email e password correspondem a um utilizador existente e se a conta está ativa.
     * Se a autenticação for bem-sucedida, inicia uma sessão para o utilizador.
     *
     * @param Request $request Pedido HTTP contendo as credenciais do utilizador.
     * @return \Illuminate\Http\JsonResponse Resposta formatada de sucesso com os dados do utilizador autenticado (HTTP 200) ou mensagem de erro (HTTP 401/403).
     */

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::with(['role', 'accountStatus', 'memberProfile'])
            ->where('email', $credentials['email'])
            ->first();

        if (
            ! $user ||
            ! Hash::check($credentials['password'], $user->password)
        ) {
            return $this->errorResponse(
                'Credenciais inválidas. Verifique o seu email e password.',
                Response::HTTP_UNAUTHORIZED
            );
        }

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

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return $this->successResponse(
            new UserResource($user),
            'Autenticação efetuada com sucesso.',
            Response::HTTP_OK
        );
    }




    /**
     * Termina a sessão de um utilizador autenticado.
     *
     * Revoga o token de acesso atual, invalidando a autenticação do utilizador.
     *
     * @param Request $request Pedido HTTP contendo o utilizador autenticado.
     * @return \Illuminate\Http\JsonResponse Mensagem de sucesso (HTTP 200).
     */
    public function logout(Request $request): JsonResponse
    {
        if ($request->bearerToken()) {
            $token = $request->user()->currentAccessToken();

            if ($token) {
                $token->delete();
            }
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->successResponse(
            null,
            'Sessão terminada com sucesso.',
            Response::HTTP_OK
        );
    }




    /**
     * Cria um novo token de acesso para o utilizador autenticado.
     * Usado mais propriamente para aplicações SPA que necessitam de renovar o token sem reautenticar.
     *
     * @param Request $request Pedido HTTP contendo as credenciais do utilizador.
     * @return \Illuminate\Http\JsonResponse Mensagem de sucesso (HTTP 200).
     */
    public function token(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::with(['role', 'accountStatus', 'memberProfile'])
            ->where('email', $credentials['email'])
            ->first();

        if (
            ! $user ||
            ! Hash::check($credentials['password'], $user->password)
        ) {
            return $this->errorResponse(
                'Credenciais inválidas. Verifique o seu email e password.',
                Response::HTTP_UNAUTHORIZED
            );
        }

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

        $token = $user->createToken('postman')->plainTextToken;

        return $this->successResponse([
            'token' => $token,
            'user' => new UserResource($user),
        ], 'Token criado com sucesso.');
    }

    /**
     * Retorna os detalhes do utilizador autenticado.
     *
     * @param Request $request Pedido HTTP contendo o utilizador autenticado.
     * @return \Illuminate\Http\JsonResponse Resposta formatada de sucesso com os dados do utilizador (HTTP 200).
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->load(['role', 'accountStatus', 'memberProfile']);

        return $this->successResponse(
            new UserResource($user),
            'Utilizador autenticado obtido com sucesso.',
            Response::HTTP_OK
        );
    }



    



}
