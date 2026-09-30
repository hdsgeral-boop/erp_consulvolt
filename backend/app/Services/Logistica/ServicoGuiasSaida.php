<?php

namespace App\Services\Logistica;

use App\Exceptions\ErroNegocio;
use App\Models\GuiaSaida;
use App\Models\ItemGuiaSaida;
use App\Models\Produto;
use App\Models\Terceiro;
use App\Services\Contabilidade\LocalizadorLancamentos;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Services\Sistema\ServicoNumeracao;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Guias de saída do armazém (delivery_notes, js/ui_warehouse.js:854-1008). As saídas para CLIENTE fazem-se agora
 * pela guia de remessa das Vendas (GR, com stock e CMV — ADR-043); aqui ficam as guias de CONSUMO interno
 * (saída para uma área, custo imputado) e a VENDA AO BALCÃO do POS de armazém (ADR-050). As guias VENDA/BACK_TO_BACK
 * do legado migram só para consulta.
 * Correcções (ADR-043):
 *   - numeração GE AAAA/NNNN sem repetições (o legado contava as guias + 1 e apagava-as: números duplicados);
 *   - linhas próprias da guia (no legado partilhavam a tabela com as recepções e apagavam-se umas às outras);
 *   - o movimento tem tipo e sentido (o legado gravava a saída sem tipo — nem entrada nem saída nos relatórios);
 *   - anular deixa a guia ANULADA e repõe o stock (o legado apagava-a); contabilizada, é preciso estornar primeiro;
 *   - contabilização única (no legado a guia e a sua «GR LOG» espelho podiam ser contabilizadas as duas).
 * Venda ao balcão (finalizePOSWhSale, js/ui_pos_armazem.js:461-578) — só guia de saída, sem factura nem pagamento, como
 * no legado; correcções: numeração GE POS AAAA/NNNN pelo maior número (o legado: contagem de todas as guias + 1),
 * stock do armazém sem negativos e ao custo médio (o legado caía no preço de venda sem custo e cortava o total a zero),
 * CMV no diário GS com n.º de lançamento e estornável (o legado adivinhava o diário — LO, GS ou o primeiro — e gravava
 * linhas soltas sem n.º de lançamento), tudo numa transacção.
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
            $g = GuiaSaida::create(['numero_documento' => $this->numero('GE', $data), 'data' => $data, 'tipo' => 'CONSUMO', 'armazem_id' => $d['armazem_id'],
                'area_rececao' => $d['area_rececao'], 'estado' => 'CONCLUIDO', 'contabilizado' => false, 'observacoes' => $d['observacoes'] ?? null,
                'criado_por' => Auth::user()?->nome_utilizador]);
            $this->darSaida($g, $d['linhas'], "Guia de consumo {$g->numero_documento} ({$d['area_rececao']})");

            return $g->refresh();
        });
    }

    /**
     * Venda ao balcão do POS de armazém: guia de saída para o cliente (ou «Cliente de balcão»), stock ao custo médio e CMV
     * lançado logo (D custo / C inventário, diário GS), como o legado lançava na emissão.
     *
     * @param  array{armazem_id: int, terceiro_id?: ?int, observacoes?: ?string, linhas: list<array{produto_id: int, quantidade: mixed}>}  $d
     */
    public function emitirVendaBalcao(array $d): GuiaSaida
    {
        $cliente = isset($d['terceiro_id']) ? Terceiro::query()->findOrFail($d['terceiro_id']) : null;
        if ($cliente && ! $cliente->eCliente()) {
            throw new ErroNegocio('O destinatário tem de estar registado como cliente.', 'CLIENTE_INVALIDO', 422);
        }
        if (! $d['linhas']) {
            throw new ErroNegocio('Indique os produtos a expedir.', 'SEM_LINHAS', 422);
        }

        return DB::transaction(function () use ($d, $cliente) {
            $data = now()->toDateString();
            $g = GuiaSaida::create(['numero_documento' => $this->numero('GE POS', $data), 'data' => $data, 'tipo' => GuiaSaida::VENDA_BALCAO,
                'terceiro_id' => $cliente?->id, 'armazem_id' => $d['armazem_id'], 'area_rececao' => $cliente?->nome ?? 'Cliente de balcão', 'estado' => 'CONCLUIDO',
                'contabilizado' => false, 'observacoes' => $d['observacoes'] ?? null, 'criado_por' => Auth::user()?->nome_utilizador]);
            $this->darSaida($g, $d['linhas'], "Saída POS {$g->numero_documento} (".($cliente?->nome ?? 'Cliente de balcão').')', $cliente?->id);
            // o CMV lança-se já; sem contas da logística ou com o exercício fechado a guia fica emitida e por contabilizar (aviso),
            // para não parar o balcão — contabiliza-se depois em Logística › Guias de saída. Produtos sem custo: nada a lançar.
            $aviso = null;
            try {
                $this->linhasCmv($g) && DB::transaction(fn () => $this->lancar($g));
            } catch (ErroNegocio $e) {
                $aviso = $e->getMessage();
            }
            $g->refresh()->avisoContabilizacao = $aviso;

            return $g;
        });
    }

    /** Consumo ou venda ao balcão: D custo do produto / C inventário (diário GS). */
    public function contabilizar(GuiaSaida $g): GuiaSaida
    {
        if (! $g->eGerida() || $g->estado === 'ANULADA') {
            throw new ErroNegocio('Só as guias de consumo e de venda ao balcão não anuladas se contabilizam aqui (as de venda contabilizam-se na GR).', 'NAO_CONTABILIZAVEL', 422);
        }
        if ($g->contabilizado) {
            throw new ErroNegocio('A guia já está contabilizada.', 'JA_CONTABILIZADO', 422);
        }
        if (! $this->linhasCmv($g)) {
            throw new ErroNegocio('A guia não tem valor a contabilizar (produtos sem custo).', 'NADA_A_CONTABILIZAR', 422);
        }

        return DB::transaction(fn () => $this->lancar($g));
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
        if (! $g->eGerida()) {
            throw new ErroNegocio('As guias de venda do legado anulam-se pela guia de remessa das Vendas.', 'NAO_ANULAVEL', 422);
        }
        if ($g->contabilizado) {
            throw new ErroNegocio('A guia está contabilizada: descontabilize-a primeiro (estorno).', 'DOCUMENTO_CONTABILIZADO', 422);
        }

        return DB::transaction(function () use ($g, $motivo) {
            foreach (ItemGuiaSaida::query()->where('guia_saida_id', $g->id)->get() as $i) {
                $this->stock->entrada($i->produto_id, (int) $g->armazem_id, (string) $i->quantidade, (string) ($i->custo_unitario_kz ?? 0), now()->toDateString(),
                    "Anulação da guia {$g->numero_documento}: {$motivo}", $g->terceiro_id, null, ['documento_tipo' => 'GUIA_SAIDA', 'documento_id' => $g->id]);
            }
            $g->update(['estado' => 'ANULADA', 'anulado_em' => now(), 'motivo_anulacao' => $motivo]);

            return $g->refresh();
        });
    }

    /** Próximo n.º «<prefixo> AAAA/NNNN», a continuar o maior já emitido com o mesmo prefixo (o legado: contagem + 1). */
    private function numero(string $prefixo, string $data): string
    {
        $ano = substr($data, 0, 4);
        $chave = $prefixo === 'GE' ? "logistica:guia_saida:{$ano}" : 'logistica:guia_saida:'.strtolower(str_replace(' ', '_', $prefixo)).":{$ano}";
        $n = $this->numeracao->proximo($this->contexto->obrigatorio(), $chave, fn () => (int) GuiaSaida::query()
            ->where('numero_documento', 'like', "{$prefixo} {$ano}/%")->get()->map(fn ($g) => (int) substr((string) $g->numero_documento, strlen("{$prefixo} {$ano}/")))->max());

        return sprintf('%s %s/%04d', $prefixo, $ano, $n);
    }

    /** Saída de stock ao custo médio (sem negativos) e linhas da guia com o custo do movimento. */
    private function darSaida(GuiaSaida $g, array $linhas, string $referencia, ?int $terceiro = null): void
    {
        foreach ($linhas as $l) {
            $r = $this->stock->saida((int) $l['produto_id'], (int) $g->armazem_id, (string) $l['quantidade'], null, $g->data->toDateString(), $referencia,
                $terceiro, null, false, ['documento_tipo' => 'GUIA_SAIDA', 'documento_id' => $g->id]);
            ItemGuiaSaida::create(['guia_saida_id' => $g->id, 'produto_id' => (int) $l['produto_id'], 'quantidade' => $l['quantidade'],
                'custo_unitario_kz' => $r['custo_unitario'], 'valor_kz' => $r['movimento']->valor]);
        }
    }

    /** @return list<array{codigo_conta: string, tipo_dc: string, valor: string, terceiro_id?: ?int}> */
    private function linhasCmv(GuiaSaida $g): array
    {
        $linhas = [];
        foreach (ItemGuiaSaida::query()->where('guia_saida_id', $g->id)->get() as $i) {
            if (bccomp((string) $i->valor_kz, '0', 2) <= 0) {
                continue;
            }
            $p = Produto::query()->withTrashed()->findOrFail($i->produto_id);
            foreach ([[$this->config->contaCusto($p), 'D'], [$this->config->contaInventario($p), 'C']] as [$conta, $dc]) {
                $linhas["{$conta}|{$dc}"] ??= ['codigo_conta' => $conta, 'tipo_dc' => $dc, 'valor' => '0.00', 'terceiro_id' => $g->terceiro_id];
                $linhas["{$conta}|{$dc}"]['valor'] = bcadd($linhas["{$conta}|{$dc}"]['valor'], (string) $i->valor_kz, 2);
            }
        }

        return array_values($linhas);
    }

    private function lancar(GuiaSaida $g): GuiaSaida
    {
        $descricao = $g->eVendaBalcao() ? "Venda ao balcão {$g->numero_documento} ({$g->area_rececao})" : "Consumo {$g->numero_documento} ({$g->area_rececao})";
        $lan = $this->lancamentos->criar(['diario_id' => $this->localizador->diario('GS', 'Guias de saída')->id, 'data_documento' => $g->data->toDateString(),
            'numero_documento' => $g->numero_documento, 'descricao' => $descricao, 'tipo_origem' => 'LOGISTICA',
            'linhas' => $this->linhasCmv($g)])->first()->numero_lan;
        $g->update(['contabilizado' => true, 'numero_lan_contabilizacao' => $lan]);

        return $g->refresh();
    }
}
