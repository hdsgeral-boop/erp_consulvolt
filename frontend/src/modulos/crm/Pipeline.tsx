import { Alert, Button, Card, Checkbox, Col, Empty, Flex, Input, Row, Segmented, Select, Skeleton, Space, Statistic, Tag, Tooltip, Typography } from 'antd';
import { CalendarOutlined, DollarOutlined, PlusOutlined, WarningOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState, type DragEvent } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { TabelaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { useAccao } from '@/componentes/Accoes';
import { CHAVE_CRM, useFunis } from './comum/dados';
import { ModalMoverEtapa, TagSaude } from './comum/componentes';
import { FichaOportunidade, FormOportunidade } from './comum/Oportunidade';
import { filtrarCartoes, podeMover, type Cartao, type Etapa, type Oportunidade, type Quadro } from './comum/tipos';

/** CRM › Pipeline (crm_pipeline): quadro Kanban por etapas do funil, mover com arrastar (motivo na perda), lista e ficha. */
export default function Pipeline() {
  const { pode } = useSessao();
  const funis = useFunis();
  const [funilId, setFunilId] = useState<number | undefined>();
  const [vista, setVista] = useState<'quadro' | 'lista'>('quadro');
  const [texto, setTexto] = useState('');
  const [responsavel, setResponsavel] = useState('');
  const [soRisco, setSoRisco] = useState(false);
  const [aberta, setAberta] = useState<number | null>(null);
  const [nova, setNova] = useState(false);
  const [mover, setMover] = useState<{ oportunidade: Oportunidade; etapa: Etapa } | null>(null);
  const [estado, setEstado] = useState<string | undefined>('ABERTA');

  useEffect(() => {
    if (!funilId && funis.data?.length) setFunilId((funis.data.find((f) => f.ativo) ?? funis.data[0]).id);
  }, [funis.data, funilId]);

  const quadro = useQuery({
    queryKey: ['crm', 'quadro', funilId, responsavel],
    queryFn: () => obter<Quadro>(`/crm/funis/${funilId}/quadro`, { responsavel: responsavel || undefined }),
    enabled: !!funilId && vista === 'quadro',
  });
  const moverDirecto = useAccao({ invalidar: [CHAVE_CRM], tituloErro: 'Não foi possível mudar a etapa' });
  const editar = pode('crm_editar');

  const largar = (e: DragEvent, etapa: Etapa) => {
    e.preventDefault();
    const dados = e.dataTransfer.getData('application/json');
    if (!dados) return;
    const { id, origem } = JSON.parse(dados) as { id: number; origem: string };
    if (!podeMover(origem, etapa.id)) return;
    const cartao = quadro.data?.etapas.flatMap((x) => x.cartoes).find((c) => c.oportunidade.id === id);
    if (!cartao) return;
    // ganho/perda e etapas com tarefas automáticas pedem confirmação; as outras movem logo
    if (etapa.tipo !== 'ABERTA' || etapa.tarefas?.length) setMover({ oportunidade: cartao.oportunidade, etapa });
    else moverDirecto.mutate({ url: `/crm/oportunidades/${id}/etapa`, dados: { etapa_codigo: etapa.id } });
  };

  if (funis.isLoading) return <Skeleton active />;
  if (!funis.data?.length) return <Empty description="Não há funis de vendas. Crie um em CRM › Configuração." />;
  const r = quadro.data?.resumo;

  return (
    <>
      <CabecalhoPagina
        titulo="Pipeline de vendas"
        subtitulo="Oportunidades por etapa do funil; arraste os cartões para mudar de etapa"
        accoes={editar && <Button type="primary" icon={<PlusOutlined />} onClick={() => setNova(true)}>Nova oportunidade</Button>}
      />
      <Card size="small" style={{ marginBottom: 12 }}>
        <Flex gap={8} wrap align="center">
          <Select style={{ width: 200 }} value={funilId} onChange={setFunilId} options={funis.data.map((f) => ({ value: f.id, label: f.ativo ? f.nome : `${f.nome} (inactivo)` }))} aria-label="Funil" />
          <Segmented value={vista} onChange={(v) => setVista(v as 'quadro' | 'lista')} options={[{ value: 'quadro', label: 'Quadro' }, { value: 'lista', label: 'Lista' }]} />
          <Input.Search placeholder="Título ou conta" allowClear style={{ width: 220 }} onSearch={setTexto} onChange={(e) => !e.target.value && setTexto('')} />
          <Input.Search placeholder="Responsável" allowClear style={{ width: 160 }} onSearch={setResponsavel} />
          {vista === 'quadro' ? (
            <Checkbox checked={soRisco} onChange={(e) => setSoRisco(e.target.checked)}>Só em risco ou atenção</Checkbox>
          ) : (
            <Select allowClear placeholder="Estado" style={{ width: 140 }} value={estado} onChange={setEstado} options={[{ value: 'ABERTA', label: 'Abertas' }, { value: 'GANHA', label: 'Ganhas' }, { value: 'PERDIDA', label: 'Perdidas' }]} />
          )}
        </Flex>
      </Card>

      {vista === 'quadro' ? (
        <>
          {r && (
            <Row gutter={12} style={{ marginBottom: 12 }}>
              <Col xs={12} md={6}><Card size="small"><Statistic title="Abertas" value={r.abertas} /></Card></Col>
              <Col xs={12} md={6}><Card size="small"><Statistic title="Valor em funil (Kz)" value={formatarKz(r.valor)} /></Card></Col>
              <Col xs={12} md={6}><Card size="small"><Statistic title="Ponderado (Kz)" value={formatarKz(r.ponderado)} /></Card></Col>
              <Col xs={12} md={6}><Card size="small"><Statistic title="Em risco / atenção" value={`${r.em_risco} / ${r.atencao}`} valueStyle={{ color: r.em_risco ? '#cf1322' : undefined }} /></Card></Col>
            </Row>
          )}
          {quadro.isLoading ? (
            <Skeleton active />
          ) : quadro.error ? (
            <Alert type="error" showIcon message={(quadro.error as Error).message} />
          ) : (
            <div style={{ display: 'flex', gap: 12, overflowX: 'auto', paddingBottom: 8 }} role="list" aria-label="Etapas do funil">
              {quadro.data?.etapas.map((col) => {
                const cartoes = filtrarCartoes(col.cartoes, texto, soRisco);
                return (
                  <div
                    key={col.etapa.id}
                    role="listitem"
                    onDragOver={(e) => editar && e.preventDefault()}
                    onDrop={(e) => editar && largar(e, col.etapa)}
                    style={{ minWidth: 270, width: 270, flex: 'none', background: '#fafafa', border: '1px solid #f0f0f0', borderTop: `3px solid ${col.etapa.cor ?? '#d9d9d9'}`, borderRadius: 8, padding: 8 }}
                  >
                    <Flex justify="space-between" align="baseline" style={{ marginBottom: 8 }}>
                      <Typography.Text strong>{col.etapa.nome}</Typography.Text>
                      <Tag>{col.n}</Tag>
                    </Flex>
                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                      {formatarKz(col.valor)} Kz · pond. {formatarKz(col.ponderado)}
                      {col.etapa.tipo === 'ABERTA' && col.etapa.probabilidade !== null && col.etapa.probabilidade !== undefined && ` · ${col.etapa.probabilidade}%`}
                    </Typography.Text>
                    <Space direction="vertical" size={8} style={{ width: '100%', marginTop: 8 }}>
                      {cartoes.map((c) => (
                        <CartaoOportunidade key={c.oportunidade.id} cartao={c} arrastavel={editar} aoAbrir={() => setAberta(c.oportunidade.id)} />
                      ))}
                      {cartoes.length === 0 && <Typography.Text type="secondary" style={{ fontSize: 12 }}>Sem oportunidades.</Typography.Text>}
                    </Space>
                  </div>
                );
              })}
            </div>
          )}
        </>
      ) : (
        <Card>
          <TabelaApi<Oportunidade>
            url="/crm/oportunidades"
            chaveConsulta={['crm', 'oportunidades']}
            filtros={{ funil_vendas_crm_id: funilId, estado, responsavel: responsavel || undefined, pesquisa: texto || undefined }}
            onRow={(o) => ({ onClick: () => setAberta(o.id), style: { cursor: 'pointer' } })}
            columns={[
              { title: 'Oportunidade', dataIndex: 'titulo', render: (v: string, o) => (<><strong>{v}</strong><div style={{ fontSize: 12, color: 'rgba(0,0,0,0.55)' }}>{o.conta_crm?.nome}</div></>) },
              { title: 'Etapa', dataIndex: 'etapa_codigo', render: (e: string) => funis.data?.find((f) => f.id === funilId)?.etapas.find((x) => x.id === e)?.nome ?? e },
              { title: 'Estado', dataIndex: 'estado', render: (e: string) => <Tag color={e === 'GANHA' ? 'green' : e === 'PERDIDA' ? 'default' : 'blue'}>{e === 'GANHA' ? 'Ganha' : e === 'PERDIDA' ? 'Perdida' : 'Aberta'}</Tag> },
              { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v: string) => formatarKz(v) },
              { title: 'Fecho previsto', dataIndex: 'data_fecho_prevista', render: formatarData },
              { title: 'Responsável', dataIndex: 'responsavel' },
            ]}
          />
        </Card>
      )}

      <FichaOportunidade id={aberta} aoFechar={() => setAberta(null)} />
      <FormOportunidade oportunidade={null} aberto={nova} aoFechar={() => setNova(false)} inicial={funilId ? { funil_vendas_crm_id: funilId } : undefined} />
      <ModalMoverEtapa oportunidade={mover?.oportunidade ?? null} etapa={mover?.etapa} aoFechar={() => setMover(null)} />
    </>
  );
}

