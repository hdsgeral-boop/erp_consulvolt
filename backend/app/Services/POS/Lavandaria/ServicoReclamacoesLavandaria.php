<?php

namespace App\Services\POS\Lavandaria;

use App\Exceptions\ErroNegocio;
use App\Models\PedidoLavandaria;
use App\Models\ReclamacaoLavandaria;
use App\Services\Tesouraria\ServicoDocumentosTesouraria;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Danos e reclamações (lavConfirmarDano, lavGravarDecisaoDano, lavConfirmarPagarDano — js/lavandaria.js:1049-1060, 1489-1573).
 * Circuito AGUARDA_COMPROVATIVO → COMPROVADO → APROVADA | RECUSADA → PAGA; a indemnização é o valor comprovado.
 * Correcções face ao legado:
 *   - segregação decidir × pagar aplicada no servidor, por reclamação: quem decidiu não paga (o legado só avisava, permissoes.js:528);
 *   - o pagamento é um documento de tesouraria PAGAMENTO validado pela Tesouraria (conta financeira 43/45, exercício aberto,
 *     série PAG), com D conta das indemnizações e o cliente — o legado gravava o documento sem validações nem numeração;
 *   - conta das indemnizações exigida (erro claro) e decisão só a partir dos estados abertos.
 */
final class ServicoReclamacoesLavandaria
{
    public const ESTADOS = ['AGUARDA_COMPROVATIVO', 'COMPROVADO', 'APROVADA', 'RECUSADA', 'PAGA'];

    public function __construct(
        private readonly ServicoConfigLavandaria $config,
        private readonly ServicoDocumentosTesouraria $tesouraria,
    ) {}

    public function registar(int $pedidoId, int $linhaId, string $descricao, mixed $valorDeclarado): ReclamacaoLavandaria
    {
        $descricao = trim($descricao);
        if (mb_strlen($descricao) < 5) {
            throw new ErroNegocio('Descreva o dano ou a reclamação.', 'DESCRICAO_OBRIGATORIA', 422);
        }

        return DB::transaction(function () use ($pedidoId, $linhaId, $descricao, $valorDeclarado) {
            $o = PedidoLavandaria::query()->lockForUpdate()->findOrFail($pedidoId);
            $i = collect($o->itens ?? [])->first(fn ($x) => (int) $x['linha_id'] === $linhaId);
            if (! $i || $i['estado'] === 'ANULADA') {
                throw new ErroNegocio('Linha inexistente ou anulada.', 'LINHA_INVALIDA', 422);
            }
            $r = ReclamacaoLavandaria::create(['pedido_lavandaria_id' => $o->id, 'numero_encomenda' => $o->numero_encomenda, 'linha_pedido_id' => (string) $linhaId,
                'nome_item' => mb_substr(trim(($i['nome_peca'] ?? '').' · '.$i['nome'], ' ·'), 0, 255), 'descricao_peca' => RegrasLavandaria::descricaoPeca($i),
                'estado_entrada' => RegrasLavandaria::estadoEntradaTexto($i), 'cliente_id' => $o->cliente_id, 'descricao' => $descricao,
                'valor_declarado' => RegrasLavandaria::dinheiro($valorDeclarado ?? 0), 'estado' => 'AGUARDA_COMPROVATIVO', 'criado_por' => Auth::user()?->nome_utilizador]);
            ServicoCaixaLavandaria::historico($o, "{$i['nome']}: registado dano/reclamação — {$descricao}.");
            $o->save();

            return $r;
        });
    }

