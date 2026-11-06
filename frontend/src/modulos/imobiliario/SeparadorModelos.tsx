import { Alert, Button, Checkbox, Col, Form, Input, Modal, Row, Select, Space, Tag, message } from 'antd';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useEffect, useRef, useState } from 'react';
import { TabelaComModos } from '@/componentes/vistas';
import { larguraModal } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { CHAVES, eliminarModelo, gravarModelo, importarModelosPadrao, listarModelos, obterCatalogoModelos, preVisualizarModelo, type CatalogoModelos, type ModeloDocumento, type PreVisualizacao } from './api';
import { useRefrescar } from './hooks';
import { inserirVariavel } from './logica';

type Valores = { tipo?: string; nome?: string; assunto?: string; corpo?: string; activo?: boolean };

function ModalModelo({ modelo, catalogo, aoFechar }: { modelo: ModeloDocumento | null | undefined; catalogo: CatalogoModelos | undefined; aoFechar: () => void }) {
  const [form] = Form.useForm<Valores>();
  const aberto = modelo !== undefined;
  const refrescar = useRefrescar();
  const area = useRef<{ resizableTextArea?: { textArea?: HTMLTextAreaElement } } | null>(null);
  const [pre, setPre] = useState<PreVisualizacao | null>(null);
  useEffect(() => {
    if (!aberto) return;
    setPre(null);
    form.resetFields();
    form.setFieldsValue(modelo ? { ...modelo, assunto: modelo.assunto ?? undefined } : { activo: true });
  }, [aberto, modelo, form]);
  const gravar = useMutation({
    mutationFn: (v: Valores) => gravarModelo(v, modelo?.id), onError: (e) => notificarErro(e, 'Não foi possível gravar o modelo'),
    onSuccess: async () => { message.success('Modelo gravado.'); await refrescar(); aoFechar(); },
  });
  const ver = useMutation({
    mutationFn: () => preVisualizarModelo({ assunto: form.getFieldValue('assunto') ?? '', corpo: form.getFieldValue('corpo') ?? '' }),
    onError: (e) => notificarErro(e, 'Não foi possível pré-visualizar'), onSuccess: setPre,
  });
  const inserir = (chave: string) => {
    const ta = area.current?.resizableTextArea?.textArea;
    const r = inserirVariavel(String(form.getFieldValue('corpo') ?? ''), chave, ta?.selectionStart ?? null, ta?.selectionEnd ?? null);
    form.setFieldValue('corpo', r.texto);
    if (ta) window.setTimeout(() => { ta.focus(); ta.setSelectionRange(r.cursor, r.cursor); }, 0);
  };
  return (
    <Modal title={modelo ? 'Editar modelo' : 'Novo modelo'} open={aberto} onCancel={aoFechar} onOk={() => form.submit()} okText="Gravar" cancelText="Cancelar" confirmLoading={gravar.isPending}
      width={larguraModal(900)} destroyOnHidden>
      <Form form={form} layout="vertical" onFinish={(v) => gravar.mutate(v)}>
        <Row gutter={12}>
          <Col xs={24} md={8}><Form.Item name="tipo" label="Tipo" rules={[{ required: true, message: 'Escolha o tipo de documento.' }]}><Select options={Object.entries(catalogo?.tipos ?? {}).map(([v, l]) => ({ value: v, label: l }))} /></Form.Item></Col>
          <Col xs={24} md={16}><Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Dê um nome ao modelo.' }]}><Input maxLength={200} /></Form.Item></Col>
          <Col xs={24}><Form.Item name="assunto" label="Assunto"><Input maxLength={300} /></Form.Item></Col>
          <Col xs={24}><Form.Item name="corpo" label="Texto" rules={[{ required: true, message: 'O modelo não pode ficar sem texto.' }]}><Input.TextArea ref={area as never} rows={14} className="imo-texto-modelo" /></Form.Item></Col>
        </Row>
        <div className="imo-subtitulo">Variáveis disponíveis — clique para inserir no texto</div>
        <div className="imo-variaveis">
          {(catalogo?.variaveis ?? []).map((v) => <Button key={v.chave} size="small" onClick={() => inserir(v.chave)} title={v.rotulo}>{`{{${v.chave}}}`}</Button>)}
        </div>
        <Form.Item name="activo" valuePropName="checked" style={{ marginTop: 12 }}><Checkbox>Activo</Checkbox></Form.Item>
        <Button onClick={() => ver.mutate()} loading={ver.isPending}>Pré-visualizar</Button>
        {pre && (
          <div style={{ marginTop: 12 }}>
            {pre.invalidas.length > 0 && <Alert type="error" showIcon title={`Variáveis que não existem: ${pre.invalidas.join(', ')}.`} style={{ marginBottom: 8 }} />}
            {pre.faltas.length > 0 && <Alert type="info" showIcon style={{ marginBottom: 8 }} title="Sem dados de exemplo, estas variáveis ficam entre parênteses rectos e são listadas antes de imprimir um documento real:" description={pre.faltas.join(' · ')} />}
            {pre.assunto && <p><b>Assunto:</b> {pre.assunto}</p>}
            <div className="imo-pre">{pre.corpo}</div>
          </div>
        )}
      </Form>
    </Modal>
  );
}

