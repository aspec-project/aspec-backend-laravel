<?php

namespace Tests\Unit\Geocoding;

use App\Services\Geocoding\AddressCleaner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AddressCleanerTest extends TestCase
{
    private function cleaner(): AddressCleaner
    {
        return new AddressCleaner;
    }

    public static function addressesWithMarkers(): array
    {
        return [
            'nº e andar com lado' => ['Rua do Brasil nº 25, 3º Esq, Coimbra', 'Rua do Brasil 25, Coimbra'],
            'n.º' => ['Rua do Brasil n.º 25', 'Rua do Brasil 25'],
            'Nº colado ao número' => ['Rua do Brasil Nº25', 'Rua do Brasil 25'],
            'núm.' => ['Rua do Brasil núm. 25', 'Rua do Brasil 25'],
            'N.º e 2.º Dto com código postal' => ['Avenida da Liberdade, N.º 10, 2.º Dto, 1250-096 Lisboa', 'Avenida da Liberdade, 10, 1250-096 Lisboa'],
            'r/c como segmento' => ['Rua Augusta 100, r/c, 1100-053 Lisboa', 'Rua Augusta 100, 1100-053 Lisboa'],
            'R/C Esq no fim' => ['Rua Augusta 100 R/C Esq', 'Rua Augusta 100'],
            'rés-do-chão' => ['Rua Augusta 100, rés-do-chão', 'Rua Augusta 100'],
            'cave' => ['Rua das Flores 42, cave', 'Rua das Flores 42'],
            'lote e loja' => ['Rua do Comércio, Lote 5, Loja 3, Leiria', 'Rua do Comércio, Leiria'],
            'bloco e fração' => ['Rua Nova 12, Bloco B, Fração C', 'Rua Nova 12'],
            'fracção e piso' => ['Rua Nova 12, Fracção A, Piso 2', 'Rua Nova 12'],
            'apt.' => ['Rua Nova 12, Apt. 4', 'Rua Nova 12'],
            'andar e lado com ponto' => ['Rua X 25, 3º andar Esq.', 'Rua X 25'],
            'andar com letra' => ['Rua X 25, 4º A', 'Rua X 25'],
            'andar sem º com lado' => ['Rua X 25, 3 Esq', 'Rua X 25'],
            'lado solto' => ['Rua X 25 Dto', 'Rua X 25'],
            'lote com nº' => ['Rua do Comércio, Lote nº 5', 'Rua do Comércio'],
            'Região Autónoma dos Açores' => ['Rua da Sé 10, Ponta Delgada, Região Autónoma dos Açores', 'Rua da Sé 10, Ponta Delgada, Açores'],
            'Região Autónoma da Madeira' => ['Rua da Carreira 50, Funchal, Região Autónoma da Madeira', 'Rua da Carreira 50, Funchal, Madeira'],
            'Portugal no fim' => ['Rua Augusta 100, 1100-053 Lisboa, Portugal', 'Rua Augusta 100, 1100-053 Lisboa'],
            'espaços e vírgulas a mais' => ['  Rua   Augusta  100 ,, Lisboa , ', 'Rua Augusta 100, Lisboa'],
        ];
    }

    #[Test]
    #[DataProvider('addressesWithMarkers')]
    public function clean_removes_the_markers(string $address, string $expected): void
    {
        $this->assertSame($expected, $this->cleaner()->clean($address));
    }

    public static function validAddresses(): array
    {
        return [
            'Praça 1.º de Maio' => ['Praça 1.º de Maio 5'],
            'Rua 1º de Dezembro' => ['Rua 1º de Dezembro 20'],
            'Avenida 25 de Abril' => ['Avenida 25 de Abril 3'],
            '2º Travessa' => ['2º Travessa da Boavista 8'],
            '1.ª Travessa' => ['1.ª Travessa da Rua Direita 4'],
            'Rua da Cave' => ['Rua da Cave 3'],
            'Rua do Bloco' => ['Rua do Bloco 7'],
            'Rua dos Lotes' => ['Rua dos Lotes 9'],
            'Loja do Cidadão' => ['Loja do Cidadão, Rua Nova 1'],
            'Rua Frei Luís de Sousa' => ['Rua Frei Luís de Sousa 8'],
            'Rua do Direito' => ['Rua do Direito 4'],
            'número de porta em segmento próprio' => ['Avenida da Boavista, 1277, Porto'],
            'código postal' => ['Rua Augusta 100, 1100-053 Lisboa'],
            'no dentro de uma palavra' => ['Rua Lino 25'],
        ];
    }

    #[Test]
    #[DataProvider('validAddresses')]
    public function clean_keeps_valid_addresses_unchanged(string $address): void
    {
        $this->assertSame($address, $this->cleaner()->clean($address));
    }

    #[Test]
    public function clean_keeps_ordinal_street_names_while_removing_the_floor(): void
    {
        $this->assertSame('Praça 1.º de Maio 5', $this->cleaner()->clean('Praça 1.º de Maio 5, 4º Esq'));
    }

    public static function onlyMarkers(): array
    {
        return [
            'r/c' => ['r/c'],
            '3º Esq' => ['3º Esq'],
            'Lote 5, Loja 3' => ['Lote 5, Loja 3'],
        ];
    }

    #[Test]
    #[DataProvider('onlyMarkers')]
    public function clean_returns_an_empty_string_when_the_address_has_only_markers(string $address): void
    {
        $this->assertSame('', $this->cleaner()->clean($address));
    }

    #[Test]
    public function clean_keeps_a_floor_without_ordinal_in_the_middle_of_a_segment(): void
    {
        $this->assertSame('Rua X 25 3', $this->cleaner()->clean('Rua X 25 3 Esq'));
    }

    public static function attemptsCases(): array
    {
        return [
            'código postal e distrito' => [
                'Rua Augusta 100, 1100-053 Lisboa', 'Lisboa',
                ['Rua Augusta 100, 1100-053 Lisboa, Lisboa', 'Rua Augusta 100, 1100-053 Lisboa', 'Rua Augusta 100, Lisboa'],
            ],
            'Coimbra com marcadores' => [
                'Rua do Brasil nº 25, 3º Esq, Coimbra', 'Coimbra',
                ['Rua do Brasil 25, Coimbra, Coimbra', 'Rua do Brasil 25, Coimbra'],
            ],
            'Açores sem repetir a terceira' => [
                'Rua da Sé 10, Ponta Delgada', 'Açores',
                ['Rua da Sé 10, Ponta Delgada, Açores', 'Rua da Sé 10, Ponta Delgada'],
            ],
            'Região Autónoma dos Açores escrita na morada' => [
                'Rua da Sé 10, Ponta Delgada, Região Autónoma dos Açores', 'Açores',
                ['Rua da Sé 10, Ponta Delgada, Açores, Açores', 'Rua da Sé 10, Ponta Delgada, Açores', 'Rua da Sé 10, Ponta Delgada'],
            ],
            'Madeira escrita na morada' => [
                'Rua da Carreira 50, Funchal, Madeira', 'Madeira',
                ['Rua da Carreira 50, Funchal, Madeira, Madeira', 'Rua da Carreira 50, Funchal, Madeira', 'Rua da Carreira 50, Funchal'],
            ],
            'nome do edifício antes da rua' => [
                'Edifício Atlântico, Rua Nova 12, Bairro Alto, 1200-001 Lisboa', 'Lisboa',
                ['Edifício Atlântico, Rua Nova 12, Bairro Alto, 1200-001 Lisboa, Lisboa', 'Edifício Atlântico, Rua Nova 12, Bairro Alto, 1200-001 Lisboa', 'Rua Nova 12, Lisboa'],
            ],
            'número em segmento próprio depois de limpar' => [
                'Avenida da Liberdade, N.º 10, 2.º Dto, 1250-096 Lisboa', 'Lisboa',
                ['Avenida da Liberdade, 10, 1250-096 Lisboa, Lisboa', 'Avenida da Liberdade, 10, 1250-096 Lisboa', 'Avenida da Liberdade 10, Lisboa'],
            ],
            'número de porta em segmento próprio' => [
                'Avenida da Boavista, 1277, Porto', 'Porto',
                ['Avenida da Boavista, 1277, Porto, Porto', 'Avenida da Boavista, 1277, Porto', 'Avenida da Boavista 1277, Porto'],
            ],
            'sem distrito nem localidade' => ['Rua Augusta 1', null, ['Rua Augusta 1']],
            'sem distrito e com localidade' => ['Rua do Brasil 25, Coimbra', null, ['Rua do Brasil 25, Coimbra']],
            'só marcadores' => ['r/c', 'Lisboa', []],
        ];
    }

    #[Test]
    #[DataProvider('attemptsCases')]
    public function attempts_returns_the_searches_in_order_without_repeats(string $address, ?string $district, array $expected): void
    {
        $this->assertSame($expected, $this->cleaner()->attempts($address, $district));
    }

    public static function streetCases(): array
    {
        return [
            'primeiro segmento com tipo de via' => ['Edifício Atlântico, Rua Nova 12, Bairro Alto, 1200-001 Lisboa', 'Rua Nova 12'],
            'junta o número do segmento seguinte' => ['Avenida da Boavista, 1277, Porto', 'Avenida da Boavista 1277'],
            'não junta quando a rua já tem número' => ['Rua X 25, 3, Lisboa', 'Rua X 25'],
            'sem tipo de via usa o primeiro segmento' => ['Hotel Central, Lisboa', 'Hotel Central'],
        ];
    }

    #[Test]
    #[DataProvider('streetCases')]
    public function street_returns_the_street_segment(string $cleaned, string $expected): void
    {
        $this->assertSame($expected, $this->cleaner()->street($cleaned));
    }

    #[Test]
    public function street_returns_null_for_an_empty_address(): void
    {
        $this->assertNull($this->cleaner()->street(''));
    }
}
