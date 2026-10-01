<?php

namespace App\Services\Gestao\Paineis;

use App\Exceptions\ErroNegocio;

/**
 * Período de referência dos painéis (ui_painel_modulos.js:62-100, criarContexto): ano e mês escolhidos, com
 *   - "no mês"   = o mês de referência;
 *   - "no ano"   = de Janeiro até ao fim do mês de referência (acumulado do ano);
 *   - "até ao mês" = tudo até ao fim do mês de referência (saldos);
 *   - janela de 12 meses que termina no mês de referência (séries dos gráficos).
 * Por omissão, o mês corrente (como o legado).
 */
final class PeriodoPainel
{
    public const MESES = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

    public const MESES_LONGOS = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

    /** @var list<array{chave: string, rotulo: string}> */
    public readonly array $meses;

    public readonly string $chaveMes;

    public readonly string $inicioMes;

    public readonly string $fimMes;

    public readonly string $inicioAno;

    public readonly string $inicioJanela;

    private function __construct(public readonly int $ano, public readonly int $mes)
    {
        $meses = [];
        for ($i = 11; $i >= 0; $i--) {
            $t = mktime(0, 0, 0, $mes - $i, 1, $ano);
            $meses[] = ['chave' => date('Y-m', $t), 'rotulo' => self::MESES[(int) date('n', $t) - 1].'/'.date('y', $t)];
        }
        $this->meses = $meses;
        $this->chaveMes = sprintf('%04d-%02d', $ano, $mes);
        $this->inicioMes = "{$this->chaveMes}-01";
        $this->fimMes = date('Y-m-t', mktime(0, 0, 0, $mes, 1, $ano));
        $this->inicioAno = sprintf('%04d-01-01', $ano);
        $this->inicioJanela = $meses[0]['chave'].'-01';
    }

    public static function de(?int $ano, ?int $mes): self
    {
        $ano ??= (int) now()->format('Y');
        $mes ??= (int) now()->format('n');
        if ($ano < 1900 || $ano > 2999 || $mes < 1 || $mes > 12) {
            throw new ErroNegocio('Período inválido.', 'PERIODO_INVALIDO', 422);
        }

        return new self($ano, $mes);
    }

    /** O mesmo mês do ano anterior (comparação "Janeiro a <mês>" do ano anterior). */
    public function anoAnterior(): self
    {
        return new self($this->ano - 1, $this->mes);
    }

    /** @return list<string> chaves AAAA-MM da janela */
    public function chaves(): array
    {
        return array_column($this->meses, 'chave');
    }

    /** @return list<string> rótulos "Mmm/AA" da janela */
    public function rotulos(): array
    {
        return array_column($this->meses, 'rotulo');
    }

    /**
     * Valores de um mapa chave AAAA-MM => valor alinhados com a janela (meses sem valor = '0.00' ou 0).
     *
     * @param  array<string, mixed>  $porMes
     * @return list<string|int>
     */
    public function serie(array $porMes, bool $dinheiro = true): array
    {
        return array_map(fn ($k) => $dinheiro ? Indicadores::dinheiro($porMes[$k] ?? '0') : (int) ($porMes[$k] ?? 0), $this->chaves());
    }

    /** Soma dos meses da janela que pertencem ao ano de referência (Janeiro..mês). */
    public function somaAno(array $porMes): string
    {
        $t = '0.00';
        foreach ($porMes as $k => $v) {
            if ($k >= substr($this->inicioAno, 0, 7) && $k <= $this->chaveMes) {
                $t = bcadd($t, Indicadores::dinheiro($v), 2);
            }
        }

        return $t;
    }

    public function nomeMes(): string
    {
        return self::MESES_LONGOS[$this->mes - 1];
    }

    /** @return array<string, mixed> */
    public function descrever(): array
    {
        return ['ano' => $this->ano, 'mes' => $this->mes, 'inicio_mes' => $this->inicioMes, 'fim_mes' => $this->fimMes, 'inicio_ano' => $this->inicioAno,
            'inicio_janela' => $this->inicioJanela, 'meses' => $this->meses];
    }
}
