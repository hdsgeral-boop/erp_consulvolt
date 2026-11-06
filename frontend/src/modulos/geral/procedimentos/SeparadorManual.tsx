import { EyeOutlined, SaveOutlined } from '@ant-design/icons';
import { Alert, Button, Card, Checkbox, Col, Form, Input, Row, Skeleton, Space, Tag, Typography, message } from 'antd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { createElement, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { BotoesExportar } from '@/componentes/impressao';
import { notificarErro } from '@/utilitarios/erros';
import { CHAVE_DEFINICOES, CHAVE_PROCESSOS, OPCOES, TEXTOS, gravarDefinicoes, listarProcessos, obterDefinicoes, recolherManual, type DefinicoesManual, type Recolha } from './api';
import { DocumentoProcedimentos, type FormaDocumento } from './DocumentoProcedimentos';
import { ROTULO_OPCAO, excluidosDe } from './logica';

const hoje = () => new Date().toISOString().slice(0, 10);

type Valores = Omit<FormaDocumento, never>;

/** Separador «Manual»: definições do documento à esquerda, pré-visualização à direita (empilhada em ecrãs pequenos), imprimir. */
export function SeparadorManual({ podeEditar, podeImprimir }: { podeEditar: boolean; podeImprimir: boolean }) {
  const qc = useQueryClient();
  const def = useQuery({ queryKey: CHAVE_DEFINICOES, queryFn: obterDefinicoes });
  const lista = useQuery({ queryKey: CHAVE_PROCESSOS, queryFn: listarProcessos });
  const [form] = Form.useForm<Valores>();
  const [marcados, setMarcados] = useState<string[] | null>(null);
  const [sujo, setSujo] = useState(false);
  const [dados, setDados] = useState<Recolha | null>(null);
  const inicializado = useRef(false);
  const valores = Form.useWatch([], form) as Valores | undefined;
  useEffect(() => { if (def.error) notificarErro(def.error, 'Não foi possível carregar o manual'); }, [def.error]);
  useEffect(() => { if (lista.error) notificarErro(lista.error, 'Não foi possível carregar os processos'); }, [lista.error]);

  const processos = useMemo(() => lista.data?.processos ?? [], [lista.data]);
  const todas = useMemo(() => processos.map((p) => p.chave), [processos]);

  const forma = useCallback((): Valores => {
    const v = form.getFieldsValue(true) as Partial<Valores>;
    return { ...(def.data as DefinicoesManual), ...v } as Valores;
  }, [form, def.data]);
  const corpo = useCallback((m: string[]): Partial<DefinicoesManual> => ({ ...forma(), processos_excluidos: excluidosDe(m, todas) }), [forma, todas]);

  const recolher = useMutation({ mutationFn: (d: Partial<DefinicoesManual>) => recolherManual(d), onError: (e) => notificarErro(e, 'Não foi possível montar o documento'), onSuccess: setDados });
  const gravar = useMutation({
    mutationFn: (d: Partial<DefinicoesManual>) => gravarDefinicoes(d), onError: (e) => notificarErro(e),
    onSuccess: async () => { setSujo(false); message.success('Manual gravado.'); await qc.invalidateQueries({ queryKey: ['manuais'] }); },
  });
  const { mutate: pre } = recolher;

  // Primeira carga: preenche o formulário com o gravado (data de hoje por omissão) e marca os processos não excluídos.
  useEffect(() => {
    if (inicializado.current || !def.data || !lista.data) return;
    inicializado.current = true;
    const m = todas.filter((k) => !def.data.processos_excluidos.includes(k));
    form.setFieldsValue({ ...def.data, data_documento: def.data.data_documento ?? hoje() } as Valores);
    setMarcados(m);
    pre({ ...def.data, data_documento: def.data.data_documento ?? hoje(), processos_excluidos: def.data.processos_excluidos });
  }, [def.data, lista.data, todas, form, pre]);

  if (def.isLoading || lista.isLoading || marcados === null) return <Skeleton active />;
  const ajuda = def.data?.textos_ajuda;
  const atual = forma();
  const aMarcar = (m: string[]) => { setMarcados(m); setSujo(true); };

  const pedidoImpressao = async () => {
    if (sujo && podeEditar) await gravar.mutateAsync(corpo(marcados));
    const d = await recolherManual(corpo(marcados));
    setDados(d);
    const { renderToStaticMarkup } = await import('react-dom/server');
    const f = forma();
    return {
      titulo: f.titulo || 'Manual de Procedimentos', subtitulo: `Versão ${f.versao}`, estilosDaPagina: true,
      conteudo: renderToStaticMarkup(createElement(DocumentoProcedimentos, { dados: d, forma: f })),
    };
  };

  return (
    <Row gutter={[12, 12]}>
      <Col xs={24} lg={10}>
        <Form form={form} layout="vertical" disabled={!podeEditar} onValuesChange={() => setSujo(true)}>
          <Space orientation="vertical" size={12} style={{ width: '100%' }}>
            <Card size="small" title="O documento">
              <Row gutter={8}>
                <Col xs={24} sm={12}><Form.Item name="titulo" label="Título"><Input maxLength={200} /></Form.Item></Col>
                <Col xs={24} sm={12}><Form.Item name="versao" label="Versão"><Input maxLength={20} /></Form.Item></Col>
                <Col xs={24} sm={12}><Form.Item name="data_documento" label="Data"><Input type="date" /></Form.Item></Col>
                <Col xs={24} sm={12}><Form.Item name="aprovado_em" label="Aprovado em"><Input type="date" /></Form.Item></Col>
                <Col xs={24} sm={12}><Form.Item name="elaborado_por" label="Elaborado por"><Input maxLength={150} /></Form.Item></Col>
                <Col xs={24} sm={12}><Form.Item name="aprovado_por" label="Aprovado por"><Input maxLength={150} /></Form.Item></Col>
              </Row>
            </Card>
            <Card size="small" title="Processos no manual" extra={podeEditar && <Space><Button size="small" onClick={() => aMarcar([...todas])}>Todos</Button><Button size="small" onClick={() => aMarcar([])}>Nenhum</Button></Space>}>
              <div style={{ maxHeight: 220, overflow: 'auto' }}>
                <Checkbox.Group value={marcados} disabled={!podeEditar} onChange={(v) => aMarcar(v as string[])} style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                  {processos.map((p) => <Checkbox key={p.chave} value={p.chave}>{p.titulo} <Typography.Text type="secondary">({p.etapas.length} etapas)</Typography.Text></Checkbox>)}
                </Checkbox.Group>
              </div>
            </Card>
            <Card size="small" title="O que entra">
              {OPCOES.map((k) => (
                <Form.Item key={k} name={k} valuePropName="checked" style={{ marginBottom: 4 }}>
                  <Checkbox>{ROTULO_OPCAO[k].rotulo}{ROTULO_OPCAO[k].ajuda && <> <Typography.Text type="secondary">— {ROTULO_OPCAO[k].ajuda}</Typography.Text></>}</Checkbox>
                </Form.Item>
              ))}
            </Card>
            <Card size="small" title="Texto que se escreve à mão">
              {TEXTOS.map((k) => (
                <Form.Item key={k} name={k} label={ajuda?.[k]?.rot ?? k} extra={ajuda?.[k]?.ajuda}>
                  <Input.TextArea rows={4} maxLength={20000} placeholder={ajuda?.[k]?.exemplo} />
                </Form.Item>
              ))}
            </Card>
          </Space>
        </Form>
        <Space wrap align="center" style={{ marginTop: 12 }}>
          {podeEditar && <Button type="primary" icon={<SaveOutlined />} loading={gravar.isPending} onClick={() => gravar.mutate(corpo(marcados))}>Gravar</Button>}
          <Button icon={<EyeOutlined />} loading={recolher.isPending} onClick={() => pre(corpo(marcados))}>Pré-visualizar</Button>
          {podeImprimir && <BotoesExportar obterPedido={pedidoImpressao} excel={false} copiar={false} textoImprimir="Imprimir" />}
          <span role="status" aria-live="polite" style={{ fontSize: 13, color: '#64748b' }}>{sujo ? 'Há alterações por gravar.' : gravar.isSuccess ? 'Gravado.' : ''}</span>
        </Space>
      </Col>
      <Col xs={24} lg={14}>
        <div className="pm-previa-fixa">
          <Card size="small" title="Pré-visualização" extra={dados && (
            <Space size={4} wrap>
              <Tag><b>{dados.totais.processos}</b> processos</Tag><Tag><b>{dados.totais.etapas}</b> etapas</Tag><Tag><b>{dados.totais.controlos}</b> controlos</Tag>
              <Tag><b>{dados.totais.intervenientes}</b> papéis</Tag><Tag><b>{dados.totais.ligacoes}</b> cruzamentos</Tag>
            </Space>
          )}>
            {dados && dados.avisos.length > 0 && (
              <Alert type="warning" showIcon style={{ marginBottom: 8 }} title="O manual sai, mas com partes por preencher:"
                description={<ul style={{ margin: 0, paddingLeft: 18 }}>{dados.avisos.map((a) => <li key={a}>{a}</li>)}</ul>} />
            )}
            {recolher.isPending && !dados ? <Skeleton active /> : dados ? (
              <div className="pm-previa" role="region" aria-label="Pré-visualização do manual"><DocumentoProcedimentos dados={dados} forma={valores ? { ...atual, ...valores } : atual} /></div>
            ) : null}
          </Card>
        </div>
      </Col>
    </Row>
  );
}
