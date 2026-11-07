<?php

namespace App\Services\Imobiliario;

use App\Exceptions\ErroNegocio;
use App\Models\EspacoComum;
use App\Models\Fracao;
use App\Models\Imovel;
use App\Models\ReservaEspaco;
use App\Models\Terceiro;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Áreas comuns (imobiliario_areas.js do legado): o que o prédio partilha, com o regulamento de cada espaço (horário, dias, duração,
 * antecedência, limite por fracção, aprovação, preço, caução, prazo de pagamento e escala de cancelamento). Uma área comum não é fracção:
 * não se arrenda nem conta para a ocupação. Calendário, disponibilidade do dia, resumo e avisos. Correcções (P3): o preço fixo também
 * exige valor (o legado deixava gravar um espaço pago de 0 Kz), o espaço não muda de imóvel depois de ter reservas e uma conta de
 * proveito deixa de existir (a receita vai pelo artigo de serviço da facturação, que traz a sua conta).
 */
final class ServicoAreasComuns
{
    /**
     * @param  array{imovel_id?: ?int, activo?: ?bool, reservaveis?: ?bool}  $f
     * @return list<array<string, mixed>>
     */
    public function listar(array $f = []): array
    {
        $q = EspacoComum::query()->orderByRaw('lower(nome)');
        if (! empty($f['imovel_id'])) {
            $q->where('imovel_id', (int) $f['imovel_id']);
        }
        if (isset($f['activo'])) {
            $q->where('activo', (bool) $f['activo']);
        }
        if (! empty($f['reservaveis'])) {
            $q->whereIn('regime', ['RESERVA_GRATUITA', 'RESERVA_PAGA']);
        }
        $espacos = $q->limit(1000)->get();
        $imoveis = Imovel::query()->whereIn('id', $espacos->pluck('imovel_id')->unique())->get(['id', 'codigo', 'nome'])->keyBy('id');
        $comReservas = ReservaEspaco::query()->whereIn('espaco_id', $espacos->pluck('id'))->selectRaw('espaco_id, count(*) as n')->groupBy('espaco_id')->pluck('n', 'espaco_id');

        return $espacos->map(fn ($e) => $e->attributesToArray() + ['imovel_nome' => $imoveis[$e->imovel_id]->nome ?? null, 'imovel_codigo' => $imoveis[$e->imovel_id]->codigo ?? null,
            'reservas' => (int) ($comReservas[$e->id] ?? 0)])->all();
    }

    /** @param  array<string, mixed>  $d */
    public function gravar(array $d, ?EspacoComum $existente = null): EspacoComum
    {
        return DB::transaction(function () use ($d, $existente) {
            $e = $existente ? EspacoComum::query()->whereKey($existente->id)->lockForUpdate()->firstOrFail() : null;
            $reg = $this->normalizar($d, $e);
            if ($e !== null) {
                if ((int) $reg['imovel_id'] !== (int) $e->imovel_id && ReservaEspaco::query()->where('espaco_id', $e->id)->exists()) {
                    throw new ErroNegocio('O espaço já tem reservas: não muda de imóvel. Crie outro espaço no imóvel certo.', 'AREA_COM_RESERVAS');
                }
                $paga = CatalogoParqueamento::REGIMES[$reg['regime']]['paga'];
                $reserva = CatalogoParqueamento::REGIMES[$reg['regime']]['reserva'];
                if ((! $paga || ! $reserva) && ReservaEspaco::query()->where('espaco_id', $e->id)->whereIn('estado', ['PEDIDA', 'APROVADA', 'AGUARDA_PAGAMENTO', 'CONFIRMADA'])->where('valor', '>', 0)->exists()) {
                    throw new ErroNegocio('Há reservas pagas por realizar neste espaço: cancele-as antes de mudar o regime.', 'AREA_REGIME_COM_RESERVAS');
                }
            }
            try {
                if ($e === null) {
                    return EspacoComum::query()->create($reg + ['criado_por' => Autor::nome(), 'atualizado_por' => Autor::nome()])->refresh();
                }
                $e->update($reg + ['atualizado_por' => Autor::nome()]);

                return $e->refresh();
            } catch (UniqueConstraintViolationException) {
                throw new ErroNegocio('Já existe um espaço com esse nome neste imóvel.', 'AREA_DUPLICADA');
            }
        });
    }

