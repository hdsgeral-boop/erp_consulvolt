import { Alert, Badge, Button, Card, Col, Collapse, Descriptions, Drawer, Empty, Flex, Input, List, Progress, Row, Select, Skeleton, Space, Steps, Tabs, Tag, Typography } from 'antd';
import { RightOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { TabelaApi } from '@/componentes/TabelaApi';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { GraficoBarras } from '@/componentes/graficos/Graficos';
import { CartaoKpi, formatarPorFormato, useRotaDaVista } from './comum/componentes';

interface Etapa {
  id: string;
  nome: string;
}

interface FluxoResumoLista {
  id: string;
  nome: string;
  sub: string;
  tem_actividade: boolean;
  etapas: Etapa[];
}

interface NarrativaEtapa {
  nome: string;
  quem: string;
  descricao: string;
  controlos: string;
  resultado: string;
}

interface ResumoFluxo {
  fluxo: FluxoResumoLista & { narrativa?: { titulo: string; objectivo: string; intervenientes: string[]; etapas: NarrativaEtapa[] } | null };
  tem_actividade: boolean;
  total: number;
  kpis: { chave: string; rotulo: string; valor: unknown; formato: string; alerta: boolean }[];
  funil: {
    etapa: string;
    nome: string;
    parados: number;
    bloqueados: number;
    com_pendencias: number;
    valor: string | null;
    valor_pendente: string | null;
    estados: Record<'concluida' | 'curso' | 'fazer' | 'bloqueada', number> | null;
  }[];
}

interface ProcessoLista {
  chave: string;
  titulo: string;
  subtitulo: string | null;
  data: string | null;
  valor: string | null;
  valor_pendente: string | null;
  total_etapas: number;
  concluidas: number;
  etapa_actual: string | null;
  etapa_actual_nome: string | null;
  etapa_actual_estado: string | null;
  etapa_actual_resumo: string | null;
  bloqueado: boolean;
  n_erros: number;
  n_avisos: number;
  progresso: Record<string, string>;
}

interface EtapaProcesso {
  estado: string;
  resumo: string | null;
  factos: { rotulo: string; valor: unknown; formato: string }[];
  problemas: { nivel: 'erro' | 'aviso' | string; texto: string }[];
  accoes: { rotulo: string; acao: string; primaria: boolean }[];
  narrativa: NarrativaEtapa | null;
}

interface DetalheProcesso extends Omit<ProcessoLista, 'progresso' | 'etapa_actual_nome' | 'etapa_actual_estado' | 'etapa_actual_resumo'> {
  etapas: Record<string, EtapaProcesso>;
  documentos: { tipo: string; id: number; tipo_documento?: string; numero?: string }[];
  fluxo: { id: string; nome: string };
}

const ESTADOS: Record<string, { rotulo: string; cor: string; passo: 'finish' | 'process' | 'wait' | 'error' }> = {
  concluida: { rotulo: 'Concluída', cor: 'green', passo: 'finish' },
  curso: { rotulo: 'Em curso', cor: 'blue', passo: 'process' },
  fazer: { rotulo: 'Por fazer', cor: 'default', passo: 'wait' },
  bloqueada: { rotulo: 'Bloqueada', cor: 'red', passo: 'error' },
  na: { rotulo: 'Não aplicável', cor: 'default', passo: 'wait' },
};

/** Geral › Fluxo de Processos (fluxo_processos): 14 fluxos com funil, lista de processos e detalhe com narrativa (GET /gestao/fluxos/*). */
export default function FluxoProcessos() {
  const lista = useQuery({ queryKey: ['gestao', 'fluxos'], queryFn: () => obter<FluxoResumoLista[]>('/gestao/fluxos'), staleTime: 300_000 });
  if (lista.isLoading) return <Skeleton active />;
  const fluxos = lista.data ?? [];
  return (
    <>
      <CabecalhoPagina titulo="Fluxo de processos" subtitulo="Onde está cada processo, o que falta fazer e quem o faz" />
      {fluxos.length === 0 ? (
        <Empty description="Não tem acesso a nenhum fluxo (cada fluxo exige também a consulta do módulo de origem)." />
      ) : (
        <Tabs destroyInactiveTabPane items={fluxos.map((f) => ({ key: f.id, label: f.tem_actividade ? f.nome : <Typography.Text type="secondary">{f.nome}</Typography.Text>, children: <Fluxo fluxo={f} /> }))} />
      )}
    </>
  );
}

function Fluxo({ fluxo }: { fluxo: FluxoResumoLista }) {
  const resumo = useQuery({ queryKey: ['gestao', 'fluxos', fluxo.id], queryFn: () => obter<ResumoFluxo>(`/gestao/fluxos/${fluxo.id}`) });
  const [etapa, setEtapa] = useState<string | undefined>();
  const [estado, setEstado] = useState<string | undefined>();
  const [pesquisa, setPesquisa] = useState('');
  const [aberto, setAberto] = useState<string | null>(null);

  if (resumo.isLoading) return <Skeleton active />;
  if (resumo.error || !resumo.data) return <Alert type="error" showIcon message={(resumo.error as Error | null)?.message ?? 'Erro ao carregar o fluxo.'} />;
  const r = resumo.data;
  const nomeEtapa = (id: string | null) => fluxo.etapas.find((e) => e.id === id)?.nome ?? r.funil.find((f) => f.etapa === id)?.nome ?? id ?? '—';
  const comEstados = r.funil.filter((f) => f.estados);

  return (
    <Space direction="vertical" size={16} style={{ width: '100%' }}>
      <Typography.Paragraph type="secondary" style={{ margin: 0 }}>
        {fluxo.sub}
      </Typography.Paragraph>
      {!r.tem_actividade && <Alert type="info" showIcon message="Ainda não há processos neste fluxo na empresa activa." />}
      <Row gutter={[12, 12]}>
        {r.kpis.map((k) => (
          <Col key={k.chave} xs={24} sm={12} md={8} xl={Math.max(4, Math.floor(24 / Math.max(1, r.kpis.length)))}>
            <CartaoKpi rotulo={k.rotulo} valor={k.valor} formato={k.formato} alerta={k.alerta} />
          </Col>
        ))}
      </Row>

      <Card size="small" title="Funil do processo" extra={etapa && <Button size="small" type="link" onClick={() => setEtapa(undefined)}>Limpar etapa</Button>}>
        <Flex gap={8} wrap>
          {r.funil.map((f, i) => (
            <Card
              key={f.etapa}
              size="small"
              hoverable
              onClick={() => setEtapa(etapa === f.etapa ? undefined : f.etapa)}
              style={{ flex: '1 1 150px', borderColor: etapa === f.etapa ? '#1677ff' : undefined, background: etapa === f.etapa ? '#e6f4ff' : undefined }}
              styles={{ body: { padding: 10 } }}
              aria-pressed={etapa === f.etapa}
            >
              <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                {i + 1}. {f.nome}
              </Typography.Text>
              <div style={{ fontSize: 20, fontWeight: 600 }}>{f.parados}</div>
              <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                {f.etapa === 'concluidos' ? 'concluídos' : 'parados aqui'}
              </Typography.Text>
              {f.valor && Number(f.valor) !== 0 && <div style={{ fontSize: 12 }}>{formatarKz(f.valor)} Kz</div>}
              <Space size={4} wrap style={{ marginTop: 4 }}>
                {f.bloqueados > 0 && <Tag color="red">{f.bloqueados} bloq.</Tag>}
                {f.com_pendencias > 0 && <Tag color="orange">{f.com_pendencias} pend.</Tag>}
              </Space>
            </Card>
          ))}
        </Flex>
        {comEstados.length > 0 && (
          <div style={{ marginTop: 16 }}>
            <GraficoBarras
              titulo="Estado de cada etapa nos processos"
              horizontal
              empilhado
              rotulos={comEstados.map((f) => f.nome)}
              series={(['concluida', 'curso', 'fazer', 'bloqueada'] as const).map((e) => ({ rotulo: ESTADOS[e].rotulo, valores: comEstados.map((f) => f.estados?.[e] ?? 0) }))}
            />
          </div>
        )}
      </Card>

      {r.fluxo.narrativa && (
        <Collapse
          items={[
            {
              key: 'n',
              label: `${r.fluxo.narrativa.titulo} — como funciona`,
              children: (
                <>
                  <Typography.Paragraph>{r.fluxo.narrativa.objectivo}</Typography.Paragraph>
                  <Typography.Paragraph>
                    <strong>Intervenientes:</strong> {r.fluxo.narrativa.intervenientes.join(', ')}
                  </Typography.Paragraph>
                  <Steps
                    direction="vertical"
                    size="small"
                    current={-1}
                    items={r.fluxo.narrativa.etapas.map((e) => ({
                      title: `${e.nome} · ${e.quem}`,
                      description: (
                        <>
                          <div>{e.descricao}</div>
                          <Typography.Text type="secondary">Controlos: {e.controlos} · Resultado: {e.resultado}</Typography.Text>
                        </>
                      ),
                    }))}
                  />
                </>
              ),
            },
          ]}
        />
      )}

      <Card size="small" title={`Processos${etapa ? ` — ${nomeEtapa(etapa)}` : ''}`}>
        <Flex gap={8} wrap style={{ marginBottom: 12 }}>
          <Input.Search placeholder="Pesquisar n.º, entidade…" allowClear style={{ width: 280 }} onSearch={setPesquisa} />
          <Select
            allowClear
            placeholder="Estado"
            style={{ width: 200 }}
            value={estado}
            onChange={setEstado}
            options={[
              { value: 'em_curso', label: 'Em curso' },
              { value: 'bloqueado', label: 'Bloqueados' },
              { value: 'com_pendencias', label: 'Com pendências' },
              { value: 'concluido', label: 'Concluídos' },
            ]}
          />
          <Select allowClear placeholder="Etapa" style={{ width: 220 }} value={etapa} onChange={setEtapa} options={fluxo.etapas.map((e) => ({ value: e.id, label: e.nome }))} />
        </Flex>
        <TabelaApi<ProcessoLista>
          url={`/gestao/fluxos/${fluxo.id}/processos`}
          chaveConsulta={['gestao', 'fluxos', fluxo.id, 'processos']}
          filtros={{ etapa, estado, pesquisa }}
          rowKey="chave"
          size="small"
          onRow={(p) => ({ onClick: () => setAberto(p.chave), style: { cursor: 'pointer' } })}
          columns={[
            { title: 'Processo', dataIndex: 'titulo', render: (v: string, p) => (<><strong>{v}</strong>{p.subtitulo && <div style={{ fontSize: 12, color: 'rgba(0,0,0,0.55)' }}>{p.subtitulo}</div>}</>) },
            { title: 'Data', dataIndex: 'data', render: formatarData, width: 110 },
            { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v: string | null) => formatarKz(v) },
            { title: 'Pendente', dataIndex: 'valor_pendente', align: 'right', render: (v: string | null) => (v && Number(v) ? <Typography.Text type="warning">{formatarKz(v)}</Typography.Text> : '—') },
            {
              title: 'Etapa actual',
              dataIndex: 'etapa_actual_nome',
              render: (v: string | null, p) => (
                <Space direction="vertical" size={0}>
                  <span>
                    {v ?? '—'} {p.etapa_actual_estado && <Tag color={ESTADOS[p.etapa_actual_estado]?.cor}>{ESTADOS[p.etapa_actual_estado]?.rotulo ?? p.etapa_actual_estado}</Tag>}
                  </span>
                  {p.etapa_actual_resumo && <Typography.Text type="secondary" style={{ fontSize: 12 }}>{p.etapa_actual_resumo}</Typography.Text>}
                </Space>
              ),
            },
            { title: 'Progresso', key: 'p', width: 140, render: (_, p) => <Progress percent={Math.round((p.concluidas / Math.max(1, p.total_etapas)) * 100)} size="small" status={p.bloqueado ? 'exception' : undefined} format={() => `${p.concluidas}/${p.total_etapas}`} /> },
            {
              title: 'Alertas',
              key: 'a',
              render: (_, p) => (
                <Space size={4}>
                  {p.n_erros > 0 && <Badge count={p.n_erros} color="red" title="Erros" />}
                  {p.n_avisos > 0 && <Badge count={p.n_avisos} color="orange" title="Avisos" />}
                </Space>
              ),
            },
          ]}
        />
      </Card>
      <DetalheDoProcesso fluxo={fluxo} chave={aberto} aoFechar={() => setAberto(null)} />
    </Space>
  );
}

