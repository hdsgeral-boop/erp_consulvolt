<?php

namespace App\Http\Controllers\Api\Reparticao;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\CriterioReparticaoUN;
use App\Models\EncomendaCompra;
use App\Models\FaturaCompra;
use App\Models\PedidoCompra;
use App\Models\ReciboVenda;
use App\Models\Venda;
use App\Services\Reparticao\ServicoReparticao;
use App\Support\Api\RespostaApi;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * /api/reparticao — Fase C (ADR-071): critérios de repartição por unidade de negócio (Configurações › Unidades de negócio ›
 * «Critérios de repartição»), pré-visualização/repartição de um valor (componente de alocação e «Repartir linha») e a
 * alocação gravada em cada objecto (colaborador, pedido/encomenda/factura de compra, venda, recibo).
 * Permissões verificadas ANTES da validação (OWASP A01); tudo no âmbito da empresa activa.
 */
final class ReparticaoController extends Controller
{
    /** Consulta dos critérios: quem gere as tabelas auxiliares e quem aloca unidades num documento ou ficha. */
    private const VER = ['tabelas_aux_view', 'aux_gerir', 'lancamentos_post', 'lancamentos_editar', 'teso_doc_emitir', 'vendas_fat_emitir',
        'vendas_recibos', 'compras_ped_criar', 'compras_fact_registar', 'compras_enc_criar', 'colaboradores_detail'];

    /** Objectos com alocação: model, permissões de consulta e de alteração, e o que impede a alteração. */
    private const OBJECTOS = [
        'colaborador' => [Colaborador::class, ['colaboradores_view', 'colaboradores_detail'], ['colaboradores_detail']],
        'pedido_compra' => [PedidoCompra::class, ['compras_pedidos_view', 'compras_ped_criar'], ['compras_ped_criar']],
        'encomenda_compra' => [EncomendaCompra::class, ['compras_encomendas_view', 'compras_enc_criar'], ['compras_enc_criar']],
        'fatura_compra' => [FaturaCompra::class, ['compras_faturacao_view', 'compras_fact_registar'], ['compras_fact_registar']],
        'venda' => [Venda::class, ['vendas_faturacao_view', 'vendas_fat_emitir'], ['vendas_fat_emitir']],
        'recibo_venda' => [ReciboVenda::class, ['vendas_faturacao_view', 'vendas_recibos'], ['vendas_recibos']],
    ];

    public function __construct(private readonly ServicoReparticao $reparticao) {}

    // ───────────── Critérios (R1) ─────────────

