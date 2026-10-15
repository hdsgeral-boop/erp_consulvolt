import { Alert, Button, Card, Col, Empty, Input, Modal, Row, Space, Spin, Statistic, Table, Tag, Timeline, Typography } from 'antd';
import type { ColumnsType } from 'antd/es/table';
import { useQuery } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarData, formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import { FormularioAssunto } from './FormularioAssunto';
import { agrupar, CORES_ESTADO, DELIB, ESTADOS, onde, resumoCusto, VISTAS } from './regras';
import type { ListaAssuntos, RegistoHistorico, ResumoAssunto, Vista } from './tipos';

export const CHAVE = ['metodo', 'assuntos'];

/**
 * Método de Trabalho — Registo de Assunto 5W1H (window.renderAssuntos, js/modules/metodo/assuntos_ui.js:52-186):
 * seis vistas (todos, por departamento, por cliente, por responsável, por prazo, por custo), caixa de alertas,
 * formulário em seis blocos e histórico. O servidor decide o que cada utilizador vê e pode fazer.
 */
export default function Assuntos() {
  const { pode } = useSessao();
  const navegar = useNavigate();
  const [vista, setVista] = useState<Vista>('lista');
  const [pesquisa, setPesquisa] = useState('');
  const [aberto, setAberto] = useState<number | 'novo' | null>(null);
  const [historico, setHistorico] = useState<number | null>(null);
  const q = useQuery({ queryKey: [...CHAVE, 'lista', pesquisa], queryFn: () => obter<ListaAssuntos>('/metodo/assuntos', { pesquisa: pesquisa || undefined }) });
  const lista = useMemo(() => q.data?.assuntos ?? [], [q.data]);
  const al = q.data?.alertas;
  const podeCriar = pode('mt_criar', 'mt_aprovar');

  const colunas: ColumnsType<ResumoAssunto> = [
    { title: 'Nº', dataIndex: 'numero', render: (v) => <strong>{v}</strong> },
    { title: 'Assunto (WHAT)', dataIndex: 'titulo' },
    { title: 'Responsável (WHO)', dataIndex: 'responsavel_principal_nome', render: (v) => v || '—' },
    { title: 'Onde (WHERE)', key: 'onde', render: (_, a) => onde(a) },
    { title: 'Prazo (WHEN)', key: 'prazo', render: (_, a) => <span style={{ whiteSpace: 'nowrap' }}>{formatarData(a.data_inicio)} → {formatarData(a.data_fim)} {a.em_atraso && <Tag color="red">em atraso</Tag>}</span> },
    { title: 'Custo (HOW)', dataIndex: 'custo_estimado', align: 'right', render: (v, a) => <span style={{ whiteSpace: 'nowrap' }}>{formatarKz(v)} {a.codigo_moeda && a.codigo_moeda !== 'AOA' ? a.codigo_moeda : ''}</span> },
    {
      title: 'Resolução', key: 'res', align: 'center',
      render: (_, a) => a.metodo_resolucao === 'PROJECTO'
        ? (a.projeto ? <Tag color="geekblue" icon={<IconeFa nome="hard-hat" />} title={a.projeto.nome}> {a.projeto.codigo}</Tag> : <Tag title="O projecto é criado quando o assunto é aprovado">projecto</Tag>)
        : <Typography.Text type="secondary">acção</Typography.Text>,
    },
    { title: 'Pedido', key: 'pc', align: 'center', render: (_, a) => (a.pedido_compra ? <Tag color="blue" title={a.pedido_compra.estado}>{a.pedido_compra.numero}</Tag> : '—') },
    {
      title: 'Estado', dataIndex: 'estado', align: 'center',
      render: (v: ResumoAssunto['estado'], a) => (
        <Space size={2} direction="vertical">
          <Tag color={CORES_ESTADO[v]}>{ESTADOS[v]}</Tag>
          {a.deliberacao_estado === 'PENDENTE' && <Tag color={a.pode_decidir ? 'orange' : 'gold'}>{a.pode_decidir ? 'Decisão sua' : DELIB.PENDENTE}</Tag>}
        </Space>
      ),
    },
    {
      title: 'Acções', key: 'x', align: 'right',
      render: (_, a) => (
        <Space size={4}>
          {a.projeto_id && <Button size="small" title="Abrir o projecto de resolução" icon={<IconeFa nome="hard-hat" />} onClick={() => navegar(`/m/projectos/projectos_carteira/${a.projeto_id}`)} />}
          <Button size="small" title="Abrir" aria-label={`Abrir ${a.numero}`} icon={<IconeFa nome="folder-open" />} onClick={() => setAberto(a.id)} />
          <Button size="small" title="Histórico" aria-label={`Histórico ${a.numero}`} icon={<IconeFa nome="history" />} onClick={() => setHistorico(a.id)} />
        </Space>
      ),
    },
  ];

  const nAlertas = al ? al.atrasados.length + al.por_vencer.length + al.bloqueados.length + al.aguardam_minha_decisao.length : 0;
  const tres = (xs: string[]) => xs.slice(0, 3).join(', ') + (xs.length > 3 ? '…' : '');

  return (
    <>
      <Card style={{ marginBottom: 16 }}
        title={<span><IconeFa nome="clipboard-list" /> Método de Trabalho <Typography.Text type="secondary" style={{ fontWeight: 400, fontSize: 13 }}>Registo de Assunto — 5W1H</Typography.Text></span>}
        extra={podeCriar && <Button type="primary" icon={<IconeFa nome="plus" />} onClick={() => setAberto('novo')}>Novo assunto</Button>}>
        <Space wrap>
          {VISTAS.map((v) => (
            <Button key={v.chave} size="small" type={vista === v.chave ? 'primary' : 'default'} icon={<IconeFa nome={v.icone} />} onClick={() => setVista(v.chave)}>{v.rotulo}</Button>
          ))}
          <Input.Search size="small" allowClear placeholder="Número ou título" onSearch={setPesquisa} style={{ width: 220 }} />
        </Space>
        {al && nAlertas > 0 && (
          <Alert style={{ marginTop: 12 }} type="warning" showIcon message="A precisar de atenção"
            description={
              <ul style={{ margin: 0, paddingLeft: 18 }}>
                {al.aguardam_minha_decisao.length > 0 && <li>{al.aguardam_minha_decisao.length} assunto(s) à espera da sua decisão: {tres(al.aguardam_minha_decisao)}</li>}
                {al.atrasados.length > 0 && <li>{al.atrasados.length} assunto(s) com o prazo ultrapassado: {tres(al.atrasados)}</li>}
                {al.por_vencer.length > 0 && <li>{al.por_vencer.length} assunto(s) a terminar nas próximas {al.horas_aviso} horas: {tres(al.por_vencer)}</li>}
                {al.bloqueados.length > 0 && <li>{al.bloqueados.length} assunto(s) à espera da aprovação do pedido de compra: {tres(al.bloqueados)}</li>}
              </ul>
            } />
        )}
      </Card>

      {q.isLoading ? <Spin /> : !lista.length ? (
        <Card>
          <Empty image={<IconeFa nome="clipboard-list" style={{ fontSize: 40, color: '#cbd5e1' }} />}
            description={<><strong>Ainda não há assuntos registados</strong><br />Cada assunto responde às seis perguntas: o quê, quem, quando, onde, porquê e como.</>}>
            {podeCriar && <Button type="primary" onClick={() => setAberto('novo')}>Registar o primeiro</Button>}
          </Empty>
        </Card>
      ) : vista === 'custo' ? <VistaCusto lista={lista} /> : (
        agrupar(lista, vista).map((g) => (
          <Card key={g.chave || 'todos'} style={{ marginBottom: 16 }} size="small"
            title={vista === 'lista' ? undefined : <span>{g.chave || '—'} <Typography.Text type="secondary" style={{ fontWeight: 400 }}>({g.itens.length} · {formatarKz(g.custo)})</Typography.Text></span>}>
            <Table<ResumoAssunto> rowKey="id" size="small" dataSource={g.itens} columns={colunas} pagination={vista === 'lista' ? { pageSize: 20, hideOnSinglePage: true } : false}
              scroll={{ x: 'max-content' }} loading={q.isFetching} onRow={(a) => ({ onDoubleClick: () => setAberto(a.id), style: a.em_atraso ? { background: '#fef2f2' } : undefined })} />
          </Card>
        ))
      )}

      <FormularioAssunto id={aberto} aoFechar={() => setAberto(null)} aoCriar={(id) => setAberto(id)} />
      <HistoricoAssunto id={historico} aoFechar={() => setHistorico(null)} />
    </>
  );
}

