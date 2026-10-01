import { Alert, Skeleton } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { AnaliseDinamica } from './comum/AnaliseDinamica';
import type { ConjuntoCubo } from './comum/pivot';

type MetadadosBI = ConjuntoCubo & { periodos: { id: string; rotulo: string }[] };

/** Geral › BI contabilístico (accounting_bi): análise dinâmica dos lançamentos (GET /gestao/bi, POST /gestao/bi/consultar). */
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
      <Alert
        style={{ marginTop: 16 }}
        type="info"
        showIcon
        message="Gestão documental"
        description="A gestão documental (vista «sgd» do legado) ainda não tem API no novo sistema; este ecrã cobre o BI contabilístico."
      />
    </>
  );
}
