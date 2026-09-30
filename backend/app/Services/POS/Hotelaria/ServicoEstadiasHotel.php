<?php

namespace App\Services\POS\Hotelaria;

use App\Exceptions\ErroNegocio;
use App\Models\EstadiaHotel;
use App\Models\Produto;
use App\Models\SessaoPOS;
use App\Models\Terceiro;
use App\Models\TerminalPOS;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Estadias da hotelaria (js/hotelaria.js): mapa de quartos, check-in (:344-386), alteração (:445-476), consumos (:101-132)
 * e anulação (:478-494). Os quartos são produtos marcados como quarto (e_quarto, preco_por_hora, preco_por_dia,
 * horas_minimas — js/ui_sales.js:6411-6422). Correcções face ao legado:
 *   - quarto ocupado: lock no produto do quarto + verificação na transacção (o legado lia e depois inseria: dois
 *     computadores podiam fazer check-in no mesmo quarto);
 *   - a sessão de caixa aberta do terminal HOTELARIA é verificada no servidor (no legado só no ecrã);
 *   - à diária, o n.º de diárias é inteiro (o legado facturava 1,5 diárias mas calculava a saída com 2);
 *   - a alteração da entrada de uma estadia à hora respeita o bloqueio horário (o legado só o verificava no check-in);
 *   - consumos: produto existente, não bloqueado e que não seja quarto, com o preço do produto por omissão; gravados na
 *     estadia com lock (no legado, com atraso de 250 ms e sem verificação de concorrência além do estado);
 *   - o operador e as datas do histórico vêm do servidor.
 * O preço do alojamento diferente do do quarto e o preço dos consumos alterado exigem pos_desconto (no controlador).
 */
final class ServicoEstadiasHotel
{
    /** Preço do quarto por modo (DIA: preço por diária ou, sem ele, o preço unitário — ui_sales.js:6420). */
    public static function precoQuarto(Produto $q, string $modo): string
    {
        $p = $modo === 'HORA' ? $q->preco_por_hora : ((float) $q->preco_por_dia > 0 ? $q->preco_por_dia : $q->preco_unitario);

        return number_format((float) $p, 2, '.', '');
    }

    /** Mapa de quartos (decorarEcra, js/hotelaria.js:206-253): livres, ocupados e com saída atrasada. */
    public function quartos(?TerminalPOS $t): array
    {
        $rg = RegrasHotel::doTerminal($t);
        $abertas = EstadiaHotel::query()->where('estado', EstadiaHotel::ABERTA)->get()->keyBy('produto_quarto_id');
        $agora = now();

        return Produto::query()->where('e_quarto', true)->where(fn ($q) => $q->whereNull('bloqueado')->orWhere('bloqueado', false))->orderBy('codigo')->get()
            ->map(function (Produto $p) use ($abertas, $rg, $agora) {
                $e = $abertas[$p->id] ?? null;

                return ['produto_id' => $p->id, 'codigo' => $p->codigo, 'nome' => $p->nome, 'preco_por_dia' => self::precoQuarto($p, 'DIA'),
                    'preco_por_hora' => number_format((float) $p->preco_por_hora, 2, '.', ''), 'horas_minimas' => number_format((float) ($p->horas_minimas ?: 1), 3, '.', ''),
                    'taxa_imposto' => (string) $p->taxa_imposto, 'estado' => $e ? 'OCUPADO' : 'LIVRE',
                    'estadia' => $e ? ['id' => $e->id, 'nome_hospede' => $e->nome_hospede, 'entrada_em' => $e->entrada_em?->toIso8601String(),
                        'saida_prevista_em' => $e->saida_prevista_em?->toIso8601String(), 'atrasado' => $agora->getTimestamp() > $e->saida_prevista_em->copy()->addMinutes($rg['tolerancia'])->getTimestamp(),
                        'total_em_aberto' => bcadd($e->totalAlojamento(), $e->totalConsumos(), 2)] : null];
            })->all();
    }

    /** Estadia com totais e proposta de saída tardia pelas regras do terminal da estadia (painelOcupado, :388-411). */
    public function detalhe(EstadiaHotel $e): array
    {
        $rg = RegrasHotel::doTerminal(TerminalPOS::query()->withTrashed()->find($e->terminal_pos_id));
        $proposta = $e->estado === EstadiaHotel::ABERTA ? RegrasHotel::propostaAtraso($e, $rg, now()) : null;

        return $e->toArray() + ['total_alojamento' => $e->totalAlojamento(), 'total_consumos' => $e->totalConsumos(),
            'total_em_aberto' => bcadd($e->totalAlojamento(), $e->totalConsumos(), 2), 'proposta_atraso' => $proposta, 'regras' => $rg];
    }

