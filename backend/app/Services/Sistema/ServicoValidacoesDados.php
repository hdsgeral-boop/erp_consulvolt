<?php

namespace App\Services\Sistema;

use App\Exceptions\ErroNegocio;
use App\Models\LancamentoContabil;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;

/**
 * Validações de dados (ADR-015): substituem as rotinas destrutivas escondidas do legado, que alteravam
 * ou apagavam dados ao abrir ecrãs, por relatórios só de leitura com drill-down. As correcções, quando
 * existirem, são acções explícitas e auditadas — nunca efeitos de uma leitura.
 *
 * Cada validação: código, módulo, gravidade, descrição, origem no legado e uma consulta que devolve
 * as linhas problemáticas da empresa activa (primeiro parâmetro: empresa_id).
 */
final class ServicoValidacoesDados
{
    public function __construct(private readonly ContextoEmpresa $contexto) {}

    /** @return array<string, array{titulo: string, modulo: string, gravidade: string, descricao: string, legado: string, sql: string}> */
    private function definicoes(): array
    {
        return [
            'lancamentos_desequilibrados' => [
                'titulo' => 'Lançamentos desequilibrados (Σ D ≠ Σ C)', 'modulo' => 'Contabilidade', 'gravidade' => 'ERRO',
                'descricao' => 'Lançamentos cujo total a débito difere do total a crédito. Os do legado foram importados como estão (decisão 2026-09-29).',
                'legado' => 'Alerta de desequilíbrio em ui_reports.js:381-408',
                'sql' => 'SELECT d.codigo AS diario, '.LancamentoContabil::chaveSql('l')." AS lancamento, MIN(l.data_documento) AS data,
                            SUM(CASE WHEN l.tipo_dc='D' THEN l.valor ELSE -l.valor END) AS diferenca, MIN(l.id) AS linha_id
                          FROM lancamentos_contabeis l JOIN diarios_contabeis d ON d.id = l.diario_id WHERE l.empresa_id = ?
                          GROUP BY d.codigo, ".LancamentoContabil::chaveSql('l')."
                          HAVING SUM(CASE WHEN l.tipo_dc='D' THEN l.valor ELSE -l.valor END) <> 0 ORDER BY 4",
            ],
            'lancamentos_em_contas_totalizadoras' => [
                'titulo' => 'Movimentos em contas totalizadoras', 'modulo' => 'Contabilidade', 'gravidade' => 'AVISO',
                'descricao' => 'Linhas lançadas em contas do tipo T, que só deviam agregar (regra js/db_v2.js:392-449).',
                'legado' => 'Hook de contas de movimento (não aplicado a dados antigos)',
                'sql' => "SELECT l.id AS linha_id, l.codigo_conta, l.numero_lan, l.data_documento, l.valor, l.tipo_dc
                          FROM lancamentos_contabeis l JOIN plano_contas p ON p.empresa_id = l.empresa_id AND p.codigo = l.codigo_conta AND p.eliminado_em IS NULL
                          WHERE l.empresa_id = ? AND p.tipo = 'T' ORDER BY l.data_documento, l.id",
            ],
            'lancamentos_conta_inexistente' => [
                'titulo' => 'Movimentos em contas que não existem no plano', 'modulo' => 'Contabilidade', 'gravidade' => 'AVISO',
                'descricao' => 'Linhas cujo código de conta não existe (ou foi eliminado) no plano de contas da empresa.',
                'legado' => 'O legado não validava o código de conta na importação',
                'sql' => "SELECT l.codigo_conta, COUNT(*) AS linhas, SUM(CASE WHEN l.tipo_dc='D' THEN l.valor ELSE -l.valor END) AS saldo, MIN(l.id) AS linha_id
                          FROM lancamentos_contabeis l
                          WHERE l.empresa_id = ? AND NOT EXISTS (SELECT 1 FROM plano_contas p WHERE p.empresa_id = l.empresa_id AND p.codigo = l.codigo_conta AND p.eliminado_em IS NULL)
                          GROUP BY l.codigo_conta ORDER BY 2 DESC",
            ],
            'lancamentos_valor_zero' => [
                'titulo' => 'Linhas de lançamento com valor zero', 'modulo' => 'Contabilidade', 'gravidade' => 'INFO',
                'descricao' => 'Linhas sem efeito contabilístico (38 vieram do legado).', 'legado' => '—',
                'sql' => 'SELECT id AS linha_id, codigo_conta, numero_lan, numero_documento, data_documento FROM lancamentos_contabeis WHERE empresa_id = ? AND valor = 0 ORDER BY id',
            ],
            'terceiros_nif_duplicado' => [
                'titulo' => 'Terceiros duplicados (mesmo NIF e tipo)', 'modulo' => 'Terceiros', 'gravidade' => 'AVISO',
                'descricao' => 'Clientes/fornecedores registados mais de uma vez com o mesmo NIF (408 no legado).', 'legado' => '—',
                'sql' => "SELECT nif, tipo, COUNT(*) AS registos, string_agg(id::text, ', ' ORDER BY id) AS ids, string_agg(DISTINCT nome, ' | ') AS nomes
                          FROM terceiros WHERE empresa_id = ? AND eliminado_em IS NULL AND nif IS NOT NULL AND nif <> '' AND nif NOT IN ('999999999', '000000000')
                          GROUP BY nif, tipo HAVING COUNT(*) > 1 ORDER BY 3 DESC",
            ],
            'mapeamentos_rh_duplicados' => [
                'titulo' => 'Mapeamentos contabilísticos de RH ambíguos', 'modulo' => 'RH', 'gravidade' => 'ERRO',
                'descricao' => 'Mais de uma conta para o mesmo infotipo e tipo de órgão: a integração salarial fica ambígua.', 'legado' => '—',
                'sql' => "SELECT infotipo_salarial_id, tipo_organizacao_id, avencado, COUNT(*) AS registos, string_agg(DISTINCT numero_conta, ', ') AS contas,
                            string_agg(id::text, ', ' ORDER BY id) AS ids
                          FROM mapeamentos_contabeis_rh WHERE empresa_id = ? GROUP BY 1, 2, 3 HAVING COUNT(*) > 1 ORDER BY 4 DESC",
            ],
            'faturas_fornecedor_duplicadas' => [
                'titulo' => 'Facturas de fornecedor registadas em duplicado', 'modulo' => 'Compras', 'gravidade' => 'ERRO',
                'descricao' => 'O mesmo número de factura do mesmo fornecedor registado mais de uma vez (risco de pagamento em duplicado).', 'legado' => '—',
                'sql' => "SELECT fornecedor_id, numero_fatura, COUNT(*) AS registos, string_agg(id::text, ', ' ORDER BY id) AS ids
                          FROM faturas_compra WHERE empresa_id = ? AND numero_fatura IS NOT NULL
                          GROUP BY 1, 2 HAVING COUNT(*) > 1 ORDER BY 3 DESC",
            ],
            'vendas_numeracao_repetida' => [
                'titulo' => 'Documentos comerciais não fiscais com número repetido', 'modulo' => 'Vendas', 'gravidade' => 'AVISO',
                'descricao' => 'Orçamentos/proformas com o mesmo número (o legado tratava "Orçamento" e "Orcamento" como séries distintas).', 'legado' => '—',
                'sql' => "SELECT tipo_documento, numero_documento, COUNT(*) AS registos, string_agg(id::text || ' (' || COALESCE(tipo_documento_original, '') || ')', ', ') AS documentos
                          FROM vendas WHERE empresa_id = ? GROUP BY 1, 2 HAVING COUNT(*) > 1 ORDER BY 3 DESC",
            ],
            'documentos_tesouraria_sem_data' => [
                'titulo' => 'Documentos de tesouraria sem data', 'modulo' => 'Tesouraria', 'gravidade' => 'AVISO',
                'descricao' => 'O legado APAGAVA estes documentos e as suas linhas ao abrir a Tesouraria (ui_tesouraria.js:275-286). Agora são listados para decisão.',
                'legado' => 'renderTesouraria: eliminação automática',
                'sql' => 'SELECT id, referencia, tipo, valor_total FROM documentos_tesouraria WHERE empresa_id = ? AND data_documento IS NULL ORDER BY id',
            ],
            'amortizacoes_duplicadas' => [
                'titulo' => 'Amortizações duplicadas no mesmo período', 'modulo' => 'Activos', 'gravidade' => 'AVISO',
                'descricao' => 'O legado apagava duplicados e recalculava o acumulado de TODAS as empresas ao abrir Activos (ui_assets.js:3-37). Agora são listados.',
                'legado' => 'renderAssets: "correcção" automática',
                'sql' => "SELECT ativo_imobilizado_id, periodo_codigo, COUNT(*) AS registos, string_agg(id::text, ', ' ORDER BY id) AS ids, SUM(valor) AS valor_total
                          FROM amortizacoes_ativos WHERE empresa_id = ? GROUP BY 1, 2 HAVING COUNT(*) > 1 ORDER BY 3 DESC",
            ],
            'contas_por_omissao' => [
                'titulo' => 'Contas por omissão de clientes/produtos (3112, 71.1, 34.5)', 'modulo' => 'Vendas', 'gravidade' => 'INFO',
                'descricao' => 'O legado removia estas contas por omissão uma vez por empresa ao abrir Vendas (ui_sales.js:366-393). Listadas para revisão manual.',
                'legado' => 'Limpeza automática de contas por omissão',
                'sql' => "SELECT 'terceiros' AS origem, id, nome AS registo, codigo_conta FROM terceiros WHERE empresa_id = ? AND codigo_conta IN ('3112')
                          ORDER BY id",
            ],
        ];
    }

    /** @return list<array<string, mixed>> resumo de todas as validações da empresa activa */
    public function resumo(): array
    {
        $empresa = $this->contexto->obrigatorio();
        $saida = [];
        foreach ($this->definicoes() as $codigo => $d) {
            $n = (int) DB::selectOne("SELECT COUNT(*) AS n FROM ({$d['sql']}) v", [$empresa])->n;
            $saida[] = ['codigo' => $codigo] + array_diff_key($d, ['sql' => true]) + ['ocorrencias' => $n];
        }

        return $saida;
    }

    /** @return array<string, mixed> detalhe (linhas) de uma validação */
    public function detalhe(string $codigo, int $limite = 500): array
    {
        $d = $this->definicoes()[$codigo] ?? throw new ErroNegocio("Validação desconhecida: {$codigo}", 'VALIDACAO_DESCONHECIDA', 404);
        $linhas = DB::select("SELECT * FROM ({$d['sql']}) v LIMIT ".max(1, min($limite, 5000)), [$this->contexto->obrigatorio()]);

        return ['codigo' => $codigo] + array_diff_key($d, ['sql' => true]) + ['linhas' => $linhas, 'total_mostrado' => count($linhas)];
    }
}