/** Vista «Por custo»: pequeno painel financeiro (desenharCusto, assuntos_ui.js:131-160). */
function VistaCusto({ lista }: { lista: ResumoAssunto[] }) {
  const k = resumoCusto(lista);
  const ordenada = [...lista].sort((a, b) => Number(b.custo_estimado ?? 0) - Number(a.custo_estimado ?? 0));
  return (
    <>
      <Row gutter={12} style={{ marginBottom: 16 }}>
        {[['Custo estimado total', k.total, undefined], ['Comprometido (em curso e concluído)', k.comprometido, '#1e40af'], ['Ainda em rascunho', k.rascunho, '#64748b'], ['Cancelado', k.cancelado, '#b91c1c']]
          .map(([t, v, c]) => (
            <Col key={String(t)} xs={12} md={6}><Card size="small"><Statistic title={String(t)} value={formatarKz(Number(v))} valueStyle={{ color: c as string | undefined, fontSize: 20 }} /></Card></Col>
          ))}
      </Row>
      <Card size="small">
        <Table<ResumoAssunto> rowKey="id" size="small" dataSource={ordenada} pagination={false} scroll={{ x: 'max-content' }}
          columns={[
            { title: 'Nº', dataIndex: 'numero', render: (v) => <strong>{v}</strong> },
            { title: 'Assunto', dataIndex: 'titulo' },
            { title: 'Onde', key: 'o', render: (_, a) => onde(a) },
            { title: 'Estado', dataIndex: 'estado', render: (v: ResumoAssunto['estado']) => <Tag color={CORES_ESTADO[v]}>{ESTADOS[v]}</Tag> },
            { title: 'Custo estimado', dataIndex: 'custo_estimado', align: 'right', render: (v) => <strong>{formatarKz(v)}</strong> },
            { title: 'Pedido de compra', key: 'pc', render: (_, a) => a.pedido_compra?.numero ?? '—' },
          ]} />
      </Card>
    </>
  );
}