    public function activar(EspacoComum $e, bool $activo): EspacoComum
    {
        $e->update(['activo' => $activo, 'atualizado_por' => Autor::nome()]);

        return $e->refresh();
    }

    public function eliminar(EspacoComum $e): void
    {
        $vivas = ReservaEspaco::query()->where('espaco_id', $e->id)->whereIn('estado', ['PEDIDA', 'APROVADA', 'AGUARDA_PAGAMENTO', 'CONFIRMADA'])->count();
        if ($vivas > 0) {
            throw new ErroNegocio("O espaço tem {$vivas} reserva(s) por realizar. Cancele-as, ou ponha o espaço inactivo em vez de o apagar.", 'AREA_COM_RESERVAS_VIVAS');
        }
        $todas = ReservaEspaco::query()->where('espaco_id', $e->id)->count();
        if ($todas > 0) {
            throw new ErroNegocio("O espaço tem {$todas} reserva(s) no histórico. Ponha-o inactivo: apagá-lo levava o registo do que já se passou.", 'AREA_COM_HISTORICO');
        }
        $e->delete();
    }

    /**
     * Reservas de um período agrupadas por dia (todos os estados, incluindo as canceladas e recusadas), com o reservante.
     *
     * @param  array{imovel_id?: ?int, espaco_id?: ?int, de?: ?string, ate?: ?string}  $f
     * @return list<array{data: string, dia: string, reservas: list<array<string, mixed>>}>
     */
    public function calendario(array $f): array
    {
        $q = ReservaEspaco::query()->orderBy('data')->orderBy('hora_inicio')->orderBy('id')->limit(2000);
        if (! empty($f['imovel_id'])) {
            $q->where('imovel_id', (int) $f['imovel_id']);
        }
        if (! empty($f['espaco_id'])) {
            $q->where('espaco_id', (int) $f['espaco_id']);
        }
        if (! empty($f['de'])) {
            $q->where('data', '>=', $f['de']);
        }
        if (! empty($f['ate'])) {
            $q->where('data', '<=', $f['ate']);
        }
        $reservas = $q->get();
        $espacos = EspacoComum::query()->whereIn('id', $reservas->pluck('espaco_id')->unique())->get(['id', 'nome', 'tipo', 'regime'])->keyBy('id');
        $fracoes = Fracao::query()->whereIn('id', $reservas->pluck('fracao_id')->filter()->unique())->get(['id', 'designacao', 'codigo'])->keyBy('id');
        $terceiros = Terceiro::query()->whereIn('id', $reservas->pluck('terceiro_id')->filter()->unique())->get(['id', 'nome'])->keyBy('id');
        $dias = [];
        foreach ($reservas as $r) {
            $data = $r->data->format('Y-m-d');
            $fr = $r->fracao_id ? ($fracoes[$r->fracao_id] ?? null) : null;
            $dias[$data]['data'] = $data;
            $dias[$data]['dia'] = CatalogoParqueamento::DIAS[(int) $r->data->format('w')];
            $dias[$data]['reservas'][] = $r->attributesToArray() + ['espaco_nome' => $espacos[$r->espaco_id]->nome ?? null, 'espaco_tipo' => $espacos[$r->espaco_id]->tipo ?? null,
                'fraccao' => $fr ? ($fr->designacao ?: $fr->codigo) : null,
                'reservante' => $r->interna ? 'Administração' : (($terceiros[$r->terceiro_id]->nome ?? null) ?: ($fr?->designacao ?? '—'))];
        }

        return array_values($dias);
    }

