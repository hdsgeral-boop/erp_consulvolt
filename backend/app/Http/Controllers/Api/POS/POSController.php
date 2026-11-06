<?php

namespace App\Http\Controllers\Api\POS;

use App\Http\Controllers\Controller;
use App\Http\Requests\POS\Pos\AbrirSessaoRequest;
use App\Http\Requests\POS\Pos\AnularDeliberacaoRequest;
use App\Http\Requests\POS\Pos\AtivarTerminalRequest;
use App\Http\Requests\POS\Pos\CopiarMeiosRequest;
use App\Http\Requests\POS\Pos\DeliberarRequest;
use App\Http\Requests\POS\Pos\DescontabilizarRequest;
use App\Http\Requests\POS\Pos\FecharSessaoRequest;
use App\Http\Requests\POS\Pos\GuardarDefinicoesRequest;
use App\Http\Requests\POS\Pos\GuardarServicosLavTerminalRequest;
use App\Http\Requests\POS\Pos\GuardarTerminalRequest;
use App\Http\Requests\POS\Pos\SessoesRequest;
use App\Http\Requests\POS\Pos\VenderRequest;
use App\Http\Resources\POS\SessaoPOSResource;
use App\Http\Resources\POS\TerminalPOSResource;
use App\Http\Resources\POS\VendaPOSResource;
use App\Models\Produto;
use App\Models\SessaoPOS;
use App\Models\TerminalPOS;
use App\Models\Venda;
use App\Services\POS\ServicoCatalogoPOS;
use App\Services\POS\ServicoConfigPOS;
use App\Services\POS\ServicoContabilizacaoPOS;
use App\Services\POS\ServicoSessoesPOS;
use App\Services\POS\ServicoTerminaisPOS;
use App\Services\POS\ServicoVendasPOS;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;

/** /api/pos — terminais, definições, sessões (abertura, X, Z), vendas, integração contabilística e desvios. */
final class POSController extends Controller
{
    public function __construct(
        private readonly ServicoTerminaisPOS $terminais,
        private readonly ServicoConfigPOS $config,
        private readonly ServicoSessoesPOS $sessoes,
        private readonly ServicoVendasPOS $vendas,
        private readonly ServicoContabilizacaoPOS $contabilizacao,
        private readonly ServicoCatalogoPOS $catalogo,
    ) {}

    // ───────────── Terminais ─────────────

    public function terminais(): JsonResponse
    {
        $this->exigir('pos_view', 'pos_terminais_view', 'pos_terminais_gerir', 'pos_venda');
        $abertas = SessaoPOS::query()->where('estado', 'ABERTA')->get(['id', 'terminal_pos_id', 'codigo_sessao', 'nome_operador', 'aberto_em'])->keyBy('terminal_pos_id');

        return RespostaApi::sucesso(TerminalPOS::query()->orderBy('codigo')->get()->map(fn ($t) => (new TerminalPOSResource($t, $abertas[$t->id] ?? null))->resolve()), 'Terminais POS.');
    }

    public function terminal(int $terminal): JsonResponse
    {
        $this->exigir('pos_view', 'pos_terminais_view', 'pos_terminais_gerir', 'pos_venda');
        $t = TerminalPOS::query()->findOrFail($terminal);

        return RespostaApi::sucesso(new TerminalPOSResource($t, $this->terminais->sessaoAberta($t)), 'Terminal POS.');
    }

    public function guardarTerminal(GuardarTerminalRequest $r, ?int $terminal = null): JsonResponse
    {
        $d = $r->validated();
        if ($r->has('meios_pagamento')) {
            $d['meios_pagamento'] = $r->input('meios_pagamento');   // os subcampos são validados no serviço
        }
        $t = $terminal ? TerminalPOS::query()->findOrFail($terminal) : null;
        $res = $this->terminais->guardar($d, $t);

        return $t ? RespostaApi::sucesso($res, 'Terminal actualizado.') : RespostaApi::criado($res, 'Terminal criado.');
    }

