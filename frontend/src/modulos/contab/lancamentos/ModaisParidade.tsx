import { Alert, Button, Descriptions, Flex, Form, Input, Modal, Skeleton, Space, Table, Tag, Typography, message } from 'antd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { useNavigate, type NavigateFunction } from 'react-router-dom';
import { enviar, obter } from '@/api/cliente';
import { IconeFa } from '@/componentes/fluxos';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import type { DocumentoLancamento, LinhaLancamento } from '../api';
import { ValorKz } from '../comum/Componentes';
import { LIMITE_COLADOS, paraCaixa, separarColados, juntarColados, urlSegura, urlsDasDescricoes } from './ferramentas';

/** Alvo de uma operação em massa: linhas seleccionadas ou todas as filtradas. */
export type AlvoMassa = { ids: number[] } | { filtros: Record<string, unknown> };

// --- «Colar múltiplas contas/documentos» (legado: openMultiAccountsModal / openGenericMultiModal) ---------------------

export function ModalColar({ aberto, titulo, contas, valor, aoAplicar, aoFechar }: { aberto: boolean; titulo: string; contas?: boolean; valor?: string; aoAplicar: (texto: string) => void; aoFechar: () => void }) {
  const [texto, setTexto] = useState('');
  useEffect(() => {
    if (aberto) setTexto(paraCaixa(valor));
  }, [aberto, valor]);
  const n = separarColados(texto).length;
  return (
    <Modal
      title={<span style={{ color: '#2563eb' }}>{titulo}</span>}
      open={aberto}
      onCancel={aoFechar}
      okText="Aplicar"
      cancelText="Cancelar"
      okButtonProps={{ disabled: n > LIMITE_COLADOS }}
      onOk={() => {
        aoAplicar(juntarColados(separarColados(texto)));
        aoFechar();
      }}
      width={larguraModal(420)}
      destroyOnHidden
    >
      <Typography.Paragraph type="secondary" style={{ fontSize: 13 }}>
        {contas ? 'Cole aqui as contas copiadas do Excel (uma por linha) ou separadas por vírgula.' : 'Cole aqui os valores copiados do Excel (um por linha) ou separados por vírgula.'}
      </Typography.Paragraph>
      <Input.TextArea
        autoFocus
        aria-label={titulo}
        rows={7}
        value={texto}
        onChange={(e) => setTexto(e.target.value)}
        placeholder={contas ? 'Ex:\n21\n24\n26' : 'Valor 1\nValor 2\nValor 3'}
        style={{ fontFamily: 'monospace', resize: 'none' }}
      />
      <Typography.Text type={n > LIMITE_COLADOS ? 'danger' : 'secondary'} style={{ fontSize: 12 }}>
        {n} valor(es){n > LIMITE_COLADOS ? ` — o máximo é ${LIMITE_COLADOS}` : ''}
      </Typography.Text>
    </Modal>
  );
}

// --- «Descontabilizar Selecionados» (8.1.1) --------------------------------------------------------------------------

interface AnaliseDescontabilizacao {
  lancamentos: { linha_id: number; numero_lan: string; numero_documento: string | null; data_documento: string | null; linhas: number; valor: string; estado: 'ESTORNAR' | 'BLOQUEADO' | 'IGNORADO'; motivo: string | null }[];
  a_estornar: number;
  bloqueados: number;
  ignorados: number;
  linhas: number;
}

const COR_ESTADO = { ESTORNAR: 'orange', BLOQUEADO: 'red', IGNORADO: 'default' } as const;
const ROTULO_ESTADO = { ESTORNAR: 'A descontabilizar', BLOQUEADO: 'Bloqueado', IGNORADO: 'Ignorado' } as const;

/**
 * Pré-visualiza (simulação no servidor) e confirma a descontabilização: cada lançamento manual/importado é estornado
 * (nada se apaga); um lançamento de outro módulo, de exercício encerrado ou reconciliado bloqueia a operação inteira.
 */
