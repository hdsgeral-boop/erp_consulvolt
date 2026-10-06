import { Alert, Button, Card, Checkbox, Col, Collapse, Drawer, Empty, Flex, Form, Input, List, Modal, Popconfirm, Row, Select, Skeleton, Space, Statistic, Switch, Tabs, Tag, Tooltip, Typography, message } from 'antd';
import { CheckOutlined, CopyOutlined, DeleteOutlined, EditOutlined, ExperimentOutlined, PlusOutlined, SafetyCertificateOutlined, WarningOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { ErroApi } from '@/api/tipos';
import { NavConfig } from './comum/NavConfig';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import {
  alternarEcra,
  alternarModulo,
  alternarTarefa,
  chaveVer,
  chavesDoModelo,
  construirMatriz,
  contar,
  estadoModulo,
  filtrarCatalogo,
  type CatalogoPermissoes,
  type Contagem,
  type RegraSegregacao,
} from './comum/permissoes';
import { larguraGaveta, useEcra } from '@/componentes/responsivo';
import { TabelaLocalImprimivel } from './comum/impressao';

interface PerfilResumo {
  id: number;
  nome: string;
  descricao: string | null;
  acesso_total: boolean;
  formato: 'TOTAL' | 'V2' | 'ANTIGO';
  contagem: Contagem | null;
  utilizadores: number;
}

interface PerfilDetalhe extends Omit<PerfilResumo, 'utilizadores'> {
  permissoes: string[];
  chaves_fora_do_catalogo: string[];
  utilizadores: { id: number; nome_utilizador: string; ativo: boolean }[];
  tem_permissoes_originais: boolean;
  avisos?: RegraSegregacao[];
}

const CHAVE = ['sistema', 'perfis'];

function useCatalogo() {
  return useQuery({ queryKey: [...CHAVE, 'catalogo'], queryFn: () => obter<CatalogoPermissoes>('/sistema/perfis/catalogo'), staleTime: 3_600_000 });
}

/** Configurações › Perfis e permissões (config_perfis): lista, editor v2 com segregação, matriz e perfis-modelo. */
export default function Perfis() {
  const { pode } = useSessao();
  const gerir = pode('config_perfis_gerir');
  return (
    <>
      <NavConfig actual="config_perfis" />
      <CabecalhoPagina titulo="Perfis e permissões" subtitulo="O que cada perfil pode consultar e fazer, com controlo da segregação de funções" />
      <Tabs
        items={[
          { key: 'perfis', label: 'Perfis', children: <ListaPerfis gerir={gerir} /> },
          { key: 'matriz', label: 'Matriz', children: <Matriz /> },
          ...(gerir ? [{ key: 'modelos', label: 'Perfis-modelo', children: <Modelos /> }] : []),
        ]}
      />
    </>
  );
}

function ResumoContagem({ c }: { c: Contagem | null }) {
  if (!c) return <Tag color="purple">Acesso total</Tag>;
  return (
    <Space size={4} wrap>
      <Tag>{c.ecras} ecrãs</Tag>
      <Tag>{c.tarefas} tarefas</Tag>
      {c.sensiveis > 0 && <Tag color="orange">{c.sensiveis} sensíveis</Tag>}
      {c.conflitos.length > 0 && (
        <Tooltip title={c.conflitos.map((x) => x.motivo).join(' · ')}>
          <Tag color="red" icon={<WarningOutlined />}>
            {c.conflitos.length} conflito(s)
          </Tag>
        </Tooltip>
      )}
    </Space>
  );
}

function ListaPerfis({ gerir }: { gerir: boolean }) {
  const cliente = useQueryClient();
  const lista = useQuery({ queryKey: [...CHAVE, 'lista'], queryFn: () => obter<PerfilResumo[]>('/sistema/perfis') });
  const [filtro, setFiltro] = useState('');
  const [edicao, setEdicao] = useState<number | 'novo' | null>(null);
  const accao = useMutation({
    mutationFn: (p: { metodo: 'post' | 'delete'; url: string }) => enviar<PerfilDetalhe>(p.metodo, p.url),
    onSuccess: ({ mensagem }) => {
      message.success(mensagem);
      void cliente.invalidateQueries({ queryKey: CHAVE });
    },
    onError: (e) => notificarErro(e),
  });
  const linhas = (lista.data ?? []).filter((p) => !filtro || p.nome.toLowerCase().includes(filtro.toLowerCase()));

  return (
    <Card>
      <TabelaLocalImprimivel<PerfilResumo>
        titulo="Perfis de permissões"
        filtros={filtro ? [`Pesquisa: ${filtro}`] : undefined}
        filtrosEcra={<Input.Search placeholder="Pesquisar perfil" allowClear style={{ width: 280 }} onChange={(e) => setFiltro(e.target.value)} />}
        accoes={gerir && (
          <Button type="primary" icon={<PlusOutlined />} onClick={() => setEdicao('novo')}>
            Novo perfil
          </Button>
        )}
        rowKey="id"
        size="middle"
        loading={lista.isLoading}
        dataSource={linhas}
        pagination={{ pageSize: 50, hideOnSinglePage: true }}
        columns={[
          { title: 'Perfil', dataIndex: 'nome', valorImpressao: (p) => [p.nome, p.descricao].filter(Boolean).join(' — '), render: (v: string, p) => (<><strong>{v}</strong>{p.descricao && <div style={{ fontSize: 12, color: 'rgba(0,0,0,0.55)' }}>{p.descricao}</div>}</>) },
          {
            title: 'Formato',
            dataIndex: 'formato',
            width: 120,
            responsive: ['md'],
            valorImpressao: (p) => (p.formato === 'TOTAL' ? 'Total' : p.formato === 'V2' ? 'Actual' : 'Antigo'),
            render: (f: PerfilResumo['formato']) =>
              f === 'TOTAL' ? <Tag color="purple">Total</Tag> : f === 'V2' ? <Tag color="blue">Actual</Tag> : <Tooltip title="Perfil no formato antigo: abra e grave para converter."><Tag color="orange">Antigo</Tag></Tooltip>,
          },
          { title: 'Permissões', dataIndex: 'contagem', render: (c: Contagem | null) => <ResumoContagem c={c} /> },
          { title: 'Utilizadores', dataIndex: 'utilizadores', width: 110, align: 'right' },
          {
            title: '',
            width: 130,
            render: (_, p) => (
              <Space>
                <Button size="small" type="text" icon={gerir ? <EditOutlined /> : <SafetyCertificateOutlined />} aria-label={gerir ? 'Editar' : 'Ver'} title={gerir ? 'Editar' : 'Ver'} onClick={() => setEdicao(p.id)} />
                {gerir && (
                  <Button size="small" type="text" icon={<CopyOutlined />} aria-label="Duplicar" title="Duplicar" loading={accao.isPending} onClick={() => accao.mutate({ metodo: 'post', url: `/sistema/perfis/${p.id}/duplicar` })} />
                )}
                {gerir && (
                  <Popconfirm
                    title={`Eliminar o perfil «${p.nome}»?`}
                    description={p.utilizadores ? `Tem ${p.utilizadores} utilizador(es): o servidor vai recusar.` : undefined}
                    okText="Eliminar"
                    cancelText="Cancelar"
                    okButtonProps={{ danger: true }}
                    onConfirm={() => accao.mutateAsync({ metodo: 'delete', url: `/sistema/perfis/${p.id}` })}
                  >
                    <Button size="small" type="text" danger icon={<DeleteOutlined />} aria-label="Eliminar" title="Eliminar" />
                  </Popconfirm>
                )}
              </Space>
            ),
          },
        ]}
      />
      <EditorPerfil id={edicao} gerir={gerir} aoFechar={() => setEdicao(null)} aoGravar={(id) => setEdicao(id)} />
    </Card>
  );
}

function EditorPerfil({ id, gerir, aoFechar, aoGravar }: { id: number | 'novo' | null; gerir: boolean; aoFechar: () => void; aoGravar: (id: number) => void }) {
  const cliente = useQueryClient();
  const catalogo = useCatalogo();
  const detalhe = useQuery({ queryKey: [...CHAVE, 'detalhe', id], queryFn: () => obter<PerfilDetalhe>(`/sistema/perfis/${id}`), enabled: typeof id === 'number' });
  const [nome, setNome] = useState('');
  const [descricao, setDescricao] = useState('');
  const [total, setTotal] = useState(false);
  const [chaves, setChaves] = useState<Set<string>>(new Set());
  const [filtro, setFiltro] = useState('');
  const [avaliacao, setAvaliacao] = useState<Contagem | null>(null);

  useEffect(() => {
    if (id === 'novo') {
      setNome('');
      setDescricao('');
      setTotal(false);
      setChaves(new Set());
    } else if (detalhe.data) {
      setNome(detalhe.data.nome);
      setDescricao(detalhe.data.descricao ?? '');
      setTotal(detalhe.data.acesso_total);
      setChaves(new Set(detalhe.data.permissoes));
    }
    setAvaliacao(null);
    setFiltro('');
  }, [id, detalhe.data]);

  const cat = catalogo.data;
  const contagem = useMemo(() => (cat ? contar(chaves, cat) : null), [chaves, cat]);
  const modulos = useMemo(() => (cat ? filtrarCatalogo(cat.modulos, filtro) : []), [cat, filtro]);

  const avaliar = useMutation({
    mutationFn: () => enviar<Contagem>('post', '/sistema/perfis/avaliar', { permissoes: [...chaves] }),
    onSuccess: ({ dados }) => setAvaliacao(dados),
    onError: (e) => notificarErro(e),
  });

  const gravar = useMutation({
    mutationFn: (confirmar: boolean) =>
      enviar<PerfilDetalhe>(id === 'novo' ? 'post' : 'put', id === 'novo' ? '/sistema/perfis' : `/sistema/perfis/${id}`, {
        nome: nome.trim(),
        descricao: descricao.trim() || null,
        acesso_total: total,
        permissoes: total ? [] : [...chaves],
        confirmar_conflitos: confirmar,
      }),
    onSuccess: ({ dados, mensagem }) => {
      message.success(mensagem);
      if (dados.avisos?.length) Modal.warning({ title: 'Gravado com conflitos de segregação', content: <ListaConflitos conflitos={dados.avisos} /> });
      void cliente.invalidateQueries({ queryKey: CHAVE });
      aoGravar(dados.id);
    },
    onError: (e) => {
      if (e instanceof ErroApi && e.codigo === 'SEGREGACAO_FUNCOES') {
        const conflitos = (e.erros?.conflitos as RegraSegregacao[] | undefined) ?? [];
        Modal.confirm({
          title: 'Segregação de funções',
          width: 560,
          content: (
            <>
              <Typography.Paragraph>{e.message}</Typography.Paragraph>
              <ListaConflitos conflitos={conflitos} />
            </>
          ),
          okText: 'Gravar mesmo assim',
          okButtonProps: { danger: true },
          cancelText: 'Rever',
          onOk: () => gravar.mutateAsync(true),
        });
      } else notificarErro(e, 'Não foi possível gravar o perfil');
    },
  });

  const aberto = id !== null;
  const aCarregar = catalogo.isLoading || (typeof id === 'number' && detalhe.isLoading);
  const so = !gerir;
  const rotuloTarefa = (k: string) => {
    for (const m of cat?.modulos ?? []) for (const e of m.ecras) { const t = e.tarefas.find((x) => x.chave === k); if (t) return `${t.rotulo} (${e.nome})`; }
    return k;
  };

  return (
    <Drawer
      open={aberto}
      onClose={aoFechar}
      width={larguraGaveta(980)}
      title={id === 'novo' ? 'Novo perfil' : `Perfil — ${detalhe.data?.nome ?? ''}`}
      destroyOnHidden
      extra={
        gerir && (
          <Button type="primary" loading={gravar.isPending} disabled={!nome.trim()} onClick={() => gravar.mutate(false)}>
            Gravar
          </Button>
        )
      }
    >
      {aCarregar || !cat ? (
        <Skeleton active />
      ) : (
        <Row gutter={16}>
          <Col xs={24} lg={16}>
            <Form layout="vertical" disabled={so}>
              <Form.Item label="Nome do perfil" required>
                <Input value={nome} onChange={(e) => setNome(e.target.value)} maxLength={100} />
              </Form.Item>
              <Form.Item label="Descrição">
                <Input.TextArea value={descricao} onChange={(e) => setDescricao(e.target.value)} rows={2} maxLength={2000} />
              </Form.Item>
              <Flex gap={16} wrap align="center" style={{ marginBottom: 12 }}>
                <Space>
                  <Switch checked={total} onChange={setTotal} />
                  <span>Acesso total (todas as permissões, actuais e futuras)</span>
                </Space>
                {!total && gerir && (
                  <Select
                    placeholder="Aplicar perfil-modelo"
                    style={{ width: 240 }}
                    value={null}
                    options={cat.modelos.map((m, i) => ({ value: i, label: m.nome }))}
                    onChange={(i: number) =>
                      Modal.confirm({
                        title: `Aplicar o modelo «${cat.modelos[i].nome}»?`,
                        content: 'As permissões marcadas são substituídas pelas do modelo.',
                        okText: 'Aplicar',
                        cancelText: 'Cancelar',
                        onOk: () => setChaves(chavesDoModelo(cat.modelos[i])),
                      })
                    }
                  />
                )}
              </Flex>
            </Form>
            {detalhe.data?.chaves_fora_do_catalogo.length ? (
              <Alert
                style={{ marginBottom: 12 }}
                type="warning"
                showIcon
                message="Permissões que já não existem no catálogo"
                description={`${detalhe.data.chaves_fora_do_catalogo.join(', ')} — ao gravar, o servidor recusa-as; desmarque-as primeiro.`}
                action={gerir && <Button size="small" onClick={() => setChaves(new Set([...chaves].filter((k) => !detalhe.data!.chaves_fora_do_catalogo.includes(k))))}>Retirar</Button>}
              />
            ) : null}
            {detalhe.data?.formato === 'ANTIGO' && <Alert style={{ marginBottom: 12 }} type="info" showIcon message="Perfil no formato antigo: ao gravar fica no formato actual (por ecrã e tarefa)." />}
            {total ? (
              <Alert type="warning" showIcon message="Este perfil tem acesso a tudo, incluindo tarefas sensíveis e ecrãs futuros. Use só para administradores." />
            ) : (
              <>
                <Input.Search placeholder="Filtrar módulo, ecrã ou tarefa" allowClear style={{ marginBottom: 8 }} onChange={(e) => setFiltro(e.target.value)} />
                <Collapse
                  size="small"
                  items={modulos.map((m) => {
                    const estado = estadoModulo(chaves, m);
                    return {
                      key: m.id,
                      label: (
                        <Flex justify="space-between" align="center" gap={8}>
                          <span onClick={(e) => e.stopPropagation()}>
                            <Checkbox
                              disabled={so}
                              checked={estado === 'total'}
                              indeterminate={estado === 'parcial'}
                              onChange={(e) => setChaves(alternarModulo(chaves, m, e.target.checked, false))}
                              aria-label={`Marcar o módulo ${m.nome}`}
                            />
                          </span>
                          <span style={{ flex: 1 }}>{m.nome}</span>
                          <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                            {m.ecras.filter((e) => chaves.has(chaveVer(e.id))).length}/{m.ecras.length} ecrãs
                          </Typography.Text>
                        </Flex>
                      ),
                      children: (
                        <List
                          size="small"
                          dataSource={m.ecras}
                          renderItem={(e) => (
                            <List.Item style={{ display: 'block', paddingLeft: e.pai ? 28 : 8 }}>
                              <Checkbox disabled={so} checked={chaves.has(chaveVer(e.id))} onChange={(x) => setChaves(alternarEcra(chaves, e, x.target.checked))}>
                                <strong>{e.nome}</strong>
                              </Checkbox>
                              {e.nota && (
                                <Typography.Text type="secondary" style={{ fontSize: 12, marginLeft: 4 }}>
                                  {e.nota}
                                </Typography.Text>
                              )}
                              {e.tarefas.length > 0 && (
                                <Flex vertical gap={2} style={{ marginLeft: 24, marginTop: 4 }}>
                                  {e.tarefas.map((t) => (
                                    <Checkbox key={t.chave} disabled={so} checked={chaves.has(t.chave)} onChange={(x) => setChaves(alternarTarefa(chaves, e, t.chave, x.target.checked))}>
                                      {t.rotulo} {t.sensivel && <Tag color="orange" style={{ marginLeft: 4 }}>sensível</Tag>}
                                    </Checkbox>
                                  ))}
                                </Flex>
                              )}
                            </List.Item>
                          )}
                        />
                      ),
                    };
                  })}
                />
                {modulos.length === 0 && <Empty description="Nada corresponde ao filtro." />}
              </>
            )}
          </Col>
          <Col xs={24} lg={8}>
            <Card size="small" title="Resumo" style={{ position: 'sticky', top: 0 }}>
              {total ? (
                <Tag color="purple">Acesso total</Tag>
              ) : (
                contagem && (
                  <>
                    <Flex gap={16} wrap>
                      <Statistic title="Ecrãs" value={contagem.ecras} />
                      <Statistic title="Tarefas" value={contagem.tarefas} />
                      <Statistic title="Sensíveis" value={contagem.sensiveis} valueStyle={{ color: contagem.sensiveis ? '#d46b08' : undefined }} />
                    </Flex>
                    {contagem.ecras === 0 && <Alert style={{ marginTop: 8 }} type="warning" showIcon message="Marque pelo menos um ecrã para consultar." />}
                    <Typography.Title level={5} style={{ marginTop: 12 }}>
                      Segregação de funções
                    </Typography.Title>
                    {contagem.conflitos.length ? <ListaConflitos conflitos={contagem.conflitos} /> : <Typography.Text type="success"><CheckOutlined /> Sem conflitos.</Typography.Text>}
                    <Button block style={{ marginTop: 12 }} icon={<ExperimentOutlined />} loading={avaliar.isPending} onClick={() => avaliar.mutate()}>
                      Avaliar no servidor
                    </Button>
                    {avaliacao && (
                      <Alert
                        style={{ marginTop: 8 }}
                        type={avaliacao.conflitos.length ? 'warning' : 'success'}
                        showIcon
                        message={`Servidor: ${avaliacao.ecras} ecrãs, ${avaliacao.tarefas} tarefas (${avaliacao.sensiveis} sensíveis), ${avaliacao.conflitos.length} conflito(s).`}
                      />
                    )}
                    {contagem.sensiveis > 0 && (
                      <>
                        <Typography.Title level={5} style={{ marginTop: 12 }}>
                          Tarefas sensíveis
                        </Typography.Title>
                        <ul style={{ paddingLeft: 18, fontSize: 12 }}>
                          {[...chaves].filter((k) => cat.modulos.some((m) => m.ecras.some((e) => e.tarefas.some((t) => t.chave === k && t.sensivel)))).map((k) => (
                            <li key={k}>{rotuloTarefa(k)}</li>
                          ))}
                        </ul>
                      </>
                    )}
                  </>
                )
              )}
              {detalhe.data && (
                <>
                  <Typography.Title level={5} style={{ marginTop: 12 }}>
                    Utilizadores com este perfil ({detalhe.data.utilizadores.length})
                  </Typography.Title>
                  <Space size={4} wrap>
                    {detalhe.data.utilizadores.map((u) => (
                      <Tag key={u.id} color={u.ativo ? undefined : 'default'} style={{ opacity: u.ativo ? 1 : 0.6 }}>
                        {u.nome_utilizador}
                      </Tag>
                    ))}
                  </Space>
                </>
              )}
            </Card>
          </Col>
        </Row>
      )}
    </Drawer>
  );
}

function ListaConflitos({ conflitos }: { conflitos: RegraSegregacao[] }) {
  return (
    <List
      size="small"
      dataSource={conflitos}
      renderItem={(c) => (
        <List.Item style={{ padding: '4px 0' }}>
          <Space direction="vertical" size={0}>
            <Typography.Text type="danger">
              <WarningOutlined /> {c.motivo}
            </Typography.Text>
            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
              {c.a} + {c.b}
            </Typography.Text>
          </Space>
        </List.Item>
      )}
    />
  );
}

function Matriz() {
  const catalogo = useCatalogo();
  const matriz = useQuery({
    queryKey: [...CHAVE, 'matriz'],
    queryFn: () => obter<{ perfis: { id: number; nome: string; acesso_total: boolean }[]; chaves: Record<string, number[]> }>('/sistema/perfis/matriz'),
  });
  const [modulo, setModulo] = useState<string | undefined>();
  const [texto, setTexto] = useState('');
  const [soAtribuidas, setSoAtribuidas] = useState(true);
  const [perfisVisiveis, setPerfisVisiveis] = useState<number[]>([]);
  const { telemovel } = useEcra();

  const linhas = useMemo(() => {
    if (!catalogo.data || !matriz.data) return [];
    const mods = filtrarCatalogo(catalogo.data.modulos.filter((m) => !modulo || m.id === modulo), texto);
    return construirMatriz({ ...catalogo.data, modulos: mods }, matriz.data, soAtribuidas);
  }, [catalogo.data, matriz.data, modulo, texto, soAtribuidas]);

  if (catalogo.isLoading || matriz.isLoading) return <Skeleton active />;
  const perfis = (matriz.data?.perfis ?? []).filter((p) => !perfisVisiveis.length || perfisVisiveis.includes(p.id));
  const fixarEsquerda = telemovel ? undefined : ('left' as const);

  return (
    <Card>
      <TabelaLocalImprimivel<(typeof linhas)[number]>
        titulo="Matriz de permissões por perfil"
        filtros={[modulo ? `Módulo: ${catalogo.data?.modulos.find((m) => m.id === modulo)?.nome ?? modulo}` : null, texto ? `Pesquisa: ${texto}` : null, soAtribuidas ? 'Só permissões atribuídas a algum perfil' : null]}
        filtrosEcra={<>
        <Select allowClear placeholder="Módulo" style={{ width: 240 }} value={modulo} onChange={setModulo} options={(catalogo.data?.modulos ?? []).map((m) => ({ value: m.id, label: m.nome }))} />
        <Input.Search placeholder="Ecrã ou tarefa" allowClear style={{ width: 240 }} onChange={(e) => setTexto(e.target.value)} />
        <Select
          mode="multiple"
          allowClear
          placeholder="Todos os perfis"
          style={{ minWidth: 240, flex: 1 }}
          value={perfisVisiveis}
          onChange={setPerfisVisiveis}
          optionFilterProp="label"
          options={(matriz.data?.perfis ?? []).map((p) => ({ value: p.id, label: p.nome }))}
        />
        <Checkbox checked={soAtribuidas} onChange={(e) => setSoAtribuidas(e.target.checked)}>
          Só permissões atribuídas a algum perfil
        </Checkbox>
        </>}
        size="small"
        bordered
        rowKey="chave"
        dataSource={linhas}
        pagination={{ pageSize: 100, showSizeChanger: false, showTotal: (n) => `${n} permissão(ões)` }}
        scroll={{ x: 'max-content', y: 560 }}
        columns={[
          { title: 'Módulo', dataIndex: 'modulo', width: 160, fixed: fixarEsquerda },
          {
            title: 'Permissão',
            dataIndex: 'rotulo',
            width: telemovel ? 200 : 320,
            fixed: fixarEsquerda,
            valorImpressao: (l) => `${l.rotulo}${l.sensivel ? ' (sensível)' : ''}`,
            render: (v: string, l) => (
              <span style={{ paddingLeft: l.tipo === 'tarefa' ? 16 : 0, fontWeight: l.tipo === 'consulta' ? 600 : 400 }}>
                {v} {l.sensivel && <Tag color="orange">sensível</Tag>}
              </span>
            ),
          },
          ...perfis.map((p) => ({
            title: <span style={{ writingMode: 'vertical-rl' as const, transform: 'rotate(180deg)', whiteSpace: 'nowrap' }}>{p.nome}</span>,
            key: `p${p.id}`,
            width: 44,
            align: 'center' as const,
            valorImpressao: (l: { perfis: Set<number> }) => (l.perfis.has(p.id) ? '✓' : ''),
            render: (_: unknown, l: { perfis: Set<number> }) => (l.perfis.has(p.id) ? <CheckOutlined style={{ color: p.acesso_total ? '#722ed1' : '#389e0d' }} aria-label="Sim" /> : null),
          })),
        ]}
      />
    </Card>
  );
}

function Modelos() {
  const cliente = useQueryClient();
  const [simulacao, setSimulacao] = useState<{ tipo: 'criar' | 'actualizar'; mensagem: string; itens: string[] } | null>(null);
  const executar = useMutation({
    mutationFn: ({ tipo, simular }: { tipo: 'criar' | 'actualizar'; simular: boolean }) =>
      enviar<{ perfis?: string[]; alteracoes?: { perfil_id: number; nome: string; acrescentar: string[] }[] }>(
        'post',
        tipo === 'criar' ? '/sistema/perfis/modelos' : '/sistema/perfis/modelos/actualizar',
        { simular },
      ),
    onSuccess: ({ dados, mensagem }, { tipo, simular }) => {
      const itens = tipo === 'criar' ? (dados.perfis ?? []) : (dados.alteracoes ?? []).map((a) => `${a.nome}: +${a.acrescentar.length} permissão(ões) (${a.acrescentar.slice(0, 6).join(', ')}${a.acrescentar.length > 6 ? '…' : ''})`);
      if (simular) setSimulacao({ tipo, mensagem, itens });
      else {
        message.success(mensagem);
        setSimulacao(null);
        void cliente.invalidateQueries({ queryKey: CHAVE });
      }
    },
    onError: (e) => notificarErro(e),
  });
  return (
    <Card>
      <Typography.Paragraph>
        Os perfis-modelo do catálogo (Contabilista, Comprador, Auditor…) servem de ponto de partida. Criar só acrescenta os que faltam; actualizar acrescenta aos
        perfis-modelo existentes as permissões novas do catálogo e nunca retira nenhuma.
      </Typography.Paragraph>
      <Space wrap>
        <Button loading={executar.isPending} onClick={() => executar.mutate({ tipo: 'criar', simular: true })}>
          Criar perfis-modelo em falta…
        </Button>
        <Button loading={executar.isPending} onClick={() => executar.mutate({ tipo: 'actualizar', simular: true })}>
          Actualizar perfis-modelo…
        </Button>
      </Space>
      {simulacao && (
        <Alert
          style={{ marginTop: 16 }}
          type="info"
          showIcon
          message={`Simulação: ${simulacao.mensagem}`}
          description={
            simulacao.itens.length ? (
              <ul style={{ paddingLeft: 18, margin: 0 }}>
                {simulacao.itens.map((i) => (
                  <li key={i}>{i}</li>
                ))}
              </ul>
            ) : (
              'Nada a fazer.'
            )
          }
          action={
            simulacao.itens.length > 0 && (
              <Space direction="vertical">
                <Button type="primary" size="small" loading={executar.isPending} onClick={() => executar.mutate({ tipo: simulacao.tipo, simular: false })}>
                  Confirmar
                </Button>
                <Button size="small" onClick={() => setSimulacao(null)}>
                  Cancelar
                </Button>
              </Space>
            )
          }
        />
      )}
    </Card>
  );
}