    public function copiarMeios(CopiarMeiosRequest $r, int $terminal): JsonResponse
    {
        $d = $r->validated();

        return RespostaApi::sucesso($this->terminais->copiarMeios(TerminalPOS::query()->findOrFail($terminal), (int) $d['terminal_origem_id'], $d['modo'],
            isset($d['empresa_origem_id']) ? (int) $d['empresa_origem_id'] : null), 'Meios de pagamento copiados.');
    }

    public function ativarTerminal(AtivarTerminalRequest $r, int $terminal): JsonResponse
    {
        $d = $r->validated();

        return RespostaApi::sucesso($this->terminais->definirAtivo(TerminalPOS::query()->findOrFail($terminal), (bool) $d['ativo']), $d['ativo'] ? 'Terminal activado.' : 'Terminal desactivado.');
    }

    public function eliminarTerminal(int $terminal): JsonResponse
    {
        $this->exigir('pos_terminais_gerir');
        $this->terminais->eliminar(TerminalPOS::query()->findOrFail($terminal));

        return RespostaApi::sucesso(null, 'Terminal eliminado.');
    }

    /** Serviços de lavandaria configurados neste terminal (null = sem restrição, todos os serviços). */
    public function servicosLavTerminal(int $terminal): JsonResponse
    {
        $this->exigir('pos_terminais_view', 'pos_terminais_gerir', 'pos_venda', 'lav_ordens', 'lav_tabelas');
        $t = TerminalPOS::query()->findOrFail($terminal);

        return RespostaApi::sucesso(['servico_ids' => $t->lavandaria_servicos_ids], 'Serviços de lavandaria do terminal.');
    }

    public function guardarServicosLavTerminal(GuardarServicosLavTerminalRequest $r, int $terminal): JsonResponse
    {
        $d = $r->validated();
        $t = $this->terminais->guardarServicosLav(TerminalPOS::query()->findOrFail($terminal), $d['servico_ids']);

        return RespostaApi::sucesso(['servico_ids' => $t->lavandaria_servicos_ids], 'Serviços de lavandaria do terminal gravados.');
    }

    // ───────────── Definições ─────────────

    public function definicoes(): JsonResponse
    {
        $this->exigir('pos_view', 'pos_terminais_view', 'pos_terminais_gerir', 'pos_desvios_view', 'pos_desvio_deliberar');

        return RespostaApi::sucesso($this->config->obter(), 'Definições do POS.');
    }

    public function guardarDefinicoes(GuardarDefinicoesRequest $r): JsonResponse
    {
        $d = $r->validated();

        return RespostaApi::sucesso($this->config->guardar($d), 'Definições do POS gravadas.');
    }

    // ───────────── Sessões ─────────────

    public function sessoes(SessoesRequest $r): JsonResponse
    {
        $f = $r->validated();
        $q = SessaoPOS::query();
        foreach (['terminal_pos_id', 'estado', 'estado_contabilizacao', 'estado_desvio', 'estado_liquidacao'] as $c) {
            $q->when($f[$c] ?? null, fn ($q, $v) => $q->where($c, $v));
        }

        return RespostaApi::paginado($q->orderByDesc('aberto_em')->orderByDesc('id')->paginate($f['por_pagina'] ?? 50, ['*'], 'pagina', $f['pagina'] ?? 1), SessaoPOSResource::class, 'Sessões POS.');
    }

    public function sessao(int $sessao): JsonResponse
    {
        $this->exigir('pos_view', 'pos_relatorios_view', 'pos_integracao_view', 'pos_desvios_view', 'pos_prestacao_view', 'pos_venda');
        $s = SessaoPOS::query()->findOrFail($sessao);

        return RespostaApi::sucesso(new SessaoPOSResource($s, Venda::query()->where('sessao_pos_id', $s->id)->orderBy('id')
            ->get(['id', 'numero_documento', 'data_emissao', 'cliente_id', 'total_bruto', 'estado', 'pos_pagamentos', 'pos_troco', 'pos_operador', 'contabilizado'])), 'Sessão POS.');
    }