/** Histórico do assunto (window.assuntoHistorico, assuntos_ui.js:533-546). */
function HistoricoAssunto({ id, aoFechar }: { id: number | null; aoFechar: () => void }) {
  const q = useQuery({ queryKey: [...CHAVE, 'historico', id], enabled: id !== null,
    queryFn: () => obter<{ numero: string; registos: RegistoHistorico[] }>(`/metodo/assuntos/${id}/historico`) });
  return (
    <Modal open={id !== null} onCancel={aoFechar} footer={<Button type="primary" onClick={aoFechar}>Fechar</Button>} width={640} destroyOnHidden
      title={<span><IconeFa nome="history" /> Histórico — {q.data?.numero ?? ''}</span>}>
      {q.isLoading ? <Spin /> : q.data?.registos.length ? (
        <div style={{ maxHeight: '55vh', overflow: 'auto', paddingTop: 8 }}>
          <Timeline items={q.data.registos.map((x) => ({
            key: x.id,
            children: <><div><strong>{x.acao}</strong>{x.detalhe ? ` — ${x.detalhe}` : ''}</div><Typography.Text type="secondary" style={{ fontSize: 12 }}>{x.por || '—'} · {formatarDataHora(x.em)}</Typography.Text></>,
          }))} />
        </div>
      ) : <Empty description="Sem registos." />}
    </Modal>
  );
}
