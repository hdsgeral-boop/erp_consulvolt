import { Card, Table } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useRef } from 'react';
import { obter } from '@/api/cliente';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { CartaoKpi, GrelhaKpi } from '@/componentes/vistas';
import { EtiquetaProjectos } from '../comum/componentes';
import type { Requisicao } from '../comum/tipos';
import type { PropsSeparador } from '../DetalheProjecto';
import { ImpressaoSeparador } from '../comum/ImpressaoSeparador';
import { scrollTabela } from '@/componentes/responsivo';

interface Backlog {
  requisicoes_pendentes: number;
  requisicoes_fechadas: number;
  requisicoes_total: number;
  estimativa_activos: string;
  requisicoes: Requisicao[];
}

/**
 * Backlog do projecto: requisições de material por estado («fechada» = já saiu de PENDENTE — foi para COMPRAS ou
 * STOCK) e a estimativa de activos totais do escopo (soma do «Valor Contrato» de todas as tarefas do Planeamento).
 */
export function SeparadorBacklog({ projecto }: PropsSeparador) {
  const b = useQuery({ queryKey: ['projectos', 'backlog', projecto.id], queryFn: () => obter<Backlog>(`/projetos/${projecto.id}/backlog`) });
  const refSeparador = useRef<HTMLDivElement>(null);

  return (
    <div ref={refSeparador}>
      <div className="imp-nao-imprimir" style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 12 }}>
        <ImpressaoSeparador alvo={refSeparador} titulo="Backlog" projecto={projecto} />
      </div>
      <GrelhaKpi>
        <CartaoKpi barra="esquerda" cor="laranja" icone="hourglass-half" rotulo="Requisições pendentes" valor={b.data?.requisicoes_pendentes ?? '—'} />
        <CartaoKpi barra="esquerda" cor="verde" icone="check-circle" rotulo="Requisições fechadas" valor={b.data?.requisicoes_fechadas ?? '—'} secundaria={`${b.data?.requisicoes_total ?? 0} no total`} />
        <CartaoKpi barra="esquerda" cor="roxo" icone="layer-group" rotulo="Estimativa de activos totais do escopo" valor={formatarKz(b.data?.estimativa_activos)} unidade="Kz" secundaria="Soma do valor de contrato de todas as tarefas" />
      </GrelhaKpi>
      <Card size="small" title="Requisições de material" style={{ marginTop: 16 }} loading={b.isLoading}>
        <Table<Requisicao>
          rowKey="id"
          size="small"
          dataSource={b.data?.requisicoes ?? []}
          pagination={false}
          scroll={scrollTabela()}
          columns={[
            { title: 'Requerente', dataIndex: 'nome_requerente' },
            { title: 'Data', dataIndex: 'data', render: (v: string) => formatarData(v) },
            { title: 'Prevista', dataIndex: 'data_prevista', render: (v: string | null) => formatarData(v) },
            { title: 'Estado', dataIndex: 'estado', render: (v: string) => <EtiquetaProjectos valor={v} /> },
            { title: 'Linhas', key: 'linhas', render: (_, r) => r.linhas.length },
          ]}
        />
      </Card>
    </div>
  );
}
