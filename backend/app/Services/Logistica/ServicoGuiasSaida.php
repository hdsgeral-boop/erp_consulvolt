<?php

namespace App\Services\Logistica;

use App\Exceptions\ErroNegocio;
use App\Models\GuiaSaida;
use App\Models\ItemGuiaSaida;
use App\Models\Produto;
use App\Services\Contabilidade\LocalizadorLancamentos;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Services\Sistema\ServicoNumeracao;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Guias de saída do armazém (delivery_notes, js/ui_warehouse.js:854-1008). As saídas para CLIENTE fazem-se agora
 * pela guia de remessa das Vendas (GR, com stock e CMV — ADR-043); aqui ficam as guias de CONSUMO interno
 * (saída para uma área, custo imputado). As guias VENDA/BACK_TO_BACK do legado migram só para consulta.
 * Correcções (ADR-043):
 *   - numeração GE AAAA/NNNN sem repetições (o legado contava as guias + 1 e apagava-as: números duplicados);
 *   - linhas próprias da guia (no legado partilhavam a tabela com as recepções e apagavam-se umas às outras);
 *   - o movimento tem tipo e sentido (o legado gravava a saída sem tipo — nem entrada nem saída nos relatórios);
 *   - anular deixa a guia ANULADA e repõe o stock (o legado apagava-a); contabilizada, é preciso estornar primeiro;
 *   - contabilização única (no legado a guia e a sua «GR LOG» espelho podiam ser contabilizadas as duas).
 */
