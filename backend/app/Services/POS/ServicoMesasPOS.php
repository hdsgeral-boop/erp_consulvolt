<?php

namespace App\Services\POS;

use App\Exceptions\ErroNegocio;
use App\Models\ContaMesaPOS;
use App\Models\MesaPOS;
use App\Models\Produto;
use App\Models\SessaoPOS;
use App\Models\TerminalPOS;
use App\Models\Venda;
use App\Services\Sistema\ServicoAuditoria;
use App\Services\Vendas\CalculadoraDocumento;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * M-15 — POS restaurante: mesas e contas por mesa no servidor (decisão 14 do utilizador).
 *
 * Legado (js/ui_sales.js:6224, 6719-6744, 8443-8490; js/pos_gestao.js:454-459): mesas e carrinhos no localStorage do
 * navegador; «Suspender» guardava o carrinho da mesa e voltava ao balcão; «Checkout» emitia a FR com table_name e
 * esvaziava a mesa. Aqui:
 *   - as mesas são de cada terminal RESTAURANTE e partilhadas entre postos;
 *   - a conta aberta (uma por mesa) guarda as linhas, o desconto, o cliente e o operador, com versão para que dois postos
 *     não se sobreponham em silêncio (409 CONTA_MESA_ALTERADA);
 *   - cobrar emite a factura-recibo pelo motor POS normal (ServicoVendasPOS: stock, pagamentos, AGT) com o nome da mesa
 *     (vendas.nome_tabela, a coluna do legado) e fecha a conta na mesma transacção.
 * Acesso por modelos Eloquent (`MesaPOS`, `ContaMesaPOS`: isolamento por empresa/RLS e auditoria automática de criação, alteração e
 * eliminação). Datas e desconto saem tal como a base os devolve (`getRawOriginal`) para o contrato JSON não mudar.
 */
final class ServicoMesasPOS
{
    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoVendasPOS $vendas,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    /** @return list<array<string, mixed>> mesas do terminal com o resumo da conta aberta */
    public function mesas(int $terminal, bool $incluirInactivas = false): array
    {
        $t = $this->terminal($terminal);
        $contas = ContaMesaPOS::query()->where('terminal_pos_id', $t->id)->where('estado', 'ABERTA')->get()->keyBy('mesa_pos_id');
        $precos = $this->precos($contas->flatMap(fn ($c) => array_column($c->linhas ?: [], 'produto_id'))->unique()->all());

        return MesaPOS::query()->where('terminal_pos_id', $t->id)
            ->when(! $incluirInactivas, fn ($q) => $q->where('ativo', true))->orderBy('ordem')->ordenarPorNome()->get()
            ->map(function ($m) use ($contas, $precos) {
                $c = $contas[$m->id] ?? null;

                return ['id' => (int) $m->id, 'terminal_pos_id' => (int) $m->terminal_pos_id, 'nome' => $m->nome, 'ordem' => (int) $m->ordem, 'ativo' => (bool) $m->ativo,
                    'conta' => $c ? $this->resumo($c, $precos) : null];
            })->all();
    }

    /** @param array{nome: string, ordem?: ?int, ativo?: ?bool} $d */
    public function guardarMesa(int $terminal, array $d, ?int $mesa = null): array
    {
        $t = $this->terminal($terminal);
        if ($t->tipo !== 'RESTAURANTE') {
            throw new ErroNegocio('As mesas só existem nos terminais do tipo restaurante.', 'TERMINAL_SEM_MESAS', 422);
        }
        $empresa = $this->contexto->obrigatorio();
        $nome = trim($d['nome']);
        $duplicada = MesaPOS::query()->where('terminal_pos_id', $t->id)->whereRaw('lower(nome) = lower(?)', [$nome])
            ->when($mesa, fn ($q) => $q->where('id', '<>', $mesa))->exists();
        if ($duplicada) {
            throw new ErroNegocio('Já existe uma mesa com esse nome neste terminal.', 'MESA_DUPLICADA', 422);
        }
        $valores = ['nome' => $nome] + array_filter(['ordem' => $d['ordem'] ?? null, 'ativo' => $d['ativo'] ?? null], fn ($v) => $v !== null);
        if ($mesa) {
            $this->mesa($mesa, $t->id)->update($valores);
        } else {
            $valores['ordem'] ??= (int) MesaPOS::query()->where('terminal_pos_id', $t->id)->max('ordem') + 1;
            $mesa = MesaPOS::query()->create($valores + ['empresa_id' => $empresa, 'terminal_pos_id' => $t->id])->id;
        }

        return collect($this->mesas($t->id, true))->firstWhere('id', $mesa);
    }

