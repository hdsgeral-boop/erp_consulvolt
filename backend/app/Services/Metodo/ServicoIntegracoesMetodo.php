<?php

namespace App\Services\Metodo;

use App\Exceptions\ErroNegocio;
use App\Models\AssuntoMetodoTrabalho;
use App\Models\Projeto;
use App\Services\Compras\ServicoProcessoCompras;
use App\Services\Projetos\ServicoEquipasProjetos;
use App\Services\Projetos\ServicoOrcamentoProjetos;
use App\Services\Projetos\ServicoPlaneamentoProjetos;
use App\Services\Projetos\ServicoProjetos;

/**
 * Pôr o assunto em curso e as integrações (assuntos.js:269-451): pedido de compra e projecto de resolução.
 * Usa os serviços de Compras e de Projectos tal como estão (circuito, numeração, deliberação por valor, validações):
 *   - pedido de compra: ServicoProcessoCompras::criarPedido (descrição «nº — título», entrega = data de fim, observações
 *     com a origem e o fornecedor indicado, artigos do assunto) — a deliberação por valor de Compras arranca aí;
 *   - projecto novo: ServicoProjetos::guardar (código PRJ-<nº>, INTERNO com UN + centro de custo ou EXTERNO com cliente + NE),
 *     uma fase com o prazo do assunto, uma tarefa por linha dos «Recursos», a equipa com os responsáveis (principal =
 *     «Responsável principal») e uma linha de orçamento DIVERSOS = custo estimado;
 *   - projecto existente: liga-se sem mexer no projecto além da origem (assunto_metodo_id / origem).
 * A ligação de volta fica no assunto (projeto_id, pedido_compra_id) e no projecto (assunto_metodo_id).
 * Tudo dentro da transacção de quem chama: se uma integração falhar, a aprovação não fica a meio.
 */
final class ServicoIntegracoesMetodo
{
    private const PAPEIS = ['COLABORADOR' => 'Colaborador interno', 'EXTERNO' => 'Prestador de serviço externo', 'FORNECEDOR' => 'Fornecedor'];

    public function __construct(
        private readonly ServicoAssuntosMetodo $assuntos,
        private readonly ServicoProcessoCompras $compras,
        private readonly ServicoProjetos $projetos,
        private readonly ServicoPlaneamentoProjetos $planeamento,
        private readonly ServicoEquipasProjetos $equipas,
        private readonly ServicoOrcamentoProjetos $orcamento,
    ) {}

    /** RASCUNHO → EM_CURSO, já aprovado (mudarEstado EM_CURSO, :276-295). Chamar dentro de uma transacção com o assunto bloqueado. */
    public function porEmCurso(AssuntoMetodoTrabalho $a, string $nota): AssuntoMetodoTrabalho
    {
        if ($a->estado !== 'RASCUNHO') {
            throw new ErroNegocio('Só um assunto em rascunho passa a «Em curso».', 'TRANSICAO_INVALIDA', 422);
        }
        if ((((array) $a->deliberacao)['estado'] ?? null) !== 'APROVADO') {
            throw new ErroNegocio('Submeta primeiro o assunto a aprovação do responsável do nível acima.', 'SEM_APROVACAO', 422);
        }
        $this->assuntos->exigirCompleto($a, 'Faltam dados para pôr o assunto em curso:');
        // o projecto primeiro, para o pedido de compra já nascer ligado a ele
        if ($a->metodo_resolucao === 'PROJECTO') {
            if ($a->projeto_modo === 'EXISTENTE') {
                $this->ligarProjeto($a);
            } elseif (! $a->projeto_id) {
                $this->gerarProjeto($a);
            }
        }
        if ($a->gerar_pedido_compra && ! $a->pedido_compra_id) {
            $this->gerarPedidoCompra($a);
        }
        $a->forceFill(['estado' => 'EM_CURSO', 'atualizado_por' => $this->assuntos->nomeUtilizador()])->save();
        $this->assuntos->registar($a, 'Estado: Em curso', $nota);

        return $a;
    }

