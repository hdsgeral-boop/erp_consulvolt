<?php

namespace App\Http\Controllers\Api\Metodo;

use App\Http\Controllers\Controller;
use App\Models\AssuntoMetodoTrabalho;
use App\Models\CentroCusto;
use App\Models\Colaborador;
use App\Models\Produto;
use App\Models\Terceiro;
use App\Models\UnidadeNegocio;
use App\Models\Venda;
use App\Services\Metodo\ServicoAssuntosMetodo;
use App\Services\Metodo\ServicoDeliberacaoMetodo;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * /api/metodo/assuntos — Método de Trabalho, Registo de Assunto 5W1H (Fase G; js/modules/metodo).
 * Permissões (ecrã mt_assuntos): ver = mt_assuntos_view | mt_criar | mt_aprovar; criar = mt_criar | mt_aprovar.
 * Por assunto, o serviço decide quem edita (criador em rascunho, responsável em curso, gestor sempre), quem conclui,
 * cancela ou reabre, e quem delibera (a chefia designada ou `mt_aprovar`, nunca o criador).
 * A permissão é verificada sempre antes da validação (OWASP A01); assuntos de outra empresa dão 404 (EscopoEmpresa)
 * e os de outros utilizadores, a quem não é gestor, 403.
 */
final class AssuntosMetodoController extends Controller
{
    private const VER = ['mt_assuntos_view', 'mt_criar', 'mt_aprovar'];

    private const CRIAR = ['mt_criar', 'mt_aprovar'];

    public function __construct(
        private readonly ServicoAssuntosMetodo $assuntos,
        private readonly ServicoDeliberacaoMetodo $deliberacao,
    ) {}

    public function index(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);
        $f = $r->validate(['estado' => ['nullable', Rule::in(AssuntoMetodoTrabalho::ESTADOS)], 'pesquisa' => ['nullable', 'string', 'max:100']]);