export function ModalDescontabilizar({ alvo, aoFechar, aoConcluir }: { alvo: AlvoMassa | null; aoFechar: () => void; aoConcluir?: () => void }) {
  const cliente = useQueryClient();
  const [form] = Form.useForm<{ motivo: string }>();
  const analise = useQuery({
    queryKey: ['contab', 'descontabilizar', 'simular', alvo],
    queryFn: async () => (await enviar<AnaliseDescontabilizacao>('post', '/contabilidade/lancamentos/descontabilizar', { ...alvo, simular: true })).dados,
    enabled: !!alvo,
    retry: false,
    gcTime: 0,
  });
  const executar = useMutation({
    mutationFn: (motivo: string) => enviar<{ estornados: { numero_lan: string; estorno: string }[] }>('post', '/contabilidade/lancamentos/descontabilizar', { ...alvo, motivo }),
    onSuccess: ({ mensagem }) => {
      message.success(mensagem);
      void cliente.invalidateQueries({ queryKey: ['contab'] });
      form.resetFields();
      aoConcluir?.();
      aoFechar();
    },
    onError: (e) => notificarErro(e, 'Não foi possível descontabilizar'),
  });
  const a = analise.data;
  return (
    <Modal
      title={
        <Space>
          <IconeFa nome="undo" style={{ color: '#d97706' }} /> Descontabilizar Selecionados
        </Space>
      }
      open={!!alvo}
      onCancel={aoFechar}
      okText="Descontabilizar"
      cancelText="Cancelar"
      okButtonProps={{ danger: true, disabled: !a || a.bloqueados > 0 || a.a_estornar === 0 }}
      confirmLoading={executar.isPending}
      onOk={() => form.submit()}
      width={larguraModal(820)}
      destroyOnHidden
    >
      {analise.isLoading && <Skeleton active />}
      {analise.error && <Alert type="error" showIcon message="Não foi possível analisar a selecção." description={(analise.error as Error).message} />}
      {a && (
        <>
          <Typography.Paragraph>
            Deseja descontabilizar os <strong>{a.a_estornar}</strong> lançamento(s) correspondente(s) à {'ids' in (alvo ?? {}) ? 'selecção' : 'filtragem'} ({a.linhas} linha(s))?
            É criado um <strong>estorno</strong> (lançamento contrário) para cada um; os originais ficam no Diário marcados como estornados.
          </Typography.Paragraph>
          {a.bloqueados > 0 && (
            <Alert
              type="error"
              showIcon
              style={{ marginBottom: 12 }}
              message={`${a.bloqueados} lançamento(s) não podem ser descontabilizados aqui: nada será feito.`}
              description="Os lançamentos gerados por outros módulos descontabilizam-se no módulo de origem (que repõe o documento). Retire-os da selecção."
            />
          )}
          {a.ignorados > 0 && <Alert type="info" showIcon style={{ marginBottom: 12 }} message={`${a.ignorados} lançamento(s) ignorado(s): já estornados ou estornos.`} />}
          <Table
            size="small"
            rowKey="linha_id"
            pagination={a.lancamentos.length > 10 ? { pageSize: 10, size: 'small' } : false}
            dataSource={a.lancamentos}
            scroll={scrollTabela()}
            columns={[
              { title: 'Nº LANÇ.', dataIndex: 'numero_lan' },
              { title: 'Nº DOC.', dataIndex: 'numero_documento', render: (v: string | null) => v ?? '—' },
              { title: 'DATA', dataIndex: 'data_documento', render: formatarData },
              { title: 'VALOR (KZ)', dataIndex: 'valor', align: 'right', render: (v: string) => <ValorKz valor={v} /> },
              { title: 'ESTADO', dataIndex: 'estado', render: (e: keyof typeof COR_ESTADO) => <Tag color={COR_ESTADO[e]}>{ROTULO_ESTADO[e]}</Tag> },
              { title: 'MOTIVO', dataIndex: 'motivo', render: (v: string | null) => v ?? '' },
            ]}
          />
          {a.bloqueados === 0 && a.a_estornar > 0 && (
            <Form form={form} layout="vertical" style={{ marginTop: 12 }} onFinish={(v) => executar.mutate(v.motivo.trim())}>
              <Form.Item name="motivo" label="Motivo da descontabilização" rules={[{ required: true, min: 5, message: 'Indique o motivo (pelo menos 5 caracteres).' }]}>
                <Input.TextArea rows={2} maxLength={300} />
              </Form.Item>
            </Form>
          )}
        </>
      )}
    </Modal>
  );
}

// --- «Estornar (Criar Lançamento contrário)» (8.1.3) ----------------------------------------------------------------

