import { Card, Input, Select, Statistic, Table, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { obter } from '@/api/cliente';
import { BotoesExportar } from '@/componentes/impressao';
import { BarraFiltros, scrollTabela, useEcraPequeno } from '@/componentes/responsivo';
import { somar } from '@/utilitarios/decimal';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { ValorKz } from '../contab/comum/Componentes';
import type { Pendente } from './api';
import { pedidoPendentes, ROTULO_NATUREZA } from './impressao';

/**
 * Mapa de pendentes de terceiros (GET /tesouraria/pendentes — documentos em aberto a receber e a pagar), com o total
 * por natureza e impressão agrupada por terceiro. Exige a consulta dos pagamentos (teso_gestao_pagamentos_view).
 */
export function MapaPendentes() {
  const [natureza, setNatureza] = useState<Pendente['natureza']>();
  const [pesquisa, setPesquisa] = useState('');
  const pequeno = useEcraPequeno();
  const consulta = useQuery({
    queryKey: ['teso', 'pendentes', 'mapa', natureza, pesquisa],
    queryFn: () => obter<Pendente[]>('/tesouraria/pendentes', { natureza, pesquisa: pesquisa || undefined }),
    retry: false,
  });
  const linhas = useMemo(() => consulta.data ?? [], [consulta.data]);
  const aReceber = somar(linhas.filter((l) => l.natureza === 'A_RECEBER').map((l) => l.saldo));
  const aPagar = somar(linhas.filter((l) => l.natureza === 'A_PAGAR').map((l) => l.saldo));

  return (
    <Card
      extra={
        <BotoesExportar
          desactivado={!linhas.length}
          obterPedido={() => pedidoPendentes(linhas, [natureza && `Natureza: ${ROTULO_NATUREZA[natureza]}`, pesquisa && `Pesquisa: ${pesquisa}`, `A receber: ${formatarKz(aReceber)} Kz`, `A pagar: ${formatarKz(aPagar)} Kz`])}
        />
      }
      title="Documentos em aberto"
    >
      <BarraFiltros>
        <Select
          placeholder="Natureza"
          allowClear
          style={{ width: 180 }}
          value={natureza}
          onChange={setNatureza}
          options={[
            { value: 'A_RECEBER', label: 'A receber (clientes)' },
            { value: 'A_PAGAR', label: 'A pagar (fornecedores)' },
          ]}
        />
        <Input.Search placeholder="Terceiro ou documento" allowClear style={{ width: 260, maxWidth: '100%' }} onSearch={setPesquisa} />
      </BarraFiltros>
      <div className="erp-grelha-auto" style={{ marginBottom: 12 }}>
        <Statistic title="A receber (Kz)" value={formatarKz(aReceber)} />
        <Statistic title="A pagar (Kz)" value={formatarKz(aPagar)} />
      </div>
      <Table<Pendente>
        rowKey={(p) => `${p.terceiro_id}|${p.codigo_conta}|${p.numero_documento}`}
        size={pequeno ? 'small' : 'middle'}
        loading={consulta.isFetching}
        dataSource={linhas}
        pagination={{ pageSize: 50, showSizeChanger: true, showTotal: (t) => `${t} documento(s)` }}
        scroll={scrollTabela()}
        locale={{ emptyText: consulta.isError ? 'Sem acesso aos pendentes (consulta de pagamentos e recebimentos).' : 'Sem documentos em aberto.' }}
        columns={[
          { title: 'Terceiro', dataIndex: 'terceiro', render: (v: string | null, p) => v?.trim() || `#${p.terceiro_id}` },
          { title: 'Documento', dataIndex: 'numero_documento' },
          { title: 'Data', dataIndex: 'data_documento', responsive: ['sm'], render: formatarData },
          { title: 'Natureza', dataIndex: 'natureza', responsive: ['md'], render: (n: Pendente['natureza']) => ROTULO_NATUREZA[n] },
          { title: 'Conta', dataIndex: 'codigo_conta', responsive: ['lg'] },
          { title: 'Total', dataIndex: 'total', align: 'right', responsive: ['md'], render: (v: string) => <ValorKz valor={v} /> },
          { title: 'Liquidado', dataIndex: 'liquidado', align: 'right', responsive: ['lg'], render: (v: string) => <ValorKz valor={v} /> },
          { title: 'Saldo', dataIndex: 'saldo', align: 'right', render: (v: string) => <ValorKz valor={v} forte /> },
        ]}
      />
      <Typography.Text type="secondary">A impressão agrupa os documentos por terceiro, com subtotais.</Typography.Text>
    </Card>
  );
}
