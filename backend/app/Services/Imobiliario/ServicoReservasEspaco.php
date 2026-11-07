<?php

namespace App\Services\Imobiliario;

use App\Exceptions\ErroNegocio;
use App\Models\ContratoArrendamento;
use App\Models\ContratoFracao;
use App\Models\EspacoComum;
use App\Models\Fracao;
use App\Models\HistoricoReservaImo;
use App\Models\ReservaEspaco;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reservas das áreas comuns (gravarReserva, elegibilidade, mudarEstado e eliminarReserva do legado). Só marca quem tem contrato de
 * arrendamento em vigor numa fracção do imóvel (ou a administração, em reserva interna). A verificação diz tudo o que impede ou avisa:
 * espaço activo, regime, dia e horário, duração, antecedência, conflito de horário, contrato em vigor, limite mensal por fracção, dívida
 * de rendas (que exige a autorização de quem assume, com o nome e o motivo gravados) e capacidade. O espaço é bloqueado (FOR UPDATE)
 * ao gravar para que duas marcações em simultâneo não ocupem o mesmo horário. Correcções (P3): a verificação em edição já não conta a
 * própria reserva como conflito; aprovar uma reserva paga leva a «a aguardar pagamento» (o legado saltava-o); o prazo de pagamento
 * conta desde que a reserva fica a aguardar pagamento (o legado só o marcava ao facturar e o horário ficava preso para sempre); uma
 * reserva facturada não se edita (cancela-se); estados e trilho em historico_reserva_imo.
 */
final class ServicoReservasEspaco
{
    public function __construct(private readonly ServicoAreasComuns $areas, private readonly ServicoContaCorrente $contaCorrente) {}

    // ---------------------------------------------------------------- elegibilidade

