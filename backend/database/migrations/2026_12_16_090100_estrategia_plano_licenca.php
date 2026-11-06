<?php

use App\Support\Tenancy\ModulosPlano;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O módulo «Planeamento Estratégico» (estrategia) entra nas versões de plano já gravadas que têm o Orçamento ou os Recursos
 * Humanos (a estratégia lê do orçamento e da estrutura orgânica). Sem isto nenhum cliente com plano o veria, porque
 * ModulosPlano capa o menu e o Gate ao que o plano inclui. Idempotente; os planos sem Orçamento nem RH ficam como estavam.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('planos_licenca_versoes')) {
            return;
        }
        foreach (DB::table('planos_licenca_versoes')->get(['id', 'modulos']) as $v) {
            $actuais = json_decode((string) $v->modulos, true);
            if (! is_array($actuais) || in_array('estrategia', $actuais, true)) {
                continue;
            }
            if (in_array('orcamento', $actuais, true) || in_array('rh', $actuais, true)) {
                DB::table('planos_licenca_versoes')->where('id', $v->id)->update(['modulos' => json_encode([...$actuais, 'estrategia'])]);
            }
        }
        ModulosPlano::invalidar();
    }

    public function down(): void {}
};
