import { Alert, Button, Descriptions, Empty, InputNumber, Modal, Select, Space, Table, Tag, Typography } from 'antd';
import { EyeOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useState } from 'react';
import { obter } from '@/api/cliente';
import { larguraModal } from '@/componentes/responsivo';
import { formatarDataHora, formatarNumero } from '@/utilitarios/formatacao';
import { PERIODOS_AVALIACAO, type PeriodoAvaliacao } from '../api';
import { useAvisarErro } from './consultas';

interface Autoavaliacao {
  id: number;
  colaborador_id: number;
  nome: string | null;
  estado: string | null;
  submetida_em: string | null;
  criterios: { chave: string; nome?: string; nota?: number | null; comentario?: string | null }[] | null;
  objetivos: { chave: string; descricao?: string; nome?: string; atingido?: number | null; nota_qual?: number | null; comentario?: string | null }[] | null;
  realizacoes: string | null;
  dificuldades: string | null;
  formacao: string | null;
}

interface Ascendente {
  respostas: number;
  minimo: number;
  liberado: boolean;
  media?: number | null;
  questoes?: Record<string, { nome: string; media: number | null }>;
  comentarios?: string[];
}

function FiltroPeriodo({ ano, periodo, setAno, setPeriodo }: { ano: number; periodo: PeriodoAvaliacao; setAno: (v: number) => void; setPeriodo: (v: PeriodoAvaliacao) => void }) {
  return (
    <Space wrap style={{ marginBottom: 12 }}>
      <InputNumber value={ano} min={2000} max={2100} onChange={(v) => v && setAno(v)} aria-label="Ano" />
      <Select value={periodo} onChange={setPeriodo} options={PERIODOS_AVALIACAO} style={{ width: 160 }} aria-label="Período" />
    </Space>
  );
}

/** Pedidos do Portal (RH) › Autoavaliações (portal_ui.js:615): o RH consulta as autoavaliações submetidas. */
export function AutoavaliacoesRH() {
  const [ano, setAno] = useState(dayjs().year());
  const [periodo, setPeriodo] = useState<PeriodoAvaliacao>('ANUAL');
  const [ver, setVer] = useState<Autoavaliacao | null>(null);
  const q = useQuery({ queryKey: ['rh', 'avaliacao', 'autoavaliacoes', ano, periodo], queryFn: () => obter<Autoavaliacao[]>('/rh/avaliacao/autoavaliacoes', { ano, periodo }) });
  useAvisarErro(q.error);
  return (
    <>
      <FiltroPeriodo ano={ano} periodo={periodo} setAno={setAno} setPeriodo={setPeriodo} />
      <Table<Autoavaliacao> rowKey="id" size="small" loading={q.isFetching} dataSource={q.data ?? []} scroll={{ x: 'max-content' }} pagination={{ pageSize: 25 }}
        locale={{ emptyText: <Empty description="Sem autoavaliações neste período." /> }} columns={[
          { title: 'Colaborador', dataIndex: 'nome' },
          { title: 'Estado', dataIndex: 'estado', render: (e: string | null) => <Tag color={e === 'SUBMETIDA' ? 'green' : 'default'}>{e ?? 'RASCUNHO'}</Tag> },
          { title: 'Submetida em', dataIndex: 'submetida_em', responsive: ['md'], render: (v: string | null) => (v ? formatarDataHora(v) : '—') },
          { title: '', key: 'a', align: 'right', render: (_, a) => <Button size="small" icon={<EyeOutlined />} onClick={() => setVer(a)}>Ver</Button> },
        ]} />
      <Modal title={`Autoavaliação — ${ver?.nome ?? ''}`} open={ver !== null} width={larguraModal(760)} onCancel={() => setVer(null)} footer={<Button onClick={() => setVer(null)}>Fechar</Button>}>
        {ver && (
          <Space direction="vertical" style={{ width: '100%' }}>
            <Table size="small" rowKey="chave" pagination={false} dataSource={ver.criterios ?? []} columns={[
              { title: 'Critério', render: (_, c) => c.nome ?? c.chave }, { title: 'Nota', dataIndex: 'nota', align: 'center' }, { title: 'Comentário', dataIndex: 'comentario' },
            ]} />
            {(ver.objetivos ?? []).length > 0 && (
              <Table size="small" rowKey="chave" pagination={false} dataSource={ver.objetivos ?? []} columns={[
                { title: 'Objectivo', render: (_, o) => o.descricao ?? o.nome ?? o.chave }, { title: 'Resultado', render: (_, o) => o.atingido ?? o.nota_qual ?? '—', align: 'right' },
                { title: 'Comentário', dataIndex: 'comentario' },
              ]} />
            )}
            <Descriptions size="small" bordered column={1}>
              <Descriptions.Item label="Realizações">{ver.realizacoes ?? '—'}</Descriptions.Item>
              <Descriptions.Item label="Dificuldades">{ver.dificuldades ?? '—'}</Descriptions.Item>
              <Descriptions.Item label="Formação pretendida">{ver.formacao ?? '—'}</Descriptions.Item>
            </Descriptions>
          </Space>
        )}
      </Modal>
    </>
  );
}

