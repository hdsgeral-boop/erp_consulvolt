<?php

namespace App\Services\Migracao;

use Illuminate\Support\Facades\DB;

/**
 * Registo, em lote, de ocorrências de migração (ocorrencias_migracao) e de linhas em quarentena
 * (quarentena_migracao). Mantém contadores por regra para o relatório final.
 */
final class RegistoOcorrencias
{
    private const LOTE = 500;

    /** @var list<array<string, mixed>> */
    private array $ocorrencias = [];

    /** @var list<array<string, mixed>> */
    private array $quarentena = [];

    /** @var array<string, int> "tabela|regra|gravidade" => total */
    private array $contadores = [];

    /** @var array<string, int> tabela_legado => linhas em quarentena */
    private array $quarentenaPorTabela = [];

    public function __construct(private readonly string $execucao) {}

    /** @param  array<string, mixed>|null  $dados */
    public function ocorrencia(string $tabelaLegado, ?string $idLegado, ?string $tabelaDestino, ?string $coluna, string $regra,
        string $gravidade, mixed $valorOriginal, mixed $valorFinal, string $descricao, ?array $dados = null, ?int $empresaLegado = null): void
    {
        $chave = "{$tabelaLegado}|{$regra}|{$gravidade}";
        $this->contadores[$chave] = ($this->contadores[$chave] ?? 0) + 1;

        $this->ocorrencias[] = [
            'execucao' => $this->execucao, 'tabela_legado' => $tabelaLegado, 'id_legado' => $idLegado,
            'tabela_destino' => $tabelaDestino, 'coluna' => $coluna, 'regra' => $regra, 'gravidade' => $gravidade,
            'valor_original' => self::texto($valorOriginal), 'valor_final' => self::texto($valorFinal), 'descricao' => $descricao,
            'dados_originais' => $dados !== null ? json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'empresa_legado_id' => $empresaLegado,
        ];
        if (count($this->ocorrencias) >= self::LOTE) {
            $this->descarregar();
        }
    }

    /** @param  array<string, mixed>  $dados */
    public function quarentena(string $tabelaLegado, ?string $idLegado, string $motivo, array $dados, ?int $empresaLegado, string $regra = 'QUARENTENA'): void
    {
        $this->quarentenaPorTabela[$tabelaLegado] = ($this->quarentenaPorTabela[$tabelaLegado] ?? 0) + 1;
        $this->quarentena[] = [
            'execucao' => $this->execucao, 'tabela_legado' => $tabelaLegado, 'id_legado' => $idLegado, 'motivo' => $motivo,
            'dados_originais' => json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'empresa_legado_id' => $empresaLegado,
        ];
        $this->ocorrencia($tabelaLegado, $idLegado, null, null, $regra, 'AVISO', null, null, $motivo, null, $empresaLegado);
    }

    public function descarregar(): void
    {
        foreach (array_chunk($this->ocorrencias, self::LOTE) as $lote) {
            DB::table('ocorrencias_migracao')->insert($lote);
        }
        foreach (array_chunk($this->quarentena, self::LOTE) as $lote) {
            DB::table('quarentena_migracao')->insert($lote);
        }
        $this->ocorrencias = [];
        $this->quarentena = [];
    }

    /** @return array<string, int> */
    public function contadores(): array
    {
        ksort($this->contadores);

        return $this->contadores;
    }

    public function quarentenaDe(string $tabelaLegado): int
    {
        return $this->quarentenaPorTabela[$tabelaLegado] ?? 0;
    }

    public function totalQuarentena(): int
    {
        return array_sum($this->quarentenaPorTabela);
    }

    private static function texto(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = is_scalar($v) ? (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v) : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return mb_substr($s, 0, 2000);
    }
}