    /**
     * gerarPedidoCompra (:321-347). O legado grava o custo estimado em purchase_requests.valor_estimado (:328); pedidos_compra
     * não tem campo próprio para isso (ADR-071, M3: não se cria coluna), por isso o custo estimado fica na observação do pedido.
     * A deliberação por valor de Compras usa o valor das linhas (ServicoDeliberacaoCompras::valorEstimado).
     */
    public function gerarPedidoCompra(AssuntoMetodoTrabalho $a): int
    {
        $forn = collect((array) $a->responsaveis)->first(fn ($r) => ($r['tipo'] ?? null) === 'FORNECEDOR')
            ?? throw new ErroNegocio('Indique o fornecedor em WHO antes de gerar o pedido de compra.', 'SEM_FORNECEDOR', 422);
        $linhas = array_values(array_filter((array) $a->linhas_pedido_compra, fn ($l) => ! empty($l['produto_id'])));
        $requerente = $a->nomePrincipal() ?: $this->assuntos->nomeUtilizador();
        $pedido = $this->compras->criarPedido([
            'nome_requerente' => mb_substr("{$requerente} (Assunto {$a->numero})", 0, 255),
            'data' => now()->toDateString(),
            'descricao' => mb_substr("{$a->numero} — {$a->titulo}", 0, 255),
            'data_entrega' => $a->data_fim?->toDateString(),
            'observacoes' => implode("\n", array_filter(["Gerado a partir do assunto {$a->numero} (Método de Trabalho).",
                $a->recursos ? "Recursos: {$a->recursos}" : null, ! empty($forn['nome']) ? "Fornecedor indicado: {$forn['nome']}" : null,
                'Custo estimado no assunto: '.number_format((float) $a->custo_estimado, 2, ',', ' ').' '.($a->codigo_moeda ?: 'AOA')])),
            'unidade_negocio_id' => $a->local_tipo === 'INTERNO' ? $a->unidade_negocio_id : null,
            'centro_custo_id' => $a->centro_custo_id,
            'projeto_id' => $a->projeto_id,
            'linhas' => array_map(fn ($l) => ['produto_id' => (int) $l['produto_id'], 'quantidade' => (float) ($l['quantidade'] ?? 1),
                'preco_unitario' => (float) ($l['preco_unitario'] ?? 0)] + (! empty($l['descricao']) ? ['descricao' => mb_substr((string) $l['descricao'], 0, 255)] : []), $linhas),
        ]);
        $a->forceFill(['pedido_compra_id' => $pedido->id])->save();
        $this->assuntos->registar($a, 'Pedido de compra', "Criado o pedido {$pedido->numero_pedido} para {$forn['nome']}.");

        return $pedido->id;
    }

