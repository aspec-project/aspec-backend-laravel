<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PasswordResetTokensTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_reset_tokens_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('password_reset_tokens'));
        $this->assertTrue(Schema::hasColumns('password_reset_tokens', ['email', 'token', 'created_at']));
    }
}