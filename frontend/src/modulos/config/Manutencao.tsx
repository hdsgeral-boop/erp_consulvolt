import { Alert, Button, Card, Checkbox, DatePicker, Descriptions, Drawer, Empty, Flex, Form, Input, List, Modal, Segmented, Select, Skeleton, Space, Table, Tabs, Tag, Timeline, Typography } from 'antd';
import { ExclamationCircleOutlined } from '@ant-design/icons';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarDataHora, formatarNumero } from '@/utilitarios/formatacao';
import { useAccao } from '@/modulos/compras/comum/accoes';
import { accoesPedido, textoConfirmacao, type PedidoResumo } from './comum/regras';

interface ParametroAccao {
  id: string;
  rotulo: string;
  tipo: 'date' | 'select' | 'empresa' | string;
  obrigatorio: boolean;
  opcoes?: [string, string][];
}

interface AccaoManutencao {
  codigo: string;
  legado: string;
  grupo: string;
  ambito: 'empresa' | 'sistema';
  gravidade: 'alta' | 'media' | string;
  titulo: string;
  descricao: string;
  parametros: ParametroAccao[];
}

interface Catalogo {
  acoes: AccaoManutencao[];
  nao_portadas: { codigo_legado: string; titulo: string; tratamento: string; substituto: string }[];
}

interface Impacto {
  linhas: Record<string, number>;
  bloqueio: string | null;
}

interface Pedido extends PedidoResumo {
  id: number;
  acao: string;
  rotulo_acao: string;
  nome_empresa_ambito: string | null;
  resumo_parametros: string | null;
  justificacao: string;
  impacto_no_pedido: Impacto | null;
  pedido_em: string;
  aprovado_em: string | null;
  nota_aprovacao: string | null;
  expira_em: string | null;
  rejeitado_por: { nome_utilizador: string } | null;
  motivo_rejeicao: string | null;
  executado_por: { nome_utilizador: string } | null;
  executado_em: string | null;
  resultado: string | null;
  impacto_actual?: Impacto;
  historico: { em: string; por: string; texto: string }[];
}

const CORES: Record<string, string> = { PENDENTE: 'orange', APROVADO: 'blue', EXECUTADO: 'green', REJEITADO: 'red', CANCELADO: 'default', EXPIRADO: 'default' };
const ROTULOS: Record<string, string> = { PENDENTE: 'A aguardar aprovação', APROVADO: 'Aprovado', EXECUTADO: 'Executado', REJEITADO: 'Rejeitado', CANCELADO: 'Cancelado', EXPIRADO: 'Expirado' };
const CHAVE = ['sistema', 'manutencao'];

/** Configurações › Manutenção de dados (config_manutencao): acções correctivas com impacto, pedido, aprovação dupla e execução. */
export default function Manutencao() {
  const catalogo = useQuery({ queryKey: [...CHAVE, 'acoes'], queryFn: () => obter<Catalogo>('/sistema/manutencao/acoes'), staleTime: 3_600_000 });
  const [separador, setSeparador] = useState('pedidos');
  return (
    <>
      <CabecalhoPagina titulo="Manutenção de dados" subtitulo="Correcções em massa com pedido, aprovação por outro administrador (com palavra-passe) e execução confirmada" />
      <Alert
        type="warning"
        showIcon
        style={{ marginBottom: 16 }}
        message="Operações com efeito em muitos registos"
        description="Exporte uma cópia de segurança antes de executar. Cada passo fica registado na auditoria. O pedido expira em 7 dias e a aprovação em 24 horas."
      />
      {catalogo.isLoading ? (
        <Skeleton active />
      ) : (
        <Tabs
          activeKey={separador}
          onChange={setSeparador}
          items={[
            { key: 'pedidos', label: 'Pedidos', children: <Pedidos /> },
            { key: 'novo', label: 'Novo pedido', children: <NovoPedido acoes={catalogo.data?.acoes ?? []} aoCriar={() => setSeparador('pedidos')} /> },
            {
              key: 'legado',
              label: 'Acções do sistema antigo',
              children: (
                <List
                  dataSource={catalogo.data?.nao_portadas ?? []}
                  renderItem={(a) => (
                    <List.Item>
                      <List.Item.Meta
                        title={
                          <Space>
                            {a.titulo}
                            <Tag color={a.tratamento === 'NAO_PORTADA' ? 'red' : 'blue'}>{a.tratamento === 'NAO_PORTADA' ? 'Não existe' : 'Substituída'}</Tag>
                          </Space>
                        }
                        description={a.substituto}
                      />
                    </List.Item>
                  )}
                />
              ),
            },
          ]}
        />
      )}
    </>
  );
}

