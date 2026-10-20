<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Marca como isentos os administradores técnicos criados antes da coluna subscription_exempt:
     * papel Admin, conta não apagada e sem nenhuma linha em member_profiles (nem apagada), que é o
     * que o aspec:create-admin criava. Os admins promovidos têm perfil e passam a pagar.
     * Usa o Query Builder (e não os modelos, que podem mudar depois) e procura o papel pelo nome;
     * sem o papel Admin (base de dados sem seed) não faz nada.
     */
    public function up(): void
    {
        $adminRoleId = DB::table('roles')->where('name', 'Admin')->value('id');

        if (! $adminRoleId) {
            return;
        }

        DB::table('users')
            ->where('role_id', $adminRoleId)
            ->whereNull('deleted_at')
            ->whereNotExists(fn (Builder $profiles) => $profiles->select(DB::raw(1))
                ->from('member_profiles')
                ->whereColumn('member_profiles.user_id', 'users.id'))
            ->update(['subscription_exempt' => true]);
    }

    /**
     * Não faz nada: a coluna (e com ela as marcas) é removida pelo down() da migração anterior.
     * Repor as marcas a false aqui tirava a isenção a um administrador técnico criado depois.
     */
    public function down(): void
    {
        //
    }
};