export function ModalEstornar({ linha, aoFechar }: { linha: Pick<LinhaLancamento, 'id' | 'numero_lan' | 'numero_documento'> | null; aoFechar: () => void }) {
  const cliente = useQueryClient();
  const [form] = Form.useForm<{ motivo: string }>();
  const estorno = useMutation({
    mutationFn: (motivo: string) => enviar<DocumentoLancamento>('post', `/contabilidade/lancamentos/${linha!.id}/estornar`, { motivo }),
    onSuccess: ({ mensagem }) => {
      message.success(mensagem);
      void cliente.invalidateQueries({ queryKey: ['contab'] });
      form.resetFields();
      aoFechar();
    },
    onError: (e) => notificarErro(e, 'Não foi possível estornar'),
  });
  return (
    <Modal
      title={`Estornar ${linha?.numero_lan ?? ''}`}
      open={!!linha}
      onCancel={aoFechar}
      okText="Estornar"
      cancelText="Cancelar"
      okButtonProps={{ danger: true }}
      confirmLoading={estorno.isPending}
      onOk={() => form.submit()}
      width={larguraModal(480)}
      destroyOnHidden
    >
      <Typography.Paragraph>
        Deseja gerar o <strong>ESTORNO</strong> automático do documento {linha?.numero_documento ?? linha?.numero_lan}? Será gerado um lançamento com os valores contrários
        (DÉBITO passa a CRÉDITO e vice-versa).
      </Typography.Paragraph>
      <Form form={form} layout="vertical" onFinish={(v) => estorno.mutate(v.motivo.trim())}>
        <Form.Item name="motivo" label="Motivo" rules={[{ required: true, min: 5, message: 'Indique o motivo (pelo menos 5 caracteres).' }]}>
          <Input.TextArea rows={3} maxLength={500} />
        </Form.Item>
      </Form>
    </Modal>
  );
}

// --- «Ver Documento de Origem» / «Ver Fluxo Completo» (8.1.3) -------------------------------------------------------

export interface OrigemModulo {
  tipo_origem: string;
  modulo: string;
  ecra: string;
  nome: string;
  instrucoes: string;
  documento_id: number | null;
  numero: string | null;
}

export interface RespostaOrigem {
  origem: OrigemModulo | null;
  manual: boolean;
  url_documento: string | null;
  tipo_origem: string | null;
}

export const obterOrigem = (id: number) => obter<RespostaOrigem>(`/contabilidade/lancamentos/${id}/origem`);

/** Destino no frontend do documento de origem (o n.º do documento vai como pesquisa; nunca dados pessoais). */
export function destinoOrigem(o: OrigemModulo): string | null {
  if (o.modulo === 'contab' && o.ecra === 'lancamentos') return null;
  return `/m/${o.modulo}/${o.ecra}${o.numero ? `?pesquisa=${encodeURIComponent(o.numero)}` : ''}`;
}

/** Abre o documento de origem (legado goToSourceDocument): ecrã do módulo, ou o URL do documento externo. */
export function abrirOrigem(r: RespostaOrigem, navegar: NavigateFunction): void {
  const destino = r.origem ? destinoOrigem(r.origem) : null;
  if (destino) {
    navegar(destino);
    return;
  }
  const url = urlSegura(r.url_documento);
  if (url) {
    window.open(url, '_blank', 'noopener,noreferrer');
    return;
  }
  void message.info(r.origem ? `${r.origem.nome}: ${r.origem.instrucoes}` : 'Não foi possível localizar o documento de origem original (lançamento da Contabilidade).');
}