    /**
     * gerarProjecto (:354-432): o QUÊ dá o nome e a descrição, o QUEM a equipa, o QUANDO a fase, o ONDE o tipo (interno com
     * unidade de negócio ou externo com cliente) e o COMO as tarefas e o orçamento.
     */
    public function gerarProjeto(AssuntoMetodoTrabalho $a): int
    {
        $externo = $a->local_tipo === 'EXTERNO';
        $codigo = 'PRJ-'.preg_replace('/\s+/', '', (string) $a->numero);
        $existe = Projeto::query()->whereRaw('upper(codigo) = upper(?)', [$codigo])->exists();
        $p = $this->projetos->guardar(($existe ? [] : ['codigo' => $codigo]) + [
            'nome' => mb_substr((string) $a->titulo, 0, 255), 'tipo' => $externo ? 'EXTERNO' : 'INTERNO', 'estado' => 'ACTIVO',
            'unidade_negocio_id' => $externo ? null : $a->unidade_negocio_id, 'centro_custo_id' => $externo ? null : $a->centro_custo_id,
            'cliente_id' => $externo ? $a->cliente_id : null, 'encomenda_venda_id' => $externo ? $a->encomenda_venda_id : null,
            'descricao' => $a->descricao,
        ]);
        $p->forceFill(['origem' => 'METODO_TRABALHO', 'assunto_metodo_id' => $a->id])->save();

        $ini = $a->data_inicio?->toDateString();
        $fim = $a->data_fim?->toDateString();
        $marco = ($ini || $fim) ? $this->planeamento->guardarMarco($p, ['nome' => 'Resolução — '.($a->numero ?: 'assunto'), 'data_inicio' => $ini, 'data' => $fim ?: $ini]) : null;

        $linhas = array_values(array_filter(array_map(fn ($s) => trim((string) preg_replace('/^[-•*]\s*/u', '', trim($s))), preg_split('/\r?\n/', (string) $a->recursos))));
        $tarefas = $linhas ?: [(string) $a->titulo];
        foreach ($tarefas as $i => $nome) {
            $this->planeamento->guardarTarefa($p, ['nome' => mb_substr($nome, 0, 160), 'codigo' => (string) ($i + 1), 'marco_projeto_id' => $marco?->id,
                'data_inicio' => $ini, 'data_fim' => $fim, 'estado' => 'PENDENTE']);
        }

        $vistos = [];
        foreach ((array) $a->responsaveis as $r) {
            $tipo = ['COLABORADOR' => 'INTERNO', 'FORNECEDOR' => 'TERCEIRO', 'EXTERNO' => 'LIVRE'][$r['tipo'] ?? ''] ?? null;
            $chave = AssuntoMetodoTrabalho::chaveResponsavel((array) $r);
            if (! $tipo || isset($vistos[$chave]) || ($tipo !== 'LIVRE' && empty($r['ref_id']))) {
                continue;
            }
            $vistos[$chave] = true;
            $this->equipas->guardarMembro($p, ['tipo' => $tipo, 'colaborador_id' => $tipo === 'INTERNO' ? (int) $r['ref_id'] : null,
                'terceiro_id' => $tipo === 'TERCEIRO' ? (int) $r['ref_id'] : null, 'nome_externo' => $tipo === 'LIVRE' ? $r['nome'] : null,
                'papel' => $chave === (string) $a->responsavel_principal ? 'Responsável principal' : (self::PAPEIS[$r['tipo']] ?? 'Participante')]);
        }

        if ((float) $a->custo_estimado > 0) {
            $this->orcamento->guardarLinha($p, ['rubrica' => 'DIVERSOS', 'montante' => (string) $a->custo_estimado]);
        }

        $a->forceFill(['projeto_id' => $p->id])->save();
        $this->assuntos->registar($a, 'Projecto de resolução', "Criado o projecto {$p->codigo} com ".count($tarefas).' tarefa(s).');
        $this->projetos->registar($p->id, 'Origem', "Criado a partir do assunto {$a->numero} (Método de Trabalho).");

        return $p->id;
    }

    /** ligarProjecto (:436-448): só se regista de onde passou a vir, para a ficha do projecto o poder mostrar. */
    public function ligarProjeto(AssuntoMetodoTrabalho $a): int
    {
        if (! $a->projeto_id) {
            throw new ErroNegocio('Escolha o projecto existente que vai resolver este assunto.', 'SEM_PROJETO', 422);
        }
        $p = Projeto::query()->lockForUpdate()->find($a->projeto_id) ?? throw new ErroNegocio('O projecto escolhido não foi encontrado.', 'PROJETO_INEXISTENTE', 422);
        if ($p->assunto_metodo_id && (int) $p->assunto_metodo_id !== $a->id) {
            throw new ErroNegocio("O projecto {$p->codigo} já resolve outro assunto.", 'PROJETO_OCUPADO', 422);
        }
        if (in_array($p->estado, Projeto::FECHADOS, true)) {
            throw new ErroNegocio("O projecto {$p->codigo} está {$p->estado}: escolha um projecto aberto.", 'PROJETO_FECHADO', 422);
        }
        if ((int) $p->assunto_metodo_id !== $a->id) {
            $p->forceFill(['assunto_metodo_id' => $a->id] + ($p->origem ? [] : ['origem' => 'METODO_TRABALHO']))->save();
            $this->projetos->registar($p->id, 'Origem', "Ligado ao assunto {$a->numero} (Método de Trabalho).");
            $this->assuntos->registar($a, 'Projecto de resolução', "Ligado ao projecto já existente {$p->codigo} «{$p->nome}».");
        }

        return $p->id;
    }
}
