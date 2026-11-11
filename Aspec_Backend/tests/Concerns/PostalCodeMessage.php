<?php

namespace Tests\Concerns;

/**
 * Mensagem de erro do código postal português, partilhada pelos testes do registo, do perfil,
 * da ativação e da regra, para o texto ser mudado num só sítio.
 */
trait PostalCodeMessage
{
    protected const POSTAL_CODE_MESSAGE = 'O campo código postal tem de ser um código postal português válido, no formato 1234-567.';
}