function TabelaImpacto({ impacto }: { impacto: Impacto | null | undefined }) {
  if (!impacto) return null;
  const linhas = Object.entries(impacto.linhas).map(([chave, n]) => ({ chave, n }));
  return (
    <>
      {impacto.bloqueio && <Alert type="error" showIcon style={{ marginBottom: 8 }} message={`Bloqueado: ${impacto.bloqueio}`} />}
      <Table size="small" rowKey="chave" pagination={false} dataSource={linhas} locale={{ emptyText: 'Sem registos afectados.' }} columns={[{ title: 'Registos', dataIndex: 'chave', render: (v: string) => v.replace(/_/g, ' ') }, { title: 'Quantidade', dataIndex: 'n', align: 'right', render: formatarNumero }]} />
    </>
  );
}

function NovoPedido({ acoes, aoCriar }: { acoes: AccaoManutencao[]; aoCriar: () => void }) {
  const { empresas } = useSessao();
  const [codigo, setCodigo] = useState<string | undefined>();
  const [form] = Form.useForm();
  const [impacto, setImpacto] = useState<Impacto | null>(null);
  const accao = acoes.find((a) => a.codigo === codigo);
  const parametros = (v: Record<string, unknown>) =>
    Object.fromEntries((accao?.parametros ?? []).map((p) => [p.id, p.tipo === 'date' ? dataApi(v[p.id] as never) : v[p.id]]));
  const calcular = useMutation({
    mutationFn: async () => enviar<Impacto>('post', '/sistema/manutencao/impacto', { acao: codigo, parametros: parametros(await form.validateFields(accao?.parametros.map((p) => p.id))) }),
    onSuccess: ({ dados }) => setImpacto(dados),
    onError: (e) => notificarErro(e, 'Não foi possível calcular o impacto'),
  });
  const pedir = useAccao({ invalidar: [CHAVE], aoSucesso: () => { form.resetFields(); setCodigo(undefined); setImpacto(null); aoCriar(); } });

  useEffect(() => {
    form.resetFields();
    setImpacto(null);
  }, [codigo, form]);

  return (
    <Card>
      <Form form={form} layout="vertical" onFinish={(v) => pedir.mutate({ url: '/sistema/manutencao/pedidos', dados: { acao: codigo, parametros: parametros(v), justificacao: v.justificacao, ciente: !!v.ciente } })} onValuesChange={(m) => !('justificacao' in m) && !('ciente' in m) && setImpacto(null)}>
        <Form.Item label="Acção" required>
          <Select
            value={codigo}
            onChange={setCodigo}
            placeholder="Escolha a acção"
            options={Object.entries(acoes.reduce<Record<string, AccaoManutencao[]>>((g, a) => ({ ...g, [a.grupo]: [...(g[a.grupo] ?? []), a] }), {})).map(([grupo, lista]) => ({
              label: grupo,
              options: lista.map((a) => ({ value: a.codigo, label: a.titulo })),
            }))}
          />
        </Form.Item>
        {accao && (
          <>
            <Alert
              type={accao.gravidade === 'alta' ? 'error' : 'warning'}
              showIcon
              style={{ marginBottom: 16 }}
              message={<Space>{accao.titulo}<Tag>{accao.ambito === 'sistema' ? 'Todo o sistema' : 'Empresa activa'}</Tag><Tag color={accao.gravidade === 'alta' ? 'red' : 'orange'}>Gravidade {accao.gravidade}</Tag></Space>}
              description={accao.descricao}
            />
            {accao.parametros.map((p) => (
              <Form.Item key={p.id} name={p.id} label={p.rotulo} rules={[{ required: p.obrigatorio, message: 'Obrigatório.' }]}>
                {p.tipo === 'date' ? (
                  <DatePicker format="DD/MM/YYYY" />
                ) : p.tipo === 'empresa' ? (
                  <Select showSearch optionFilterProp="label" options={empresas.map((e) => ({ value: e.id, label: e.nome }))} style={{ maxWidth: 420 }} />
                ) : (
                  <Select options={(p.opcoes ?? []).map(([value, label]) => ({ value, label }))} style={{ maxWidth: 420 }} />
                )}
              </Form.Item>
            ))}
            <Button onClick={() => calcular.mutate()} loading={calcular.isPending} style={{ marginBottom: 12 }}>
              Calcular impacto
            </Button>
            <TabelaImpacto impacto={impacto} />
            <Form.Item name="justificacao" label="Justificação" style={{ marginTop: 16 }} rules={[{ required: true, message: 'Indique a justificação.' }, { min: 20, message: 'Pelo menos 20 caracteres.' }]}>
              <Input.TextArea rows={3} maxLength={2000} showCount />
            </Form.Item>
            <Form.Item name="ciente" valuePropName="checked" rules={[{ validator: (_, v) => (v ? Promise.resolve() : Promise.reject(new Error('Confirme que compreende o efeito.'))) }]}>
              <Checkbox>Compreendo o efeito desta acção e que tem de ser aprovada por outro administrador.</Checkbox>
            </Form.Item>
            <Button type="primary" htmlType="submit" disabled={!impacto || !!impacto.bloqueio} loading={pedir.isPending}>
              Registar pedido
            </Button>
            {!impacto && <Typography.Text type="secondary" style={{ marginLeft: 12 }}>Calcule o impacto antes de registar o pedido.</Typography.Text>}
          </>
        )}
      </Form>
    </Card>
  );
}

