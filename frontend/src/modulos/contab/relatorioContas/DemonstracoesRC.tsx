import { Alert, Card, Skeleton } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { obter } from '@/api/cliente';
import { useSessao } from '@/sessao/SessaoContexto';
import type { Balanco, DemonstracaoResultados, FluxoCaixa } from '../api';
import { TabelaDemonstracao } from '../comum/Demonstracao';
import { linhasBalanco, linhasDR, linhasFluxo } from '../comum/demonstracoes';

/**
 * Separador «Demonstrações Financeiras» do Relatório e Contas (legado relatorio_contas.js:1003-1006 e 775-830): os três
 * blocos — Balanço em 31 de Dezembro, Demonstração dos Resultados e Demonstração dos Fluxos de Caixa — com o ano e o
 * comparativo do ano anterior. Usa os mapas contabilísticos (mesmos cálculos dos separadores Balanço/DR/Fluxo de Caixa);
 * cada bloco só aparece com a permissão de consulta do mapa respectivo.
 */
export function DemonstracoesRC({ ano, concluido = false }: { ano: number; concluido?: boolean }) {
  const { pode } = useSessao();
  const inicio = `${ano}-01-01`;
  const fim = `${ano}-12-31`;
  const pBal = { data_fim: fim, comparativo: 1 };
  const pDR = { data_inicio: inicio, data_fim: fim, comparativo: 1, modo_comparativo: 'ano_anterior' };
  const pFluxo = { data_inicio: inicio, data_fim: fim, comparativo: 1 };
  const verBal = pode('contab_mapa_balanco_view');
  const verDR = pode('contab_mapa_dr_view');
  const verFluxo = pode('contab_mapa_fluxo_view');
  const balanco = useQuery({ queryKey: ['contab', 'mapa', 'balanco', '/contabilidade/relatorios/balanco', pBal], queryFn: () => obter<Balanco>('/contabilidade/relatorios/balanco', pBal), enabled: verBal });
  const dr = useQuery({ queryKey: ['contab', 'mapa', 'dr', '/contabilidade/relatorios/demonstracao-resultados', pDR], queryFn: () => obter<DemonstracaoResultados>('/contabilidade/relatorios/demonstracao-resultados', pDR), enabled: verDR });
  const fluxo = useQuery({ queryKey: ['contab', 'mapa', 'fluxo', '/contabilidade/relatorios/fluxo-caixa', pFluxo], queryFn: () => obter<FluxoCaixa>('/contabilidade/relatorios/fluxo-caixa', pFluxo), enabled: verFluxo });
  const anterior = String(ano - 1);

  return (
    <>
      {concluido && (verBal || verDR || verFluxo) && (
        <Alert type="info" showIcon style={{ marginBottom: 12 }} message="Demonstrações calculadas com os lançamentos actuais (as grandezas abaixo e o documento impresso usam os números fixados na conclusão)." />
      )}
      {verBal && (
        <Card size="small" title={`Balanço em 31 de Dezembro de ${ano}`} style={{ marginBottom: 14 }}>
          {balanco.data ? (
            <TabelaDemonstracao
              linhas={linhasBalanco(balanco.data)}
              comparativo
              rotuloAtual={`31/12/${ano}`}
              rotuloAnterior={`31/12/${anterior}`}
              tipoNota="demonstracao"
              parametros={pBal}
              nomeCsv={`rc_balanco_${ano}`}
              impressao={{ titulo: `Balanço em 31 de Dezembro de ${ano}` }}
            />
          ) : (
            <Skeleton active loading={balanco.isLoading} />
          )}
        </Card>
      )}
      {verDR && (
        <Card size="small" title={`Demonstração dos Resultados — exercício de ${ano}`} style={{ marginBottom: 14 }}>
          {dr.data ? (
            <TabelaDemonstracao
              linhas={linhasDR(dr.data)}
              comparativo
              rotuloAtual={String(ano)}
              rotuloAnterior={anterior}
              tipoNota="demonstracao"
              parametros={pDR}
              nomeCsv={`rc_demonstracao_resultados_${ano}`}
              impressao={{ titulo: `Demonstração dos Resultados — exercício de ${ano}` }}
            />
          ) : (
            <Skeleton active loading={dr.isLoading} />
          )}
        </Card>
      )}
      {verFluxo && (
        <Card size="small" title={`Demonstração dos Fluxos de Caixa — exercício de ${ano}`} style={{ marginBottom: 14 }}>
          {fluxo.data ? (
            <TabelaDemonstracao
              linhas={linhasFluxo(fluxo.data)}
              comparativo
              rotuloAtual={String(ano)}
              rotuloAnterior={anterior}
              tipoNota="fluxo"
              parametros={pFluxo}
              nomeCsv={`rc_fluxos_caixa_${ano}`}
              impressao={{ titulo: `Demonstração dos Fluxos de Caixa — exercício de ${ano}` }}
            />
          ) : (
            <Skeleton active loading={fluxo.isLoading} />
          )}
        </Card>
      )}
    </>
  );
}
