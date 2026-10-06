import { DeleteOutlined, EditOutlined, InboxOutlined, PlusOutlined, RobotOutlined, SendOutlined, WarningOutlined } from '@ant-design/icons';
import { Alert, Button, Card, Descriptions, Drawer, Empty, Flex, Form, Input, List, Modal, Popconfirm, Segmented, Space, Switch, Table, Tabs, Tag, Typography, Upload, message } from 'antd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { enviar, http, obter } from '@/api/cliente';
import type { Envelope } from '@/api/tipos';
import { larguraGaveta, scrollTabela } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { formatarKz } from '@/utilitarios/formatacao';

import { TabelaComModos } from '@/componentes/vistas';
/**
 * Assistente IA para lançamentos (decisão 26, M-03; legado js/ui_ai_agent.js): descreve-se a operação ou anexa-se o
 * documento (PDF/imagem) e o servidor devolve propostas validadas. NADA é gravado: «Rever no formulário» abre o
 * lançamento manual (Contabilidade › Lançamentos › Novo) já preenchido, onde o utilizador confirma e grava pelo fluxo
 * normal. Regras internas (palavras-chave → linhas) funcionam sem IA externa. Desligado por omissão em cada empresa.
 */
export interface LinhaProposta {
  codigo_conta: string;
  descricao_conta: string | null;
  tipo_dc: 'D' | 'C';
  valor: number;
  descricao: string;
}

export interface Proposta {
  origem: 'IA' | 'REGRAS';
  diario_id: number | null;
  diario: string | null;
  data_documento: string;
  numero_documento: string | null;
  descricao: string | null;
  linhas: LinhaProposta[];
  debito: string;
  credito: string;
  equilibrado: boolean;
  justificacao: string | null;
  avisos: string[];
}

interface Resultado {
  motor: 'IA' | 'REGRAS';
  regra: string | null;
  propostas: Proposta[];
  observacoes: string | null;
}

interface EstadoIA {
  ativo: boolean;
  configurado: boolean;
  modelo: string;
  fornecedor: string;
  regras_internas: number;
  tem_regras_empresa: boolean;
  uso_mes: { pedidos: number; pedidos_ia: number; custo_estimado_usd: number };
  dados_enviados: string[];
}

interface Regra {
  id: number;
  nome: string;
  palavras_chave: string;
  modelo: string;
}

const CHAVE = ['contabilidade', 'assistente'];

/** Estado de navegação esperado pelo formulário de lançamento (EstadoCopia do NovoLancamento — módulo Contabilidade). */
export function propostaParaFormulario(p: Proposta) {
  return {
    // o formulário usa hoje a data de hoje nas cópias; com `origem` pode passar a usar a data proposta (patch sugerido)
    origem: 'assistente_ia' as const,
    copia: {
      diario_id: p.diario_id ?? undefined,
      numero_lan: '',
      numero_documento: p.numero_documento,
      data_documento: p.data_documento,
      debito: p.debito,
      credito: p.credito,
      equilibrado: p.equilibrado,
      linhas: p.linhas.map((l) => ({ codigo_conta: l.codigo_conta, tipo_dc: l.tipo_dc, valor: l.valor.toFixed(2), descricao: l.descricao || p.descricao || null })),
    },
  };
}

export function AssistenteIA({ aberto, aoFechar }: { aberto: boolean; aoFechar: () => void }) {
  const { pode } = useSessao();
  const estado = useQuery({ queryKey: CHAVE, queryFn: () => obter<EstadoIA>('/contabilidade/assistente'), enabled: aberto });
  return (
    <Drawer title={<span><RobotOutlined /> Assistente IA para lançamentos</span>} open={aberto} onClose={aoFechar} width={larguraGaveta(820)} destroyOnHidden>
      <Tabs
        items={[
          ...(pode('lancamentos_post') ? [{ key: 'propor', label: 'Propor lançamento', children: <Propor estado={estado.data} aoFechar={aoFechar} /> }] : []),
          ...(pode('lancamentos_post', 'aux_gerir') ? [{ key: 'regras', label: 'Regras internas', children: <Regras /> }] : []),
          { key: 'config', label: 'Configuração', children: <Configuracao estado={estado.data} /> },
        ]}
      />
    </Drawer>
  );
}

