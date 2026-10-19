import { Card, Input, Space, Table } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { formatarDataHora } from '@/utilitarios/formatacao';

interface Registo { ocorrido_em: string; utilizador: string | null; cliente: string | null; modulo: string; acao: string; tabela: string | null; detalhes: string | null; endereco_ip: string | null }

/** Plataforma SaaS — auditoria (plataforma_auditoria): últimos registos de todos os clientes, só leitura. */
export default function PlataformaAuditoria() {
  const [modulo, setModulo] = useState('');
  const [q, setQ] = useState('');
  const { data, isLoading } = useQuery({
    queryKey: ['plataforma', 'auditoria', modulo, q],
    queryFn: () => obter<Registo[]>(`/plataforma/auditoria?modulo=${encodeURIComponent(modulo)}&q=${encodeURIComponent(q)}`),
  });
  return (
    <>
      <CabecalhoPagina titulo="Auditoria da plataforma" subtitulo="Últimos 200 registos de todos os clientes" />
      <Card extra={<Space><Input.Search allowClear placeholder="Módulo" onSearch={setModulo} style={{ width: 160 }} /><Input.Search allowClear placeholder="Texto" onSearch={setQ} style={{ width: 200 }} /></Space>}>
        <Table<Registo> rowKey={(r) => `${r.ocorrido_em}-${r.acao}-${r.detalhes}`} size="small" loading={isLoading} dataSource={data ?? []} scroll={{ x: 'max-content' }} pagination={{ pageSize: 25 }}
          columns={[
            { title: 'Quando', render: (_, r) => formatarDataHora(r.ocorrido_em) },
            { title: 'Cliente', dataIndex: 'cliente' },
            { title: 'Utilizador', dataIndex: 'utilizador' },
            { title: 'Módulo', dataIndex: 'modulo' },
            { title: 'Acção', dataIndex: 'acao' },
            { title: 'Detalhes', dataIndex: 'detalhes', ellipsis: true },
            { title: 'IP', dataIndex: 'endereco_ip' },
          ]} />
      </Card>
    </>
  );
}