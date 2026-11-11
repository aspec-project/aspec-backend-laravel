<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateMemberProfileRequest;
use App\Http\Resources\MemberProfileResource;
use App\Models\User;
use App\Notifications\AccountEmailChangedNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class MemberProfileController extends Controller
{
    private const EMAIL_CHANGE_NOTICE_DAILY_LIMIT = 5;

    private const EMAIL_CHANGE_NOTICE_DECAY_SECONDS = 86400;

    /**
     * Devolve o perfil do membro autenticado, com horário, redes sociais, portfólio e média das avaliações Visible.
     * Atua sempre sobre o próprio utilizador (sem id na rota); 404 se o user não tiver perfil (ex. admin).
     */
    public function show(Request $request): JsonResponse
    {
        $profile = $request->user()->memberProfile()->withRatingSummary()->first();

        if (! $profile) {
            return $this->errorResponse('Perfil de membro não encontrado.', Response::HTTP_NOT_FOUND);
        }

        $profile->load(['user', 'sector', 'location', 'weekDays', 'socialPlatforms', 'portfolios']);

        return $this->successResponse(
            new MemberProfileResource($profile),
            'Perfil obtido com sucesso.',
            Response::HTTP_OK
        );
    }

    /**
     * Atualiza parcialmente o perfil do membro autenticado e os dados de conta (email, phone).
     * Campos ausentes ficam inalterados; business_hours e social_links, se enviados, substituem a lista atual (sync).
     * Campos sensíveis (role_id, account_status_id, password, ...) nunca são aceites.
     * Mudar o email termina as outras sessões SPA e os outros tokens, mantém o acesso atual e apaga os tokens
     * de reposição do email antigo (senão voltariam a valer se esse email voltasse a existir).
     * Depois do commit avisa o endereço anterior, exceto se só mudarem maiúsculas (é a mesma caixa de correio).
     */
    public function update(UpdateMemberProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->memberProfile;

        if (! $profile) {
            return $this->errorResponse('Perfil de membro não encontrado.', Response::HTTP_NOT_FOUND);
        }

        $previousEmail = DB::transaction(function () use ($request, $user, $profile) {
            $profile->update($request->safe()->only([
                'name',
                'business_name',
                'congregation',
                'role_in_congregation',
                'sector_id',
                'location_id',
                'description',
                'website_url',
                'commercial_email',
                'commercial_phone',
                'address',
                'postal_code',
                'city',
            ]));

            $oldEmail = $user->email;

            $user->update($request->safe()->only(['email', 'phone']));
            $emailChanged = $user->wasChanged('email');

            if ($emailChanged) {
                $user->deletePasswordResetTokens($oldEmail);

                $currentToken = $user->currentAccessToken();

                $user->tokens()
                    ->when($currentToken instanceof PersonalAccessToken, fn ($query) => $query->whereKeyNot($currentToken->getKey()))
                    ->delete();

                DB::table(config('session.table'))
                    ->where('user_id', $user->id)
                    ->when($request->hasSession(), fn ($query) => $query->where('id', '!=', $request->session()->getId()))
                    ->delete();
            }

            if ($request->has('business_hours')) {
                $profile->weekDays()->sync(
                    collect($request->validated('business_hours'))->mapWithKeys(fn ($slot) => [
                        $slot['week_day_id'] => [
                            'open_time' => $slot['open_time'],
                            'close_time' => $slot['close_time'],
                        ],
                    ])->all()
                );
            }

            if ($request->has('social_links')) {
                $profile->socialPlatforms()->sync(
                    collect($request->validated('social_links'))->mapWithKeys(fn ($link) => [
                        $link['platform_id'] => ['url' => $link['url']],
                    ])->all()
                );
            }

            return $emailChanged ? $oldEmail : null;
        });

        if ($previousEmail !== null && strcasecmp($previousEmail, $user->email) !== 0) {
            $this->notifyPreviousEmail($user, $previousEmail);
        }

        $profile->load(['user', 'sector', 'location', 'weekDays', 'socialPlatforms', 'portfolios']);

        return $this->successResponse(
            new MemberProfileResource($profile),
            'Perfil atualizado com sucesso.',
            Response::HTTP_OK
        );
    }

    /**
     * Avisa o endereço anterior, no máximo 5 vezes por dia por conta: esse endereço não está verificado
     * e alternar o email serviria para enviar emails a terceiros e gastar a quota diária de envios.
     * Uma falha aqui só fica no log, porque a alteração já foi gravada e não pode resultar num 500.
     * Regista só a classe da exceção: a mensagem de uma falha de transporte pode trazer o endereço.
     */
    private function notifyPreviousEmail(User $user, string $previousEmail): void
    {
        try {
            $key = "email-change-notice:{$user->id}";

            if (RateLimiter::tooManyAttempts($key, self::EMAIL_CHANGE_NOTICE_DAILY_LIMIT)) {
                Log::warning('Limite diário de avisos de alteração de email atingido.', ['user_id' => $user->id]);

                return;
            }

            RateLimiter::hit($key, self::EMAIL_CHANGE_NOTICE_DECAY_SECONDS);

            Notification::route('mail', $previousEmail)
                ->notify(new AccountEmailChangedNotification($user->email, now()));
        } catch (Throwable $e) {
            Log::error('Falha ao enviar o aviso de alteração de email.', ['user_id' => $user->id, 'exception' => $e::class]);
        }
    }
}