function Propor({ estado, aoFechar }: { estado?: EstadoIA; aoFechar: () => void }) {
  const navegar = useNavigate();
  const { menu } = useSessao();
  const [texto, setTexto] = useState('');
  const [ficheiro, setFicheiro] = useState<File | null>(null);
  const [motor, setMotor] = useState<'auto' | 'regras' | 'ia'>('auto');
  const podeAbrirFormulario = menu.some((m) => m.id === 'contab' && m.ecras.some((e) => e.id === 'lancamentos'));
  const pedir = useMutation({
    mutationFn: async () => {
      const dados = new FormData();
      if (texto.trim()) dados.append('texto', texto.trim());
      if (ficheiro) dados.append('ficheiro', ficheiro);
      dados.append('motor', motor);
      const r = await http.post<Envelope<Resultado>>('/contabilidade/assistente/propor', dados, { timeout: 180_000 });
      return r.data.dados;
    },
    onError: (e) => notificarErro(e, 'O assistente não conseguiu propor'),
  });
  const iaDisponivel = !!estado?.ativo && !!estado.configurado;
  return (
    <>
      {estado && !iaDisponivel && (
        <Alert
          type="info"
          showIcon
          style={{ marginBottom: 12 }}
          message={estado.configurado ? 'A IA está desligada nesta empresa' : 'A IA não está configurada no servidor'}
          description="As regras internas continuam disponíveis. Veja o separador «Configuração» para saber como activar."
        />
      )}
      <Typography.Paragraph type="secondary">
        Descreva a operação (ex.: «Pagamento em numerário de combustível, 25 000 Kz com IVA») ou anexe a factura/recibo. O assistente só propõe: o lançamento é revisto e
        gravado por si no formulário normal.
      </Typography.Paragraph>
      <Input.TextArea rows={4} maxLength={20000} value={texto} onChange={(e) => setTexto(e.target.value)} placeholder="Descrição da operação" aria-label="Descrição da operação" />
      <Upload.Dragger
        style={{ marginTop: 12 }}
        accept=".pdf,.png,.jpg,.jpeg,.webp,.gif"
        maxCount={1}
        beforeUpload={(f) => {
          setFicheiro(f);
          return false;
        }}
        onRemove={() => setFicheiro(null)}
        fileList={ficheiro ? [{ uid: '1', name: ficheiro.name, status: 'done' }] : []}
      >
        <p className="ant-upload-drag-icon"><InboxOutlined /></p>
        <p>Arraste o documento (PDF ou imagem, até 10 MB) ou clique para escolher</p>
      </Upload.Dragger>
      <Flex gap={12} align="center" wrap style={{ marginTop: 12 }}>
        <Segmented
          value={motor}
          onChange={(v) => setMotor(v as typeof motor)}
          options={[{ value: 'auto', label: 'Automático' }, { value: 'regras', label: 'Só regras internas' }, { value: 'ia', label: 'Só IA', disabled: !iaDisponivel }]}
        />
        <Button type="primary" icon={<SendOutlined />} loading={pedir.isPending} disabled={!texto.trim() && !ficheiro} onClick={() => pedir.mutate()}>
          Propor
        </Button>
      </Flex>
      {pedir.data && (
        <div style={{ marginTop: 16 }}>
          <Typography.Text type="secondary">
            {pedir.data.motor === 'REGRAS' ? `Regra interna aplicada: «${pedir.data.regra}».` : `Proposto pela IA (${estado?.fornecedor ?? 'Claude'}).`} Nada foi gravado.
          </Typography.Text>
          {pedir.data.observacoes && <Alert type="warning" showIcon style={{ marginTop: 8 }} message="Observações" description={pedir.data.observacoes} />}
          {!pedir.data.propostas.length && <Empty description="Sem propostas para este conteúdo." />}
          {pedir.data.propostas.map((p, i) => (
            <Card
              key={i}
              size="small"
              style={{ marginTop: 12 }}
              title={`${p.descricao ?? 'Proposta'}${p.numero_documento ? ` · ${p.numero_documento}` : ''}`}
              extra={p.equilibrado ? <Tag color="green">Equilibrado</Tag> : <Tag color="red">Desequilibrado</Tag>}
            >
              <Descriptions size="small" column={{ xs: 1, sm: 3 }} items={[
                { key: 'd', label: 'Diário', children: p.diario ?? '—' },
                { key: 'dt', label: 'Data', children: p.data_documento },
                { key: 't', label: 'Total', children: `${formatarKz(p.debito)} Kz` },
              ]} />
              <Table<LinhaProposta>
                size="small"
                rowKey={(_, n) => String(n)}
                pagination={false}
                scroll={scrollTabela()}
                dataSource={p.linhas}
                columns={[
                  { title: 'Conta', dataIndex: 'codigo_conta', render: (c: string, l) => <span><strong>{c}</strong> <Typography.Text type="secondary">{l.descricao_conta ?? '(não existe)'}</Typography.Text></span> },
                  { title: 'Débito', key: 'd', align: 'right', render: (_: unknown, l) => (l.tipo_dc === 'D' ? formatarKz(l.valor) : '') },
                  { title: 'Crédito', key: 'c', align: 'right', render: (_: unknown, l) => (l.tipo_dc === 'C' ? formatarKz(l.valor) : '') },
                  { title: 'Descrição', dataIndex: 'descricao', responsive: ['md'] },
                ]}
              />
              {p.justificacao && <Typography.Paragraph style={{ marginTop: 8, marginBottom: 4 }}><em>{p.justificacao}</em></Typography.Paragraph>}
              {p.avisos.length > 0 && (
                <List size="small" dataSource={p.avisos} renderItem={(a) => <List.Item><WarningOutlined style={{ color: '#b45309' }} /> {a}</List.Item>} />
              )}
              <Flex justify="end" style={{ marginTop: 8 }}>
                <Button
                  type="primary"
                  disabled={!podeAbrirFormulario}
                  title={podeAbrirFormulario ? undefined : 'Sem acesso a Contabilidade › Lançamentos'}
                  onClick={() => {
                    aoFechar();
                    navegar('/m/contab/lancamentos/novo', { state: propostaParaFormulario(p) });
                  }}
                >
                  Rever no formulário de lançamento
                </Button>
              </Flex>
            </Card>
          ))}
        </div>
      )}
    </>
  );
}

