<?php

namespace App\Services\Imobiliario;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Enumerações e regras puras das obras, do apetrechamento e dos planos (porte de TIPOS_OBRA, ESTADOS_OBRA, PRIORIDADES, QUEM_PAGA,
 * ESTADOS_ITEM, ESTADOS_BEM, comparar ficha × inventário, planoDePedido, proporDatas, estado do calendário e horizonte de geração do legado).
 * Sem acesso à base de dados: tudo aqui é testável com números à mão; as quantidades são decimais de 3 casas (bcmath), nunca float.
 */
final class CalculadoraObras
{
    public const TIPOS_OBRA = [
        'APETRECHAMENTO' => ['rot' => 'Apetrechamento', 'ajuda' => 'Equipar a fracção para poder ser arrendada ou vendida.'],
        'PREVENTIVA' => ['rot' => 'Manutenção preventiva', 'ajuda' => 'Pintura, impermeabilização, revisões periódicas.'],
        'CORRECTIVA' => ['rot' => 'Reparação', 'ajuda' => 'Avaria ou estrago a corrigir.'],
        'MELHORIA' => ['rot' => 'Melhoria', 'ajuda' => 'Obra que valoriza o imóvel.'],
        'VISTORIA' => ['rot' => 'Vistoria', 'ajuda' => 'Conferir o estado à entrada ou à saída.'],
    ];

    public const ESTADOS_OBRA = ['ABERTA' => 'Aberta', 'AGUARDA_MATERIAL' => 'Aguarda material', 'EM_CURSO' => 'Em curso', 'CONCLUIDA' => 'Concluída', 'CANCELADA' => 'Cancelada'];

    public const PRIORIDADES = ['BAIXA' => 'Baixa', 'NORMAL' => 'Normal', 'ALTA' => 'Alta', 'URGENTE' => 'Urgente'];

    public const QUEM_PAGA = ['SENHORIO' => 'Senhorio (a empresa)', 'INQUILINO' => 'Inquilino', 'PARTILHADO' => 'Partilhado'];

    public const ESTADOS_ITEM = ['POR_PEDIR' => 'Por pedir', 'REQUISITADO' => 'Requisitado ao armazém', 'PEDIDO_COMPRA' => 'Em pedido de compra', 'RECEBIDO' => 'Recebido', 'CANCELADO' => 'Cancelado'];

    public const ESTADOS_BEM = ['NOVO' => 'Novo', 'BOM' => 'Bom estado', 'USADO' => 'Usado', 'DANIFICADO' => 'Danificado', 'EM_FALTA' => 'Em falta'];

    /** Meses entre datas de cada periodicidade; MANUAL = datas à escolha. */
    public const PERIODICIDADES = [
        'MENSAL' => ['rot' => 'Mensal', 'meses' => 1], 'BIMESTRAL' => ['rot' => 'Bimestral', 'meses' => 2], 'TRIMESTRAL' => ['rot' => 'Trimestral', 'meses' => 3],
        'SEMESTRAL' => ['rot' => 'Semestral', 'meses' => 6], 'ANUAL' => ['rot' => 'Anual', 'meses' => 12], 'BIANUAL' => ['rot' => 'De dois em dois anos', 'meses' => 24],
        'MANUAL' => ['rot' => 'Datas à escolha', 'meses' => 0],
    ];

    public const TIPOS_PLANEAVEIS = ['PREVENTIVA', 'VISTORIA'];

    /** Minúsculas sem diacríticos nem espaços nas pontas: a chave com que se comparam descrições de bens. */
    public static function chave(?string $t): string
    {
        return mb_strtolower(Str::ascii(trim((string) $t)));
    }

    /** @return string quantidade com 3 casas */
    public static function q(mixed $v): string
    {
        return number_format(is_numeric($v) ? (float) $v : 0.0, 3, '.', '');
    }

