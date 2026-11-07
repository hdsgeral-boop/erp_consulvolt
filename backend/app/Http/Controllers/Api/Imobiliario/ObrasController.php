<?php

namespace App\Http\Controllers\Api\Imobiliario;

use App\Http\Controllers\Controller;
use App\Http\Resources\Imobiliario\ImobiliarioResource;
use App\Models\Armazem;
use App\Models\FichaApetrechamento;
use App\Models\Fracao;
use App\Models\OrdemTrabalhoImo;
use App\Models\Produto;
use App\Models\VistoriaReserva;
use App\Services\Imobiliario\CalculadoraObras;
use App\Services\Imobiliario\ServicoObrasImoveis;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Apetrechamento e manutenção: ordens de trabalho (Onda 4, ecrã imo_obras). Ver: imo_obras_view. Criar, editar, apagar, necessidades, abrir o
 * apetrechamento de uma fracção e a ordem dos danos de uma reserva: imo_obra_editar. Aprovar: imo_obra_aprovar. Pedir material: imo_obra_pedir.
 * Registar entregas (actualizar a recepção ligada): imo_obra_receber. Começar, concluir e cancelar: imo_obra_fechar. Debitar ao inquilino e desfazer:
 * imo_sub_debitar. Todas recusadas no servidor, antes da validação (quem aprova não recebe e quem debita não aprova rendas: pares do catálogo).
 *  GET  /imobiliario/obras/listas|artigos|armazens|resumo|avisos         enumerações, artigos, armazéns, indicadores
 *  GET|POST /imobiliario/obras/ordens;  GET|PUT|DELETE …/{id}            lista, nova, ficha, editar, apagar
 *  POST /imobiliario/obras/ordens/{id}/aprovar|iniciar|concluir|cancelar|pedir|sincronizar|debitar|desfazer-debito
 *  GET|PUT /imobiliario/obras/ordens/{id}/necessidades                    necessidades da ordem;  GET …/plano-pedido?armazem_id=
 *  POST /imobiliario/obras/fracoes/{id}/abrir-apetrechamento              ordem pelo que falta segundo a ficha
 *  POST /imobiliario/obras/vistorias/{id}/ordem                           ordem de reparação dos danos de uma reserva
 */
final class ObrasController extends Controller
{
    private const VER = ['imo_obras_view'];

    public function __construct(private readonly ServicoObrasImoveis $obras) {}

    public function listas(): JsonResponse
    {
        $this->exigir(...self::VER);
        $rot = fn (array $m) => collect($m)->map(fn ($v, $k) => ['chave' => $k, 'rotulo' => is_array($v) ? $v['rot'] : $v] + (is_array($v) && isset($v['ajuda']) ? ['ajuda' => $v['ajuda']] : []))->values()->all();

        return RespostaApi::sucesso(new ImobiliarioResource(['tipos' => $rot(CalculadoraObras::TIPOS_OBRA), 'estados' => $rot(CalculadoraObras::ESTADOS_OBRA), 'prioridades' => $rot(CalculadoraObras::PRIORIDADES),
            'quem_paga' => $rot(CalculadoraObras::QUEM_PAGA), 'estados_item' => $rot(CalculadoraObras::ESTADOS_ITEM), 'estados_bem' => $rot(CalculadoraObras::ESTADOS_BEM),
            'periodicidades' => $rot(CalculadoraObras::PERIODICIDADES), 'tipos_planeaveis' => CalculadoraObras::TIPOS_PLANEAVEIS]), 'Listas das obras.');
    }

    public function artigos(Request $r): JsonResponse
    {
        $this->exigir('imo_obras_view', 'imo_fichas_view');
        $d = $r->validate(['texto' => ['nullable', 'string', 'max:80'], 'ids' => ['nullable', 'array', 'max:100'], 'ids.*' => ['integer', 'min:1']]);
        $q = Produto::query()->orderBy('nome')->limit(50);
        if (! empty($d['ids'])) {
            $q->whereIn('id', $d['ids']);
        } elseif (! empty($d['texto'])) {
            $t = '%'.mb_strtolower(trim($d['texto'])).'%';
            $q->where(fn ($w) => $w->whereRaw('lower(nome) like ?', [$t])->orWhereRaw('lower(codigo) like ?', [$t]));
        }

        return RespostaApi::sucesso(ImobiliarioResource::collection($q->get(['id', 'codigo', 'nome', 'movimenta_stock'])->map(fn ($p) => ['id' => (int) $p->id, 'codigo' => $p->codigo, 'nome' => $p->nome,
            'de_armazem' => (bool) $p->movimenta_stock])->all()), 'Artigos.');
    }