function CartaoOportunidade({ cartao, arrastavel, aoAbrir }: { cartao: Cartao; arrastavel: boolean; aoAbrir: () => void }) {
  const o = cartao.oportunidade;
  return (
    <Card
      size="small"
      hoverable
      draggable={arrastavel}
      onDragStart={(e) => {
        e.dataTransfer.setData('application/json', JSON.stringify({ id: o.id, origem: o.etapa_codigo }));
        e.dataTransfer.effectAllowed = 'move';
      }}
      onClick={aoAbrir}
      onKeyDown={(e) => e.key === 'Enter' && aoAbrir()}
      tabIndex={0}
      aria-label={`${o.titulo}, ${o.conta_crm?.nome ?? ''}`}
      styles={{ body: { padding: 10 } }}
      style={{ cursor: arrastavel ? 'grab' : 'pointer', borderLeft: cartao.saude.nivel === 'RISCO' ? '3px solid #cf1322' : cartao.saude.nivel === 'ATENCAO' ? '3px solid #fa8c16' : undefined }}
    >
      <Typography.Text strong style={{ display: 'block' }} ellipsis>{o.titulo}</Typography.Text>
      <Typography.Text type="secondary" style={{ fontSize: 12, display: 'block' }} ellipsis>{o.conta_crm?.nome}</Typography.Text>
      <Flex justify="space-between" align="center" style={{ marginTop: 6 }}>
        <Typography.Text style={{ fontSize: 12 }}><DollarOutlined /> {formatarKz(o.valor)}</Typography.Text>
        <TagSaude saude={cartao.saude} />
      </Flex>
      <Flex justify="space-between" style={{ marginTop: 4, fontSize: 12, color: 'rgba(0,0,0,0.55)' }}>
        <span>{o.responsavel ?? '—'}</span>
        {o.data_fecho_prevista && <span><CalendarOutlined /> {formatarData(o.data_fecho_prevista)}</span>}
      </Flex>
      {cartao.saude.proxima && (
        <div style={{ fontSize: 12, marginTop: 4 }}>Próxima: {cartao.saude.proxima.titulo ?? cartao.saude.proxima.tipo} ({formatarData(cartao.saude.proxima.data_prevista)})</div>
      )}
      {cartao.atraso_financeiro && (
        <Tooltip title={`${cartao.atraso_financeiro.n_atrasadas} factura(s) em atraso, até ${cartao.atraso_financeiro.max_dias_atraso} dias`}>
          <Tag color="red" icon={<WarningOutlined />} style={{ marginTop: 4 }}>{formatarKz(cartao.atraso_financeiro.em_atraso)} em atraso</Tag>
        </Tooltip>
      )}
    </Card>
  );
}
