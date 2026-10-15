import { Alert, Button, Card, Col, DatePicker, Flex, Modal, Row, Space, Statistic, Table, Tabs, Tag, Typography } from 'antd';
import { SendOutlined, UnorderedListOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useRef, useState } from 'react';
import { obter } from '@/api/cliente';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarData } from '@/utilitarios/formatacao';
import { ImpressaoSeparador } from '../comum/ImpressaoSeparador';
import { PonteProdutividade, TIPOS_PONTE } from '../comum/PonteProdutividade';
import { formatarQtd } from '../comum/regrasMetas';
import type { ExecucaoProjecto } from '../comum/tipos';
import type { PropsSeparador } from '../DetalheProjecto';
import { ModalFolhaMedicao } from './planeamento/ModaisMetas';

/**
 * Separador «Execução» (Versão Actual, renderProjectExecucao, ui_projects.js:5715-5880): horas registadas, quantidades
 * medidas e tarefas concluídas no intervalo, por colaborador, com a parte atribuível a colaboradores internos, o alerta da
 * execução sem dono, o detalhe e os botões «Folha de medição» e «Enviar para Produtividade (RH)».
 */
export function SeparadorExecucao({ projecto, acc }: PropsSeparador) {
  const { pode } = useSessao();
  const [periodo, setPeriodo] = useState<[dayjs.Dayjs, dayjs.Dayjs]>([dayjs().startOf('month'), dayjs()]);
  const [folha, setFolha] = useState(false);
  const [ponte, setPonte] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  const de = periodo[0].format('YYYY-MM-DD');
  const ate = periodo[1].format('YYYY-MM-DD');
  const q = useQuery({ queryKey: ['projectos', 'execucao', projecto.id, de, ate], queryFn: () => obter<ExecucaoProjecto>(`/projetos/${projecto.id}/execucao`, { de, ate }) });
  const e = q.data;
  const quantidades = e ? [...new Set(e.por_colaborador.flatMap((p) => Object.keys(p.quantidades)))].sort() : [];

  return (
    <div ref={ref}>
      <Card
        title="Execução do projecto"
        extra={
          <Space wrap className="imp-nao-imprimir">
            <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} allowClear={false} onChange={(v) => v?.[0] && v[1] && setPeriodo([v[0], v[1]])} />
            {acc.execucao && <Button icon={<UnorderedListOutlined />} onClick={() => setFolha(true)}>Folha de medição</Button>}
            {pode('rh_prod_registar', 'rh_produtividade_view') && <Button icon={<SendOutlined />} onClick={() => setPonte(true)}>Enviar para Produtividade (RH)</Button>}
            <ImpressaoSeparador alvo={ref} titulo={`Execução ${formatarData(de)} a ${formatarData(ate)}`} projecto={projecto} desactivado={!e} />
          </Space>
        }
        loading={q.isLoading}
      >
        <Typography.Paragraph type="secondary">Horas, quantidades medidas e tarefas concluídas no intervalo escolhido — a matéria-prima do subsídio de produtividade do RH.</Typography.Paragraph>
        {e && (
          <>
            <Row gutter={[12, 12]}>
              <Col xs={24} md={8}><Card size="small"><Statistic title="Horas registadas" value={formatarQtd(e.cartoes.horas.total)} suffix="h" />
                <Typography.Text type="secondary" style={{ fontSize: 12 }}>{formatarQtd(e.cartoes.horas.atribuivel)} h de colaboradores internos · {e.cartoes.horas.registos} registo(s)</Typography.Text></Card></Col>
              <Col xs={24} md={8}><Card size="small"><Statistic title="Quantidade medida" value={e.cartoes.quantidades.length ? ' ' : '—'} />
                {e.cartoes.quantidades.map((c) => <div key={c.unidade}><strong>{formatarQtd(c.total)} {c.unidade}</strong> <Typography.Text type="secondary" style={{ fontSize: 12 }}>({formatarQtd(c.atribuivel)} atribuível)</Typography.Text></div>)}</Card></Col>
              <Col xs={24} md={8}><Card size="small"><Statistic title="Tarefas concluídas" value={e.cartoes.tarefas.total} />
                <Typography.Text type="secondary" style={{ fontSize: 12 }}>{e.cartoes.tarefas.atribuivel} com responsável interno</Typography.Text></Card></Col>
            </Row>
            {e.sem_dono.length > 0 && (
              <Alert style={{ marginTop: 12 }} type="warning" showIcon message="Execução sem colaborador interno — não há a quem pagar"
                description={<ul style={{ margin: 0, paddingLeft: 18 }}>{e.sem_dono.map((o) => <li key={`${o.tipo}|${o.unidade}|${o.motivo}`}>{TIPOS_PONTE[o.tipo]}: {formatarQtd(o.quantidade, 4)} {o.unidade} — {o.motivo}</li>)}</ul>} />
            )}
            <Typography.Title level={5} style={{ marginTop: 16 }}>Por colaborador</Typography.Title>
            <Table size="small" rowKey="colaborador_id" pagination={false} dataSource={e.por_colaborador} scroll={scrollTabela()} locale={{ emptyText: 'Sem execução de colaboradores internos no intervalo.' }}
              columns={[
                { title: 'Colaborador', dataIndex: 'nome' },
                { title: 'Horas', dataIndex: 'horas', align: 'right', render: (v) => formatarQtd(v) },
                ...quantidades.map((u) => ({ title: u, key: `q-${u}`, align: 'right' as const, render: (_: unknown, p: ExecucaoProjecto['por_colaborador'][number]) => formatarQtd(p.quantidades[u] ?? 0) })),
                { title: 'Tarefas concluídas', dataIndex: 'tarefas', align: 'right' },
              ]} />
            <Tabs style={{ marginTop: 16 }} items={[
              { key: 'h', label: `Folhas de horas (${e.folhas.length})`, children: (
                <Table size="small" rowKey="id" dataSource={e.folhas} pagination={{ pageSize: 20 }} scroll={scrollTabela()}
                  columns={[{ title: 'Data', dataIndex: 'data', render: formatarData }, { title: 'Colaborador / membro', dataIndex: 'colaborador', render: (v) => v ?? '—' },
                    { title: 'Tarefa', dataIndex: 'tarefa' }, { title: 'Horas', dataIndex: 'horas', align: 'right', render: (v) => formatarQtd(v) }]} />) },
              { key: 'm', label: `Medições (${e.medicoes.length})`, children: (
                <Table size="small" rowKey={(m) => `${m.tarefa_id}-${m.data}-${m.quantidade}-${m.por}`} dataSource={e.medicoes} pagination={{ pageSize: 20 }} scroll={scrollTabela()}
                  columns={[{ title: 'Data', dataIndex: 'data', render: formatarData }, { title: 'Tarefa', dataIndex: 'tarefa' }, { title: 'Responsável', dataIndex: 'responsavel', render: (v) => v ?? '—' },
                    { title: 'Quantidade', key: 'q', align: 'right', render: (_, m) => `${formatarQtd(m.quantidade)} ${m.unidade}` }, { title: 'Nota', dataIndex: 'nota' }, { title: 'Por', dataIndex: 'por' }]} />) },
              { key: 'c', label: `Tarefas concluídas (${e.concluidas.length})`, children: (
                <Table size="small" rowKey="tarefa_id" dataSource={e.concluidas} pagination={false} scroll={scrollTabela()}
                  columns={[{ title: 'Concluída em', dataIndex: 'concluida_em', render: formatarData }, { title: 'Tarefa', dataIndex: 'tarefa' },
                    { title: 'Responsável', dataIndex: 'responsavel', render: (v) => v ?? <Tag>sem responsável</Tag> }]} />) },
            ]} />
          </>
        )}
      </Card>
      <ModalFolhaMedicao projectoId={projecto.id} aberto={folha} aoFechar={() => setFolha(false)} />
      <Modal title={`Enviar para a Produtividade (RH) — ${formatarData(de)} a ${formatarData(ate)}`} open={ponte} onCancel={() => setPonte(false)} footer={null} width={larguraModal(1000)} destroyOnHidden>
        <Flex vertical gap={8}>
          <Typography.Text type="secondary">Só este projecto. Para juntar vários projectos use RH › Produtividade › «Execução de projectos».</Typography.Text>
          <PonteProdutividade de={de} ate={ate} projetos={[projecto.id]} />
        </Flex>
      </Modal>
    </div>
  );
}
