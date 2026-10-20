import { Card, Input, Table, Tag } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';

interface Codigo { codigo: string; http: number; significado: string }

const COR_HTTP = (http: number) => {
  if (http === 401 || http === 403) return 'red';
  if (http === 404) return 'purple';
  if (http === 422 || http === 409) return 'volcano';
  if (http === 400) return 'orange';
  if (http >= 500) return 'magenta';
  return 'default';
};

/** Plataforma SaaS — manual de códigos (plataforma_codigos): erros que a API devolve, com o HTTP e o significado. */
export default function PlataformaCodigos() {
  const [q, setQ] = useState('');
  const { data, isLoading } = useQuery({ queryKey: ['plataforma', 'codigos', q], queryFn: () => obter<Codigo[]>(`/plataforma/codigos?q=${encodeURIComponent(q)}`) });
  return (
    <>
      <CabecalhoPagina titulo="Manual de códigos" subtitulo="Códigos de erro padronizados devolvidos pela API do ERP" />
      <Card 
        title={data ? `${data.length} código(s) catalogado(s)` : 'Catálogo de Erros da API'}
        extra={<Input.Search allowClear placeholder="Pesquisar código ou texto..." onSearch={setQ} style={{ width: 280 }} />}>
        <Table<Codigo> 
          rowKey="codigo" 
          size="middle" 
          loading={isLoading} 
          dataSource={data ?? []} 
          pagination={{ pageSize: 20, showTotal: (total, range) => `${range[0]}-${range[1]} de ${total} códigos` }} 
          columns={[
            { title: 'Código', dataIndex: 'codigo', width: 260, render: (c: string) => <code style={{ fontWeight: 600, color: '#1d39c4' }}>{c}</code> },
            { title: 'HTTP', width: 100, render: (_, c) => <Tag color={COR_HTTP(c.http)}>{c.http}</Tag> },
            { title: 'Significado / Descrição', dataIndex: 'significado' },
          ]} 
        />
      </Card>
    </>
  );
}