    public function armazens(): JsonResponse
    {
        $this->exigir('imo_obras_view', 'imo_obra_pedir');

        return RespostaApi::sucesso(ImobiliarioResource::collection(Armazem::query()->orderBy('nome')->limit(200)->get(['id', 'nome'])->map(fn ($a) => ['id' => (int) $a->id, 'nome' => $a->nome])->all()), 'Armazéns.');
    }

    public function resumo(): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso(new ImobiliarioResource($this->obras->resumo()), 'Indicadores das obras.');
    }

    public function avisos(): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($this->obras->avisos(), 'Avisos das obras.');
    }

    public function index(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);
        $f = $r->validate(['estado' => ['nullable', Rule::in(OrdemTrabalhoImo::ESTADOS)], 'tipo' => ['nullable', Rule::in(OrdemTrabalhoImo::TIPOS)], 'imovel_id' => ['nullable', 'integer', 'min:1'],
            'fracao_id' => ['nullable', 'integer', 'min:1'], 'texto' => ['nullable', 'string', 'max:100'], 'por_debitar' => ['nullable', 'boolean']]);

        return RespostaApi::sucesso(ImobiliarioResource::collection($this->obras->listar($f)), 'Ordens de trabalho.');
    }

    public function show(int $ordem): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso(new ImobiliarioResource($this->obras->ficha(OrdemTrabalhoImo::query()->findOrFail($ordem))), 'Ordem de trabalho.');
    }

    /** @return array<string, mixed> */
    private function regras(): array
    {
        return ['tipo' => ['nullable', Rule::in(OrdemTrabalhoImo::TIPOS)], 'descricao' => ['nullable', 'string', 'max:5000'], 'imovel_id' => ['nullable', 'integer', 'min:1'], 'fracao_id' => ['nullable', 'integer', 'min:1'],
            'prioridade' => ['nullable', Rule::in(array_keys(CalculadoraObras::PRIORIDADES))], 'quem_paga' => ['nullable', Rule::in(array_keys(CalculadoraObras::QUEM_PAGA))], 'debitavel' => ['nullable', 'boolean'],
            'data_pedido' => ['nullable', 'date_format:Y-m-d'], 'data_prevista' => ['nullable', 'date_format:Y-m-d'], 'responsavel' => ['nullable', 'string', 'max:120'], 'pedido_por' => ['nullable', 'string', 'max:120'],
            'custo_estimado' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999'], 'ficha_id' => ['nullable', 'integer', 'min:1'], 'notas' => ['nullable', 'string', 'max:5000']];
    }

    public function store(Request $r): JsonResponse
    {
        $this->exigir('imo_obra_editar');

        return RespostaApi::criado(new ImobiliarioResource($this->obras->ficha($this->obras->gravar($r->validate($this->regras())))), 'Ordem aberta.');
    }

    public function update(Request $r, int $ordem): JsonResponse
    {
        $this->exigir('imo_obra_editar');
        $o = $this->obras->gravar($r->validate($this->regras()), OrdemTrabalhoImo::query()->findOrFail($ordem));

        return RespostaApi::sucesso(new ImobiliarioResource($this->obras->ficha($o)), 'Ordem gravada.');
    }

    public function destroy(int $ordem): JsonResponse
    {
        $this->exigir('imo_obra_editar');
        $this->obras->eliminar(OrdemTrabalhoImo::query()->findOrFail($ordem));

        return RespostaApi::sucesso(null, 'Ordem apagada.');
    }

    public function aprovar(int $ordem): JsonResponse
    {
        $this->exigir('imo_obra_aprovar');

        return RespostaApi::sucesso(new ImobiliarioResource($this->obras->aprovar(OrdemTrabalhoImo::query()->findOrFail($ordem))), 'Ordem aprovada.');
    }

    public function necessidades(int $ordem): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso(ImobiliarioResource::collection($this->obras->necessidades(OrdemTrabalhoImo::query()->findOrFail($ordem))), 'Necessidades da ordem.');
    }

    public function gravarNecessidades(Request $r, int $ordem): JsonResponse
    {
        $this->exigir('imo_obra_editar');
        $d = $r->validate(['linhas' => ['present', 'array', 'max:200'], 'linhas.*.produto_id' => ['nullable', 'integer', 'min:1'], 'linhas.*.descricao' => ['nullable', 'string', 'max:200'],
            'linhas.*.quantidade' => ['nullable', 'numeric', 'min:0', 'max:999999999']]);

        return RespostaApi::sucesso(ImobiliarioResource::collection($this->obras->gravarNecessidades(OrdemTrabalhoImo::query()->findOrFail($ordem), $d['linhas'])), 'Necessidades gravadas.');
    }

    public function planoPedido(Request $r, int $ordem): JsonResponse
    {
        $this->exigir('imo_obras_view', 'imo_obra_pedir');
        $d = $r->validate(['armazem_id' => ['nullable', 'integer', 'min:1']]);

        return RespostaApi::sucesso(ImobiliarioResource::collection($this->obras->planoDePedido(OrdemTrabalhoImo::query()->findOrFail($ordem), $d['armazem_id'] ?? null)), 'Plano do pedido de material.');
    }

    public function pedir(Request $r, int $ordem): JsonResponse
    {
        $this->exigir('imo_obra_pedir');
        $d = $r->validate(['armazem_id' => ['nullable', 'integer', 'min:1'], 'requerente' => ['nullable', 'string', 'max:100']]);
        $x = $this->obras->pedirMaterial(OrdemTrabalhoImo::query()->findOrFail($ordem), $d['armazem_id'] ?? null, $d['requerente'] ?? null);

        return RespostaApi::sucesso(new ImobiliarioResource($x), 'Material pedido. O que está em compras segue a deliberação.');
    }

    public function sincronizar(int $ordem): JsonResponse
    {
        $this->exigir('imo_obra_receber');
        $x = $this->obras->sincronizar(OrdemTrabalhoImo::query()->findOrFail($ordem));

        return RespostaApi::sucesso(new ImobiliarioResource($x), $x['bens_criados'] > 0 ? 'Entregas registadas. O material entrou no inventário da fracção.' : 'Entregas actualizadas.');
    }

    public function iniciar(int $ordem): JsonResponse
    {
        $this->exigir('imo_obra_fechar');

        return RespostaApi::sucesso(new ImobiliarioResource($this->obras->iniciar(OrdemTrabalhoImo::query()->findOrFail($ordem))), 'Ordem em curso.');
    }

    public function concluir(Request $r, int $ordem): JsonResponse
    {
        $this->exigir('imo_obra_fechar');
        $d = $r->validate(['cancelar_pendentes' => ['nullable', 'boolean'], 'data' => ['nullable', 'date_format:Y-m-d']]);
        $o = $this->obras->concluir(OrdemTrabalhoImo::query()->findOrFail($ordem), (bool) ($d['cancelar_pendentes'] ?? false), $d['data'] ?? null);

        return RespostaApi::sucesso(new ImobiliarioResource($o), 'Ordem concluída.');
    }

    public function cancelar(Request $r, int $ordem): JsonResponse
    {
        $this->exigir('imo_obra_fechar');
        $d = $r->validate(['motivo' => ['nullable', 'string', 'max:300']]);

        return RespostaApi::sucesso(new ImobiliarioResource($this->obras->cancelar(OrdemTrabalhoImo::query()->findOrFail($ordem), $d['motivo'] ?? '')), 'Ordem cancelada.');
    }

    public function debitar(Request $r, int $ordem): JsonResponse
    {
        $this->exigir('imo_sub_debitar');
        $d = $r->validate(['renda_id' => ['nullable', 'integer', 'min:1'], 'valor' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999'], 'designacao' => ['nullable', 'string', 'max:150']]);
        $x = $this->obras->debitar(OrdemTrabalhoImo::query()->findOrFail($ordem), $d);

        return RespostaApi::sucesso(new ImobiliarioResource($x), $x['repetido'] ? 'Esta obra já estava debitada ao inquilino.' : 'Obra debitada na renda. Aprove a renda de novo antes de facturar.');
    }

    public function desfazerDebito(int $ordem): JsonResponse
    {
        $this->exigir('imo_sub_debitar');

        return RespostaApi::sucesso(new ImobiliarioResource($this->obras->desfazerDebito(OrdemTrabalhoImo::query()->findOrFail($ordem))), 'Débito desfeito.');
    }

    public function abrirApetrechamento(Request $r, int $fracao): JsonResponse
    {
        $this->exigir('imo_obra_editar');
        $d = $r->validate(['ficha_id' => ['required', 'integer', 'min:1'], 'prioridade' => ['nullable', Rule::in(array_keys(CalculadoraObras::PRIORIDADES))]], ['ficha_id.required' => 'Escolha a ficha de apetrechamento.']);
        $x = $this->obras->abrirApetrechamento(Fracao::query()->findOrFail($fracao), FichaApetrechamento::query()->findOrFail($d['ficha_id']), $d['prioridade'] ?? null);

        return RespostaApi::criado(new ImobiliarioResource($x), "Ordem aberta com {$x['necessidades']} necessidade(s). Falta pedir o material.");
    }

    public function deVistoria(int $vistoria): JsonResponse
    {
        $this->exigir('imo_obra_editar');
        $x = $this->obras->deVistoria(VistoriaReserva::query()->findOrFail($vistoria));

        return RespostaApi::sucesso(new ImobiliarioResource($x), $x['nova'] ? 'Ordem de reparação aberta pelos danos da reserva.' : 'Esta vistoria já tinha ordem de reparação.');
    }
}