    /**
     * Compara a ficha com o inventário da fracção: para cada linha da ficha, quanto precisa, quanto tem (bens que contam: não danificados
     * nem em falta; do mesmo artigo ou, sem artigo, de igual descrição), quanto falta e quantos bens iguais estão danificados ou em falta.
     *
     * @param  list<array{produto_id: ?int, descricao: ?string, quantidade: int|string, obrigatorio?: bool, nome?: string}>  $itens
     * @param  list<array{produto_id: ?int, descricao: ?string, quantidade: int|float|string, estado: string}>  $bens
     * @return list<array{nome: string, produto_id: ?int, descricao: ?string, obrigatorio: bool, precisa: string, tem: string, falta: string, danificados: int}>
     */
    public static function comparar(array $itens, array $bens): array
    {
        $linhas = [];
        foreach ($itens as $it) {
            $precisa = (int) $it['quantidade'] >= 1 ? self::q($it['quantidade']) : '1.000';
            $tem = '0.000';
            $danificados = 0;
            foreach ($bens as $b) {
                $igual = ! empty($it['produto_id'])
                    ? (int) ($b['produto_id'] ?? 0) === (int) $it['produto_id']
                    : (self::chave($b['descricao'] ?? '') !== '' && self::chave($b['descricao'] ?? '') === self::chave($it['descricao'] ?? ''));
                if (! $igual) {
                    continue;
                }
                if (in_array($b['estado'], ['DANIFICADO', 'EM_FALTA'], true)) {
                    $danificados++;
                } else {
                    $tem = bcadd($tem, self::q($b['quantidade']), 3);
                }
            }
            $falta = bccomp($precisa, $tem, 3) > 0 ? bcsub($precisa, $tem, 3) : '0.000';
            $linhas[] = ['nome' => $it['nome'] ?? ($it['descricao'] ?: 'Artigo'), 'produto_id' => $it['produto_id'] ?? null, 'descricao' => $it['descricao'] ?? null,
                'obrigatorio' => (bool) ($it['obrigatorio'] ?? true), 'precisa' => $precisa, 'tem' => $tem, 'falta' => $falta, 'danificados' => $danificados];
        }

        return $linhas;
    }

    /**
     * Para onde vai uma necessidade: ao armazém o que há em stock, a compras o resto. Artigo que não é de armazém (serviço) ou sem artigo
     * vai tudo a compras (sem artigo, o pedido de compra recusa-o: a linha fica bloqueada até haver artigo).
     *
     * @return array{para_armazem: string, para_compra: string, porque: string, bloqueada: bool}
     */
    public static function repartir(string $quantidade, bool $temArtigo, bool $deArmazem, ?string $stock): array
    {
        $qtd = bccomp($quantidade, '0', 3) > 0 ? $quantidade : '1.000';
        if (! $temArtigo) {
            return ['para_armazem' => '0.000', 'para_compra' => '0.000', 'bloqueada' => true, 'porque' => 'Linha sem artigo: indique um artigo (serviço) para a pedir a compras'];
        }
        if (! $deArmazem) {
            return ['para_armazem' => '0.000', 'para_compra' => $qtd, 'bloqueada' => false, 'porque' => 'Serviço ou artigo que não é de armazém'];
        }
        $stock = bccomp((string) $stock, '0', 3) > 0 ? (string) $stock : '0.000';
        $paraArmazem = bccomp($stock, $qtd, 3) < 0 ? $stock : $qtd;
        $paraCompra = bcsub($qtd, $paraArmazem, 3);
        $porque = bccomp($paraCompra, '0', 3) <= 0 ? 'Há stock que chega'
            : (bccomp($paraArmazem, '0', 3) > 0 ? 'O stock só dá para '.rtrim(rtrim($paraArmazem, '0'), '.').' — a falta vai a compras' : 'Material sem stock');

        return ['para_armazem' => $paraArmazem, 'para_compra' => $paraCompra, 'bloqueada' => false, 'porque' => $porque];
    }

    /**
     * Quantidade recebida de uma necessidade: o que o armazém entregou mais o que as Compras receberam, sem passar do pedido (cada origem
     * conta no máximo a parte que lhe coube).
     */
    public static function recebida(string $quantidade, string $paraArmazem, string $entregueArmazem, string $paraCompra, string $recebidaCompra): string
    {
        $a = bccomp($entregueArmazem, $paraArmazem, 3) > 0 ? $paraArmazem : $entregueArmazem;
        $c = bccomp($recebidaCompra, $paraCompra, 3) > 0 ? $paraCompra : $recebidaCompra;
        $t = bcadd($a, $c, 3);

        return bccomp($t, $quantidade, 3) > 0 ? $quantidade : $t;
    }

