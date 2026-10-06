<?php

namespace App\Http\Controllers\Api\Integracoes;

use App\Http\Controllers\Controller;
use App\Services\Integracoes\Cambios\ServicoCambiosBAIAutomaticos;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/sistema/cambios/bai/automatico e /api/sistema/cambios/bai/pendentes — câmbios do BAI automáticos (pendentes de
 * validação). Consulta: config_moedas_view ou config_moedas_gerir; configurar, obter, validar e rejeitar: config_moedas_gerir.
 */
final class CambiosBAIAutomaticosController extends Controller
{
    public function __construct(private readonly ServicoCambiosBAIAutomaticos $servico) {}

    /** GET — configuração, pendentes por validar, última execução e última falha. */
    public function estado(): JsonResponse
    {
        $this->exigir('config_moedas_view', 'config_moedas_gerir');
        $e = $this->servico->estado();
        $n = count($e['pendentes']);

        return RespostaApi::sucesso($e, $n ? "Há {$n} câmbio(s) do BAI por validar." : 'Sem câmbios do BAI por validar.');
    }

    /** PUT {ativo, hora} — activar/desactivar a obtenção diária e a hora. */
    public function configurar(Request $r): JsonResponse
    {
        $this->exigir('config_moedas_gerir');
        $d = $r->validate(['ativo' => ['required', 'boolean'], 'hora' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/']],
            ['hora.regex' => 'A hora tem o formato HH:MM (ex.: 08:30).']);

        return RespostaApi::sucesso($this->servico->gravarConfiguracao((bool) $d['ativo'], $d['hora']),
            $d['ativo'] ? "Obtenção automática activada às {$d['hora']}." : 'Obtenção automática desactivada.');
    }

    /** POST — obtém agora os câmbios do BAI para validação (não grava nos câmbios). */
    public function obter(): JsonResponse
    {
        $this->exigir('config_moedas_gerir');
        $exec = $this->servico->obter('MANUAL');

        return RespostaApi::sucesso($exec + ['pendentes' => $this->servico->pendentes()], 'Câmbios do BAI obtidos: reveja-os e valide os que pretende gravar.');
    }

    /** POST {moedas[]} — valida e grava nos câmbios as moedas escolhidas. */
    public function validar(Request $r): JsonResponse
    {
        $this->exigir('config_moedas_gerir');
        $d = $r->validate(['moedas' => ['required', 'array', 'min:1', 'max:50'], 'moedas.*' => ['string', 'size:3']]);
        $res = $this->servico->validar($d['moedas']);

        return RespostaApi::sucesso($res, "Câmbios do BAI validados: {$res['novos']} novo(s), {$res['substituidos']} substituído(s)"
            .($res['bloqueados'] ? ', '.count($res['bloqueados']).' não gravado(s) (em uso)' : '').'.');
    }

    /** POST {moedas?[], motivo?} — rejeita as pendentes (todas, sem moedas). */
    public function rejeitar(Request $r): JsonResponse
    {
        $this->exigir('config_moedas_gerir');
        $d = $r->validate(['moedas' => ['nullable', 'array', 'max:50'], 'moedas.*' => ['string', 'size:3'], 'motivo' => ['nullable', 'string', 'max:1000']]);
        $n = $this->servico->rejeitar($d['moedas'] ?? [], $d['motivo'] ?? null);

        return RespostaApi::sucesso(['rejeitados' => $n], "{$n} câmbio(s) do BAI rejeitado(s). Nada foi gravado nos câmbios.");
    }
}