/** Pedidos do Portal (RH) › Avaliação das chefias: resultados anónimos da avaliação ascendente (mínimo de respostas do ciclo). */
export function AvaliacaoChefiasRH() {
  const [ano, setAno] = useState(dayjs().year());
  const [periodo, setPeriodo] = useState<PeriodoAvaliacao>('ANUAL');
  const [chefia, setChefia] = useState<{ colaborador_id: number; nome: string } | null>(null);
  const chefias = useQuery({ queryKey: ['rh', 'avaliacao', 'chefias'], queryFn: () => obter<{ colaborador_id: number; nome: string; equipa: number }[]>('/rh/avaliacao/chefias') });
  const res = useQuery({ queryKey: ['rh', 'avaliacao', 'ascendente', chefia?.colaborador_id, ano, periodo], enabled: Boolean(chefia),
    queryFn: () => obter<Ascendente>(`/rh/avaliacao/ascendente/${chefia?.colaborador_id}`, { ano, periodo }) });
  useAvisarErro(chefias.error);
  useAvisarErro(res.error);
  const r = res.data;
  return (
    <>
      <FiltroPeriodo ano={ano} periodo={periodo} setAno={setAno} setPeriodo={setPeriodo} />
      <Table size="small" rowKey="colaborador_id" loading={chefias.isFetching} dataSource={chefias.data ?? []} pagination={{ pageSize: 25 }} scroll={{ x: 'max-content' }} columns={[
        { title: 'Chefia', dataIndex: 'nome' }, { title: 'Equipa', dataIndex: 'equipa', align: 'right' },
        { title: '', key: 'a', align: 'right', render: (_, c) => <Button size="small" icon={<EyeOutlined />} onClick={() => setChefia(c)}>Resultados</Button> },
      ]} />
      <Modal title={`Avaliação ascendente — ${chefia?.nome ?? ''} (${periodo} ${ano})`} open={chefia !== null} onCancel={() => setChefia(null)} footer={<Button onClick={() => setChefia(null)}>Fechar</Button>}>
        {res.isFetching && <Typography.Text>A carregar…</Typography.Text>}
        {r && !r.liberado && <Alert type="info" showIcon message={`Resultados anónimos ainda não disponíveis: ${r.respostas} resposta(s) de um mínimo de ${r.minimo} (ou o ciclo ainda não libertou os resultados).`} />}
        {r?.liberado && (
          <Space direction="vertical" style={{ width: '100%' }}>
            <Typography.Text>{r.respostas} resposta(s){r.media != null ? ` · média ${formatarNumero(r.media)}` : ''}</Typography.Text>
            <Table size="small" rowKey="chave" pagination={false} dataSource={Object.entries(r.questoes ?? {}).map(([chave, x]) => ({ chave, ...x }))} columns={[{ title: 'Questão', dataIndex: 'nome' }, { title: 'Média', dataIndex: 'media', align: 'right', render: (v: number | null) => (v != null ? formatarNumero(v) : '—') }]} />
            {(r.comentarios ?? []).length > 0 && <ul>{r.comentarios?.map((c, i) => <li key={i}>{c}</li>)}</ul>}
          </Space>
        )}
      </Modal>
    </>
  );
}
