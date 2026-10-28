<?php

namespace Tests\Feature\Geocoding;

use App\Contracts\Geocoder;
use App\Services\Geocoding\GoogleGeocoder;
use App\Services\Geocoding\NominatimGeocoder;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Escolha do fornecedor de geocodificação pela configuração (GEOCODING_DRIVER).
 */
class GeocoderBindingTest extends TestCase
{
    private const KEY = 'test-google-key-7f3a9c';

    private function resolveExpectingInvalidArgument(): InvalidArgumentException
    {
        try {
            app(Geocoder::class);
        } catch (InvalidArgumentException $e) {
            return $e;
        }

        $this->fail('Era esperada uma InvalidArgumentException.');
    }

    #[Test]
    public function tests_use_nominatim_without_a_google_key_by_default(): void
    {
        $this->assertSame('nominatim', config('services.geocoding.driver'));
        $this->assertEmpty(config('services.google.geocoding_key'));
        $this->assertInstanceOf(NominatimGeocoder::class, app(Geocoder::class));
    }

    #[Test]
    public function google_driver_with_a_key_resolves_the_google_geocoder(): void
    {
        config(['services.geocoding.driver' => 'google', 'services.google.geocoding_key' => self::KEY]);

        $this->assertInstanceOf(GoogleGeocoder::class, app(Geocoder::class));
    }

    public static function missingKeys(): array
    {
        return [
            'null' => [null],
            'vazia' => [''],
            'só espaços' => ['   '],
        ];
    }

    #[Test]
    #[DataProvider('missingKeys')]
    public function google_driver_without_a_key_fails_instead_of_falling_back_to_nominatim(?string $key): void
    {
        config(['services.geocoding.driver' => 'google', 'services.google.geocoding_key' => $key]);

        $this->assertSame(
            'Falta a chave da API de geocodificação da Google.',
            $this->resolveExpectingInvalidArgument()->getMessage()
        );
    }

    #[Test]
    public function unknown_driver_fails_without_echoing_the_configured_value(): void
    {
        config(['services.geocoding.driver' => 'here']);

        $message = $this->resolveExpectingInvalidArgument()->getMessage();

        $this->assertSame('Driver de geocodificação desconhecido.', $message);
        $this->assertStringNotContainsString('here', $message);
    }

    #[Test]
    public function changing_the_config_changes_the_resolved_geocoder(): void
    {
        config(['services.geocoding.driver' => 'nominatim']);
        $first = app(Geocoder::class);

        config(['services.geocoding.driver' => 'google', 'services.google.geocoding_key' => self::KEY]);
        $second = app(Geocoder::class);

        $this->assertInstanceOf(NominatimGeocoder::class, $first);
        $this->assertInstanceOf(GoogleGeocoder::class, $second);
        $this->assertNotSame($first::class, $second::class);
    }
}