    public function eliminarMesa(int $mesa): void
    {
        $m = $this->mesa($mesa);
        if (ContaMesaPOS::query()->where('mesa_pos_id', $m->id)->where('estado', 'ABERTA')->exists()) {
            throw new ErroNegocio('A mesa tem uma conta aberta: cobre-a ou liberte-a primeiro.', 'MESA_COM_CONTA', 422);
        }
        if (ContaMesaPOS::query()->where('mesa_pos_id', $m->id)->exists()) {
            $m->update(['ativo' => false]);   // mantém o histórico

            return;
        }
        $m->delete();
    }

    /** Conta aberta da mesa (com as linhas) ou null. */
    public function conta(int $mesa): ?array
    {
        $m = $this->mesa($mesa);
        $c = ContaMesaPOS::query()->where('mesa_pos_id', $m->id)->where('estado', 'ABERTA')->first();

        return $c ? $this->detalhe($c, $m->nome) : null;
    }

    /**
     * Grava (abre ou actualiza) a conta da mesa — o «Suspender» do legado. Sem linhas, liberta a mesa (ANULADA).
     *
     * @param  array{linhas: list<array{produto_id: int, quantidade: float|string, preco_unitario?: float|string|null}>, percentagem_desconto?: float|string|null,
     *               cliente_id?: ?int, observacoes?: ?string, versao?: ?int}  $d
     */
    public function gravarConta(int $mesa, array $d): ?array
    {
        return DB::transaction(function () use ($mesa, $d) {
            $m = $this->mesa($mesa);
            MesaPOS::query()->whereKey($m->id)->lockForUpdate()->first();   // serializa as gravações da mesma mesa
            $c = ContaMesaPOS::query()->where('mesa_pos_id', $m->id)->where('estado', 'ABERTA')->lockForUpdate()->first();
            $this->exigirVersao($c, $d['versao'] ?? null);
            $linhas = $this->normalizarLinhas($d['linhas'] ?? []);
            $operador = Auth::user()?->nomeApresentacao();
            if (! $linhas) {
                if ($c) {
                    $c->update(['estado' => 'ANULADA', 'fechada_em' => now(), 'operador' => $operador]);
                    $this->auditoria->registar('POS', 'Libertou a mesa', "Mesa {$m->nome}: conta {$c->id} libertada sem venda", 'contas_mesa_pos', $c->id);
                }

                return null;
            }
            $valores = ['linhas' => $linhas, 'percentagem_desconto' => number_format((float) ($d['percentagem_desconto'] ?? 0), 4, '.', ''),
                'cliente_id' => $d['cliente_id'] ?? null, 'observacoes' => $d['observacoes'] ?? null, 'operador' => $operador];
            if ($c) {
                $c->update($valores + ['versao' => $c->versao + 1]);
                $id = $c->id;
            } else {
                $id = ContaMesaPOS::query()->create($valores + ['empresa_id' => $m->empresa_id, 'mesa_pos_id' => $m->id, 'terminal_pos_id' => $m->terminal_pos_id,
                    'estado' => 'ABERTA', 'versao' => 1, 'aberta_em' => now()])->id;
            }

            return $this->detalhe(ContaMesaPOS::query()->findOrFail($id), $m->nome);
        });
    }

    /**
     * Cobra a conta da mesa: emite a factura-recibo (ServicoVendasPOS) com o nome da mesa e fecha a conta, numa transacção.
     * O pedido traz o carrinho final (o operador pode tê-lo acertado antes de cobrar) e os pagamentos.
     *
     * @param  array<string, mixed>  $d  dados da venda POS + versao
     */
    public function cobrar(int $sessao, int $mesa, array $d): Venda
    {
        return DB::transaction(function () use ($sessao, $mesa, $d) {
            $m = $this->mesa($mesa);
            $s = SessaoPOS::query()->findOrFail($sessao);
            if ((int) $s->terminal_pos_id !== (int) $m->terminal_pos_id) {
                throw new ErroNegocio('A mesa pertence a outro terminal.', 'MESA_OUTRO_TERMINAL', 422);
            }
            MesaPOS::query()->whereKey($m->id)->lockForUpdate()->first();
            $c = ContaMesaPOS::query()->where('mesa_pos_id', $m->id)->where('estado', 'ABERTA')->lockForUpdate()->first();
            $this->exigirVersao($c, $d['versao'] ?? null);
            unset($d['versao']);
            $venda = $this->vendas->vender($s, $d, ['nome_tabela' => $m->nome]);
            $valores = ['estado' => 'FECHADA', 'venda_id' => $venda->id, 'sessao_pos_id' => $s->id, 'fechada_em' => now(),
                'linhas' => $this->normalizarLinhas($d['linhas']), 'percentagem_desconto' => number_format((float) ($d['percentagem_desconto'] ?? 0), 4, '.', ''),
                'operador' => Auth::user()?->nomeApresentacao()];
            if ($c) {
                $c->update($valores);
            } else {   // cobrança directa sem conta suspensa: fica o registo da mesa
                ContaMesaPOS::query()->create($valores + ['empresa_id' => $m->empresa_id, 'mesa_pos_id' => $m->id, 'terminal_pos_id' => $m->terminal_pos_id,
                    'versao' => 1, 'aberta_em' => now(), 'cliente_id' => $d['cliente_id'] ?? null]);
            }

            return $venda;
        });
    }

