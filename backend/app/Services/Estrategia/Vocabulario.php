<?php

namespace App\Services\Estrategia;

/** Enumerações do Planeamento Estratégico (códigos do legado + rótulos exactos). */
final class Vocabulario
{
    public const ESTADOS_PLANO = ['RASCUNHO' => 'Em elaboração', 'APROVADO' => 'Aprovado', 'EM_EXECUCAO' => 'Em execução', 'ENCERRADO' => 'Encerrado'];

    public const HORIZONTES = ['PLANO' => 'Objectivo do plano', 'TRIMESTRE' => 'OKR do trimestre'];

    public const ESTADOS_OBJECTIVO = ['ACTIVO' => 'Activo', 'SUSPENSO' => 'Suspenso', 'CONCLUIDO' => 'Concluído', 'ABANDONADO' => 'Abandonado'];

    public const POLARIDADES = ['SOBE' => 'Quanto maior, melhor', 'DESCE' => 'Quanto menor, melhor', 'ALVO' => 'Quanto mais perto da meta, melhor'];

    public const TIPOS_INDICADOR = ['RESULTADO' => 'Resultado (lagging)', 'INDUTOR' => 'Indutor (leading)'];

    public const FREQUENCIAS = ['MENSAL' => 'Mensal', 'TRIMESTRAL' => 'Trimestral', 'SEMESTRAL' => 'Semestral', 'ANUAL' => 'Anual'];

    public const FORMATOS = ['kz' => 'Kwanzas', 'num' => 'Número', 'pct' => 'Percentagem', 'dias' => 'Dias', 'horas' => 'Horas', 'meses' => 'Meses', 'nota' => 'Nota (0-20)'];

    public const ORIGENS = ['KPI_GESTAO' => 'Indicador de gestão', 'CONTA' => 'Contas do plano', 'ORCAMENTO' => 'Orçamento', 'MODULO' => 'Dados de um módulo', 'MANUAL' => 'Recolha manual'];

    /** Origens disponíveis nesta onda (as restantes estão previstas e ainda não se medem no sistema novo). */
    public const ORIGENS_DISPONIVEIS = ['MODULO', 'MANUAL'];

    public const AGREGACOES = ['SOMA' => 'Soma dos contributos', 'MEDIA' => 'Média dos contributos', 'NENHUMA' => 'Não se agrega'];

    public const SEMAFOROS = ['VERDE' => 'Cumprida', 'AMARELO' => 'Em risco', 'VERMELHO' => 'Não cumprida', 'SEM_META' => 'Sem meta', 'SEM_MEDIDA' => 'Por medir'];

    public const OPERACOES = ['CONTAGEM' => 'Contar registos', 'SOMA' => 'Somar um campo', 'MEDIA' => 'Média de um campo'];

    public const OPERADORES = ['IGUAL' => '=', 'DIFERENTE' => '≠', 'CONTEM' => 'contém', 'MAIOR' => '>', 'MENOR' => '<', 'PREENCHIDO' => 'preenchido', 'VAZIO' => 'vazio'];

    /** Perspectivas criadas com o plano (Balanced Scorecard). */
    public const PERSPECTIVAS_OMISSAO = [
        ['nome' => 'Financeira', 'descricao' => 'Crescimento, rentabilidade e solidez financeira.', 'cor' => '#0f766e', 'icone' => 'fa-coins'],
        ['nome' => 'Clientes', 'descricao' => 'Valor entregue e relação com os clientes.', 'cor' => '#1d4ed8', 'icone' => 'fa-handshake'],
        ['nome' => 'Processos internos', 'descricao' => 'Eficiência e qualidade dos processos.', 'cor' => '#b45309', 'icone' => 'fa-gears'],
        ['nome' => 'Pessoas e aprendizagem', 'descricao' => 'Competências, cultura e capacidade de mudar.', 'cor' => '#7e22ce', 'icone' => 'fa-graduation-cap'],
    ];

    /** Agregação por omissão no desdobramento (EST-C-14): percentagens e notas fazem média, o resto soma. */
    public static function agregacaoPorOmissao(string $formato): string
    {
        return in_array($formato, ['pct', 'nota'], true) ? 'MEDIA' : 'SOMA';
    }
}
