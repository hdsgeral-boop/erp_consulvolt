<?php

use App\Support\Tenancy\ModulosPlano;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Os módulos «Estrutura Orgânica» (estrutura), «Método de Trabalho» (metodo) e «Acréscimos e Diferimentos» (acrescimos)
 * existem no catálogo de permissões e no menu, mas faltavam no catálogo de módulos dos planos (erp.planos_catalogo): como
 * ModulosPlano capa o menu e o Gate ao que o plano inclui, nenhum cliente com plano os via, nem um administrador.
 *
 * Acrescenta-os às versões de plano já gravadas, onde fazem sentido: «acrescimos» a quem tem a contabilidade, «estrutura»
 * e «metodo» a quem tem os recursos humanos (a estrutura define a chefia que aprova férias, ausências e assuntos).
 * Idempotente. Os planos sem Contabilidade/RH (ex.: Básico) ficam como estavam.
 */
return new class extends Migration
{
    private const LIGACOES = ['contab' => ['acrescimos'], 'rh' => ['estrutura', 'metodo']];

    public function up(): void
    {
        if (! Schema::hasTable('planos_licenca_versoes')) {
            return;
        }
        foreach (DB::table('planos_licenca_versoes')->get(['id', 'modulos']) as $v) {
            $actuais = json_decode((string) $v->modulos, true);
            if (! is_array($actuais)) {
                continue;
            }
            $novos = $actuais;
            foreach (self::LIGACOES as $base => $extras) {
                if (in_array($base, $actuais, true)) {
                    $novos = array_merge($novos, $extras);
                }
            }
            $novos = array_values(array_unique($novos));
            if ($novos !== $actuais) {
                DB::table('planos_licenca_versoes')->where('id', $v->id)->update(['modulos' => json_encode($novos)]);
            }
        }
        ModulosPlano::invalidar();
    }

    public function down(): void {}
};