    /**
     * Check-in (hotelConfirmarCheckin, js/hotelaria.js:344-386).
     *
     * @param  array{produto_quarto_id: int, cliente_hospede_id: int, modo: string, entrada_em?: ?string, quantidade: mixed, preco_unitario?: mixed,
     *               numero_hospedes?: ?int, observacoes?: ?string}  $d
     */
    public function checkin(SessaoPOS $s, array $d): EstadiaHotel
    {
        return DB::transaction(function () use ($s, $d) {
            [$s, $t] = $this->sessaoHotel($s);
            $rg = RegrasHotel::doTerminal($t);
            $quarto = Produto::query()->lockForUpdate()->findOrFail($d['produto_quarto_id']);   // serializa os check-ins do mesmo quarto
            if (! $quarto->e_quarto || $quarto->bloqueado) {
                throw new ErroNegocio("O produto {$quarto->codigo} não é um quarto disponível.", 'QUARTO_INVALIDO', 422);
            }
            $ocupado = EstadiaHotel::query()->where('produto_quarto_id', $quarto->id)->where('estado', EstadiaHotel::ABERTA)->first();
            if ($ocupado) {
                throw new ErroNegocio("O {$ocupado->nome_quarto} já tem check-in de {$ocupado->nome_hospede} (registado às ".RegrasHotel::dataHora($ocupado->criado_em ?? $ocupado->entrada_em).').',
                    'QUARTO_OCUPADO', 422, ['estadia_id' => $ocupado->id]);
            }
            $hospede = $this->hospede((int) $d['cliente_hospede_id']);
            $modo = $d['modo'];
            $entrada = isset($d['entrada_em']) ? Carbon::parse($d['entrada_em'], config('app.timezone')) : now();
            $qtd = $this->quantidade($d['quantidade'], $modo, $quarto, $entrada, $rg);
            $preco = isset($d['preco_unitario']) ? number_format((float) $d['preco_unitario'], 2, '.', '') : self::precoQuarto($quarto, $modo);
            if ($modo === 'HORA' && (float) $quarto->preco_por_hora <= 0) {
                throw new ErroNegocio("O {$quarto->nome} não tem preço por hora: use a diária.", 'SEM_PRECO_HORA', 422);
            }
            $saida = RegrasHotel::saidaPrevista($entrada, $modo, $qtd, $rg);
            $u = Auth::user()?->nome_utilizador;
            $e = new EstadiaHotel([
                'terminal_pos_id' => $t->id, 'codigo_terminal' => $t->codigo, 'sessao_pos_id' => $s->id, 'produto_quarto_id' => $quarto->id, 'nome_quarto' => $quarto->nome,
                'estado' => EstadiaHotel::ABERTA, 'cliente_hospede_id' => $hospede->id, 'nome_hospede' => $hospede->nome,
                'numero_hospedes' => max(1, (int) ($d['numero_hospedes'] ?? 1)), 'modo' => $modo, 'entrada_em' => $entrada, 'saida_prevista_em' => $saida,
                'quantidade' => $qtd, 'preco_unitario' => $preco, 'taxa_imposto' => $quarto->taxa_imposto, 'observacoes' => trim((string) ($d['observacoes'] ?? '')) ?: null,
                'itens' => [], 'historico_alteracoes' => [], 'criado_por' => $u,
            ]);
            $e->registar('Check-in: '.RegrasHotel::unidade($modo, $qtd).' × '.RegrasHotel::kz($preco).', saída prevista '.RegrasHotel::dataHora($saida).'.', $u);
            $e->save();

            return $e->refresh();
        });
    }