    /**
     * Tudo o que impede (impedimentos) ou deve avisar (avisos) sobre uma marcação, e o preço. Não grava nada.
     *
     * @return array<string, mixed>
     */
    public function elegibilidade(int $espacoId, ?int $fracaoId, ?string $data, ?string $inicio, ?string $fim, ?int $excepto = null, ?int $pessoas = null): array
    {
        $r = ['espaco' => null, 'impedimentos' => [], 'avisos' => [], 'exige_autorizacao' => false, 'divida' => 0.0, 'contrato_id' => null, 'terceiro_id' => null, 'conflitos' => [],
            'valor' => '0.00', 'caucao' => '0.00', 'detalhe' => 'Sem custo', 'horas' => null];
        $e = EspacoComum::query()->find($espacoId);
        if (! $e) {
            $r['impedimentos'][] = 'Espaço não encontrado.';

            return $r;
        }
        $r['espaco'] = $e->only(['id', 'nome', 'tipo', 'regime', 'imovel_id']);
        if (! $e->activo) {
            $r['impedimentos'][] = 'O espaço está inactivo.';
        }
        $regime = CatalogoParqueamento::REGIMES[$e->regime];
        if (! $regime['reserva']) {
            $r['impedimentos'][] = "«{$e->nome}» é de uso livre: não se marca.";
        }
        if (! $data || preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) !== 1) {
            $r['impedimentos'][] = 'Indique a data.';

            return $r;
        }
        $dia = CarbonImmutable::parse($data);
        $dow = (int) $dia->format('w');
        if (! in_array($dow, array_map('intval', $e->dias_semana ?? []), true)) {
            $r['impedimentos'][] = CatalogoParqueamento::DIAS[$dow].' não é dia de funcionamento (abre '.implode(', ', array_map(fn ($d) => CatalogoParqueamento::DIAS[(int) $d], $e->dias_semana ?? [])).').';
        }
        $a = CatalogoParqueamento::minutos($inicio);
        $b = CatalogoParqueamento::minutos($fim);
        $horas = null;
        if ($a === null || $b === null) {
            $r['impedimentos'][] = 'Indique a hora de início e de fim.';
        } elseif ($b <= $a) {
            $r['impedimentos'][] = 'A hora de fim tem de ser depois da de início.';
        } else {
            $horas = CalculadoraReserva::horasEntre($inicio, $fim);
            $r['horas'] = $horas;
            if ($a < (int) CatalogoParqueamento::minutos($e->hora_abre) || $b > (int) CatalogoParqueamento::minutos($e->hora_fecha)) {
                $r['impedimentos'][] = "Fora do horário: o espaço funciona das {$e->hora_abre} às {$e->hora_fecha}.";
            }
            $fmt = fn (float $h) => rtrim(rtrim(number_format($h, 2, '.', ''), '0'), '.');
            if ($e->duracao_min && (float) $horas < (float) $e->duracao_min) {
                $r['impedimentos'][] = 'A reserva mínima é de '.$fmt((float) $e->duracao_min)." h e esta tem {$horas} h.";
            }
            if ($e->duracao_max && (float) $horas > (float) $e->duracao_max) {
                $r['impedimentos'][] = 'A reserva máxima é de '.$fmt((float) $e->duracao_max)." h e esta tem {$horas} h.";
            }
            $valor = CalculadoraReserva::valor($e->regime, $e->preco, $e->preco_unidade, $e->caucao, $horas);
            $r['valor'] = $valor['valor'];
            $r['caucao'] = $valor['caucao'];
            $r['detalhe'] = $valor['detalhe'];
        }
        $faltam = (int) CarbonImmutable::parse(now()->toDateString())->diffInDays($dia, false);
        if ($faltam < 0) {
            $r['impedimentos'][] = 'A data já passou.';
        } elseif ($faltam < $e->antecedencia_min_dias) {
            $r['impedimentos'][] = "Marca-se com pelo menos {$e->antecedencia_min_dias} dia(s) de antecedência e faltam {$faltam}.";
        }
        if ($e->antecedencia_max_dias > 0 && $faltam > $e->antecedencia_max_dias) {
            $r['impedimentos'][] = "Não se marca com mais de {$e->antecedencia_max_dias} dia(s) de antecedência.";
        }
        if ($a !== null && $b !== null && $b > $a) {
            foreach ($this->conflitos($e->id, $data, $a, $b, $excepto) as $c) {
                $r['conflitos'][] = $c->only(['id', 'hora_inicio', 'hora_fim', 'estado']);
                $r['impedimentos'][] = "Já há reserva das {$c->hora_inicio} às {$c->hora_fim} (".CatalogoParqueamento::ESTADOS_RESERVA[$c->estado]['rot'].').';
            }
        }
        if ($pessoas !== null && $e->capacidade && $pessoas > $e->capacidade) {
            $r['impedimentos'][] = "O espaço comporta {$e->capacidade} pessoas e a reserva indica {$pessoas}.";
        }
        if (! $fracaoId) {
            $r['avisos'][] = 'Reserva interna, sem fracção: fica em nome da administração.';

            return $r;
        }
        $f = Fracao::query()->find($fracaoId);
        if (! $f) {
            $r['impedimentos'][] = 'Fracção não encontrada.';

            return $r;
        }
        if ((int) $f->imovel_id !== (int) $e->imovel_id) {
            $r['impedimentos'][] = 'A fracção é de outro imóvel: só os moradores do prédio usam as suas áreas comuns.';

            return $r;
        }
        $contratos = $this->contratosDaFraccao($fracaoId);
        $vivo = $contratos->first(fn ($c) => $c->inicio->format('Y-m-d') <= $data && ($c->fim === null || $c->fim->format('Y-m-d') >= $data));
        if (! $vivo) {
            $r['impedimentos'][] = $contratos->isNotEmpty() ? 'O contrato da fracção não cobre esta data.'
                : 'A fracção não tem contrato de arrendamento em vigor. Se é o proprietário a usar, marque como reserva interna.';

            return $r;
        }
        $r['contrato_id'] = (int) $vivo->id;
        $r['terceiro_id'] = (int) $vivo->terceiro_id;
        if ($e->limite_mes_fraccao) {
            $ja = ReservaEspaco::query()->where('espaco_id', $e->id)->where('fracao_id', $fracaoId)->where('data', '>=', $dia->startOfMonth()->toDateString())->where('data', '<=', $dia->endOfMonth()->toDateString())
                ->when($excepto, fn ($q) => $q->where('id', '<>', $excepto))->whereIn('estado', [...$this->areas->estadosQueOcupam(), 'FALTOU'])->orderBy('data')->get(['data']);
            $lim = (int) $e->limite_mes_fraccao;
            $datas = $ja->map(fn ($x) => $x->data->format('Y-m-d'))->implode(', ');
            if ($ja->count() >= $lim) {
                $r['impedimentos'][] = $lim === 1 ? "A fracção já marcou este mês ({$datas}) e só pode uma vez por mês." : "A fracção já usou as {$lim} reservas deste mês ({$datas}).";
            } elseif ($ja->count() > 0 && $ja->count() === $lim - 1) {
                $r['avisos'][] = "Com esta, a fracção esgota as {$lim} reservas que tem direito este mês.";
            }
        }
        $divida = (float) ($this->contaCorrente->doContrato($vivo)['totais']['em_divida'] ?? 0);
        if ($divida > 0.009) {
            $r['divida'] = round($divida, 2);
            $r['exige_autorizacao'] = true;
            $r['avisos'][] = 'A fracção tem '.number_format($divida, 2, ',', ' ').' Kz de rendas em dívida.';
        }