    public function abrirSessao(AbrirSessaoRequest $r, int $terminal): JsonResponse
    {
        $d = $r->validated();
        $res = $this->sessoes->abrir(TerminalPOS::query()->findOrFail($terminal), isset($d['fundo_maneio']) ? (string) $d['fundo_maneio'] : null);

        return RespostaApi::criado($res['sessao'], $res['aviso'] ?? 'Sessão aberta.');
    }

    public function relatorioX(int $sessao): JsonResponse
    {
        $this->exigir('pos_venda', 'pos_fecho');

        return RespostaApi::sucesso($this->sessoes->relatorioX(SessaoPOS::query()->findOrFail($sessao)), 'Relatório X.');
    }

    public function fecharSessao(FecharSessaoRequest $r, int $sessao): JsonResponse
    {
        $d = $r->validated();

        return RespostaApi::sucesso($this->sessoes->fechar(SessaoPOS::query()->findOrFail($sessao), $d), 'Sessão fechada (Z).');
    }

    // ───────────── Vendas ─────────────

    public function vender(VenderRequest $r, int $sessao): JsonResponse
    {
        $d = $r->validated();
        // desconto e alteração de preço (updatePOSDiscount / updatePOSCartPrice, permissoes.js:229)
        $precos = Produto::query()->whereIn('id', array_column($d['linhas'], 'produto_id'))->pluck('preco_unitario', 'id');
        $alterado = (float) ($d['percentagem_desconto'] ?? 0) > 0 || collect($d['linhas'])->contains(fn ($l) => isset($l['preco_unitario'])
            && bccomp(number_format((float) $l['preco_unitario'], 2, '.', ''), number_format((float) ($precos[$l['produto_id']] ?? 0), 2, '.', ''), 2) !== 0);
        if ($alterado) {
            $this->exigir('pos_desconto');
        }
        $s = SessaoPOS::query()->findOrFail($sessao);
        // venda ao balcão: só produtos marcados para o serviço do terminal (r3_vendas; o catálogo do terminal já os filtra).
        // Mesas, estadias e ordens de lavandaria não passam por aqui: os consumos já gravados não ficam presos no check-out.
        $this->catalogo->exigirDoServico($d['linhas'], TerminalPOS::query()->findOrFail($s->terminal_pos_id));
        $v = $this->vendas->vender($s, $d);

        return RespostaApi::criado(new VendaPOSResource($v), "Venda {$v->numero_documento} registada. Troco: ".number_format((float) $v->pos_troco, 2, ',', ' ').' Kz.');
    }

    // ───────────── Integração e desvios ─────────────

    public function contabilizar(int $sessao): JsonResponse
    {
        $this->exigir('pos_integrar');

        return RespostaApi::sucesso($this->contabilizacao->contabilizar(SessaoPOS::query()->findOrFail($sessao)), 'Sessão integrada na contabilidade.');
    }

    public function descontabilizar(DescontabilizarRequest $r, int $sessao): JsonResponse
    {
        $d = $r->validated();

        return RespostaApi::sucesso($this->contabilizacao->descontabilizar(SessaoPOS::query()->findOrFail($sessao), $d['motivo']), 'Integração estornada.');
    }

    public function deliberar(DeliberarRequest $r, int $sessao): JsonResponse
    {
        $d = $r->validated();

        return RespostaApi::sucesso($this->contabilizacao->deliberar(SessaoPOS::query()->findOrFail($sessao), $d['decisao'], $d['nota'] ?? null), 'Desvio deliberado.');
    }

    public function anularDeliberacao(AnularDeliberacaoRequest $r, int $sessao): JsonResponse
    {
        $d = $r->validated();

        return RespostaApi::sucesso($this->contabilizacao->anularDeliberacao(SessaoPOS::query()->findOrFail($sessao), $d['motivo']), 'Deliberação anulada.');
    }
}