function Regras() {
  const { pode } = useSessao();
  const gerir = pode('aux_gerir');
  const cliente = useQueryClient();
  const regras = useQuery({ queryKey: [...CHAVE, 'regras'], queryFn: () => obter<Regra[]>('/contabilidade/assistente/regras') });
  const [edicao, setEdicao] = useState<Regra | 'nova' | null>(null);
  const [form] = Form.useForm<{ nome: string; palavras_chave: string; modelo: string }>();
  const gravar = useMutation({
    mutationFn: (v: { nome: string; palavras_chave: string; modelo: string }) =>
      edicao && edicao !== 'nova' ? enviar('put', `/contabilidade/assistente/regras/${edicao.id}`, v) : enviar('post', '/contabilidade/assistente/regras', v),
    onSuccess: ({ mensagem }) => {
      message.success(mensagem);
      setEdicao(null);
      void cliente.invalidateQueries({ queryKey: CHAVE });
    },
    onError: (e) => notificarErro(e),
  });
  const eliminar = useMutation({
    mutationFn: (id: number) => enviar('delete', `/contabilidade/assistente/regras/${id}`),
    onSuccess: () => void cliente.invalidateQueries({ queryKey: CHAVE }),
    onError: (e) => notificarErro(e),
  });
  const abrir = (r: Regra | 'nova') => {
    setEdicao(r);
    form.setFieldsValue(r === 'nova' ? { nome: '', palavras_chave: '', modelo: '[\n  {"account_code": "6243", "type_dc": "D"},\n  {"account_code": "4511", "type_dc": "C"}\n]' } : r);
  };
  return (
    <>
      <Typography.Paragraph type="secondary">
        Motor local, sem envio de dados a terceiros: a primeira regra cujas palavras-chave aparecem no texto gera a proposta com as linhas do modelo; o valor é o maior
        montante do texto e <Typography.Text code>percent</Typography.Text> aplica uma percentagem a essa linha (ex.: IVA 14).
      </Typography.Paragraph>
      {gerir && <Button icon={<PlusOutlined />} style={{ marginBottom: 12 }} onClick={() => abrir('nova')}>Nova regra</Button>}
      <TabelaComModos<Regra> idVista="regras"
        size="small"
        rowKey="id"
        loading={regras.isLoading}
        dataSource={regras.data}
        pagination={false}
        scroll={scrollTabela()}
        columns={[
          { title: 'Nome', dataIndex: 'nome' },
          { title: 'Palavras-chave', dataIndex: 'palavras_chave' },
          ...(gerir
            ? [{
                title: '',
                key: 'a',
                render: (_: unknown, r: Regra) => (
                  <Space>
                    <Button size="small" type="text" icon={<EditOutlined />} aria-label="Editar" onClick={() => abrir(r)} />
                    <Popconfirm title="Eliminar a regra?" okText="Eliminar" cancelText="Cancelar" onConfirm={() => eliminar.mutateAsync(r.id)}>
                      <Button size="small" type="text" danger icon={<DeleteOutlined />} aria-label="Eliminar" />
                    </Popconfirm>
                  </Space>
                ),
              }]
            : []),
        ]}
      />
      <Modal title={edicao === 'nova' ? 'Nova regra interna' : 'Regra interna'} open={!!edicao} onCancel={() => setEdicao(null)} okText="Gravar" cancelText="Cancelar" confirmLoading={gravar.isPending} onOk={() => form.submit()} destroyOnHidden>
        <Form form={form} layout="vertical" onFinish={(v) => gravar.mutate(v)}>
          <Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }]}><Input maxLength={255} /></Form.Item>
          <Form.Item name="palavras_chave" label="Palavras-chave (separadas por vírgulas)" rules={[{ required: true, message: 'Indique as palavras-chave.' }]}>
            <Input placeholder="combustível, gasóleo" />
          </Form.Item>
          <Form.Item name="modelo" label="Modelo das linhas (JSON)" rules={[{ required: true, message: 'Indique o modelo.' }]}>
            <Input.TextArea rows={7} style={{ fontFamily: 'monospace', fontSize: 12 }} />
          </Form.Item>
        </Form>
      </Modal>
    </>
  );
}

