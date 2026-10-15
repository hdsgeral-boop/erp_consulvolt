<?php

namespace App\Http\Controllers\Api\RH;

use App\Http\Controllers\Controller;
use App\Services\RH\ServicoEscalas;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * /api/rh/assiduidade/escalas — RH › Efectividade › separador «Escala do mês» (Versão Actual: assiduidade_ui.js:82,
 * escalas.js). Consulta com rh_assiduidade_view; alterações com rh_assid_registar (a mesma tarefa que regista a
 * efectividade). A autorização vem sempre antes da validação.
 */
final class EscalasController extends Controller
{
    public function __construct(private readonly ServicoEscalas $escalas) {}

    public function grelha(string $mes): JsonResponse
    {
        $this->exigir('rh_assiduidade_view');

        return RespostaApi::sucesso($this->escalas->grelha($mes), 'Escala do mês.');
    }

    public function aplicar(Request $r): JsonResponse
    {
        $this->exigir('rh_assid_registar');
        $d = $r->validate($this->regrasTurno() + [
            'colaboradores' => ['required', 'array', 'min:1', 'max:500'], 'colaboradores.*' => ['integer'],
            'de' => ['required', 'date_format:Y-m-d'], 'ate' => ['nullable', 'date_format:Y-m-d'], 'dias_semana' => ['nullable', 'array', 'max:7'], 'dias_semana.*' => ['integer', 'between:0,6'],
            'substituir_prioritarias' => ['nullable', 'boolean'],
        ]);
        $res = $this->escalas->aplicar($d);

        return RespostaApi::sucesso($res, $this->mensagem($res));
    }

    public function gerarDoContrato(Request $r): JsonResponse
    {
        $this->exigir('rh_assid_registar');
        $d = $r->validate(['de' => ['required', 'date_format:Y-m-d'], 'ate' => ['required', 'date_format:Y-m-d'], 'colaboradores' => ['nullable', 'array', 'max:500'],
            'colaboradores.*' => ['integer'], 'substituir_prioritarias' => ['nullable', 'boolean'], 'marcar_folgas' => ['nullable', 'boolean']]);
        $res = $this->escalas->gerarDoContrato($d['de'], $d['ate'], $d['colaboradores'] ?? null, (bool) ($d['substituir_prioritarias'] ?? false), (bool) ($d['marcar_folgas'] ?? false));

        return RespostaApi::sucesso($res, $this->mensagem($res).($res['sem_horario'] ? ' Sem horário no contrato: '.count($res['sem_horario']).'.' : ''));
    }

    public function copiar(Request $r): JsonResponse
    {
        $this->exigir('rh_assid_registar');
        $d = $r->validate(['de_origem' => ['required', 'date_format:Y-m-d'], 'ate_origem' => ['required', 'date_format:Y-m-d'], 'para_inicio' => ['required', 'date_format:Y-m-d'],
            'colaboradores' => ['nullable', 'array', 'max:500'], 'colaboradores.*' => ['integer'], 'prioritaria' => ['nullable', 'boolean'], 'substituir_prioritarias' => ['nullable', 'boolean']]);
        $res = $this->escalas->copiar($d['de_origem'], $d['ate_origem'], $d['para_inicio'], $d['colaboradores'] ?? null, (bool) ($d['prioritaria'] ?? false),
            (bool) ($d['substituir_prioritarias'] ?? false));

        return RespostaApi::sucesso($res, $this->mensagem($res));
    }

    public function importar(Request $r): JsonResponse
    {
        $this->exigir('rh_assid_registar');
        $d = $r->validate(['ficheiro' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xlsx,xls'], 'substituir_prioritarias' => ['nullable', 'boolean']]);
        $res = $this->escalas->importar($d['ficheiro'], (bool) ($d['substituir_prioritarias'] ?? false));

        return RespostaApi::sucesso($res, $res['gravado'] ? $this->mensagem($res) : count($res['erros']).' erro(s) no ficheiro: nada foi gravado.');
    }

    public function modelo(): Response
    {
        $this->exigir('rh_assiduidade_view');

        return response($this->escalas->modelo(), 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="Modelo_Escala.xlsx"', 'Cache-Control' => 'no-store']);
    }

    public function eliminar(Request $r): JsonResponse
    {
        $this->exigir('rh_assid_registar');
        $d = $r->validate(['ids' => ['required', 'array', 'min:1', 'max:5000'], 'ids.*' => ['integer']]);
        $n = $this->escalas->eliminar($d['ids']);

        return RespostaApi::sucesso(['eliminados' => $n], "{$n} dia(s) de escala eliminado(s).");
    }

    public function limpar(Request $r): JsonResponse
    {
        $this->exigir('rh_assid_registar');
        $d = $r->validate(['de' => ['required', 'date_format:Y-m-d'], 'ate' => ['required', 'date_format:Y-m-d'], 'colaboradores' => ['nullable', 'array', 'max:500'],
            'colaboradores.*' => ['integer'], 'apenas_prioritarias' => ['nullable', 'boolean'], 'manter_prioritarias' => ['nullable', 'boolean']]);
        $n = $this->escalas->limpar($d['de'], $d['ate'], $d['colaboradores'] ?? null, (bool) ($d['apenas_prioritarias'] ?? false), (bool) ($d['manter_prioritarias'] ?? true));

        return RespostaApi::sucesso(['eliminados' => $n], "{$n} dia(s) de escala eliminado(s).");
    }

    /** @return array<string, list<mixed>> */
    private function regrasTurno(): array
    {
        return ['entrada' => ['nullable', 'date_format:H:i'], 'saida' => ['nullable', 'date_format:H:i'], 'periodos' => ['nullable', 'integer', 'in:1,2'],
            'saida_intervalo' => ['nullable', 'date_format:H:i'], 'entrada_intervalo' => ['nullable', 'date_format:H:i'], 'intervalo_minutos' => ['nullable', 'integer', 'between:0,480'],
            'tolerancia_min' => ['nullable', 'integer', 'between:0,120'], 'folga' => ['nullable', 'boolean'], 'prioritaria' => ['nullable', 'boolean'], 'nota' => ['nullable', 'string', 'max:500']];
    }

    private function mensagem(array $r): string
    {
        return "Escala: {$r['criados']} dia(s) criado(s), {$r['actualizados']} actualizado(s)".($r['protegidos'] ? ", {$r['protegidos']} protegido(s) por escala prioritária" : '').'.';
    }
}