/** Fluxo do lançamento (legado showFlowFromAccounting: fluxo de RH, de venda ou de lançamento manual). */
export function ModalFluxoLancamento({ linhaId, aoFechar, aoDescontabilizar }: { linhaId: number | null; aoFechar: () => void; aoDescontabilizar?: (ids: number[]) => void }) {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const doc = useQuery({ queryKey: ['contab', 'lancamento', String(linhaId)], queryFn: () => obter<DocumentoLancamento>(`/contabilidade/lancamentos/${linhaId}`), enabled: linhaId !== null });
  const origem = useQuery({ queryKey: ['contab', 'lancamento', String(linhaId), 'origem'], queryFn: () => obterOrigem(linhaId!), enabled: linhaId !== null });
  const d = doc.data;
  const o = origem.data?.origem ?? null;
  const rh = o?.modulo === 'rh';
  const titulo = !o ? 'Fluxo de Lançamento Manual' : rh ? 'Fluxo de Processamento de RH' : `Fluxo do Documento — ${o.nome}`;
  const icone = !o ? 'file-invoice' : rh ? 'users' : 'project-diagram';
  const urls = d ? [...new Set([urlSegura(origem.data?.url_documento), ...urlsDasDescricoes(d.linhas.map((l) => l.descricao))].filter((u): u is string => !!u))] : [];
  const estornado = d?.linhas.some((l) => l.estornado_por_id || l.estorno_de_id);
  const podeDescontabilizar = !o && !estornado && pode('contab_lanc_del') && !!aoDescontabilizar;
  return (
    <Modal
      title={
        <Space style={{ color: o ? '#2563eb' : '#0284c7' }}>
          <IconeFa nome={icone} /> {titulo}
        </Space>
      }
      open={linhaId !== null}
      onCancel={aoFechar}
      footer={<Button onClick={aoFechar}>Fechar</Button>}
      width={larguraModal(900)}
      destroyOnHidden
    >
      {(doc.isLoading || origem.isLoading) && <Skeleton active />}
      {d && (
        <>
          <Flex
            wrap
            gap={12}
            justify="space-between"
            align="center"
            style={{ background: o ? '#f8fafc' : '#f0f9ff', padding: 16, borderRadius: 10, marginBottom: 16, borderLeft: `5px solid ${o ? '#2563eb' : '#0284c7'}` }}
          >
            <div>
              <Typography.Title level={5} style={{ margin: 0 }}>
                {rh ? `Período: ${o?.numero ?? '—'}` : `Documento: ${d.numero_documento ?? d.numero_lan}`}
              </Typography.Title>
              <Typography.Text type="secondary">
                Data do Documento: {formatarData(d.data_documento)} | Total de Linhas: {d.linhas.length} | Lançamento {d.numero_lan}
              </Typography.Text>
              {o && (
                <div>
                  <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                    Gerado pelo módulo {o.nome}. {o.instrucoes}
                  </Typography.Text>
                </div>
              )}
            </div>
            <Space wrap>
              {podeDescontabilizar && (
                <Button danger icon={<IconeFa nome="undo" />} onClick={() => aoDescontabilizar!(d.linhas.map((l) => l.id))}>
                  Descontabilizar
                </Button>
              )}
              {origem.data && (o || urlSegura(origem.data.url_documento)) && (
                <Button type="primary" icon={<IconeFa nome="external-link-alt" />} onClick={() => abrirOrigem(origem.data!, navegar)}>
                  {rh ? 'Abrir Processamento' : o ? 'Abrir no Módulo' : 'Localizar Origem'}
                </Button>
              )}
              <Button
                icon={<IconeFa nome="eye" />}
                onClick={() => {
                  aoFechar();
                  navegar(`/m/contab/lancamentos/${linhaId}`);
                }}
              >
                Abrir lançamento
              </Button>
            </Space>
          </Flex>
          {urls.length > 0 && (
            <Flex wrap gap={8} align="center" style={{ marginBottom: 16, padding: 12, background: '#f0f9ff', borderRadius: 10, border: '1px solid #bae6fd' }}>
              <Typography.Text strong style={{ fontSize: 12, color: '#0369a1', textTransform: 'uppercase' }}>
                <IconeFa nome="link" /> Documentos Externos:
              </Typography.Text>
              {urls.map((u) => (
                <Button key={u} size="small" type="primary" href={u} target="_blank" rel="noopener noreferrer" icon={<IconeFa nome="external-link-alt" />} style={{ background: '#0284c7' }}>
                  {u.length > 50 ? `${u.slice(0, 50)}…` : u}
                </Button>
              ))}
            </Flex>
          )}
          <Typography.Title level={5}>{rh ? 'Lançamentos Contabilísticos Associados' : 'Detalhamento das Linhas (Documento Completo)'}</Typography.Title>
          <Table<LinhaLancamento>
            size="small"
            rowKey="id"
            pagination={false}
            dataSource={d.linhas}
            scroll={scrollTabela()}
            columns={[
              { title: 'Conta', dataIndex: 'codigo_conta' },
              { title: 'Descrição', dataIndex: 'descricao', render: (v: string | null) => v ?? '' },
              { title: 'DÉBITO', align: 'right', render: (_, l) => (l.tipo_dc === 'D' ? formatarKz(l.valor) : '') },
              { title: 'CRÉDITO', align: 'right', render: (_, l) => (l.tipo_dc === 'C' ? formatarKz(l.valor) : '') },
            ]}
            summary={() => (
              <Table.Summary.Row>
                <Table.Summary.Cell index={0} colSpan={2}>
                  <strong>TOTAL:</strong>
                </Table.Summary.Cell>
                <Table.Summary.Cell index={2} align="right">
                  <ValorKz valor={d.debito} forte />
                </Table.Summary.Cell>
                <Table.Summary.Cell index={3} align="right">
                  <ValorKz valor={d.credito} forte />
                </Table.Summary.Cell>
              </Table.Summary.Row>
            )}
          />
          {!d.equilibrado && <Alert style={{ marginTop: 12 }} type="warning" showIcon message="Lançamento desequilibrado (débito diferente do crédito)." />}
          {estornado && (
            <Descriptions size="small" style={{ marginTop: 12 }} column={1}>
              <Descriptions.Item label="Situação">{d.linhas.some((l) => l.estorno_de_id) ? <Tag color="purple">Estorno</Tag> : <Tag color="red">Estornado</Tag>}</Descriptions.Item>
            </Descriptions>
          )}
        </>
      )}
    </Modal>
  );
}