    /**
     * Alteração (hotelGravarAlteracao, :445-476): hóspede, entrada, quantidade, preço, n.º de hóspedes e observações;
     * a saída prevista é recalculada com as regras do terminal da estadia e a alteração fica no histórico.
     *
     * @param  array<string, mixed>  $d
     */
    public function alterar(EstadiaHotel $e, array $d): EstadiaHotel
    {
        return DB::transaction(function () use ($e, $d) {
            $e = $this->aberta($e);
            $quarto = Produto::query()->withTrashed()->findOrFail($e->produto_quarto_id);
            $rg = RegrasHotel::doTerminal(TerminalPOS::query()->withTrashed()->find($e->terminal_pos_id));
            $antes = ['hospede' => $e->nome_hospede, 'entrada' => $e->entrada_em->copy(), 'qtd' => number_format((float) $e->quantidade, 3, '.', ''), 'preco' => (string) $e->preco_unitario];
            $hospede = isset($d['cliente_hospede_id']) ? $this->hospede((int) $d['cliente_hospede_id']) : null;
            $entrada = isset($d['entrada_em']) ? Carbon::parse($d['entrada_em'], config('app.timezone')) : $e->entrada_em;
            $qtd = $this->quantidade($d['quantidade'] ?? $e->quantidade, $e->modo, $quarto, $entrada, $rg, $entrada->getTimestamp() !== $antes['entrada']->getTimestamp());
            $preco = isset($d['preco_unitario']) ? number_format((float) $d['preco_unitario'], 2, '.', '') : $antes['preco'];
            $mudancas = [];
            if ($hospede && $hospede->id !== $e->cliente_hospede_id) {
                $mudancas[] = "hóspede {$e->nome_hospede} → {$hospede->nome}";
            }
            if ($entrada->getTimestamp() !== $antes['entrada']->getTimestamp()) {
                $mudancas[] = 'entrada '.RegrasHotel::dataHora($antes['entrada']).' → '.RegrasHotel::dataHora($entrada);
            }
            if (bccomp($qtd, $antes['qtd'], 3) !== 0) {
                $mudancas[] = RegrasHotel::unidade($e->modo, $antes['qtd']).' → '.RegrasHotel::unidade($e->modo, $qtd);
            }
            if (bccomp($preco, $antes['preco'], 2) !== 0) {
                $mudancas[] = 'preço '.RegrasHotel::kz($antes['preco']).' → '.RegrasHotel::kz($preco);
            }
            $u = Auth::user()?->nome_utilizador;
            $e->fill([
                'cliente_hospede_id' => $hospede?->id ?? $e->cliente_hospede_id, 'nome_hospede' => $hospede?->nome ?? $e->nome_hospede, 'entrada_em' => $entrada,
                'saida_prevista_em' => RegrasHotel::saidaPrevista($entrada, $e->modo, $qtd, $rg), 'quantidade' => $qtd, 'preco_unitario' => $preco,
                'numero_hospedes' => array_key_exists('numero_hospedes', $d) ? max(1, (int) $d['numero_hospedes']) : $e->numero_hospedes,
                'observacoes' => array_key_exists('observacoes', $d) ? (trim((string) $d['observacoes']) ?: null) : $e->observacoes, 'atualizado_por' => $u,
            ]);
            $e->registar('Estadia alterada'.($mudancas ? ': '.implode('; ', $mudancas) : '').'.', $u);
            $e->save();

            return $e->refresh();
        });
    }

    /**
     * Consumos do quarto (gravarConsumos, :107-126): substitui a lista (como o carrinho do quarto no legado).
     *
     * @param  list<array{produto_id: int, quantidade: mixed, preco_unitario?: mixed}>  $linhas
     */
    public function consumos(EstadiaHotel $e, array $linhas): EstadiaHotel
    {
        return DB::transaction(function () use ($e, $linhas) {
            $e = $this->aberta($e);
            $produtos = Produto::query()->whereIn('id', array_column($linhas, 'produto_id'))->get()->keyBy('id');
            $itens = [];
            foreach ($linhas as $l) {
                $p = $produtos[$l['produto_id']] ?? throw new ErroNegocio("Produto #{$l['produto_id']} inexistente.", 'PRODUTO_INEXISTENTE', 422);
                if ($p->e_quarto) {
                    throw new ErroNegocio('O alojamento lança-se pelo check-in no mapa de quartos.', 'CONSUMO_QUARTO', 422);
                }
                if ($p->bloqueado) {
                    throw new ErroNegocio("O produto {$p->codigo} está bloqueado.", 'PRODUTO_BLOQUEADO', 422);
                }
                $itens[] = ['produto_id' => $p->id, 'descricao' => $p->nome, 'quantidade' => (float) $l['quantidade'],
                    'preco_unitario' => (float) number_format((float) ($l['preco_unitario'] ?? $p->preco_unitario), 2, '.', ''), 'taxa_imposto' => (float) $p->taxa_imposto];
            }
            $e->update(['itens' => $itens, 'atualizado_por' => Auth::user()?->nome_utilizador]);

            return $e->refresh();
        });
    }