    /**
     * Datas propostas por periodicidade: contam-se sempre a partir do dia ORIGINAL (31 de Janeiro + 1 mês = 28 ou 29 de Fevereiro, e o mês
     * seguinte volta ao 31) e não encadeadas. Entre 1 e 60 datas; periodicidade desconhecida ou manual dá só a primeira.
     *
     * @return list<string>
     */
    public static function proporDatas(?string $primeira, string $periodicidade, int $quantas): array
    {
        if (! is_string($primeira) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $primeira)) {
            return [];
        }
        try {
            $base = CarbonImmutable::createFromFormat('!Y-m-d', $primeira);
        } catch (\Throwable) {
            return [];
        }
        if (! $base || $base->format('Y-m-d') !== $primeira) {
            return [];
        }
        $n = max(1, min(60, $quantas));
        $meses = self::PERIODICIDADES[$periodicidade]['meses'] ?? 0;
        if ($meses === 0) {
            return [$primeira];
        }
        $dia = (int) $base->format('j');
        $datas = [];
        for ($i = 0; $i < $n; $i++) {
            $alvo = $base->startOfMonth()->addMonthsNoOverflow($i * $meses);
            $datas[] = $alvo->setDay(min($dia, $alvo->daysInMonth))->format('Y-m-d');
        }

        return $datas;
    }

    /** Datas válidas, ordenadas e sem repetições (1 a 60). @param  list<mixed>  $datas  @return list<string> */
    public static function limparDatas(array $datas): array
    {
        $ok = [];
        foreach ($datas as $d) {
            $d = (string) $d;
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && CarbonImmutable::createFromFormat('!Y-m-d', $d)?->format('Y-m-d') === $d) {
                $ok[$d] = true;
            }
        }
        $r = array_keys($ok);
        sort($r);

        return $r;
    }

    /**
     * Estado de cada data do plano: o da ordem já gerada ou, sem ordem, «em falta» (data passada) ou «por gerar».
     *
     * @param  list<string>  $datas
     * @param  array<string, array{id: int, numero: string, estado: string}>  $ordensPorData
     * @return array{linhas: list<array<string, mixed>>, geradas: int, por_gerar: int, em_falta: int, concluidas: int, proxima: ?string}
     */
    public static function estadoDasDatas(array $datas, array $ordensPorData, string $hoje): array
    {
        $linhas = [];
        $r = ['geradas' => 0, 'por_gerar' => 0, 'em_falta' => 0, 'concluidas' => 0, 'proxima' => null];
        foreach ($datas as $d) {
            $o = $ordensPorData[$d] ?? null;
            $passada = $d < $hoje;
            $estado = $o ? $o['estado'] : ($passada ? 'EM_FALTA' : 'POR_GERAR');
            $linhas[] = ['data' => $d, 'estado' => $estado, 'passada' => $passada, 'ordem_id' => $o['id'] ?? null, 'ordem_numero' => $o['numero'] ?? null];
            if ($o) {
                $r['geradas']++;
                $r['concluidas'] += $o['estado'] === 'CONCLUIDA' ? 1 : 0;
            } elseif ($passada) {
                $r['em_falta']++;
            } else {
                $r['por_gerar']++;
            }
            if ($r['proxima'] === null && ! $passada) {
                $r['proxima'] = $d;
            }
        }

        return ['linhas' => $linhas] + $r;
    }

    /** Último dia até onde «gerar as próximas» cria ordens: hoje + a antecedência do plano (0 é válido: só o que é para hoje ou já passou). */
    public static function horizonte(string $hoje, int $antecedenciaDias): string
    {
        return CarbonImmutable::parse($hoje)->addDays(max(0, $antecedenciaDias))->format('Y-m-d');
    }
}