function Configuracao({ estado }: { estado?: EstadoIA }) {
  const { pode } = useSessao();
  const cliente = useQueryClient();
  const activar = useMutation({
    mutationFn: (ativo: boolean) => enviar('put', '/contabilidade/assistente/configuracao', { ativo }),
    onSuccess: ({ mensagem }) => {
      message.success(mensagem);
      void cliente.invalidateQueries({ queryKey: CHAVE });
    },
    onError: (e) => notificarErro(e),
  });
  if (!estado) return <Card loading />;
  return (
    <Space direction="vertical" size={16} style={{ width: '100%' }}>
      {!estado.configurado && (
        <Alert
          type="warning"
          showIcon
          message="Como activar a IA"
          description={
            <ol style={{ margin: 0, paddingInlineStart: 18 }}>
              <li>O administrador do servidor cria uma chave na consola da Anthropic e define a variável de ambiente <Typography.Text code>ANTHROPIC_API_KEY</Typography.Text> no ficheiro de ambiente do servidor (nunca no Git).</li>
              <li>Reinicia os contentores da aplicação (ver docs/PRODUCAO.md › Assistente IA).</li>
              <li>Um utilizador com «Gestão de empresas» activa o assistente nesta empresa (interruptor abaixo).</li>
            </ol>
          }
        />
      )}
      <Flex gap={12} align="center" wrap>
        <Switch checked={estado.ativo} disabled={!pode('config_empresas_gerir')} loading={activar.isPending} onChange={(v) => activar.mutate(v)} aria-label="Assistente IA activo nesta empresa" />
        <span>{estado.ativo ? 'Activo nesta empresa' : 'Desligado nesta empresa (por omissão)'}</span>
      </Flex>
      <Descriptions size="small" column={1} bordered items={[
        { key: 'f', label: 'Fornecedor e modelo', children: `${estado.fornecedor} — ${estado.modelo}` },
        { key: 's', label: 'Chave no servidor', children: estado.configurado ? 'Configurada' : 'Em falta' },
        { key: 'r', label: 'Regras internas', children: estado.regras_internas },
        { key: 'e', label: 'Regras de negócio da empresa', children: estado.tem_regras_empresa ? 'Definidas na ficha da empresa (enviadas à IA)' : 'Não definidas' },
        { key: 'u', label: 'Utilização este mês', children: `${estado.uso_mes.pedidos} pedido(s), ${estado.uso_mes.pedidos_ia} com IA — custo estimado ${estado.uso_mes.custo_estimado_usd.toFixed(2)} USD` },
      ]} />
      <Card size="small" title="Dados enviados ao fornecedor quando usa a IA">
        <ul style={{ margin: 0, paddingInlineStart: 18 }}>{estado.dados_enviados.map((d) => <li key={d}>{d}</li>)}</ul>
        <Typography.Paragraph type="secondary" style={{ marginTop: 8, marginBottom: 0 }}>
          Não são enviados o nome nem o NIF da empresa, terceiros, saldos ou lançamentos. O documento anexado vai tal como está: não anexe documentos com dados pessoais
          desnecessários. As regras internas não enviam nada.
        </Typography.Paragraph>
      </Card>
    </Space>
  );
}
