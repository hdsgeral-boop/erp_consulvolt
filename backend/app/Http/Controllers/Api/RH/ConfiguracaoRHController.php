<?php

namespace App\Http\Controllers\Api\RH;

use App\Http\Controllers\Controller;
use App\Models\PeriodoProcessamentoSalarial;
use App\Services\RH\ServicoAssiduidade;
use App\Services\RH\ServicoCalendarioRH;
use App\Services\RH\ServicoConfiguracaoRH;
use App\Services\RH\ServicoRecibosSalario;
use App\Services\RH\ServicoTabelaIRT;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ronda 2 (RH): configuração por empresa (decisões 5 e 7), tabela de IRT (decisão 1), feriados nacionais de Angola
 * (decisão 6), leitura directa do relógio biométrico (M-12) e recibos em PDF/ZIP (A-10).
 */
final class ConfiguracaoRHController extends Controller
{
    /** Quem consulta o RH vê a configuração (o cálculo, as férias e os mapas dependem dela). */
    private const VER = ['calcular_view', 'processamento_view', 'rh_ferias_view', 'infotipos_view', 'rh_assiduidade_view', 'colaboradores_view'];

    public function config(ServicoConfiguracaoRH $cfg, ServicoTabelaIRT $irt): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($cfg->config() + ['tabela_irt' => ServicoTabelaIRT::paraEcra($irt->atual()), 'tabela_irt_personalizada' => $irt->personalizada()],
            'Configuração de RH.');
    }

    /** PUT /rh/configuracao — a segregação exige quem gere as empresas; as regras das férias exigem rh_ferias_edit. */
    public function gravarConfig(Request $r, ServicoConfiguracaoRH $cfg): JsonResponse
    {
        // OWASP A01: sem nenhuma das permissões, recusa logo (um corpo vazio passava e devolvia a configuração de RH)
        $this->exigir('config_empresas_gerir', 'rh_ferias_edit');
        $d = $r->validate(['segregar_encerrar_validar' => ['sometimes', 'boolean'], 'ferias_dias_mes_admissao' => ['sometimes', 'integer', 'between:0,5'],
            'ferias_meses_minimos_gozo' => ['sometimes', 'integer', 'between:0,12'], 'ferias_transporte_saldo' => ['sometimes', 'boolean'],
            'ferias_transporte_max_dias' => ['sometimes', 'integer', 'between:0,66']]);
        if (array_key_exists('segregar_encerrar_validar', $d)) {
            $this->exigir('config_empresas_gerir');
        }
        if (array_diff(array_keys($d), ['segregar_encerrar_validar'])) {
            $this->exigir('rh_ferias_edit');
        }

        return RespostaApi::sucesso($cfg->gravar($d), 'Configuração de RH gravada.');
    }

    public function gravarTabelaIrt(Request $r, ServicoTabelaIRT $irt): JsonResponse
    {
        $this->exigir('rh_tabela_irt_gerir');
        $d = $r->validate(['escaloes' => ['required', 'array', 'min:2', 'max:30'], 'escaloes.*.max' => ['nullable', 'numeric', 'min:0'],
            'escaloes.*.taxa' => ['required', 'numeric', 'min:0', 'max:100'], 'escaloes.*.fixo' => ['required', 'numeric', 'min:0'], 'escaloes.*.excesso' => ['required', 'numeric', 'min:0']]);

        return RespostaApi::sucesso(ServicoTabelaIRT::paraEcra($irt->gravar($d['escaloes'])), 'Tabela de IRT gravada (aplica-se aos próximos encerramentos).');
    }

    public function reporTabelaIrt(ServicoTabelaIRT $irt): JsonResponse
    {
        $this->exigir('rh_tabela_irt_gerir');

        return RespostaApi::sucesso(ServicoTabelaIRT::paraEcra($irt->repor()), 'Tabela de IRT reposta (engine_v2.js).');
    }

    /** GET /rh/assiduidade/feriados-nacionais?ano= — lista para pré-carregar (nada é gravado). */
    public function feriadosNacionais(Request $r): JsonResponse
    {
        $this->exigir('rh_assiduidade_view', 'rh_assid_config', 'rh_ferias_view');
        $d = $r->validate(['ano' => ['required', 'integer', 'between:1900,2200']]);

        return RespostaApi::sucesso(ServicoCalendarioRH::feriadosNacionais((int) $d['ano']), 'Feriados nacionais de Angola (confirme antes de gravar).');
    }

    /** POST /rh/assiduidade/registos/importar-relogio — leitura do relógio pelo servidor (M-12). */
    public function importarRelogio(Request $r, ServicoAssiduidade $assiduidade): JsonResponse
    {
        $this->exigir('rh_assid_registar');
        $d = $r->validate(['substituir' => ['nullable', 'boolean']]);
        $res = $assiduidade->importarDoRelogio((bool) ($d['substituir'] ?? true));

        return RespostaApi::sucesso($res, "Relógio lido: {$res['gravados']} registo(s) gravado(s), {$res['ignorados']} ignorado(s), ".count($res['erros']).' erro(s).');
    }

    /** GET /rh/salarios/periodos/{id}/recibos/{colaborador}/pdf — recibo em PDF (2 vias). */
    public function reciboPdf(int $id, int $colaborador, ServicoRecibosSalario $recibos): Response
    {
        $this->exigir('rh_recibos_emitir', 'rh_rel_recibos_view');
        $p = PeriodoProcessamentoSalarial::query()->findOrFail($id);
        $r = $recibos->resultados($p, [$colaborador])[0];

        return response($recibos->pdf($p, $r), 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.ServicoRecibosSalario::nomeFicheiro($p, $r).'"', 'Cache-Control' => 'no-store']);
    }

    /** GET /rh/salarios/periodos/{id}/recibos-zip?colaboradores=1,2 — ZIP com um PDF por colaborador (rh_recibos_emitir). */
    public function recibosZip(Request $r, int $id, ServicoRecibosSalario $recibos): Response
    {
        $this->exigir('rh_recibos_emitir');
        $d = $r->validate(['colaboradores' => ['nullable', 'string', 'max:20000']]);
        $ids = ! empty($d['colaboradores']) ? array_values(array_filter(array_map('intval', explode(',', $d['colaboradores'])))) : null;
        $p = PeriodoProcessamentoSalarial::query()->findOrFail($id);
        $caminho = $recibos->zip($p, $ids);
        [$mes, $ano] = explode('/', $p->mes_ano);

        return response()->download($caminho, "Recibos_{$ano}{$mes}.zip", ['Content-Type' => 'application/zip', 'Cache-Control' => 'no-store'])
            ->deleteFileAfterSend();
    }
}
