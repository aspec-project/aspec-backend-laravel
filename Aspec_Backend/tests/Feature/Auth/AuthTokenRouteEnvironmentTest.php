<?php

namespace Tests\Feature\Auth;

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * POST /api/auth/token existe só para o Postman obter um Bearer token sem sessão de browser: só é
 * registado com APP_ENV local ou testing. No deploy (APP_ENV=staging ou production) não existe, por
 * isso há menos uma entrada de autenticação por password. Cada caso arranca uma aplicação nova com
 * o APP_ENV indicado, porque as rotas são registadas no arranque.
 */
class AuthTokenRouteEnvironmentTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function deployEnvironments(): array
    {
        return [
            'staging' => ['staging'],
            'production' => ['production'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function developmentEnvironments(): array
    {
        return [
            'local' => ['local'],
            'testing' => ['testing'],
        ];
    }

    #[Test]
    #[DataProvider('deployEnvironments')]
    public function token_route_is_not_registered_outside_local_and_testing(string $environment): void
    {
        $this->assertFalse($this->tokenRouteExistsIn($environment));
    }

    #[Test]
    #[DataProvider('developmentEnvironments')]
    public function token_route_is_registered_in_local_and_testing(string $environment): void
    {
        $this->assertTrue($this->tokenRouteExistsIn($environment));
    }

    /**
     * Arranca outra aplicação com o APP_ENV indicado e procura a rota; repõe o ambiente e a
     * aplicação do teste no fim.
     */
    private function tokenRouteExistsIn(string $environment): bool
    {
        $previous = [
            'putenv' => getenv('APP_ENV') === false ? null : getenv('APP_ENV'),
            '_ENV' => $_ENV['APP_ENV'] ?? null,
            '_SERVER' => $_SERVER['APP_ENV'] ?? null,
        ];

        $this->setAppEnv(['putenv' => $environment, '_ENV' => $environment, '_SERVER' => $environment]);

        try {
            $app = $this->createApplication();

            $this->assertSame($environment, $app->environment());

            try {
                $app['router']->getRoutes()->match(Request::create('/api/auth/token', 'POST'));

                return true;
            } catch (NotFoundHttpException) {
                return false;
            } finally {
                $app->flush();
            }
        } finally {
            $this->setAppEnv($previous);
            $this->restoreTestApplication($this->app);
        }
    }

    /**
     * O Env do Laravel lê o APP_ENV de $_SERVER, $_ENV e getenv(): muda (ou apaga, com null) os três.
     *
     * @param  array{putenv: ?string, _ENV: ?string, _SERVER: ?string}  $values
     */
    private function setAppEnv(array $values): void
    {
        putenv($values['putenv'] === null ? 'APP_ENV' : "APP_ENV={$values['putenv']}");

        if ($values['_ENV'] === null) {
            unset($_ENV['APP_ENV']);
        } else {
            $_ENV['APP_ENV'] = $values['_ENV'];
        }

        if ($values['_SERVER'] === null) {
            unset($_SERVER['APP_ENV']);
        } else {
            $_SERVER['APP_ENV'] = $values['_SERVER'];
        }
    }

    private function restoreTestApplication(Application $app): void
    {
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
    }
}