function Pedidos() {
  const [estado, setEstado] = useState('ACTIVOS');
  const [aberto, setAberto] = useState<number | null>(null);
  const lista = useQuery({ queryKey: [...CHAVE, 'pedidos', estado], queryFn: () => obter<Pedido[]>('/sistema/manutencao/pedidos', { estado }) });
  return (
    <Card>
      <Segmented
        style={{ marginBottom: 12 }}
        value={estado}
        onChange={(v) => setEstado(String(v))}
        options={[{ value: 'ACTIVOS', label: 'Activos' }, { value: 'PENDENTE', label: 'Pendentes' }, { value: 'APROVADO', label: 'Aprovados' }, { value: 'EXECUTADO', label: 'Executados' }, { value: 'TODOS', label: 'Todos' }]}
      />
      <Table<Pedido>
        rowKey="id"
        size="middle"
        loading={lista.isLoading}
        dataSource={lista.data}
        onRow={(p) => ({ onClick: () => setAberto(p.id), style: { cursor: 'pointer' } })}
        locale={{ emptyText: <Empty description="Sem pedidos." /> }}
        columns={[
          { title: 'N.º', dataIndex: 'id', width: 70 },
          { title: 'Acção', dataIndex: 'rotulo_acao', render: (v: string, p) => (<>{v}{p.resumo_parametros && <div style={{ fontSize: 12, color: 'rgba(0,0,0,0.55)' }}>{p.resumo_parametros}</div>}</>) },
          { title: 'Âmbito', key: 'ambito', render: (_, p) => (p.ambito === 'sistema' ? <Tag>Sistema</Tag> : p.nome_empresa_ambito) },
          { title: 'Pedido por', key: 'por', render: (_, p) => <>{p.pedido_por?.nome_utilizador}<div style={{ fontSize: 12, color: 'rgba(0,0,0,0.55)' }}>{formatarDataHora(p.pedido_em)}</div></> },
          { title: 'Aprovado por', key: 'apr', render: (_, p) => p.aprovado_por?.nome_utilizador ?? '—' },
          { title: 'Estado', dataIndex: 'estado', render: (e: string) => <Tag color={CORES[e]}>{ROTULOS[e] ?? e}</Tag> },
          { title: 'Expira', dataIndex: 'expira_em', render: formatarDataHora },
        ]}
      />
      <DetalhePedido id={aberto} aoFechar={() => setAberto(null)} />
    </Card>
  );
}