        return RespostaApi::sucesso($this->assuntos->listar($f), 'Assuntos.');
    }

    /** Listas de escolha do formulário (fontes, assuntos_ui.js:37-49). */
    public function opcoes(): JsonResponse
    {
        $this->exigir(...self::VER);
        $terceiros = Terceiro::query()->orderBy('nome')->get(['id', 'nome', 'nif', 'tipo']);

        return RespostaApi::sucesso([
            'colaboradores' => Colaborador::query()->where('estado', 'ACTIVO')->orderBy('nome_completo')->get(['id', 'nome_completo']),
            'fornecedores' => $terceiros->filter(fn ($t) => $t->eFornecedor())->values()->map->only(['id', 'nome', 'nif']),
            'clientes' => $terceiros->filter(fn ($t) => $t->eCliente())->values()->map->only(['id', 'nome', 'nif']),
            'unidades_negocio' => UnidadeNegocio::query()->orderBy('codigo')->get(['id', 'codigo', 'nome']),
            'centros_custo' => CentroCusto::query()->orderBy('codigo')->get(['id', 'codigo', 'descricao']),
            'pode_criar' => $this->pode(self::CRIAR),
            'gestor' => $this->assuntos->eGestor(),
        ], 'Opções do assunto.');
    }

    /** Quem aprovará o que este utilizador submeter (antevisão no formulário). */
    public function aprovador(): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($this->deliberacao->aprovadorDe(), 'Aprovador.');
    }

    public function artigos(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);
        $f = $r->validate(['texto' => ['nullable', 'string', 'max:100']]);
        $termo = isset($f['texto']) && $f['texto'] !== '' ? '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $f['texto']).'%' : null;

        return RespostaApi::sucesso(Produto::query()->where(fn ($q) => $q->whereNull('bloqueado')->orWhere('bloqueado', false))
            ->when($termo, fn ($q, $t) => $q->where(fn ($x) => $x->where('codigo', 'ilike', $t)->orWhere('nome', 'ilike', $t)))
            ->orderBy('codigo')->limit(50)->get(['id', 'codigo', 'nome', 'custo_medio']), 'Artigos.');
    }

    /** Notas de encomenda (NE) não anuladas do cliente — para o projecto externo. */
    public function encomendas(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);
        $f = $r->validate(['cliente_id' => ['required', 'integer', 'min:1']]);

        return RespostaApi::sucesso(Venda::query()->where('tipo_documento', 'NE')->where('cliente_id', $f['cliente_id'])
            ->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'ANULADO'))
            ->orderByDesc('data_emissao')->limit(100)->get(['id', 'numero_documento', 'data_emissao']), 'Encomendas do cliente.');
    }

    public function projetosDisponiveis(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);
        $f = $r->validate(['assunto_id' => ['nullable', 'integer', 'min:1']]);

        return RespostaApi::sucesso($this->assuntos->projetosDisponiveis($f['assunto_id'] ?? null), 'Projectos disponíveis.');
    }

    public function store(Request $r): JsonResponse
    {
        $this->exigir(...self::CRIAR);
        $res = $this->assuntos->gravar($r->validate(self::regras()));

        return RespostaApi::criado(['assunto' => $this->assuntos->detalhe($res['assunto']), 'avisos' => $res['avisos']], 'Assunto gravado.');
    }

    public function show(int $assunto): JsonResponse
    {
        $this->exigir(...self::VER);
        $a = AssuntoMetodoTrabalho::query()->findOrFail($assunto);
        $this->assuntos->exigirVer($a);

        return RespostaApi::sucesso($this->assuntos->detalhe($a), 'Assunto.');
    }

    public function update(Request $r, int $assunto): JsonResponse
    {
        $this->exigir(...self::VER);
        $a = AssuntoMetodoTrabalho::query()->findOrFail($assunto);
        $this->assuntos->exigirVer($a);
        $this->assuntos->exigirEditar($a);   // antes da validação (OWASP A01)
        $res = $this->assuntos->gravar($r->validate(self::regras()), $a);

        return RespostaApi::sucesso(['assunto' => $this->assuntos->detalhe($res['assunto']), 'avisos' => $res['avisos']], 'Assunto gravado.');
    }

    public function historico(int $assunto): JsonResponse
    {
        $this->exigir(...self::VER);
        $a = AssuntoMetodoTrabalho::query()->findOrFail($assunto);
        $this->assuntos->exigirVer($a);

        return RespostaApi::sucesso(['numero' => $a->numero, 'registos' => $this->assuntos->historico($a)], 'Histórico do assunto.');
    }

    public function submeter(int $assunto): JsonResponse
    {
        $this->exigir(...self::VER);
        $a = AssuntoMetodoTrabalho::query()->findOrFail($assunto);
        $this->assuntos->exigirVer($a);
        $res = $this->deliberacao->submeter($a);

        return RespostaApi::sucesso(['aprovado' => $res['aprovado'], 'aprovador' => $res['aprovador'], 'assunto' => $this->assuntos->detalhe($res['assunto'])],
            $res['aprovado'] ? 'Aprovado automaticamente — o assunto está em curso.' : "Submetido. Aguarda a aprovação de {$res['aprovador']['nome']}.");
    }

    public function decidir(Request $r, int $assunto): JsonResponse
    {
        $this->exigir(...self::VER);
        $a = AssuntoMetodoTrabalho::query()->findOrFail($assunto);
        $this->assuntos->exigirVer($a);
        $this->deliberacao->exigirPodeDecidir($a);   // antes da validação (OWASP A01)
        $d = $r->validate(['accao' => ['required', Rule::in(['APROVAR', 'DEVOLVER', 'RECUSAR'])], 'nota' => ['nullable', 'string', 'max:500']]);
        $res = $this->deliberacao->decidir($a, $d['accao'], $d['nota'] ?? null);

        return RespostaApi::sucesso($this->assuntos->detalhe($res), ['APROVAR' => 'Aprovado — o assunto está em curso.',
            'DEVOLVER' => 'Assunto devolvido a rascunho para correcção.', 'RECUSAR' => 'Assunto recusado e cancelado.'][$d['accao']]);
    }

    public function estado(Request $r, int $assunto): JsonResponse
    {
        $this->exigir(...self::VER);
        $a = AssuntoMetodoTrabalho::query()->findOrFail($assunto);
        $this->assuntos->exigirVer($a);
        $d = $r->validate(['estado' => ['required', Rule::in(['CONCLUIDO', 'CANCELADO', 'RASCUNHO', 'EM_CURSO'])], 'nota' => ['nullable', 'string', 'max:500']]);
        $res = $this->assuntos->mudarEstado($a, $d['estado'], $d['nota'] ?? null);

        return RespostaApi::sucesso($this->assuntos->detalhe($res), 'Assunto em «'.ServicoAssuntosMetodo::ROTULOS_ESTADO[$res->estado].'».');
    }

    private function pode(array $chaves): bool
    {
        return Gate::any($chaves);
    }

    /** Forma do pedido; as regras de negócio (e o que é obrigatório fora de rascunho) estão no serviço. */
    private static function regras(): array
    {
        return [
            'titulo' => ['required', 'string', 'max:120'],
            'descricao' => ['nullable', 'string', 'max:5000'],
            'responsaveis' => ['nullable', 'array', 'max:50'],
            'responsaveis.*.tipo' => ['required', Rule::in(AssuntoMetodoTrabalho::TIPOS_RESPONSAVEL)],
            'responsaveis.*.ref_id' => ['nullable', 'integer', 'min:1'],
            'responsaveis.*.nome' => ['required', 'string', 'max:150'],
            'responsavel_principal' => ['nullable', 'string', 'max:150'],
            'data_inicio' => ['nullable', 'date_format:Y-m-d'],
            'data_fim' => ['nullable', 'date_format:Y-m-d'],
            'local_tipo' => ['nullable', Rule::in(AssuntoMetodoTrabalho::LOCAIS)],
            'unidade_negocio_id' => ['nullable', 'integer', 'min:1'],
            'cliente_id' => ['nullable', 'integer', 'min:1'],
            'encomenda' => ['nullable', 'string', 'max:255'],
            'morada' => ['nullable', 'string', 'max:500'],
            'fundamentacao' => ['nullable', 'string', 'max:4000'],
            'causa_raiz' => ['nullable', 'string', 'max:4000'],
            'recursos' => ['nullable', 'string', 'max:5000'],
            'custo_estimado' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'codigo_moeda' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z]{3}$/'],
            'metodo_resolucao' => ['nullable', Rule::in(AssuntoMetodoTrabalho::METODOS)],
            'projeto_modo' => ['nullable', Rule::in(AssuntoMetodoTrabalho::MODOS_PROJETO)],
            'projeto_id' => ['nullable', 'integer', 'min:1'],
            'centro_custo_id' => ['nullable', 'integer', 'min:1'],
            'encomenda_venda_id' => ['nullable', 'integer', 'min:1'],
            'gerar_pedido_compra' => ['nullable', 'boolean'],
            'linhas_pedido_compra' => ['nullable', 'array', 'max:50'],
            'linhas_pedido_compra.*.produto_id' => ['required', 'integer', 'min:1'],
            'linhas_pedido_compra.*.quantidade' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'linhas_pedido_compra.*.preco_unitario' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'linhas_pedido_compra.*.descricao' => ['nullable', 'string', 'max:255'],
            'anexos' => ['nullable', 'array', 'max:30'],
            'anexos.*.nome' => ['nullable', 'string', 'max:150'],
            'anexos.*.url' => ['required', 'string', 'max:2000'],
            'validar_tudo' => ['nullable', 'boolean'],
        ];
    }
}