    /** Anulação do check-in (hotelAnularEstadia, :478-494): só sem consumos e com motivo. */
    public function anular(EstadiaHotel $e, string $motivo): EstadiaHotel
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 3) {
            throw new ErroNegocio('Indique o motivo da anulação.', 'MOTIVO_OBRIGATORIO', 422);
        }

        return DB::transaction(function () use ($e, $motivo) {
            $e = $this->aberta($e);
            if ($e->itens) {
                throw new ErroNegocio('A estadia tem consumos lançados. Remova-os antes de anular, ou faça o check-out.', 'ESTADIA_COM_CONSUMOS', 422);
            }
            $u = Auth::user()?->nome_utilizador;
            $e->fill(['estado' => EstadiaHotel::ANULADA, 'motivo_cancelamento' => $motivo, 'fechado_em' => now(), 'fechado_por' => $u]);
            $e->registar("Check-in anulado — {$motivo}.", $u);
            $e->save();

            return $e->refresh();
        });
    }

    /**
     * Sessão aberta de um terminal HOTELARIA activo (sessaoValida do legado).
     *
     * @return array{0: SessaoPOS, 1: TerminalPOS}
     */
    public function sessaoHotel(SessaoPOS $s): array
    {
        $s = SessaoPOS::query()->lockForUpdate()->findOrFail($s->id);
        if ($s->estado !== 'ABERTA') {
            throw new ErroNegocio('A sessão de caixa já foi fechada. Abra uma nova sessão.', 'SESSAO_NAO_ABERTA', 422);
        }
        $t = TerminalPOS::query()->findOrFail($s->terminal_pos_id);
        if ($t->tipo !== 'HOTELARIA') {
            throw new ErroNegocio("O terminal {$t->codigo} não é de hotelaria.", 'TERMINAL_NAO_HOTELARIA', 422);
        }
        if (! $t->ativo) {
            throw new ErroNegocio('O terminal está inactivo.', 'TERMINAL_INATIVO', 422);
        }

        return [$s, $t];
    }

    /** Estadia bloqueada e ainda aberta (possivelmente fechada noutro computador). */
    public function aberta(EstadiaHotel $e): EstadiaHotel
    {
        $e = EstadiaHotel::query()->lockForUpdate()->findOrFail($e->id);
        if ($e->estado !== EstadiaHotel::ABERTA) {
            throw new ErroNegocio("A estadia do {$e->nome_quarto} já não está aberta (possivelmente fechada noutro computador).", 'ESTADIA_NAO_ABERTA', 422);
        }

        return $e;
    }

    private function hospede(int $id): Terceiro
    {
        $h = Terceiro::query()->findOrFail($id);
        if (! $h->eCliente()) {
            throw new ErroNegocio('O hóspede tem de estar registado como cliente.', 'HOSPEDE_INVALIDO', 422);
        }

        return $h;
    }

    /** Quantidade válida: > 0; à diária inteira; à hora ≥ mínimo do quarto e fora da janela bloqueada (hotelPrever, :322-342). */
    private function quantidade(mixed $valor, string $modo, Produto $quarto, Carbon $entrada, array $rg, bool $verificarBloqueio = true): string
    {
        $q = number_format((float) $valor, 3, '.', '');
        if (bccomp($q, '0', 3) <= 0) {
            throw new ErroNegocio('Indique a quantidade de diárias ou horas.', 'QUANTIDADE_INVALIDA', 422);
        }
        if ($modo === 'DIA' && bccomp($q, bcadd($q, '0', 0), 3) !== 0) {
            throw new ErroNegocio('O n.º de diárias tem de ser inteiro.', 'QUANTIDADE_INVALIDA', 422);
        }
        if ($modo === 'HORA') {
            $minimo = number_format((float) ($quarto->horas_minimas ?: 1), 3, '.', '');
            if (bccomp($q, $minimo, 3) < 0) {
                throw new ErroNegocio('O mínimo é de '.RegrasHotel::unidade('HORA', $minimo).'.', 'MINIMO_HORAS', 422, ['minimo' => $minimo]);
            }
            if ($verificarBloqueio && RegrasHotel::horaBloqueada($entrada, $rg)) {
                throw new ErroNegocio("A venda à hora está bloqueada entre as {$rg['de']} e as {$rg['ate']}. Use a diária.", 'HORA_BLOQUEADA', 422);
            }
        }

        return $q;
    }
}