function DetalhePedido({ id, aoFechar }: { id: number | null; aoFechar: () => void }) {
  const { utilizador } = useSessao();
  const q = useQuery({ queryKey: [...CHAVE, 'pedido', id], queryFn: () => obter<Pedido>(`/sistema/manutencao/pedidos/${id}`), enabled: id !== null });
  const [decisao, setDecisao] = useState<'aprovar' | 'rejeitar' | 'executar' | 'cancelar' | null>(null);
  const p = q.data;
  const acc = p && utilizador ? accoesPedido(p, { id: utilizador.id, superAdmin: utilizador.papel === 'SUPER_ADMINISTRADOR' }) : null;
  return (
    <Drawer open={id !== null} onClose={aoFechar} width={720} title={p ? `Pedido #${p.id} — ${p.rotulo_acao}` : 'Pedido'} destroyOnClose>
      {!p ? (
        <Skeleton active />
      ) : (
        <Space direction="vertical" size={16} style={{ width: '100%' }}>
          <Descriptions size="small" column={1} bordered>
            <Descriptions.Item label="Estado"><Tag color={CORES[p.estado]}>{ROTULOS[p.estado] ?? p.estado}</Tag>{p.expira_em && ['PENDENTE', 'APROVADO'].includes(p.estado) && <Typography.Text type="secondary"> expira {formatarDataHora(p.expira_em)}</Typography.Text>}</Descriptions.Item>
            <Descriptions.Item label="Âmbito">{p.ambito === 'sistema' ? 'Todo o sistema' : p.nome_empresa_ambito}</Descriptions.Item>
            {p.resumo_parametros && <Descriptions.Item label="Parâmetros">{p.resumo_parametros}</Descriptions.Item>}
            <Descriptions.Item label="Justificação">{p.justificacao}</Descriptions.Item>
            <Descriptions.Item label="Pedido">{p.pedido_por?.nome_utilizador} · {formatarDataHora(p.pedido_em)}</Descriptions.Item>
            {p.aprovado_por && <Descriptions.Item label="Aprovado">{p.aprovado_por.nome_utilizador} · {formatarDataHora(p.aprovado_em)}{p.nota_aprovacao ? ` — ${p.nota_aprovacao}` : ''}</Descriptions.Item>}
            {p.rejeitado_por && <Descriptions.Item label="Rejeitado">{p.rejeitado_por.nome_utilizador} — {p.motivo_rejeicao}</Descriptions.Item>}
            {p.executado_por && <Descriptions.Item label="Executado">{p.executado_por.nome_utilizador} · {formatarDataHora(p.executado_em)}</Descriptions.Item>}
            {p.resultado && <Descriptions.Item label="Resultado">{p.resultado}</Descriptions.Item>}
          </Descriptions>
          <Card size="small" title="Impacto no pedido"><TabelaImpacto impacto={p.impacto_no_pedido} /></Card>
          {p.impacto_actual && <Card size="small" title="Impacto actual"><TabelaImpacto impacto={p.impacto_actual} /></Card>}
          {acc && (acc.aprovar || acc.executar || acc.cancelar) && (
            <Flex gap={8} wrap>
              {acc.aprovar && <Button type="primary" onClick={() => setDecisao('aprovar')}>Aprovar</Button>}
              {acc.rejeitar && <Button danger onClick={() => setDecisao('rejeitar')}>Rejeitar</Button>}
              {acc.executar && <Button type="primary" danger icon={<ExclamationCircleOutlined />} onClick={() => setDecisao('executar')}>Executar</Button>}
              {acc.cancelar && <Button onClick={() => setDecisao('cancelar')}>Cancelar pedido</Button>}
            </Flex>
          )}
          {p.estado === 'PENDENTE' && p.pedido_por?.id === utilizador?.id && (
            <Alert type="info" showIcon message="A aprovação tem de ser feita por outro administrador, na sessão dele, com a palavra-passe dele." />
          )}
          {p.historico.length > 0 && (
            <Card size="small" title="Histórico">
              <Timeline items={p.historico.map((h) => ({ children: <><Typography.Text type="secondary">{formatarDataHora(h.em)} · {h.por}</Typography.Text><div>{h.texto}</div></> }))} />
            </Card>
          )}
        </Space>
      )}
      {p && <ModalDecisao pedido={p} tipo={decisao} aoFechar={() => setDecisao(null)} />}
    </Drawer>
  );
}

