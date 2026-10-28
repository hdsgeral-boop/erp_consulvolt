<?php

namespace App\Services\Terceiros;

use App\Exceptions\ErroNegocio;
use App\Models\Terceiro;

/**
 * Contas contabilísticas do terceiro por papel — paridade com window.ContaTerceiro do legado novo
 * (Versão Actual, js/shared/contas_terceiro.js):
 *   conta_cliente     conta de cliente    (classe 31)        → integração das vendas, recibos e NC
 *   conta_fornecedor  conta de fornecedor (classe 32 ou 37.0) → integração das facturas de compra e pagamentos
 * O `codigo_conta` antigo mantém-se nos registos anteriores e só é lido como recurso quando a classe corresponde ao papel
 * (contas_terceiro.js:57-67). Nada é preenchido por defeito: sem conta, a integração pára e diz o que falta e em que
 * terceiro (exigir/exigirOuAvisar, contas_terceiro.js:78-98).
 *
 * O tipo no sistema novo é um código normalizado (CLIENTE, FORNECEDOR, CLIENTE_FORNECEDOR, COLABORADOR); no legado era
 * uma lista ('CLIENTE, FORNECEDOR'). Sem tipo nenhum (registos antigos), o terceiro não se esconde de nenhum papel.
 */
final class ContaTerceiro
{
    public const CLIENTE = 'CLIENTE';

    public const FORNECEDOR = 'FORNECEDOR';

    /** Terceiro sem papel comercial, só de conta corrente genérica (classe 37) — não é cliente nem fornecedor. */
    public const CREDOR_DEVEDOR = 'CREDOR_DEVEDOR';

    /** Classes da conta de cliente: 31 e, por pedido do utilizador, também 37.91 e 37.2. */
    public const CLASSES_CLIENTE = ['31', '3791', '372'];

    /** As compras admitem a 32 (fornecedores), a 37.0 (outros devedores e credores) e, por pedido do utilizador, 37.1 e 37.92. */
    public const CLASSES_FORNECEDOR = ['32', '370', '371', '3792'];

    /** Conta de Credor/Devedor: qualquer conta da classe 37 (outros valores a receber e a pagar) não reservada aos dois papéis acima. */
    public const CLASSE_CREDOR_DEVEDOR = '37';

    /** Rótulos do tipo (rotuloTipo, contas_terceiro.js:110-115). */
    public const ROTULOS_TIPO = [
        Terceiro::CLIENTE => 'Cliente',
        Terceiro::FORNECEDOR => 'Fornecedor',
        Terceiro::CLIENTE_FORNECEDOR => 'Cliente e Fornecedor',
        Terceiro::COLABORADOR => 'Colaborador',
        Terceiro::CREDOR_DEVEDOR => 'Credor/Devedor',
    ];

    /** Só os dígitos: '32.1.1', '321001' e '32-1' são todos da classe 32 (contas_terceiro.js:30-34). */
    public static function digitos(?string $valor): string
    {
        return preg_replace('/\D/', '', trim((string) $valor)) ?? '';
    }

    public static function daClasse(?string $conta, string $classe): bool
    {
        $d = self::digitos($conta);

        return $d !== '' && str_starts_with($d, self::digitos($classe));
    }

    /** Campo da conta própria de cada papel. */
    private const CAMPOS_CONTA = [
        self::CLIENTE => 'conta_cliente',
        self::FORNECEDOR => 'conta_fornecedor',
        self::CREDOR_DEVEDOR => 'conta_credor_devedor',
    ];

    /** @return list<string> classes aceites para a conta do papel. */
    private static function classesDoPapel(string $papel): array
    {
        return match ($papel) {
            self::FORNECEDOR => self::CLASSES_FORNECEDOR,
            self::CREDOR_DEVEDOR => [self::CLASSE_CREDOR_DEVEDOR],
            default => self::CLASSES_CLIENTE,
        };
    }

