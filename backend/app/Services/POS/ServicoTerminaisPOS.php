<?php

namespace App\Services\POS;

use App\Exceptions\ErroNegocio;
use App\Models\Armazem;
use App\Models\SessaoPOS;
use App\Models\Terceiro;
use App\Models\TerminalPOS;
use App\Models\Venda;
use App\Services\Contabilidade\ServicoPlanoContas;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Terminais POS e meios de pagamento (js/pos_gestao.js:760-1046), com as correcções:
 *   - gravar não toca nos contadores (o legado regravava o terminal inteiro com os contadores lidos ao abrir o editor
 *     e fazia recuar a numeração das sessões, dos Z e da lavandaria); a numeração vive em ServicoNumeracao;
 *   - código único garantido pela base (índice parcial) e bloqueado depois da primeira sessão;
 *   - contas validadas no servidor (movimento; numerário liquida em 45, TPA/transferência em 43; transitória ≠ liquidação;
 *     comissão com conta; transitórias sem repetição entre meios);
 *   - copiar meios de outra empresa só se o utilizador tiver acesso a essa empresa.
 */
final class ServicoTerminaisPOS
{
    public const TIPOS = ['LOJA', 'RESTAURANTE', 'LAVANDARIA', 'HOTELARIA'];

    public const MEIOS = ['NUMERARIO', 'TPA', 'TRANSFERENCIA'];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoPlanoContas $plano,
    ) {}

    /** Meios de pagamento por omissão (meiosPadrao, pos_gestao.js:44), sem contas: o utilizador escolhe-as. */
    public static function meiosPadrao(): array
    {
        return [
            ['id' => 'pm_num', 'tipo' => 'NUMERARIO', 'nome' => 'Numerário', 'ativo' => true, 'conta_transitoria' => null, 'conta_liquidacao' => null],
            ['id' => 'pm_tpa', 'tipo' => 'TPA', 'nome' => 'Multicaixa (TPA)', 'ativo' => true, 'conta_transitoria' => null, 'conta_liquidacao' => null,
                'codigo_tpa' => null, 'comissao_pct' => 0, 'conta_comissao' => null, 'comissao_deduzida' => true],
            ['id' => 'pm_trf', 'tipo' => 'TRANSFERENCIA', 'nome' => 'Transferência bancária', 'ativo' => true, 'conta_transitoria' => null, 'conta_liquidacao' => null],
        ];
    }

    /** @param  array<string, mixed>  $d */
    public function guardar(array $d, ?TerminalPOS $t = null): TerminalPOS
    {
        $codigo = strtoupper(trim((string) ($d['codigo'] ?? $t?->codigo)));
        if ($codigo === '' || ! preg_match('/^[A-Z0-9_-]{1,10}$/', $codigo)) {
            throw new ErroNegocio('Código do terminal inválido (até 10 letras, algarismos, «-» ou «_»).', 'CODIGO_INVALIDO', 422);
        }
        if ($t && $codigo !== $t->codigo && $this->temMovimento($t)) {
            throw new ErroNegocio('O terminal já tem sessões ou vendas: o código não se altera (entra na numeração dos documentos).', 'CODIGO_BLOQUEADO', 422);
        }
        if (TerminalPOS::query()->where('codigo', $codigo)->when($t, fn ($q) => $q->whereKeyNot($t->id))->exists()) {
            throw new ErroNegocio("Já existe um terminal com o código {$codigo}.", 'CODIGO_DUPLICADO', 422);
        }
        $tipo = $d['tipo'] ?? $t?->tipo ?? 'LOJA';
        if (! in_array($tipo, self::TIPOS, true)) {
            throw new ErroNegocio('Tipo de terminal inválido.', 'TIPO_INVALIDO', 422);
        }
        if (! empty($d['armazem_id']) && ! Armazem::query()->whereKey($d['armazem_id'])->exists()) {
            throw new ErroNegocio('Armazém inexistente.', 'ARMAZEM_INEXISTENTE', 422);
        }
        if (! empty($d['cliente_padrao_id'])) {
            $c = Terceiro::query()->find($d['cliente_padrao_id']);
            if (! $c?->eCliente() || ! $c->codigo_conta) {
                throw new ErroNegocio('O cliente padrão tem de ser um cliente com conta contabilística.', 'CLIENTE_INVALIDO', 422);
            }
        }
        if ((float) ($d['fundo_maneio_padrao'] ?? 0) < 0) {
            throw new ErroNegocio('O fundo de maneio não pode ser negativo.', 'FUNDO_INVALIDO', 422);
        }
        $meios = array_key_exists('meios_pagamento', $d) ? $this->validarMeios($d['meios_pagamento']) : ($t?->meios_pagamento ?? self::meiosPadrao());

        $campos = array_intersect_key($d, array_flip(['nome', 'armazem_id', 'cliente_padrao_id', 'fundo_maneio_padrao', 'unidade_negocio_id', 'centro_custo_id',
            'hotel_hora_entrada', 'hotel_hora_saida', 'hotel_tolerancia_atraso_min', 'hotel_bloco_horas', 'hotel_bloco_horas_de', 'hotel_bloco_horas_ate']));
        $dados = ['codigo' => $codigo, 'tipo' => $tipo, 'meios_pagamento' => $meios, 'atualizado_por' => Auth::user()?->nome_utilizador] + $campos;
        if ($t) {
            $t->update($dados);

            return $t->refresh();
        }

        return TerminalPOS::create($dados + ['nome' => $d['nome'] ?? $codigo, 'ativo' => true, 'criado_por' => Auth::user()?->nome_utilizador,
            'hotel_hora_entrada' => $d['hotel_hora_entrada'] ?? '14:00', 'hotel_hora_saida' => $d['hotel_hora_saida'] ?? '12:00',
            'hotel_tolerancia_atraso_min' => $d['hotel_tolerancia_atraso_min'] ?? 60]);
    }

    /**
     * Valida e normaliza a lista de meios (posGravarTerminal, pos_gestao.js:974-1010).
     *
     * @param  list<array<string, mixed>>  $meios
     * @return list<array<string, mixed>>
     */
    public function validarMeios(array $meios): array
    {
        $saida = [];
        $ids = $transitorias = [];
        foreach (array_values($meios) as $n => $m) {
            $onde = 'Meio '.($n + 1).(empty($m['nome']) ? '' : " ({$m['nome']})").':';
            $tipo = $m['tipo'] ?? null;
            if (! in_array($tipo, self::MEIOS, true)) {
                throw new ErroNegocio("{$onde} tipo inválido (NUMERARIO, TPA ou TRANSFERENCIA).", 'MEIO_INVALIDO', 422);
            }
            if (trim((string) ($m['nome'] ?? '')) === '') {
                throw new ErroNegocio("{$onde} indique o nome.", 'MEIO_INVALIDO', 422);
            }
            $id = (string) ($m['id'] ?? '') ?: 'pm_'.Str::lower(Str::random(8));
            if (isset($ids[$id])) {
                throw new ErroNegocio("{$onde} identificador repetido ({$id}).", 'MEIO_INVALIDO', 422);
            }
            $ids[$id] = true;
            $ativo = (bool) ($m['ativo'] ?? true);
            $transitoria = trim((string) ($m['conta_transitoria'] ?? '')) ?: null;
            $liquidacao = trim((string) ($m['conta_liquidacao'] ?? '')) ?: null;
            $pct = (float) ($m['comissao_pct'] ?? 0);
            $comissao = trim((string) ($m['conta_comissao'] ?? '')) ?: null;
            if ($ativo) {
                if (! $transitoria || ! $liquidacao) {
                    throw new ErroNegocio("{$onde} indique a conta transitória e a conta de liquidação.", 'MEIO_SEM_CONTAS', 422);
                }
                foreach ([$transitoria, $liquidacao] as $c) {
                    $this->contaMovimento($c, $onde);
                }
                $classe = $tipo === 'NUMERARIO' ? '45' : '43';
                if (! str_starts_with($liquidacao, $classe)) {
                    throw new ErroNegocio("{$onde} a conta de liquidação tem de ser da classe {$classe} (".($tipo === 'NUMERARIO' ? 'caixa' : 'bancos').').', 'CONTA_CLASSE_INVALIDA', 422);
                }
                if ($transitoria === $liquidacao) {
                    throw new ErroNegocio("{$onde} a conta transitória tem de ser diferente da conta de liquidação.", 'CONTAS_IGUAIS', 422);
                }
                if (isset($transitorias[$transitoria])) {
                    throw new ErroNegocio("{$onde} a conta transitória {$transitoria} já é usada por outro meio: cada meio precisa da sua (a prestação de contas salda-as por meio).",
                        'TRANSITORIA_REPETIDA', 422);
                }
                $transitorias[$transitoria] = true;
                if ($tipo === 'TPA' && $pct > 0) {
                    $comissao ?? throw new ErroNegocio("{$onde} com comissão, indique a conta da comissão.", 'COMISSAO_SEM_CONTA', 422);
                    $this->contaMovimento($comissao, $onde);
                }
            }
            if ($pct < 0 || $pct >= 100) {
                throw new ErroNegocio("{$onde} percentagem de comissão inválida.", 'COMISSAO_INVALIDA', 422);
            }
            $saida[] = array_filter(['id' => $id, 'tipo' => $tipo, 'nome' => trim((string) $m['nome']), 'ativo' => $ativo,
                'conta_transitoria' => $transitoria, 'conta_liquidacao' => $liquidacao,
                'codigo_tpa' => $tipo === 'TPA' ? (trim((string) ($m['codigo_tpa'] ?? '')) ?: null) : null,
                'comissao_pct' => $tipo === 'TPA' ? $pct : null, 'conta_comissao' => $tipo === 'TPA' ? $comissao : null,
                'comissao_deduzida' => $tipo === 'TPA' ? (bool) ($m['comissao_deduzida'] ?? true) : null,
                'copiado_de' => $m['copiado_de'] ?? null], fn ($v) => $v !== null);
        }
        if (! array_filter($saida, fn ($m) => $m['ativo'])) {
            throw new ErroNegocio('O terminal tem de ter pelo menos um meio de pagamento activo.', 'SEM_MEIOS_ATIVOS', 422);
        }

        return $saida;
    }

    /**
     * Copia os meios de outro terminal (posConfirmarCopiaMeios, pos_gestao.js:876-972). SUBSTITUIR reaproveita os ids
     * existentes por tipo (as sessões fechadas continuam a encontrar os seus meios); ACRESCENTAR junta com ids novos.
     */
    public function copiarMeios(TerminalPOS $destino, int $origemId, string $modo, ?int $empresaOrigem = null): TerminalPOS
    {
        if (! in_array($modo, ['SUBSTITUIR', 'ACRESCENTAR'], true)) {
            throw new ErroNegocio('Modo de cópia inválido.', 'MODO_INVALIDO', 422);
        }
        $empresa = $this->contexto->obrigatorio();
        $empresaOrigem ??= $empresa;
        if ($empresaOrigem !== $empresa && ! Auth::user()?->empresas()->whereKey($empresaOrigem)->exists()) {
            throw new ErroNegocio('Não tem acesso à empresa de origem.', 'SEM_ACESSO_EMPRESA', 403);
        }
        $origem = $this->contexto->executarComo($empresaOrigem, fn () => TerminalPOS::query()->findOrFail($origemId));
        $copiados = array_map(fn ($m) => ['copiado_de' => $origem->codigo] + $m, $origem->meios_pagamento ?? []);
        $atuais = $destino->meios_pagamento ?? [];
        if ($modo === 'SUBSTITUIR') {
            $livres = collect($atuais)->groupBy('tipo')->map(fn ($g) => $g->pluck('id')->all())->all();
            $meios = array_map(function ($m) use (&$livres) {
                $m['id'] = array_shift($livres[$m['tipo']]) ?? 'pm_'.Str::lower(Str::random(8));

                return $m;
            }, $copiados);
        } else {
            $meios = array_merge($atuais, array_map(fn ($m) => ['id' => 'pm_'.Str::lower(Str::random(8))] + $m, $copiados));
        }

        return $this->guardar(['meios_pagamento' => $meios], $destino);
    }

    public function definirAtivo(TerminalPOS $t, bool $ativo): TerminalPOS
    {
        if (! $ativo && $this->sessaoAberta($t)) {   // pos_gestao.js:1036-1046
            throw new ErroNegocio('O terminal tem uma sessão aberta: feche-a antes de o desactivar.', 'SESSAO_ABERTA', 422);
        }
        $t->update(['ativo' => $ativo, 'atualizado_por' => Auth::user()?->nome_utilizador]);

        return $t;
    }

    public function eliminar(TerminalPOS $t): void
    {
        if ($this->temMovimento($t)) {
            throw new ErroNegocio('O terminal tem sessões ou vendas: desactive-o em vez de o eliminar.', 'TERMINAL_COM_MOVIMENTO', 422);
        }
        DB::transaction(fn () => $t->delete());
    }

    public function sessaoAberta(TerminalPOS $t): ?SessaoPOS
    {
        return SessaoPOS::query()->where('terminal_pos_id', $t->id)->where('estado', 'ABERTA')->first();
    }

    /** Meio activo do terminal pelo id. */
    public static function meio(TerminalPOS $t, string $id): array
    {
        $m = collect($t->meios_pagamento ?? [])->firstWhere('id', $id);
        if (! $m || empty($m['ativo'])) {
            throw new ErroNegocio("Meio de pagamento {$id} inexistente ou inactivo no terminal {$t->codigo}.", 'MEIO_INVALIDO', 422);
        }

        return $m;
    }

    private function temMovimento(TerminalPOS $t): bool
    {
        return SessaoPOS::query()->where('terminal_pos_id', $t->id)->exists() || Venda::query()->where('terminal_pos_id', $t->id)->exists();
    }

    private function contaMovimento(string $conta, string $onde): void
    {
        try {
            $this->plano->contaDeMovimento($conta);
        } catch (ErroNegocio $e) {
            throw new ErroNegocio("{$onde} {$e->getMessage()}", $e->codigo, 422);
        }
    }
}