    /**
     * O dia de um espaço: se abre, as reservas que o ocupam e os intervalos livres entre a abertura e o fecho.
     *
     * @return array<string, mixed>
     */
    public function dia(EspacoComum $e, string $data): array
    {
        $d = CarbonImmutable::parse($data);
        $abre = in_array((int) $d->format('w'), array_map('intval', $e->dias_semana ?? []), true);
        $ocupadas = ReservaEspaco::query()->where('espaco_id', $e->id)->where('data', $d->toDateString())->whereIn('estado', $this->estadosQueOcupam())->orderBy('hora_inicio')->get(['id', 'hora_inicio', 'hora_fim', 'estado']);
        $livres = [];
        if ($abre) {
            $cursor = (int) CatalogoParqueamento::minutos($e->hora_abre);
            $fecho = (int) CatalogoParqueamento::minutos($e->hora_fecha);
            foreach ($ocupadas as $o) {
                $ini = (int) CatalogoParqueamento::minutos($o->hora_inicio);
                if ($ini > $cursor) {
                    $livres[] = $this->intervalo($cursor, min($ini, $fecho));
                }
                $cursor = max($cursor, (int) CatalogoParqueamento::minutos($o->hora_fim));
            }
            if ($cursor < $fecho) {
                $livres[] = $this->intervalo($cursor, $fecho);
            }
        }
        $horas = array_sum(array_map(fn ($l) => (float) $l['horas'], $livres));

        return ['espaco_id' => (int) $e->id, 'data' => $d->toDateString(), 'dia' => CatalogoParqueamento::DIAS[(int) $d->format('w')], 'abre' => $abre, 'hora_abre' => $e->hora_abre, 'hora_fecha' => $e->hora_fecha,
            'ocupadas' => $ocupadas->map(fn ($o) => ['id' => (int) $o->id, 'inicio' => $o->hora_inicio, 'fim' => $o->hora_fim, 'estado' => $o->estado])->all(), 'livres' => $livres,
            'horas_livres' => round($horas, 1), 'vazio' => $abre && $ocupadas->isEmpty()];
    }

    /** @return array{inicio: string, fim: string, horas: float} */
    private function intervalo(int $a, int $b): array
    {
        return ['inicio' => CatalogoParqueamento::hhmm($a), 'fim' => CatalogoParqueamento::hhmm($b), 'horas' => round(((int) round(($b - $a) / 6)) / 10, 1)];
    }

    /** @return list<string> */
    public function estadosQueOcupam(): array
    {
        return array_keys(array_filter(CatalogoParqueamento::ESTADOS_RESERVA, fn ($e) => $e['ocupa']));
    }

    /**
     * Indicadores das áreas comuns (de um imóvel ou de todos).
     *
     * @return array<string, int>
     */
    public function resumo(?int $imovelId = null, ?string $hoje = null): array
    {
        $hoje ??= now()->toDateString();
        $espacos = EspacoComum::query()->when($imovelId, fn ($q) => $q->where('imovel_id', $imovelId))->get(['id', 'activo', 'regime']);
        $reservas = ReservaEspaco::query()->when($imovelId, fn ($q) => $q->where('imovel_id', $imovelId))->get(['estado', 'data', 'divida_na_marcacao']);
        $vivas = $reservas->filter(fn ($r) => CatalogoParqueamento::ESTADOS_RESERVA[$r->estado]['viva']);
        $d = fn ($r) => $r->data->format('Y-m-d');

        return [
            'espacos' => $espacos->count(), 'activos' => $espacos->where('activo', true)->count(),
            'reservaveis' => $espacos->filter(fn ($e) => CatalogoParqueamento::REGIMES[$e->regime]['reserva'])->count(),
            'pagos' => $espacos->where('regime', 'RESERVA_PAGA')->count(), 'por_aprovar' => $reservas->where('estado', 'PEDIDA')->count(),
            'aguarda_pagamento' => $reservas->where('estado', 'AGUARDA_PAGAMENTO')->count(), 'futuras' => $vivas->filter(fn ($r) => $d($r) >= $hoje)->count(),
            'hoje' => $vivas->filter(fn ($r) => $d($r) === $hoje)->count(), 'por_fechar' => $vivas->filter(fn ($r) => $d($r) < $hoje)->count(),
            'com_divida' => $reservas->filter(fn ($r) => (float) $r->divida_na_marcacao > 0)->count(),
        ];
    }

