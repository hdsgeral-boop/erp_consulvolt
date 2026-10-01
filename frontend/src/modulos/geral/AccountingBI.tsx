import { Alert, Skeleton } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { AnaliseDinamica } from './comum/AnaliseDinamica';
import type { ConjuntoCubo } from './comum/pivot';

type MetadadosBI = ConjuntoCubo & { periodos: { id: string; rotulo: string }[] };

/**
 * Geral › BI contabilístico (accounting_bi): análise dinâmica dos lançamentos (GET /gestao/bi, POST /gestao/bi/consultar).
 * A vista «sgd» (Gestão documental) do mesmo ecrã no legado era um ecrã morto: app_v2.js:1166 chamava window.renderSGD,
 * que nenhum ficheiro define (INVENTARIO_FUNCIONAL_LEGADO.md 3.1) — não há funcionalidade a migrar.
 */
export default function AccountingBI() {
  const meta = useQuery({ queryKey: ['gestao', 'bi'], queryFn: () => obter<MetadadosBI>('/gestao/bi'), staleTime: 300_000 });
  return (
    <>
      <CabecalhoPagina titulo="BI contabilístico" subtitulo="Análise dinâmica dos lançamentos por conta, entidade, diário, centro de custo e período" />
      {meta.isLoading ? (
        <Skeleton active />
      ) : meta.error || !meta.data ? (
        <Alert type="error" showIcon message="Não foi possível carregar o BI contabilístico." />
      ) : (
        <AnaliseDinamica conjunto={meta.data} urlConsultar="/gestao/bi/consultar" periodos={meta.data.periodos} />
      )}
    </>
  );
}