function DetalheDoProcesso({ fluxo, chave, aoFechar }: { fluxo: FluxoResumoLista; chave: string | null; aoFechar: () => void }) {
  const navegar = useNavigate();
  const rotaDaVista = useRotaDaVista();
  const q = useQuery({
    queryKey: ['gestao', 'fluxos', fluxo.id, 'processo', chave],
    queryFn: () => obter<DetalheProcesso>(`/gestao/fluxos/${fluxo.id}/processos/${encodeURIComponent(chave!)}`),
    enabled: !!chave,
  });
  const d = q.data;
  return (
    <Drawer open={!!chave} onClose={aoFechar} width={760} title={d ? `${d.titulo} — ${fluxo.nome}` : 'Processo'} destroyOnClose>
      {q.isLoading || !d ? (
        <Skeleton active />
      ) : (
        <Space direction="vertical" size={16} style={{ width: '100%' }}>
          <Descriptions size="small" column={2} bordered>
            <Descriptions.Item label="Descrição" span={2}>{d.subtitulo ?? '—'}</Descriptions.Item>
            <Descriptions.Item label="Data">{formatarData(d.data)}</Descriptions.Item>
            <Descriptions.Item label="Progresso">{d.concluidas}/{d.total_etapas} etapas{d.bloqueado && <Tag color="red" style={{ marginLeft: 8 }}>Bloqueado</Tag>}</Descriptions.Item>
            <Descriptions.Item label="Valor">{formatarKz(d.valor)} Kz</Descriptions.Item>
            <Descriptions.Item label="Pendente">{formatarKz(d.valor_pendente)} Kz</Descriptions.Item>
            {d.documentos.length > 0 && (
              <Descriptions.Item label="Documentos" span={2}>
                <Space wrap>{d.documentos.map((x) => <Tag key={`${x.tipo}-${x.id}`}>{x.numero ?? `${x.tipo} ${x.id}`}</Tag>)}</Space>
              </Descriptions.Item>
            )}
          </Descriptions>
          <Steps
            direction="vertical"
            size="small"
            items={fluxo.etapas.map((e) => {
              const et = d.etapas[e.id];
              const est = ESTADOS[et?.estado ?? 'fazer'] ?? ESTADOS.fazer;
              return {
                status: est.passo,
                title: (
                  <Space>
                    {e.nome}
                    <Tag color={est.cor}>{est.rotulo}</Tag>
                    {et?.narrativa?.quem && <Typography.Text type="secondary" style={{ fontSize: 12 }}>{et.narrativa.quem}</Typography.Text>}
                  </Space>
                ),
                description: et && (
                  <Space direction="vertical" size={6} style={{ width: '100%', marginBottom: 8 }}>
                    {et.resumo && <Typography.Text strong>{et.resumo}</Typography.Text>}
                    {et.factos.length > 0 && (
                      <List
                        size="small"
                        dataSource={et.factos}
                        renderItem={(f) => (
                          <List.Item style={{ padding: '2px 0' }}>
                            <Typography.Text type="secondary">{f.rotulo}</Typography.Text>
                            <Typography.Text>{formatarPorFormato(f.valor, f.formato)}</Typography.Text>
                          </List.Item>
                        )}
                      />
                    )}
                    {et.problemas.map((p, i) => (
                      <Alert key={i} type={p.nivel === 'erro' ? 'error' : 'warning'} showIcon message={p.texto} />
                    ))}
                    {et.accoes.length > 0 && (
                      <Space wrap>
                        {et.accoes.map((a) => {
                          const rota = rotaDaVista(a.acao);
                          return (
                            <Button key={a.acao + a.rotulo} size="small" type={a.primaria ? 'primary' : 'default'} icon={<RightOutlined />} disabled={!rota} title={rota ? undefined : 'Sem acesso a este ecrã'} onClick={() => rota && navegar(rota)}>
                              {a.rotulo}
                            </Button>
                          );
                        })}
                      </Space>
                    )}
                    {et.narrativa && (
                      <Typography.Paragraph type="secondary" style={{ fontSize: 12, margin: 0 }}>
                        {et.narrativa.descricao} <em>Controlos: {et.narrativa.controlos}.</em>
                      </Typography.Paragraph>
                    )}
                  </Space>
                ),
              };
            })}
          />
        </Space>
      )}
    </Drawer>
  );
}