final class ServicoGuiasSaida
{
    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoStock $stock,
        private readonly ServicoNumeracao $numeracao,
        private readonly ServicoConfigLogistica $config,
        private readonly ServicoLancamentos $lancamentos,
        private readonly LocalizadorLancamentos $localizador,
    ) {}

    /** @param  array{armazem_id: int, data: string, area_rececao: string, observacoes?: ?string, centro_custo_id?: ?int, linhas: list<array{produto_id: int, quantidade: mixed}>}  $d */
    public function emitirConsumo(array $d): GuiaSaida
    {
        return DB::transaction(function () use ($d) {
            $data = substr($d['data'], 0, 10);
            $ano = substr($data, 0, 4);
            $n = $this->numeracao->proximo($this->contexto->obrigatorio(), "logistica:guia_saida:{$ano}", fn () => (int) GuiaSaida::query()
                ->where('numero_documento', 'like', "GE {$ano}/%")->get()->map(fn ($g) => (int) substr((string) $g->numero_documento, strlen("GE {$ano}/")))->max());
            $g = GuiaSaida::create(['numero_documento' => sprintf('GE %s/%04d', $ano, $n), 'data' => $data, 'tipo' => 'CONSUMO', 'armazem_id' => $d['armazem_id'],
                'area_rececao' => $d['area_rececao'], 'estado' => 'CONCLUIDO', 'contabilizado' => false, 'observacoes' => $d['observacoes'] ?? null,
                'criado_por' => Auth::user()?->nome_utilizador]);
            foreach ($d['linhas'] as $l) {
                $r = $this->stock->saida((int) $l['produto_id'], (int) $d['armazem_id'], (string) $l['quantidade'], null, $data, "Guia de consumo {$g->numero_documento} ({$d['area_rececao']})",
                    null, null, false, ['documento_tipo' => 'GUIA_SAIDA', 'documento_id' => $g->id]);
                ItemGuiaSaida::create(['guia_saida_id' => $g->id, 'produto_id' => (int) $l['produto_id'], 'quantidade' => $l['quantidade'],
                    'custo_unitario_kz' => $r['custo_unitario'], 'valor_kz' => $r['movimento']->valor]);
            }

            return $g->refresh();
        });
    }

    /** Consumo: D custo do produto / C inventário (diário GS). */
    public function contabilizar(GuiaSaida $g): GuiaSaida
    {
        if ($g->tipo !== 'CONSUMO' || $g->estado === 'ANULADA') {
            throw new ErroNegocio('Só as guias de consumo não anuladas se contabilizam aqui (as de venda contabilizam-se na GR).', 'NAO_CONTABILIZAVEL', 422);
        }
        if ($g->contabilizado) {
            throw new ErroNegocio('A guia já está contabilizada.', 'JA_CONTABILIZADO', 422);
        }

        return DB::transaction(function () use ($g) {
            $linhas = [];
            foreach (ItemGuiaSaida::query()->where('guia_saida_id', $g->id)->get() as $i) {
                if (bccomp((string) $i->valor_kz, '0', 2) <= 0) {
                    continue;
                }
                $p = Produto::query()->withTrashed()->findOrFail($i->produto_id);
                foreach ([[$this->config->contaCusto($p), 'D'], [$this->config->contaInventario($p), 'C']] as [$conta, $dc]) {
                    $linhas["{$conta}|{$dc}"] ??= ['codigo_conta' => $conta, 'tipo_dc' => $dc, 'valor' => '0.00'];
                    $linhas["{$conta}|{$dc}"]['valor'] = bcadd($linhas["{$conta}|{$dc}"]['valor'], (string) $i->valor_kz, 2);
                }
            }
            if (! $linhas) {
                throw new ErroNegocio('A guia não tem valor a contabilizar (produtos sem custo).', 'NADA_A_CONTABILIZAR', 422);
            }
            $lan = $this->lancamentos->criar(['diario_id' => $this->localizador->diario('GS', 'Guias de saída')->id, 'data_documento' => $g->data->toDateString(),
                'numero_documento' => $g->numero_documento, 'descricao' => "Consumo {$g->numero_documento} ({$g->area_rececao})", 'tipo_origem' => 'LOGISTICA',
                'linhas' => array_values($linhas)])->first()->numero_lan;
            $g->update(['contabilizado' => true, 'numero_lan_contabilizacao' => $lan]);

            return $g->refresh();
        });
    }

    public function descontabilizar(GuiaSaida $g, string $motivo): GuiaSaida
    {
        if (! $g->contabilizado) {
            throw new ErroNegocio('A guia não está contabilizada.', 'NAO_CONTABILIZADO', 422);
        }

        return DB::transaction(function () use ($g, $motivo) {
            $this->lancamentos->estornar($this->localizador->localizar($g->numero_lan_contabilizacao, (string) $g->numero_documento), $motivo);
            $g->update(['contabilizado' => false, 'numero_lan_contabilizacao' => null]);

            return $g->refresh();
        });
    }

    public function anular(GuiaSaida $g, string $motivo): GuiaSaida
    {
        if ($g->estado === 'ANULADA') {
            throw new ErroNegocio('A guia já está anulada.', 'DOCUMENTO_ANULADO', 422);
        }
        if ($g->tipo !== 'CONSUMO') {
            throw new ErroNegocio('As guias de venda do legado anulam-se pela guia de remessa das Vendas.', 'NAO_ANULAVEL', 422);
        }
        if ($g->contabilizado) {
            throw new ErroNegocio('A guia está contabilizada: descontabilize-a primeiro (estorno).', 'DOCUMENTO_CONTABILIZADO', 422);
        }

        return DB::transaction(function () use ($g, $motivo) {
            foreach (ItemGuiaSaida::query()->where('guia_saida_id', $g->id)->get() as $i) {
                $this->stock->entrada($i->produto_id, (int) $g->armazem_id, (string) $i->quantidade, (string) ($i->custo_unitario_kz ?? 0), now()->toDateString(),
                    "Anulação da guia {$g->numero_documento}: {$motivo}", null, null, ['documento_tipo' => 'GUIA_SAIDA', 'documento_id' => $g->id]);
            }
            $g->update(['estado' => 'ANULADA', 'anulado_em' => now(), 'motivo_anulacao' => $motivo]);

            return $g->refresh();
        });
    }
}