    /** A conta é da classe certa para o papel? Vazio conta como certo (a obrigatoriedade valida-se à parte). */
    public static function classeCorrecta(?string $codigo, string $papel): bool
    {
        $c = trim((string) $codigo);
        if ($c === '') {
            return true;
        }
        foreach (self::classesDoPapel($papel) as $classe) {
            if (self::daClasse($c, $classe)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> papéis do tipo gravado */
    public static function papeis(?string $tipo): array
    {
        return match (strtoupper(trim((string) $tipo))) {
            Terceiro::CLIENTE => [self::CLIENTE],
            Terceiro::FORNECEDOR => [self::FORNECEDOR],
            Terceiro::CLIENTE_FORNECEDOR => [self::CLIENTE, self::FORNECEDOR],
            Terceiro::COLABORADOR => [Terceiro::COLABORADOR],
            Terceiro::CREDOR_DEVEDOR => [self::CREDOR_DEVEDOR],
            default => [],
        };
    }

    /** O tipo inclui o papel (temTipo)? */
    public static function temPapel(?string $tipo, string $papel): bool
    {
        return in_array($papel, self::papeis($tipo), true);
    }

    /**
     * Conta do papel: a própria; senão o `codigo_conta` antigo se for da classe do papel (não se aplica ao Credor/Devedor,
     * papel novo sem fichas antigas); senão ''.
     *
     * @param  Terceiro|array<string, mixed>|null  $t
     */
    public static function conta(Terceiro|array|null $t, string $papel): string
    {
        if ($t === null) {
            return '';
        }
        $ler = fn (string $campo) => trim((string) ($t instanceof Terceiro ? $t->getAttribute($campo) : ($t[$campo] ?? '')));
        $propria = $ler(self::CAMPOS_CONTA[$papel] ?? 'conta_cliente');
        if ($propria !== '' || $papel === self::CREDOR_DEVEDOR) {
            return $propria;
        }
        $legada = $ler('codigo_conta');
        if ($legada === '') {
            return '';
        }

        return self::classeCorrecta($legada, $papel) ? $legada : '';
    }

    /** @param  Terceiro|array<string, mixed>|null  $t */
    public static function cliente(Terceiro|array|null $t): string
    {
        return self::conta($t, self::CLIENTE);
    }

    /** @param  Terceiro|array<string, mixed>|null  $t */
    public static function fornecedor(Terceiro|array|null $t): string
    {
        return self::conta($t, self::FORNECEDOR);
    }

    /** @param  Terceiro|array<string, mixed>|null  $t */
    public static function credorDevedor(Terceiro|array|null $t): string
    {
        return self::conta($t, self::CREDOR_DEVEDOR);
    }

    /**
     * Mensagem única para quem integra: diz que conta falta, em que terceiro e onde se resolve (falta, contas_terceiro.js:71-75).
     *
     * @param  Terceiro|array<string, mixed>|null  $t
     */
    public static function falta(Terceiro|array|null $t, string $papel): string
    {
        $nome = trim((string) ($t instanceof Terceiro ? ($t->nome ?: $t->nif) : ($t['nome'] ?? $t['nif'] ?? ''))) ?: 'terceiro';

        return match ($papel) {
            self::FORNECEDOR => "O fornecedor {$nome} não tem conta de fornecedor (classe 32, 37.0, 37.1 ou 37.92) associada. "
                .'Abra Compras › Fornecedores (ou Contabilidade › Tabelas auxiliares › Terceiros), associe a conta e volte a integrar.',
            self::CREDOR_DEVEDOR => "O credor/devedor {$nome} não tem conta (classe 37) associada. "
                .'Abra Contabilidade › Tabelas auxiliares › Credores e Devedores, associe a conta e volte a integrar.',
            default => "O cliente {$nome} não tem conta de cliente (classe 31, 37.91 ou 37.2) associada. "
                .'Abra Vendas › Clientes (ou Contabilidade › Tabelas auxiliares › Terceiros), associe a conta e volte a integrar.',
        };
    }

    /**
     * Devolve a conta do papel ou pára com ErroNegocio TERCEIRO_SEM_CONTA (exigir/exigirOuAvisar — sem contas por defeito).
     * $contexto antecede a mensagem (ex.: «O documento FT A/1 não pode ser integrado:»).
     */
    public static function exigir(Terceiro $t, string $papel, string $contexto = ''): string
    {
        $c = self::conta($t, $papel);
        if ($c !== '') {
            return $c;
        }

        throw new ErroNegocio(trim($contexto.' '.self::falta($t, $papel)), 'TERCEIRO_SEM_CONTA', 422, [
            'terceiro_id' => $t->id, 'terceiro' => $t->nome, 'papel' => $papel,
            'campo' => self::CAMPOS_CONTA[$papel] ?? 'conta_cliente',
            'classe' => match ($papel) {
                self::FORNECEDOR => '32, 37.0, 37.1 ou 37.92',
                self::CREDOR_DEVEDOR => '37',
                default => '31, 37.91 ou 37.2',
            },
        ]);
    }

    /**
     * A mesma regra de leitura em SQL (PostgreSQL), para consultas em massa: a conta própria do papel ou o codigo_conta antigo
     * da classe do papel (o Credor/Devedor, papel novo, não tem codigo_conta antigo). $alias é o alias da tabela terceiros
     * na consulta (só letras, números e _).
     */
    public static function sqlConta(string $alias, string $papel): string
    {
        $a = preg_replace('/\W/', '', $alias);
        $propria = "{$a}.".(self::CAMPOS_CONTA[$papel] ?? 'conta_cliente');
        if ($papel === self::CREDOR_DEVEDOR) {
            return "NULLIF(TRIM({$propria}), '')";
        }
        $digitos = "regexp_replace(COALESCE({$a}.codigo_conta, ''), '[^0-9]', '', 'g')";
        $classe = implode(' OR ', array_map(fn ($cl) => "{$digitos} LIKE '".self::digitos($cl)."%'", self::classesDoPapel($papel)));

        return "COALESCE(NULLIF(TRIM({$propria}), ''), CASE WHEN ({$classe}) THEN NULLIF(TRIM({$a}.codigo_conta), '') END)";
    }

    /** Rótulo do tipo para listagens («Cliente e Fornecedor», «—» sem tipo). */
    public static function rotuloTipo(?string $tipo): string
    {
        return self::ROTULOS_TIPO[strtoupper(trim((string) $tipo))] ?? (trim((string) $tipo) ?: '—');
    }
}