    /** @return list<string> */
    public function avisos(?int $imovelId = null, ?string $hoje = null): array
    {
        $espacos = EspacoComum::query()->when($imovelId, fn ($q) => $q->where('imovel_id', $imovelId))->get();
        if ($espacos->isEmpty()) {
            return [];
        }
        $r = $this->resumo($imovelId, $hoje);
        $avisos = [];
        $t = [['por_aprovar', '%d reserva(s) à espera de aprovação.'],
            ['por_fechar', '%d reserva(s) com data passada ainda por fechar — diga se foram realizadas ou se ninguém apareceu.'],
            ['aguarda_pagamento', '%d reserva(s) à espera de pagamento: o espaço está preso mas ainda não está garantido.'],
            ['com_divida', '%d reserva(s) foram autorizadas com rendas em dívida.']];
        foreach ($t as [$k, $msg]) {
            if ($r[$k] > 0) {
                $avisos[] = sprintf($msg, $r[$k]);
            }
        }
        $festa = $espacos->filter(fn ($e) => $e->activo && CatalogoParqueamento::REGIMES[$e->regime]['reserva'] && in_array($e->tipo, ['SALAO', 'CHURRASQUEIRA'], true) && ! $e->limite_mes_fraccao)->count();
        if ($festa > 0) {
            $avisos[] = "{$festa} espaço(s) de festa sem limite de reservas por fracção — uma só família pode ficar com todos os fins-de-semana.";
        }

        return $avisos;
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private function normalizar(array $d, ?EspacoComum $actual): array
    {
        $nome = Normaliza::texto($d['nome'] ?? null) ?? throw new ErroNegocio('Dê um nome ao espaço (ex.: «Salão de festas», «Ginásio do bloco A»).', 'AREA_SEM_NOME');
        $imovelId = Normaliza::inteiro($d['imovel_id'] ?? $actual?->imovel_id, true);
        if (! $imovelId) {
            throw new ErroNegocio('Escolha o imóvel a que o espaço pertence.', 'AREA_SEM_IMOVEL');
        }
        Imovel::query()->find($imovelId) ?? throw new ErroNegocio('Imóvel não encontrado.', 'AREA_IMOVEL_INEXISTENTE', 404);
        $tipo = (string) ($d['tipo'] ?? $actual?->tipo ?? 'OUTRO');
        $tipo = isset(CatalogoParqueamento::TIPOS_AREA[$tipo]) ? $tipo : 'OUTRO';
        $regime = (string) ($d['regime'] ?? $actual?->regime ?? 'RESERVA_GRATUITA');
        if (! isset(CatalogoParqueamento::REGIMES[$regime])) {
            throw new ErroNegocio('Escolha o regime do espaço.', 'AREA_REGIME');
        }
        $reg = CatalogoParqueamento::REGIMES[$regime];
        $abre = Normaliza::texto($d['hora_abre'] ?? null) ?? ($actual?->hora_abre ?? '08:00');
        $fecha = Normaliza::texto($d['hora_fecha'] ?? null) ?? ($actual?->hora_fecha ?? '22:00');
        $ma = CatalogoParqueamento::minutos($abre);
        $mf = CatalogoParqueamento::minutos($fecha);
        if ($ma === null || $mf === null || $ma >= 1440) {
            throw new ErroNegocio('As horas de abertura e de fecho têm o formato HH:MM.', 'AREA_HORAS');
        }
        if ($reg['reserva'] && $mf <= $ma) {
            throw new ErroNegocio('A hora de fecho tem de ser depois da de abertura.', 'AREA_HORAS');
        }
        $dias = collect((array) ($d['dias_semana'] ?? $actual?->dias_semana ?? [0, 1, 2, 3, 4, 5, 6]))->map(fn ($x) => (int) $x)->filter(fn ($x) => $x >= 0 && $x <= 6)->unique()->sort()->values()->all();
        $dias = $dias === [] ? [0, 1, 2, 3, 4, 5, 6] : $dias;
        $dmin = $this->positivoOuNulo($d, 'duracao_min', $actual);
        $dmax = $this->positivoOuNulo($d, 'duracao_max', $actual);
        if ($dmin !== null && $dmax !== null && $dmin > $dmax) {
            throw new ErroNegocio('A duração mínima não pode ser maior do que a máxima.', 'AREA_DURACAO');
        }
        $amin = max(0, Normaliza::inteiro($d['antecedencia_min_dias'] ?? $actual?->antecedencia_min_dias ?? 0));
        $amax = max(0, Normaliza::inteiro($d['antecedencia_max_dias'] ?? $actual?->antecedencia_max_dias ?? 0));
        if ($amax > 0 && $amin > $amax) {
            throw new ErroNegocio('A antecedência mínima não pode ser maior do que a máxima.', 'AREA_ANTECEDENCIA');
        }
        $unidade = (string) ($d['preco_unidade'] ?? $actual?->preco_unidade ?? 'HORA');
        $unidade = isset(CatalogoParqueamento::UNIDADES_PRECO[$unidade]) ? $unidade : 'HORA';
        $preco = $reg['paga'] ? Normaliza::r2($d['preco'] ?? $actual?->preco) : 0.0;
        if ($reg['paga'] && $preco <= 0) {
            throw new ErroNegocio('Um espaço de reserva paga precisa de preço. Ponha o valor, ou mude o regime.', 'AREA_SEM_PRECO');
        }
        $caucao = $reg['paga'] ? Normaliza::r2($d['caucao'] ?? $actual?->caucao) : 0.0;
        $capacidade = Normaliza::inteiro($d['capacidade'] ?? $actual?->capacidade, true);
        $limite = Normaliza::inteiro($d['limite_mes_fraccao'] ?? $actual?->limite_mes_fraccao, true);

        return [
            'imovel_id' => $imovelId, 'tipo' => $tipo, 'nome' => mb_substr($nome, 0, 120), 'localizacao' => array_key_exists('localizacao', $d) ? Normaliza::texto($d['localizacao']) : $actual?->localizacao,
            'regime' => $regime, 'capacidade' => $capacidade > 0 ? $capacidade : null, 'hora_abre' => CatalogoParqueamento::hhmm($ma), 'hora_fecha' => CatalogoParqueamento::hhmm($mf),
            'dias_semana' => $dias, 'duracao_min' => $dmin, 'duracao_max' => $dmax, 'antecedencia_min_dias' => $amin, 'antecedencia_max_dias' => $amax,
            'limite_mes_fraccao' => $limite > 0 ? $limite : null, 'exige_aprovacao' => array_key_exists('exige_aprovacao', $d) ? filter_var($d['exige_aprovacao'], FILTER_VALIDATE_BOOLEAN) : (bool) ($actual?->exige_aprovacao),
            'preco' => $preco, 'preco_unidade' => $unidade, 'caucao' => max(0, $caucao), 'prazo_pagamento_horas' => max(1, Normaliza::inteiro($d['prazo_pagamento_horas'] ?? $actual?->prazo_pagamento_horas ?? 48) ?: 48),
            'cancel_dias' => max(0, Normaliza::inteiro($d['cancel_dias'] ?? $actual?->cancel_dias ?? 7)), 'cancel_pct' => min(100, max(0, Normaliza::inteiro($d['cancel_pct'] ?? $actual?->cancel_pct ?? 50))),
            'regulamento' => array_key_exists('regulamento', $d) ? Normaliza::texto($d['regulamento']) : $actual?->regulamento,
            'regulamento_url' => array_key_exists('regulamento_url', $d) ? (Normaliza::url($d['regulamento_url'], 'Regulamento') ?: null) : $actual?->regulamento_url,
            'activo' => array_key_exists('activo', $d) ? filter_var($d['activo'], FILTER_VALIDATE_BOOLEAN) : ($actual?->activo ?? true),
            'notas' => array_key_exists('notas', $d) ? Normaliza::texto($d['notas']) : $actual?->notas,
        ];
    }

    /** @param  array<string, mixed>  $d */
    private function positivoOuNulo(array $d, string $k, ?EspacoComum $actual): ?float
    {
        $v = Normaliza::r2(array_key_exists($k, $d) ? $d[$k] : $actual?->{$k}, true);

        return $v !== null && $v > 0 ? $v : null;
    }
}