/** Modelos de documentos (imo_modelo_editar): os 8 por omissão podem importar-se; cada alteração do texto sobe a versão. */
export function SeparadorModelos({ podeEditar }: { podeEditar: boolean }) {
  const refrescar = useRefrescar();
  const lista = useQuery({ queryKey: CHAVES.modelos, queryFn: listarModelos });
  const catalogo = useQuery({ queryKey: CHAVES.catalogoModelos, queryFn: obterCatalogoModelos, staleTime: 5 * 60_000 });
  const [editar, setEditar] = useState<ModeloDocumento | null | undefined>(undefined);
  useEffect(() => { if (lista.error) notificarErro(lista.error, 'Não foi possível carregar os modelos'); }, [lista.error]);
  const importar = useMutation({ mutationFn: importarModelosPadrao, onError: (e) => notificarErro(e), onSuccess: async (r) => { message.success(r.mensagem); await refrescar(); } });
  const eliminar = useMutation({ mutationFn: (m: ModeloDocumento) => eliminarModelo(m.id), onError: (e) => notificarErro(e), onSuccess: async () => { message.success('Modelo eliminado.'); await refrescar(); } });
  const tipos = catalogo.data?.tipos ?? {};
  return (
    <>
      <p>{lista.data?.length ?? 0} modelo(s). Uma variável sem valor fica entre parênteses rectos e é listada antes de imprimir.</p>
      {podeEditar && (
        <Space wrap style={{ marginBottom: 12 }}>
          <Button onClick={() => importar.mutate()} loading={importar.isPending}>Importar modelos por omissão</Button>
          <Button type="primary" onClick={() => setEditar(null)}>Novo modelo</Button>
        </Space>
      )}
      <TabelaComModos<ModeloDocumento>
        idVista="imobiliario_modelos" rowKey="id" size="middle" loading={lista.isFetching} dataSource={lista.data ?? []} scroll={{ x: 'max-content' }} impressao={{ titulo: 'Modelos de documentos' }}
        columns={[
          { title: 'Tipo', dataIndex: 'tipo', render: (v: string) => tipos[v] ?? v, valorImpressao: (m: ModeloDocumento) => tipos[m.tipo] ?? m.tipo },
          { title: 'Nome', dataIndex: 'nome', principal: true, ocultavel: false },
          { title: 'Assunto', dataIndex: 'assunto', render: (v: string | null) => v ?? '—' },
          { title: 'Linhas', dataIndex: 'corpo', align: 'right', render: (v: string) => v.split('\n').length, valorImpressao: (m: ModeloDocumento) => String(m.corpo.split('\n').length) },
          { title: 'Versão', dataIndex: 'versao', align: 'right', ocultaPorOmissao: true },
          { title: 'Origem', dataIndex: 'sistema', ocultaPorOmissao: true, render: (v: boolean) => (v ? 'De fábrica' : 'Próprio'), valorImpressao: (m: ModeloDocumento) => (m.sistema ? 'De fábrica' : 'Próprio') },
          { title: 'Estado', dataIndex: 'activo', etiqueta: true, render: (v: boolean) => <Tag color={v ? 'green' : 'default'}>{v ? 'Activo' : 'Inactivo'}</Tag>, valorImpressao: (m: ModeloDocumento) => (m.activo ? 'Activo' : 'Inactivo') },
        ]}
        accoes={(m) => (podeEditar ? [
          { icone: 'edit', titulo: 'Editar', onClick: () => setEditar(m) },
          { icone: 'trash', titulo: 'Eliminar', variante: 'perigo' as const, confirmar: { titulo: 'Eliminar este modelo de documento?', textoOk: 'Eliminar', perigo: true }, onClick: () => eliminar.mutate(m) },
        ] : [])}
        vazio={{ titulo: 'Sem modelos', texto: 'Comece por importar os que vêm por omissão.' }}
      />
      <ModalModelo modelo={editar} catalogo={catalogo.data} aoFechar={() => setEditar(undefined)} />
    </>
  );
}