function ModalDecisao({ pedido, tipo, aoFechar }: { pedido: Pedido; tipo: 'aprovar' | 'rejeitar' | 'executar' | 'cancelar' | null; aoFechar: () => void }) {
  const [form] = Form.useForm();
  const accao = useAccao({ invalidar: [CHAVE], aoSucesso: () => aoFechar() });
  useEffect(() => {
    if (tipo) form.resetFields();
  }, [tipo, form]);
  const titulos = { aprovar: 'Aprovar pedido', rejeitar: 'Rejeitar pedido', executar: 'Executar pedido', cancelar: 'Cancelar pedido' };
  const confirmacao = textoConfirmacao(pedido.id);
  const submeter = (v: Record<string, unknown>) => {
    const url = `/sistema/manutencao/pedidos/${pedido.id}/${tipo}`;
    if (tipo === 'aprovar') accao.mutate({ url, dados: { palavra_passe: v.palavra_passe, nota: v.nota || null } });
    else if (tipo === 'rejeitar') accao.mutate({ url, dados: { palavra_passe: v.palavra_passe, motivo: v.motivo } });
    else if (tipo === 'executar') accao.mutate({ url, dados: { confirmacao: v.confirmacao, copia_seguranca_confirmada: !!v.copia } });
    else accao.mutate({ url, dados: { motivo: v.motivo } });
  };
  return (
    <Modal
      open={!!tipo}
      title={tipo ? `${titulos[tipo]} #${pedido.id}` : ''}
      onCancel={aoFechar}
      okText={tipo ? titulos[tipo].split(' ')[0] : 'OK'}
      cancelText="Voltar"
      okButtonProps={{ danger: tipo !== 'aprovar' }}
      confirmLoading={accao.isPending}
      onOk={() => form.submit()}
      destroyOnClose
    >
      <Form form={form} layout="vertical" onFinish={submeter}>
        {(tipo === 'aprovar' || tipo === 'rejeitar') && (
          <>
            <Typography.Paragraph type="secondary">Confirme a decisão com a sua palavra-passe (fica registada na auditoria).</Typography.Paragraph>
            <Form.Item name="palavra_passe" label="A sua palavra-passe" rules={[{ required: true, message: 'Indique a palavra-passe.' }]}>
              <Input.Password autoComplete="current-password" maxLength={200} />
            </Form.Item>
          </>
        )}
        {tipo === 'aprovar' && (
          <Form.Item name="nota" label="Nota (opcional)">
            <Input maxLength={255} />
          </Form.Item>
        )}
        {(tipo === 'rejeitar' || tipo === 'cancelar') && (
          <Form.Item name="motivo" label="Motivo" rules={[{ required: true, message: 'Indique o motivo.' }, { min: 10, message: 'Pelo menos 10 caracteres.' }]}>
            <Input.TextArea rows={3} maxLength={2000} />
          </Form.Item>
        )}
        {tipo === 'executar' && (
          <>
            <Alert type="error" showIcon style={{ marginBottom: 12 }} message="Esta operação altera muitos registos e não tem desfazer automático." />
            <Form.Item name="copia" valuePropName="checked" rules={[{ validator: (_, v) => (v ? Promise.resolve() : Promise.reject(new Error('Confirme a cópia de segurança.'))) }]}>
              <Checkbox>Tenho uma cópia de segurança recente da empresa.</Checkbox>
            </Form.Item>
            <Form.Item
              name="confirmacao"
              label={<>Escreva <Typography.Text code>{confirmacao}</Typography.Text></>}
              rules={[{ validator: (_, v: string) => ((v ?? '').trim().toUpperCase() === confirmacao ? Promise.resolve() : Promise.reject(new Error(`Escreva exactamente: ${confirmacao}`))) }]}
            >
              <Input autoComplete="off" />
            </Form.Item>
          </>
        )}
      </Form>
    </Modal>
  );
}
