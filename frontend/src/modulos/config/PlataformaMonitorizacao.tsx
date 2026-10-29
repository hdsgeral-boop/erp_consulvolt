import { Button, Card, Table, Tag, Typography } from 'antd';
import { ReloadOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';

interface Componente { estado: string; detalhe: string; latencia_ms: number }
interface Monitorizacao {
  estado: string;
  versao: string;
  componentes: Record<string, Componente>;
  informacao: Record<string, { estado: string; detalhe: string }>;
}

const NOMES: Record<string, string> = {
  base_dados: 'Base de dados', redis: 'Redis', filas: 'Filas', armazenamento: 'Armazenamento', processos: 'Scheduler / worker',
};

/**
 * Fase 7 da extensão SaaS (plataforma_monitorizacao): estado das dependências (base de dados, Redis, filas,
 * armazenamento, batimentos do scheduler/worker) — a mesma verificação do `/api/saude` público, só que aqui com o
 * detalhe completo, por já estar atrás de autenticação e permissão de back-office.
 */
export default function PlataformaMonitorizacao() {
  const m = useQuery({ queryKey: ['plataforma', 'monitorizacao'], queryFn: () => obter<Monitorizacao>('/plataforma/monitorizacao') });
  const linhas = Object.entries(m.data?.componentes ?? {}).map(([chave, c]) => ({ chave, ...c }));

  return (
    <>
      <CabecalhoPagina
        titulo="Monitorização"
        subtitulo="Estado das dependências do sistema, em tempo real"
        accoes={<Button icon={<ReloadOutlined />} onClick={() => m.refetch()}>Actualizar</Button>}
      />
      <Card loading={m.isLoading}>
        <Table rowKey="chave" size="small" pagination={false} dataSource={linhas}
          columns={[
            { title: 'Componente', render: (_, c) => NOMES[c.chave] ?? c.chave },
            { title: 'Estado', dataIndex: 'estado', render: (v: string) => <Tag color={v === 'OK' ? 'green' : 'red'}>{v}</Tag> },
            { title: 'Detalhe', dataIndex: 'detalhe' },
            { title: 'Latência', dataIndex: 'latencia_ms', align: 'right', render: (v: number) => `${v} ms` },
          ]} />
      </Card>
      {m.data?.informacao && Object.keys(m.data.informacao).length > 0 && (
        <Card title="Informação adicional" style={{ marginTop: 16 }}>
          <Table rowKey="chave" size="small" pagination={false} dataSource={Object.entries(m.data.informacao).map(([chave, v]) => ({ chave, ...v }))}
            columns={[{ title: 'Serviço', dataIndex: 'chave' }, { title: 'Estado', dataIndex: 'estado' }, { title: 'Detalhe', dataIndex: 'detalhe' }]} />
        </Card>
      )}
      <Typography.Text type="secondary" style={{ display: 'block', marginTop: 12 }}>
        Versão: {m.data?.versao ?? '—'}
      </Typography.Text>
    </>
  );
}