    /** Decisão (lavGravarDecisaoDano, lavandaria.js:1527-1540): COMPROVADO, APROVADA (pelo valor comprovado) ou RECUSADA (fundamentada). */
    public function decidir(int $id, string $decisao, ?string $referencia, ?string $data, mixed $valor, ?string $nota): ReclamacaoLavandaria
    {
        if (! in_array($decisao, ['COMPROVADO', 'APROVADA', 'RECUSADA'], true)) {
            throw new ErroNegocio('Decisão inválida (COMPROVADO, APROVADA ou RECUSADA).', 'DECISAO_INVALIDA', 422);
        }
        $referencia = trim((string) $referencia);
        $nota = trim((string) $nota);
        $valor = RegrasLavandaria::dinheiro($valor ?? 0);
        if ($decisao !== 'RECUSADA' && ($referencia === '' || bccomp($valor, '0', 2) <= 0)) {
            throw new ErroNegocio('A indemnização exige o comprovativo de custo e o valor comprovado.', 'COMPROVATIVO_EM_FALTA', 422);
        }
        if ($decisao === 'RECUSADA' && mb_strlen($nota) < 5) {
            throw new ErroNegocio('Fundamente a recusa.', 'FUNDAMENTACAO_OBRIGATORIA', 422);
        }
        if (mb_strlen($nota) > 255) {
            throw new ErroNegocio('A fundamentação tem no máximo 255 caracteres.', 'FUNDAMENTACAO_LONGA', 422);
        }

        return DB::transaction(function () use ($id, $decisao, $referencia, $data, $valor, $nota) {
            $r = ReclamacaoLavandaria::query()->lockForUpdate()->findOrFail($id);
            if (! in_array($r->estado, ['AGUARDA_COMPROVATIVO', 'COMPROVADO'], true)) {
                throw new ErroNegocio("A reclamação já está {$r->estado}.", 'RECLAMACAO_DECIDIDA', 422);
            }
            $r->update(['referencia_comprovativo' => $referencia ?: null, 'data_comprovativo' => $data ?: null, 'valor_comprovativo' => $valor, 'estado' => $decisao,
                'nota_decisao' => $nota ?: null, 'decidido_em' => now(), 'decidido_por' => Auth::user()?->nome_utilizador]
                + ($decisao === 'APROVADA' ? ['valor_compensacao' => $valor] : []));

            return $r->refresh();
        });
    }

    /** Pagamento da indemnização aprovada (lavConfirmarPagarDano, lavandaria.js:1560-1573): documento de tesouraria PAGAMENTO por integrar. */
    public function pagar(int $id, string $data, string $contaFinanceira): ReclamacaoLavandaria
    {
        $conta = $this->config->obter()['conta_compensacao']
            ?? throw new ErroNegocio('Defina a conta das indemnizações nas definições da lavandaria.', 'CONFIG_LAVANDARIA_EM_FALTA', 422, ['campo' => 'conta_compensacao']);

        return DB::transaction(function () use ($id, $data, $contaFinanceira, $conta) {
            $r = ReclamacaoLavandaria::query()->lockForUpdate()->findOrFail($id);
            if ($r->estado !== 'APROVADA') {
                throw new ErroNegocio('Só se paga uma indemnização aprovada.', 'RECLAMACAO_NAO_APROVADA', 422);
            }
            $u = Auth::user();
            if ($u && $r->decidido_por && $r->decidido_por === $u->nome_utilizador) {
                throw new ErroNegocio('Segregação de funções: quem decidiu a indemnização não a paga.', 'SEGREGACAO_FUNCOES', 403);
            }
            $descricao = "Indemnização por dano · {$r->numero_encomenda} · {$r->nome_item}";
            $doc = $this->tesouraria->gravar(['tipo' => 'PAGAMENTO', 'data_documento' => $data, 'conta_financeira' => $contaFinanceira,
                'descricao' => mb_substr($descricao, 0, 255), 'referencia' => "LAV-IND-{$r->id}", 'linhas' => [[
                    'codigo_conta' => $conta, 'tipo_dc' => 'D', 'valor' => (string) $r->valor_compensacao, 'terceiro_id' => $r->cliente_id,
                    'descricao' => mb_substr("{$descricao} (comprovativo {$r->referencia_comprovativo})", 0, 255),
                ]]]);
            $r->update(['estado' => 'PAGA', 'documento_tesouraria_id' => $doc->id, 'pago_em' => now(), 'pago_por' => $u?->nome_utilizador]);

            return $r->refresh();
        });
    }
}
