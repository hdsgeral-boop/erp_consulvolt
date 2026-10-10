import { Alert, Card, Flex, Space } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState, type ReactNode } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import type { DetalhePeriodo, PeriodoSalarial, ResultadoSalarial } from '../api';
import { BotaoImprimir, SemPeriodo, SeletorPeriodo } from '../comum/componentes';
import { useAvisarErro, useColaboradores, usePeriodosSalariais } from '../comum/consultas';

/**
 * Período escolhido nos mapas: por omissão o validado mais recente. Com `idFixo` (mapa embutido no ecrã único
 * «Relatórios e Recibos») o período é o escolhido nesse ecrã.
 */
export function usePeriodoMapa(apenas?: PeriodoSalarial['estado'][], idFixo?: number) {
  const periodos = usePeriodosSalariais();
  useAvisarErro(periodos.error, 'Erro ao carregar os períodos salariais');
  const [idInterno, setId] = useState<number>();
  const id = idFixo ?? idInterno;
  useEffect(() => {
    if (idFixo === undefined && id === undefined && periodos.data?.length) {
      const preferido = periodos.data.find((p) => p.estado === 'VALIDADO') ?? (apenas ? undefined : periodos.data[0]);
      if (preferido) setId(preferido.id);
    }
  }, [periodos.data, id, apenas, idFixo]);
  const detalhe = useQuery({ queryKey: ['rh', 'salarios', 'periodo', String(id)], queryFn: () => obter<DetalhePeriodo>(`/rh/salarios/periodos/${id}`), enabled: id !== undefined });
  useAvisarErro(detalhe.error, 'Erro ao carregar o período');
  return { periodos, id, setId, detalhe, periodo: periodos.data?.find((p) => p.id === id) };
}

/** Moldura comum dos mapas: cabeçalho, escolha do período, imprimir e acções extra. */
export function MolduraMapa({ titulo, subtitulo, mapa, apenas, accoes, filtros, children, aviso, embutido }: {
  titulo: string;
  subtitulo: string;
  mapa: ReturnType<typeof usePeriodoMapa>;
  apenas?: PeriodoSalarial['estado'][];
  accoes?: ReactNode;
  filtros?: ReactNode;
  aviso?: ReactNode;
  children: ReactNode;
  /** Dentro do ecrã «Relatórios e Recibos»: sem cabeçalho de página nem selector de período (já escolhido lá). */
  embutido?: boolean;
}) {
  if (embutido) {
    return (
      <Card title={titulo} extra={<Space wrap className="rh-nao-imprimir">{accoes}<BotaoImprimir desactivado={!mapa.detalhe.data} titulo={titulo} /></Space>}>
        {filtros && <Flex className="rh-nao-imprimir" gap={8} wrap align="center" style={{ marginBottom: 12 }}>{filtros}</Flex>}
        {aviso}
        {mapa.id === undefined ? <SemPeriodo /> : mapa.detalhe.isLoading ? <Card loading variant="borderless" /> : mapa.detalhe.data ? children : null}
      </Card>
    );
  }
  return (
    <>
      <CabecalhoPagina titulo={titulo} subtitulo={subtitulo} accoes={<Space wrap>{accoes}<BotaoImprimir desactivado={!mapa.detalhe.data} titulo={titulo} /></Space>} />
      <Card className="rh-nao-imprimir" style={{ marginBottom: 16 }} styles={{ body: { padding: 12 } }}>
        <Flex gap={8} wrap align="center">
          <SeletorPeriodo periodos={mapa.periodos.data} valor={mapa.id} aoMudar={mapa.setId} apenas={apenas} carregando={mapa.periodos.isLoading} />
          {filtros}
        </Flex>
      </Card>
      {aviso}
      {mapa.id === undefined ? <SemPeriodo /> : mapa.detalhe.isLoading ? <Card loading /> : mapa.detalhe.data ? <Card>{children}</Card> : null}
    </>
  );
}

export function AvisoNaoValidado({ periodo }: { periodo?: PeriodoSalarial }) {
  if (!periodo || periodo.estado === 'VALIDADO') return null;
  return <Alert className="rh-nao-imprimir" type="warning" showIcon style={{ marginBottom: 16 }} message={`Período ${periodo.estado === 'ABERTO' ? 'em cálculo (valores provisórios, ao vivo)' : 'fechado mas ainda não validado'}.`} />;
}

/** Dados do colaborador para os mapas (nome, NIF, INSS, morada): nome, NIF e INSS do próprio resultado (fotografados, ADR-064) e, na falta, da ficha. */
export function useDadosColaborador() {
  const c = useColaboradores();
  return (id: number, r?: Pick<ResultadoSalarial, 'nome' | 'nif' | 'numero_inss'>) => {
    const x = c.mapa.get(id);
    return { nome: r?.nome ?? x?.nome_completo ?? `#${id}`, nif: r?.nif ?? x?.nif ?? '', inss: r?.numero_inss ?? x?.numero_inss ?? '', provincia: x?.provincia ?? '', municipio: x?.municipio ?? '', cargo: x?.cargo_funcao_id ?? null };
  };
}
