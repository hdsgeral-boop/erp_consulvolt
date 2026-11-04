<?php

namespace App\Http\Controllers\Api\Vendas;

use App\Http\Controllers\Controller;
use App\Models\SerieFaturacaoEletronica;
use App\Models\TipoRetencaoFonte;
use App\Services\Vendas\ServicoConfigVendas;
use App\Services\Vendas\ServicoRetencaoFonte;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /api/vendas/configuracao — contas de vendas (vendas_config) e séries de numeração (vendas_fe_config). */
final class ConfigVendasController extends Controller
{
    public function __construct(private readonly ServicoConfigVendas $config, private readonly ServicoRetencaoFonte $retencoes) {}

    public function contas(): JsonResponse
    {
        $this->exigir('vendas_faturacao_view', 'vendas_config');

        return RespostaApi::sucesso($this->config->todas(), 'Contas de vendas obtidas com sucesso.');
    }

    public function definirContas(Request $request): JsonResponse
    {
        $this->exigir('vendas_config');
        $regras = [];
        foreach (array_keys(ServicoConfigVendas::CHAVES) as $chave) {
            $regras["contas.{$chave}"] = ['nullable', 'string', 'max:20'];
        }
        $d = $request->validate(['contas' => ['required', 'array']] + $regras);
        $this->config->definir($d['contas']);

        return RespostaApi::sucesso($this->config->todas(), 'Contas de vendas actualizadas.');
    }

    /** GET configuracao/retencoes — tipos de retenção na fonte (quem emite recibos precisa de os ler) e a conta do imposto retido. */
    public function tiposRetencao(Request $request): JsonResponse
    {
        $this->exigir('vendas_faturacao_view', 'vendas_config');
        $tipos = $this->retencoes->tipos($request->boolean('so_activos'));

        return RespostaApi::sucesso(['tipos' => $tipos, 'conta_imposto_padrao' => $this->retencoes->contaDoImposto(), 'conta_sugerida' => ServicoRetencaoFonte::CONTA_POR_OMISSAO],
            'Tipos de retenção na fonte obtidos com sucesso.');
    }

    public function guardarTipoRetencao(Request $request, ?int $tipo = null): JsonResponse
    {
        $this->exigir('vendas_config');
        $d = $request->validate([
            'codigo' => ['required', 'string', 'max:30'], 'descricao' => ['required', 'string', 'max:200'],
            'percentagem' => ['required', 'numeric'], 'conta_imposto' => ['nullable', 'string', 'max:20'], 'ativo' => ['nullable', 'boolean'],
        ], [], ['codigo' => 'código', 'descricao' => 'descrição', 'percentagem' => 'percentagem', 'conta_imposto' => 'conta do imposto']);
        $registo = $this->retencoes->guardarTipo($d, $tipo ? TipoRetencaoFonte::query()->findOrFail($tipo) : null);

        return $tipo ? RespostaApi::sucesso($registo, 'Tipo de retenção actualizado.') : RespostaApi::criado($registo, 'Tipo de retenção criado.');
    }

    public function eliminarTipoRetencao(int $tipo): JsonResponse
    {
        $this->exigir('vendas_config');
        $this->retencoes->eliminarTipo(TipoRetencaoFonte::query()->findOrFail($tipo));

        return RespostaApi::sucesso(null, 'Tipo de retenção eliminado.');
    }

    public function series(Request $request): JsonResponse
    {
        $this->exigir('vendas_faturacao_view', 'vendas_fe_config');
        $f = $request->validate(['ano' => ['nullable', 'integer', 'min:2000', 'max:2100'], 'tipo' => ['nullable', 'string', 'max:5']]);
        $series = SerieFaturacaoEletronica::query()
            ->when($f['ano'] ?? null, fn ($q, $v) => $q->where('ano', $v))
            ->when($f['tipo'] ?? null, fn ($q, $v) => $q->where('tipo', $v))
            ->orderByDesc('ano')->orderBy('tipo')->orderBy('codigo')
            ->get(['id', 'codigo', 'tipo', 'ano', 'origem', 'origem_nome', 'estado', 'contingencia', 'proximo_numero', 'ultima_data', 'agt_ultimo_numero']);

        return RespostaApi::sucesso($series, 'Séries obtidas com sucesso.');
    }
}