        return $r;
    }

    /** @return Collection<int, ReservaEspaco> reservas que ocupam o horário e se sobrepõem a [a,b) (semiaberto) */
    private function conflitos(int $espacoId, string $data, int $a, int $b, ?int $excepto, array $estados = []): Collection
    {
        return ReservaEspaco::query()->where('espaco_id', $espacoId)->where('data', $data)->whereIn('estado', $estados ?: $this->areas->estadosQueOcupam())
            ->when($excepto, fn ($q) => $q->where('id', '<>', $excepto))->get(['id', 'hora_inicio', 'hora_fim', 'estado'])
            ->filter(fn ($r) => CalculadoraReserva::sobrepoe((int) CatalogoParqueamento::minutos($r->hora_inicio), (int) CatalogoParqueamento::minutos($r->hora_fim), $a, $b))->values();
    }

    /** @return Collection<int, ContratoArrendamento> contratos activos (lado senhorio) que abrangem a fracção */
    private function contratosDaFraccao(int $fracaoId): Collection
    {
        $ids = ContratoFracao::query()->where('fracao_id', $fracaoId)->where('posicao', 'SENHORIO')->pluck('contrato_id');

        return ContratoArrendamento::query()->whereIn('id', $ids)->where('estado', 'ACTIVO')->orderBy('inicio')->get();
    }

    // ---------------------------------------------------------------- gravar

    /**
     * Marca (ou altera) uma reserva.
     *
     * @param  array<string, mixed>  $d  espaco_id, fracao_id (vazio = interna), data, hora_inicio, hora_fim, pessoas, motivo, contacto, autorizacao_motivo, notas
     * @param  bool  $podeAutorizar  o utilizador tem imo_reserva_devedor
     */
    public function gravar(array $d, ?ReservaEspaco $existente = null, bool $podeAutorizar = false): ReservaEspaco
    {
        $espacoId = Normaliza::inteiro($d['espaco_id'] ?? $existente?->espaco_id, true);
        if (! $espacoId) {
            throw new ErroNegocio('Escolha o espaço.', 'RESERVA_SEM_ESPACO');
        }

        return DB::transaction(function () use ($d, $existente, $podeAutorizar, $espacoId) {
            $e = EspacoComum::query()->whereKey($espacoId)->lockForUpdate()->first() ?? throw new ErroNegocio('Espaço não encontrado.', 'RESERVA_ESPACO_INEXISTENTE', 404);
            $r = $existente ? ReservaEspaco::query()->whereKey($existente->id)->lockForUpdate()->firstOrFail() : null;
            if ($r !== null) {
                if (! CatalogoParqueamento::ESTADOS_RESERVA[$r->estado]['viva']) {
                    throw new ErroNegocio('Uma reserva «'.mb_strtolower(CatalogoParqueamento::ESTADOS_RESERVA[$r->estado]['rot']).'» não se altera. Faça uma nova.', 'RESERVA_NAO_VIVA');
                }
                if ($r->venda_id !== null || (float) $r->caucao_recebida > 0) {
                    throw new ErroNegocio('A reserva já está facturada ou tem caução recebida: cancele-a e faça uma nova.', 'RESERVA_FACTURADA');
                }
                if ((int) $r->espaco_id !== (int) $e->id) {
                    throw new ErroNegocio('A reserva não muda de espaço: cancele-a e faça uma nova.', 'RESERVA_MUDA_ESPACO');
                }
            }
            $fracaoId = array_key_exists('fracao_id', $d) ? (Normaliza::inteiro($d['fracao_id'], true) ?: null) : $r?->fracao_id;
            $data = Normaliza::texto($d['data'] ?? null) ?? $r?->data?->format('Y-m-d');
            $ini = Normaliza::texto($d['hora_inicio'] ?? null) ?? $r?->hora_inicio;
            $fim = Normaliza::texto($d['hora_fim'] ?? null) ?? $r?->hora_fim;
            $pessoas = array_key_exists('pessoas', $d) ? Normaliza::inteiro($d['pessoas'], true) : $r?->pessoas;
            $pessoas = $pessoas !== null && $pessoas > 0 ? $pessoas : null;
            $el = $this->elegibilidade($e->id, $fracaoId, $data, $ini, $fim, $r?->id, $pessoas);
            if ($el['impedimentos'] !== []) {
                throw new ErroNegocio(implode(' ', $el['impedimentos']), 'RESERVA_IMPEDIDA', 422, ['impedimentos' => $el['impedimentos']]);
            }
            $motivoAut = Normaliza::texto($d['autorizacao_motivo'] ?? null);
            $divida = (float) $el['divida'];
            if ($el['exige_autorizacao'] && $motivoAut === null) {
                throw new ErroNegocio('A fracção tem '.number_format($divida, 2, ',', ' ').' Kz em dívida. Para marcar assim mesmo é preciso o motivo da autorização de quem assume a decisão.', 'RESERVA_DEVEDOR');
            }
            if ($el['exige_autorizacao'] && ! $podeAutorizar) {
                throw new ErroNegocio('A fracção tem rendas em dívida e só quem tem a permissão de autorizar reservas de devedores pode marcar assim.', 'RESERVA_DEVEDOR_SEM_PERMISSAO', 403);
            }
            $paga = CatalogoParqueamento::REGIMES[$e->regime]['paga'] && bccomp($el['valor'], '0', 2) > 0;
            $campos = ['espaco_id' => $e->id, 'imovel_id' => $e->imovel_id, 'fracao_id' => $fracaoId, 'contrato_id' => $el['contrato_id'], 'terceiro_id' => $el['terceiro_id'], 'interna' => $fracaoId === null,
                'data' => $data, 'hora_inicio' => $ini, 'hora_fim' => $fim, 'pessoas' => $pessoas, 'motivo' => array_key_exists('motivo', $d) ? Normaliza::texto($d['motivo']) : $r?->motivo,
                'contacto' => array_key_exists('contacto', $d) ? Normaliza::texto($d['contacto']) : $r?->contacto, 'valor' => $el['valor'], 'valor_detalhe' => $el['detalhe'], 'caucao' => $el['caucao'],
                'notas' => array_key_exists('notas', $d) ? Normaliza::texto($d['notas']) : $r?->notas, 'atualizado_por' => Autor::nome()];
            $campos['divida_na_marcacao'] = $divida;
            if ($el['exige_autorizacao']) {
                $campos += ['autorizacao_motivo' => mb_substr((string) $motivoAut, 0, 300), 'autorizacao_por' => Autor::nome(), 'autorizacao_em' => now()];
            } elseif ($r === null || $divida <= 0.009) {
                $campos += ['autorizacao_motivo' => null, 'autorizacao_por' => null, 'autorizacao_em' => null];
            }
            if ($r === null) {
                $estado = $e->exige_aprovacao ? 'PEDIDA' : ($paga ? 'AGUARDA_PAGAMENTO' : 'APROVADA');
                $r = ReservaEspaco::query()->create($campos + ['estado' => $estado, 'estado_em' => now(), 'estado_por' => Autor::nome(), 'criado_por' => Autor::nome()]);
                if ($estado === 'AGUARDA_PAGAMENTO') {
                    $r->update(['prazo_pagamento' => CalculadoraReserva::prazoPagamento(now(), $e->prazo_pagamento_horas, $data, $ini)]);
                }
                $this->registar($r, 'CRIADA', null, $estado, null, $el['exige_autorizacao'] ? ['autorizada_com_divida' => $divida] : null);
            } else {
                $r->update($campos);
                if ($r->estado === 'AGUARDA_PAGAMENTO') {
                    $r->update(['prazo_pagamento' => CalculadoraReserva::prazoPagamento(now(), $e->prazo_pagamento_horas, $data, $ini)]);
                }
                $this->registar($r, 'EDITADA', null, $r->estado, null);
            }

            return $r->refresh();
        });
    }

    // ---------------------------------------------------------------- estados

    /**
     * Muda o estado seguindo as transições. Recusar e cancelar exigem motivo; realizada e «não compareceu» só depois da data; aprovar e confirmar
     * voltam a verificar o horário (ignorando as reservas só pedidas). Aprovar uma reserva paga leva a «a aguardar pagamento», com o prazo a contar.
     * Cancelar uma reserva com dinheiro envolvido passa por ServicoReservasFinanceiro::cancelar (reembolso, nota de crédito).
     */
    public function mudarEstado(ReservaEspaco $reserva, string $estado, ?string $motivo, bool $sistema = false): ReservaEspaco
    {
        if (! isset(CatalogoParqueamento::ESTADOS_RESERVA[$estado])) {
            throw new ErroNegocio('Estado desconhecido.', 'RESERVA_ESTADO');
        }

        return DB::transaction(function () use ($reserva, $estado, $motivo, $sistema) {
            $r = ReservaEspaco::query()->whereKey($reserva->id)->lockForUpdate()->firstOrFail();
            $de = $r->estado;
            $rot = fn (string $e) => mb_strtolower(CatalogoParqueamento::ESTADOS_RESERVA[$e]['rot']);
            $e = EspacoComum::query()->whereKey($r->espaco_id)->lockForUpdate()->firstOrFail();
            // aprovar uma reserva paga: o destino é «a aguardar pagamento»
            $destino = $estado === 'APROVADA' && $de === 'PEDIDA' && CatalogoParqueamento::REGIMES[$e->regime]['paga'] && (float) $r->valor > 0 ? 'AGUARDA_PAGAMENTO' : $estado;
            if (! in_array($destino, CatalogoParqueamento::TRANSICOES[$de] ?? [], true)) {
                throw new ErroNegocio("Uma reserva «{$rot($de)}» não passa a «{$rot($estado)}».", 'RESERVA_TRANSICAO');
            }
            if ($destino === 'CONFIRMADA' && ! $sistema) {
                throw new ErroNegocio('Uma reserva só fica confirmada quando o pagamento entra.', 'RESERVA_CONFIRMAR_SO_PAGAMENTO');
            }
            $motivo = Normaliza::texto($motivo);
            if (in_array($destino, ['RECUSADA', 'CANCELADA'], true) && $motivo === null) {
                throw new ErroNegocio('Diga o motivo: quem marcou vai perguntar porquê.', 'RESERVA_MOTIVO');
            }
            $hoje = now()->toDateString();
            if ($destino === 'REALIZADA' && $r->data->format('Y-m-d') > $hoje) {
                throw new ErroNegocio('A reserva ainda não aconteceu.', 'RESERVA_FUTURA');
            }
            if ($destino === 'FALTOU' && $r->data->format('Y-m-d') > $hoje) {
                throw new ErroNegocio('Ainda não se pode dizer que não compareceu: a data não chegou.', 'RESERVA_FUTURA');
            }
            if (in_array($destino, ['APROVADA', 'AGUARDA_PAGAMENTO', 'CONFIRMADA'], true)) {
                $a = (int) CatalogoParqueamento::minutos($r->hora_inicio);
                $b = (int) CatalogoParqueamento::minutos($r->hora_fim);
                $outras = $this->conflitos($e->id, $r->data->format('Y-m-d'), $a, $b, $r->id, array_values(array_diff($this->areas->estadosQueOcupam(), ['PEDIDA'])));
                if ($outras->isNotEmpty()) {
                    $c = $outras->first();
                    throw new ErroNegocio("O horário já está ocupado por outra reserva das {$c->hora_inicio} às {$c->hora_fim}. Recuse esta, ou mude-lhe a hora.", 'RESERVA_CONFLITO');
                }
            }
            $extra = [];
            if ($destino === 'REALIZADA') {
                $extra['realizada_em'] = now();
            }
            if ($destino === 'AGUARDA_PAGAMENTO' && $r->prazo_pagamento === null) {
                $extra['prazo_pagamento'] = CalculadoraReserva::prazoPagamento(now(), $e->prazo_pagamento_horas, $r->data->format('Y-m-d'), $r->hora_inicio);
            }
            $por = $sistema ? 'sistema' : Autor::nome();
            $r->update($extra + ['estado' => $destino, 'estado_em' => now(), 'estado_por' => $por, 'motivo_estado' => $motivo !== null ? mb_substr($motivo, 0, 300) : null, 'atualizado_por' => $por]);
            $this->registar($r, 'ESTADO', $de, $destino, $motivo);

            return $r->refresh();
        });
    }

    public function eliminar(ReservaEspaco $reserva): void
    {
        DB::transaction(function () use ($reserva) {
            $r = ReservaEspaco::query()->whereKey($reserva->id)->lockForUpdate()->firstOrFail();
            if (! CatalogoParqueamento::ESTADOS_RESERVA[$r->estado]['viva']) {
                throw new ErroNegocio('Uma reserva «'.mb_strtolower(CatalogoParqueamento::ESTADOS_RESERVA[$r->estado]['rot']).'» não se apaga: faz parte do histórico do espaço.', 'RESERVA_NAO_VIVA');
            }
            if ($r->venda_id !== null || (float) $r->caucao_recebida > 0) {
                throw new ErroNegocio('A reserva já tem documento emitido ou caução recebida. Cancele-a em vez de a apagar.', 'RESERVA_FACTURADA');
            }
            HistoricoReservaImo::query()->where('reserva_id', $r->id)->delete();
            $r->delete();
        });
    }

    // ---------------------------------------------------------------- leitura

    /**
     * @param  array{imovel_id?: ?int, espaco_id?: ?int, fracao_id?: ?int, estado?: ?string, de?: ?string, ate?: ?string, vivas?: ?bool}  $f
     * @return list<array<string, mixed>>
     */
    public function listar(array $f = []): array
    {
        $q = ReservaEspaco::query()->orderBy('data')->orderBy('hora_inicio')->orderBy('id')->limit(1000);
        foreach (['imovel_id', 'espaco_id', 'fracao_id'] as $k) {
            if (! empty($f[$k])) {
                $q->where($k, (int) $f[$k]);
            }
        }
        if (! empty($f['estado'])) {
            $q->where('estado', $f['estado']);
        }
        if (! empty($f['vivas'])) {
            $q->whereIn('estado', array_keys(array_filter(CatalogoParqueamento::ESTADOS_RESERVA, fn ($e) => $e['viva'])));
        }
        if (! empty($f['de'])) {
            $q->where('data', '>=', $f['de']);
        }
        if (! empty($f['ate'])) {
            $q->where('data', '<=', $f['ate']);
        }

        return $q->get()->map(fn ($r) => $r->attributesToArray())->all();
    }

    public function registar(ReservaEspaco $r, string $evento, ?string $de, ?string $para, ?string $motivo, ?array $dados = null): void
    {
        HistoricoReservaImo::create(['reserva_id' => $r->id, 'evento' => $evento, 'estado_de' => $de, 'estado_para' => $para, 'motivo' => $motivo !== null ? mb_substr($motivo, 0, 300) : null,
            'dados' => $dados, 'utilizador_id' => Autor::id()]);
    }
}