    public function formas(): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso(collect(ServicoReparticao::CRITERIOS)->map(fn ($c, $k) => ['codigo' => $k] + $c)->values(), 'Formas de repartição obtidas.');
    }

    public function index(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);
        $d = $r->validate(['activos' => ['nullable', 'boolean']]);

        return RespostaApi::sucesso($this->reparticao->listar(filter_var($d['activos'] ?? false, FILTER_VALIDATE_BOOLEAN)), 'Critérios de repartição obtidos.');
    }

    public function show(int $criterio): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso(CriterioReparticaoUN::query()->findOrFail($criterio), 'Critério obtido.');
    }

    public function store(Request $r): JsonResponse
    {
        $this->exigir('aux_gerir');

        return RespostaApi::criado($this->reparticao->gravar($this->validarCriterio($r, true)), 'Critério de repartição criado.');
    }

    public function update(Request $r, int $criterio): JsonResponse
    {
        $this->exigir('aux_gerir');
        $c = CriterioReparticaoUN::query()->findOrFail($criterio);

        return RespostaApi::sucesso($this->reparticao->gravar($this->validarCriterio($r, false), $c), 'Critério de repartição actualizado.');
    }

    public function destroy(int $criterio): JsonResponse
    {
        $this->exigir('aux_eliminar');
        $this->reparticao->eliminar(CriterioReparticaoUN::query()->findOrFail($criterio));

        return RespostaApi::sucesso(null, 'Critério de repartição eliminado.');
    }

    // ───────────── Pré-visualização / repartição de um valor ─────────────

    /** Percentagens (e, com valor, as parcelas) de uma alocação ainda não gravada — AlocacaoUN.previa / ReparticaoPagina.escolher. */
    public function simular(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);
        $d = $r->validate(['valor' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99']] + ServicoReparticao::regras());
        $alocacao = $this->reparticao->normalizar($d['unidades_alocadas'] ?? null)
            ?? throw new ErroNegocio('Escolha o critério e aloque as unidades.', 'ALOCACAO_VAZIA', 422);
        $valor = isset($d['valor']) ? number_format((float) $d['valor'], 2, '.', '') : '0.00';

        return RespostaApi::sucesso(bccomp($valor, '0', 2) > 0
            ? ['alocacao' => $alocacao] + $this->reparticao->repartir($valor, $alocacao)
            : ['alocacao' => $alocacao, 'linhas' => $this->reparticao->pesos($alocacao), 'total' => null], 'Repartição calculada.');
    }

    // ───────────── Alocação gravada no objecto (R2) ─────────────

    public function alocacao(string $tipo, int $id): JsonResponse
    {
        [$classe, $ver] = self::OBJECTOS[$tipo] ?? abort(404);
        $this->exigir(...$ver);
        /** @var Model $obj */
        $obj = $classe::query()->findOrFail($id);
        $alocacao = ServicoReparticao::ler($obj->getAttributes()['unidades_alocadas'] ?? null);

        return RespostaApi::sucesso(['tipo' => $tipo, 'id' => $obj->getKey(), 'unidades_alocadas' => ServicoReparticao::vazia($alocacao) ? null : $alocacao,
            'unidade_negocio_id' => $obj->getAttributes()['unidade_negocio_id'] ?? null], 'Alocação obtida.');
    }

    public function gravarAlocacao(Request $r, string $tipo, int $id): JsonResponse
    {
        [$classe, , $alterar] = self::OBJECTOS[$tipo] ?? abort(404);
        $this->exigir(...$alterar);
        $d = $r->validate(ServicoReparticao::regras());
        $alocacao = $this->reparticao->normalizar($d['unidades_alocadas'] ?? null);

        $obj = DB::transaction(function () use ($classe, $id, $alocacao, $tipo) {
            /** @var Model $obj */
            $obj = $classe::query()->lockForUpdate()->findOrFail($id);
            $this->exigirAlteravel($tipo, $obj);
            $obj->forceFill(['unidades_alocadas' => $alocacao === null ? null : ($obj->hasCast('unidades_alocadas') ? $alocacao : json_encode($alocacao))])->save();

            return $obj;
        });

        return RespostaApi::sucesso(['tipo' => $tipo, 'id' => $obj->getKey(), 'unidades_alocadas' => $alocacao],
            $alocacao ? 'Alocação das unidades de negócio gravada.' : 'Repartição retirada: o registo usa a unidade do campo normal.');
    }

    /** Documentos contabilizados ou anulados não mudam (a contabilização já foi feita com a alocação que tinham). */
    private function exigirAlteravel(string $tipo, Model $obj): void
    {
        $a = $obj->getAttributes();
        if (! empty($a['contabilizado'])) {
            throw new ErroNegocio('O documento já está contabilizado: a repartição não pode ser alterada (descontabilize-o primeiro).', 'DOCUMENTO_CONTABILIZADO', 422);
        }
        if ($tipo !== 'colaborador' && in_array($a['estado'] ?? null, ['ANULADO', 'ANULADA'], true)) {
            throw new ErroNegocio('Documento anulado: a repartição não pode ser alterada.', 'DOCUMENTO_ANULADO', 422);
        }
    }

    /** @return array<string, mixed> */
    private function validarCriterio(Request $r, bool $criar): array
    {
        $exige = $criar ? 'required' : 'sometimes';

        return $r->validate([
            'codigo' => [$exige, 'string', 'max:20', 'regex:/^[A-Za-z0-9._-]{1,20}$/'],
            'nome' => [$exige, 'string', 'max:255'],
            'criterio' => [$exige, Rule::in(array_keys(ServicoReparticao::CRITERIOS))],
            'indicador' => ['sometimes', 'nullable', 'string', 'max:100'],
            'periodo_de' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'periodo_ate' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'notas' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'ativo' => ['sometimes', 'boolean'],
        ], [], ['codigo' => 'código', 'criterio' => 'forma de repartir', 'periodo_de' => 'vendas desde', 'periodo_ate' => 'vendas até']);
    }
}