    // ───────────── apoio ─────────────

    private function terminal(int $id): TerminalPOS
    {
        return TerminalPOS::query()->findOrFail($id);
    }

    private function mesa(int $id, ?int $terminal = null): MesaPOS
    {
        $m = MesaPOS::query()->whereKey($id)->when($terminal, fn ($q) => $q->where('terminal_pos_id', $terminal))->first();

        return $m ?? throw new ErroNegocio('Mesa não encontrada.', 'NAO_ENCONTRADO', 404);
    }

    private function exigirVersao(?ContaMesaPOS $c, mixed $versao): void
    {
        if ($versao !== null && $versao !== '' && (! $c || (int) $c->versao !== (int) $versao)) {
            throw new ErroNegocio('A conta desta mesa foi alterada noutro posto: reabra a mesa para ver a versão actual.', 'CONTA_MESA_ALTERADA', 409,
                ['versao_actual' => $c ? (int) $c->versao : null]);
        }
    }

    /** @return list<array{produto_id: int, quantidade: string, preco_unitario?: string}> */
    private function normalizarLinhas(array $linhas): array
    {
        $out = [];
        foreach ($linhas as $l) {
            $q = number_format((float) $l['quantidade'], 3, '.', '');
            if (bccomp($q, '0', 3) <= 0) {
                continue;
            }
            $out[] = ['produto_id' => (int) $l['produto_id'], 'quantidade' => $q]
                + (isset($l['preco_unitario']) && $l['preco_unitario'] !== '' && $l['preco_unitario'] !== null ? ['preco_unitario' => number_format((float) $l['preco_unitario'], 2, '.', '')] : []);
        }
        $ids = array_unique(array_column($out, 'produto_id'));
        if ($ids && Produto::query()->whereKey($ids)->count() !== count($ids)) {
            throw new ErroNegocio('Há produtos na conta que não existem nesta empresa.', 'PRODUTO_INEXISTENTE', 422);
        }

        return $out;
    }

    /** @return array<int, array{preco: string, taxa: string, nome: string}> */
    private function precos(array $ids): array
    {
        return $ids ? Produto::query()->whereKey($ids)->get(['id', 'nome', 'preco_unitario', 'taxa_imposto'])
            ->mapWithKeys(fn ($p) => [$p->id => ['preco' => (string) ($p->preco_unitario ?? '0'), 'taxa' => (string) ($p->taxa_imposto ?? '0'), 'nome' => (string) $p->nome]])->all() : [];
    }

    /** Total estimado (mesmo cálculo da venda POS: preços com IVA e desconto global). */
    private function total(array $linhas, string $pct, array $precos): string
    {
        if (! $linhas) {
            return '0.00';
        }

        return CalculadoraDocumento::calcularComIva(array_map(fn ($l) => ['quantidade' => $l['quantidade'],
            'preco_unitario' => $l['preco_unitario'] ?? $precos[$l['produto_id']]['preco'] ?? '0', 'taxa_imposto' => $precos[$l['produto_id']]['taxa'] ?? '0'], $linhas), $pct)['total_bruto'];
    }

    private function resumo(ContaMesaPOS $c, array $precos): array
    {
        $linhas = $c->linhas ?: [];

        return ['id' => (int) $c->id, 'versao' => (int) $c->versao, 'itens' => count($linhas), 'total' => $this->total($linhas, (string) $c->getRawOriginal('percentagem_desconto'), $precos),
            'operador' => $c->operador, 'aberta_em' => $c->getRawOriginal('aberta_em'), 'atualizado_em' => $c->getRawOriginal('atualizado_em')];
    }

    private function detalhe(ContaMesaPOS $c, string $mesa): array
    {
        $linhas = $c->linhas ?: [];
        $precos = $this->precos(array_column($linhas, 'produto_id'));

        return $this->resumo($c, $precos) + ['mesa' => $mesa, 'mesa_pos_id' => (int) $c->mesa_pos_id, 'estado' => $c->estado, 'linhas' => $linhas,
            'percentagem_desconto' => (string) $c->getRawOriginal('percentagem_desconto'), 'cliente_id' => $c->cliente_id ? (int) $c->cliente_id : null, 'observacoes' => $c->observacoes];
    }
}
