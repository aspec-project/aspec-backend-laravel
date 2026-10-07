<?php

namespace Tests\Feature;

use App\Models\Sector;
use App\Traits\ApiResponse;
use Illuminate\Auth\Access\Response as GateResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class ApiErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    private const MSG_422 = 'Os dados enviados são inválidos.';
    private const MSG_401 = 'Não autenticado.';
    private const MSG_403 = 'Não tem permissão para realizar esta ação.';
    private const MSG_404 = 'Recurso não encontrado.';
    private const MSG_405 = 'Método não permitido.';
    private const MSG_419 = 'A sessão expirou. Atualize a página e tente novamente.';
    private const MSG_429 = 'Demasiados pedidos. Tente novamente mais tarde.';
    private const MSG_500 = 'Ocorreu um erro interno. Tente novamente mais tarde.';

    protected function setUp(): void
    {
        parent::setUp();

        // Mesmo cenário do ambiente de desenvolvimento; os testes do 500 alteram-no explicitamente.
        config(['app.debug' => true]);

        Gate::define('_test-deny', fn ($user = null) => false);
        Gate::define('_test-deny-with-message', fn ($user = null) => GateResponse::deny('Mensagem própria da policy.'));

        Route::middleware('api')->prefix('api/_test')->group(function () {
            Route::post('/validation', function (Request $request) {
                $request->validate([
                    'name' => ['required', 'string'],
                    'email' => ['required', 'email'],
                ]);

                return response()->json(['ok' => true]);
            });

            Route::get('/auth', fn () => response()->json(['ok' => true]))->middleware('auth:sanctum');

            Route::get('/gate-deny', function () {
                Gate::authorize('_test-deny');
            });

            Route::get('/gate-deny-with-message', function () {
                Gate::authorize('_test-deny-with-message');
            });

            Route::get('/abort-403', fn () => abort(403, 'A sua conta não está ativa.'));
            Route::get('/abort-403-empty', fn () => abort(403));

            Route::get('/signed', fn () => response()->json(['ok' => true]))->middleware('signed');

            Route::get('/http-exception-subclass-403', function () {
                throw new class(403, 'Framework internal forbidden message.') extends HttpException {};
            });

            Route::get('/not-found-subclass', function () {
                throw new class('Framework internal not found message.') extends NotFoundHttpException {};
            });

            Route::get('/find-or-fail', fn () => Sector::findOrFail((string) Str::uuid()));
            Route::get('/abort-404', fn () => abort(404, 'Evento não encontrado.'));

            Route::get('/abort-409', fn () => abort(409, 'Já está inscrito neste evento.'));
            Route::get('/abort-409-empty', fn () => abort(409));

            // O middleware CSRF não verifica o token em testes, por isso lança-se a exceção que ele lançaria.
            Route::post('/csrf-mismatch', function () {
                throw new TokenMismatchException('CSRF token mismatch.');
            });

            Route::get('/get-only', fn () => response()->json(['ok' => true]));

            Route::get('/throttled', fn () => response()->json(['ok' => true]))->middleware('throttle:1,1');

            Route::get('/server-error', function () {
                throw new \RuntimeException('SQLSTATE[HY000]: detalhe interno secreto');
            });

            Route::get('/success', fn () => (new class {
                use ApiResponse;
            })->successResponse(['id' => 1], 'Operação concluída.'));

            Route::get('/trait-error', fn () => (new class {
                use ApiResponse;
            })->errorResponse('Erro sem detalhes.', 400));

            Route::get('/trait-error-with-errors', fn () => (new class {
                use ApiResponse;
            })->errorResponse('Erro com detalhes.', 422, ['field' => ['Mensagem do campo.']]));
        });

        Route::get('/_test/web-abort-404', fn () => abort(404, 'Web not found.'));
    }

    // 422

    #[Test]
    public function validation_error_returns_422_with_generic_message_and_top_level_errors(): void
    {
        $response = $this->postJson('/api/_test/validation', ['email' => 'not-an-email']);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', self::MSG_422)
            ->assertJsonValidationErrors(['name', 'email'])
            ->assertJsonMissingPath('data')
            ->assertJsonMissingPath('data.errors');

        $this->assertSame(['success', 'message', 'errors'], array_keys($response->json()));
    }

    #[Test]
    public function validation_errors_are_translated_to_pt_pt(): void
    {
        $this->assertSame('pt_PT', app()->getLocale());

        $response = $this->postJson('/api/_test/validation', []);

        $message = $response->json('errors.name.0');

        $this->assertIsString($message);
        $this->assertNotSame('validation.required', $message);
        $this->assertStringNotContainsString('field is required', $message);
        $this->assertStringNotContainsString('The name', $message);
    }

    // 401

    #[Test]
    public function unauthenticated_request_returns_401_json(): void
    {
        $this->getJson('/api/_test/auth')
            ->assertStatus(401)
            ->assertExactJson(['success' => false, 'message' => self::MSG_401]);
    }

    #[Test]
    public function unauthenticated_request_without_accept_header_returns_401_json_without_redirect(): void
    {
        $response = $this->get('/api/_test/auth');

        $response->assertStatus(401);
        $this->assertFalse($response->isRedirect());
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertExactJson(['success' => false, 'message' => self::MSG_401]);
    }

    // 403

    #[Test]
    public function denied_gate_returns_generic_403_message(): void
    {
        $this->getJson('/api/_test/gate-deny')
            ->assertStatus(403)
            ->assertExactJson(['success' => false, 'message' => self::MSG_403]);
    }

    #[Test]
    public function denied_gate_with_custom_message_still_returns_generic_403_message(): void
    {
        $this->getJson('/api/_test/gate-deny-with-message')
            ->assertStatus(403)
            ->assertExactJson(['success' => false, 'message' => self::MSG_403]);
    }

    #[Test]
    public function abort_403_with_message_keeps_the_message(): void
    {
        $this->getJson('/api/_test/abort-403')
            ->assertStatus(403)
            ->assertExactJson(['success' => false, 'message' => 'A sua conta não está ativa.']);
    }

    #[Test]
    public function abort_403_without_message_returns_generic_403_message(): void
    {
        $this->getJson('/api/_test/abort-403-empty')
            ->assertStatus(403)
            ->assertExactJson(['success' => false, 'message' => self::MSG_403]);
    }

    #[Test]
    public function invalid_signature_returns_generic_403_message(): void
    {
        $response = $this->getJson('/api/_test/signed');

        $response->assertStatus(403)
            ->assertExactJson(['success' => false, 'message' => self::MSG_403]);
        $this->assertStringNotContainsString('Invalid signature', $response->getContent());
    }

    #[Test]
    public function http_exception_subclass_returns_generic_message_for_its_code(): void
    {
        $response = $this->getJson('/api/_test/http-exception-subclass-403');

        $response->assertStatus(403)
            ->assertExactJson(['success' => false, 'message' => self::MSG_403]);
        $this->assertStringNotContainsString('Framework internal', $response->getContent());
    }

    // 404

    #[Test]
    public function not_found_http_exception_subclass_returns_generic_404_message(): void
    {
        $response = $this->getJson('/api/_test/not-found-subclass');

        $response->assertStatus(404)
            ->assertExactJson(['success' => false, 'message' => self::MSG_404]);
        $this->assertStringNotContainsString('Framework internal', $response->getContent());
    }

    #[Test]
    public function find_or_fail_returns_generic_404_without_model_details(): void
    {
        $response = $this->getJson('/api/_test/find-or-fail');

        $response->assertStatus(404)
            ->assertExactJson(['success' => false, 'message' => self::MSG_404]);

        $content = $response->getContent();
        $this->assertStringNotContainsString('App\\\\Models', $content);
        $this->assertStringNotContainsString('App\\Models', $content);
        $this->assertStringNotContainsString('Sector', $content);
        $this->assertStringNotContainsString('No query results', $content);
    }

    #[Test]
    public function abort_404_with_message_keeps_the_message(): void
    {
        $this->getJson('/api/_test/abort-404')
            ->assertStatus(404)
            ->assertExactJson(['success' => false, 'message' => 'Evento não encontrado.']);
    }

    #[Test]
    public function unknown_api_route_returns_generic_404_message(): void
    {
        $this->getJson('/api/_test/does-not-exist')
            ->assertStatus(404)
            ->assertExactJson(['success' => false, 'message' => self::MSG_404]);
    }

    #[Test]
    public function unknown_api_route_without_accept_header_returns_json(): void
    {
        $this->get('/api/_test/does-not-exist')
            ->assertStatus(404)
            ->assertExactJson(['success' => false, 'message' => self::MSG_404]);
    }

    // 419

    #[Test]
    public function csrf_token_mismatch_returns_419_with_pt_pt_message(): void
    {
        $response = $this->postJson('/api/_test/csrf-mismatch');

        $response->assertStatus(419)
            ->assertExactJson(['success' => false, 'message' => self::MSG_419]);
        $this->assertStringNotContainsString('CSRF token mismatch', $response->getContent());
    }

    #[Test]
    public function csrf_token_mismatch_without_accept_header_returns_419_json(): void
    {
        $response = $this->post('/api/_test/csrf-mismatch');

        $response->assertStatus(419);
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertExactJson(['success' => false, 'message' => self::MSG_419]);
        $this->assertStringNotContainsString('CSRF token mismatch', $response->getContent());
    }

    // 405 / 429

    #[Test]
    public function wrong_http_method_returns_405(): void
    {
        $this->postJson('/api/_test/get-only')
            ->assertStatus(405)
            ->assertExactJson(['success' => false, 'message' => self::MSG_405]);
    }

    #[Test]
    public function too_many_requests_returns_429(): void
    {
        $this->getJson('/api/_test/throttled')->assertOk();

        $this->getJson('/api/_test/throttled')
            ->assertStatus(429)
            ->assertExactJson(['success' => false, 'message' => self::MSG_429]);
    }

    // Outras HttpException

    #[Test]
    public function other_http_exception_with_message_keeps_status_and_message(): void
    {
        $this->getJson('/api/_test/abort-409')
            ->assertStatus(409)
            ->assertExactJson(['success' => false, 'message' => 'Já está inscrito neste evento.']);
    }

    #[Test]
    public function other_http_exception_without_message_returns_non_empty_generic_message(): void
    {
        $response = $this->getJson('/api/_test/abort-409-empty');

        $response->assertStatus(409)->assertJsonPath('success', false);
        $this->assertSame(['success', 'message'], array_keys($response->json()));
        $this->assertNotEmpty($response->json('message'));
    }

    // 500

    #[Test]
    public function server_error_without_debug_returns_generic_500_without_details(): void
    {
        config(['app.debug' => false]);

        $response = $this->getJson('/api/_test/server-error');

        $response->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => self::MSG_500]);

        $content = $response->getContent();
        $this->assertStringNotContainsString('SQLSTATE', $content);
        $this->assertStringNotContainsString('detalhe interno secreto', $content);
        $this->assertStringNotContainsString('RuntimeException', $content);
        $response->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('trace')
            ->assertJsonMissingPath('file')
            ->assertJsonMissingPath('line');
    }

    #[Test]
    public function server_error_without_debug_and_without_accept_header_returns_json(): void
    {
        config(['app.debug' => false]);

        $this->get('/api/_test/server-error')
            ->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => self::MSG_500]);
    }

    #[Test]
    public function server_error_with_debug_falls_back_to_laravel_debug_output(): void
    {
        config(['app.debug' => true]);

        $this->getJson('/api/_test/server-error')
            ->assertStatus(500)
            ->assertJsonPath('message', 'SQLSTATE[HY000]: detalhe interno secreto')
            ->assertJsonPath('exception', \RuntimeException::class)
            ->assertJsonMissingPath('success');
    }

    // Fora de api/*

    #[Test]
    public function non_api_routes_are_not_affected(): void
    {
        $this->getJson('/_test/web-abort-404')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Web not found.')
            ->assertJsonMissingPath('success');
    }

    // Trait ApiResponse

    #[Test]
    public function success_response_format_is_unchanged(): void
    {
        $this->getJson('/api/_test/success')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Operação concluída.',
                'data' => ['id' => 1],
            ]);
    }

    #[Test]
    public function error_response_without_errors_omits_errors_key(): void
    {
        $this->getJson('/api/_test/trait-error')
            ->assertStatus(400)
            ->assertExactJson(['success' => false, 'message' => 'Erro sem detalhes.']);
    }

    #[Test]
    public function error_response_with_errors_includes_top_level_errors(): void
    {
        $this->getJson('/api/_test/trait-error-with-errors')
            ->assertStatus(422)
            ->assertExactJson([
                'success' => false,
                'message' => 'Erro com detalhes.',
                'errors' => ['field' => ['Mensagem do campo.']],
            ]);
    }
}
