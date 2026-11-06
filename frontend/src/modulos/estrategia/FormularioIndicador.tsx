import { DeleteOutlined, ExperimentOutlined, PlusOutlined } from '@ant-design/icons';
import { Alert, Button, Col, Form, Input, InputNumber, Modal, Popconfirm, Row, Select, Space, Switch, message } from 'antd';
import { useMutation } from '@tanstack/react-query';
import { useState } from 'react';
import { larguraModal } from '@/componentes/responsivo';
import { mensagemParaUtilizador, notificarErro } from '@/utilitarios/erros';
import { apagarIndicador, gravarIndicador, testarIndicador, type Catalogos, type DetalhePlano, type FiltroFonte, type Indicador, type ResultadoTeste } from './api';
import { formatarValorIndicador } from './comum';

/** Descreve a origem de um indicador numa linha (como o `descreverFonte` do legado). */
export function descreverFonte(i: Indicador, cat?: Catalogos): string {
  if (i.origem === 'MANUAL') return i.prazo_dias ? `entrega até ${i.prazo_dias} dias após o período` : 'entregue por quem o recolhe';
  if (i.origem === 'MODULO') {
    const f = cat?.fontes_modulo.find((x) => x.id === i.fonte.modulo);
    const op = cat?.operacoes.find((o) => o.id === i.fonte.operacao)?.nome ?? i.fonte.operacao;
    return `${f?.nome ?? i.fonte.modulo ?? 'módulo'} · ${op}`;
  }
  return i.origem_rotulo;
}

type Valores = Record<string, unknown>;

/**
 * Indicador (criar/editar): o selector de origem muda o sub-formulário. Nesta onda medem-se sozinhos os dados de entregáveis e de
 * colaboradores («Dados de um módulo»); as restantes origens do legado aparecem desactivadas até entrarem com o painel.
 * Os filtros da fonte são ids da lista fechada do servidor: o cliente nunca envia nomes de tabela nem de coluna.
 */
export function FormularioIndicador({ detalhe, catalogos, indicador, editavel, aoFechar, pedirMotivo }: {
  detalhe: DetalhePlano; catalogos: Catalogos; indicador?: Indicador; editavel: boolean; aoFechar: () => void; pedirMotivo: (f: (m?: string) => void) => void;
}) {
  const [form] = Form.useForm();
  const origem = (Form.useWatch('origem', form) as string | undefined) ?? indicador?.origem;
  const modulo = (Form.useWatch('modulo', form) as string | undefined) ?? indicador?.fonte.modulo;
  const operacao = (Form.useWatch('operacao', form) as string | undefined) ?? indicador?.fonte.operacao;
  const [filtros, setFiltros] = useState<FiltroFonte[]>(indicador?.fonte.filtros ?? []);
  const [teste, setTeste] = useState<ResultadoTeste | string | null>(null);
  const fonte = catalogos.fontes_modulo.find((f) => f.id === modulo);
  const plano = detalhe.plano;

  const inicial = indicador
    ? { nome: indicador.nome, descricao: indicador.descricao ?? '', objectivo_id: indicador.objectivo_id, tipo: indicador.tipo, origem: indicador.origem, modulo: indicador.fonte.modulo, operacao: indicador.fonte.operacao, campo: indicador.fonte.campo ?? undefined,
      polaridade: indicador.polaridade, frequencia: indicador.frequencia, formato: indicador.formato, agregacao: indicador.agregacao ?? undefined, linha_base: indicador.oculto ? undefined : (indicador.linha_base === null ? undefined : Number(indicador.linha_base)), linha_base_periodo: indicador.linha_base_periodo ?? '',
      tolerancia_verde: Number(indicador.tolerancia_verde), tolerancia_amarela: Number(indicador.tolerancia_amarela), dono_colaborador_id: indicador.dono_colaborador_id ?? undefined, dono_externo: indicador.dono_externo ?? '', prazo_dias: indicador.prazo_dias ?? undefined, ativo: indicador.ativo }
    : { nome: '', descricao: '', tipo: 'RESULTADO', polaridade: 'SOBE', frequencia: 'MENSAL', formato: 'num', tolerancia_verde: 100, tolerancia_amarela: 90, ativo: true };

  const montar = (v: Valores): Valores => {
    const corpo: Valores = {
      nome: v.nome, descricao: v.descricao ?? '', objectivo_id: v.objectivo_id, tipo: v.tipo, origem: v.origem, polaridade: v.polaridade, frequencia: v.frequencia, formato: v.formato, agregacao: v.agregacao ?? null,
      linha_base: v.linha_base ?? null, linha_base_periodo: v.linha_base_periodo || null, tolerancia_verde: v.tolerancia_verde, tolerancia_amarela: v.tolerancia_amarela,
      dono_colaborador_id: v.dono_colaborador_id ?? null, dono_externo: v.dono_externo || null, prazo_dias: v.prazo_dias ?? null, ativo: v.ativo,
    };
    if (v.origem === 'MODULO') corpo.fonte = { modulo: v.modulo, operacao: v.operacao, campo: v.operacao === 'CONTAGEM' ? null : v.campo ?? null, filtros: filtros.filter((f) => f.campo) };
    return corpo;
  };

  const gravar = useMutation({
    mutationFn: (v: { corpo: Valores; motivo?: string }) => gravarIndicador(plano.id, indicador?.id ?? null, { ...v.corpo, motivo_excepcao: v.motivo }),
    onSuccess: () => { message.success('Indicador gravado.'); aoFechar(); },
    onError: (e) => notificarErro(e),
  });
  const apagar = useMutation({
    mutationFn: (motivo?: string) => apagarIndicador(plano.id, indicador!.id, motivo),
    onSuccess: () => { message.success('Indicador apagado.'); aoFechar(); },
    onError: (e) => notificarErro(e),
  });
  const testar = useMutation({
    mutationFn: () => testarIndicador(indicador!.id),
    onSuccess: (r) => setTeste(r),
    onError: (e) => setTeste(mensagemParaUtilizador(e)),
  });

  const mexerFiltro = (i: number, parte: Partial<FiltroFonte>) => setFiltros((fs) => fs.map((f, k) => (k === i ? { ...f, ...parte } : f)));
  const objectivos = detalhe.objectivos.map((o) => ({ value: o.id, label: `${o.nome}${o.horizonte === 'TRIMESTRE' ? ' (OKR)' : ''}` }));
  const opcoes = (l: { id: string; nome: string }[]) => l.map((o) => ({ value: o.id, label: o.nome }));

  return (
    <Modal open title={indicador ? 'Editar indicador' : 'Novo indicador'} width={larguraModal(760)} onCancel={aoFechar} destroyOnHidden confirmLoading={gravar.isPending} okText="Gravar" cancelText="Cancelar"
      onOk={() => form.validateFields().then((v) => pedirMotivo((m) => gravar.mutate({ corpo: montar(v), motivo: m }))).catch(() => undefined)}
      footer={(_, { OkBtn, CancelBtn }) => (
        <Space wrap style={{ justifyContent: 'flex-end' }}>
          {indicador && editavel && <Popconfirm title="Apagar este indicador?" okText="Apagar" cancelText="Cancelar" onConfirm={() => pedirMotivo((m) => apagar.mutate(m))}><Button danger icon={<DeleteOutlined />}>Apagar</Button></Popconfirm>}
          {indicador && indicador.origem !== 'MANUAL' && <Button icon={<ExperimentOutlined />} loading={testar.isPending} onClick={() => testar.mutate()}>Testar medição</Button>}
          <CancelBtn />{editavel && <OkBtn />}
        </Space>
      )}>
      <Form form={form} layout="vertical" disabled={!editavel} initialValues={inicial}>
        <Form.Item name="nome" label="Indicador" rules={[{ required: true, whitespace: true, message: 'Dê um nome ao indicador.' }]}><Input maxLength={200} /></Form.Item>
        <Row gutter={12}>
          <Col xs={24} md={14}><Form.Item name="objectivo_id" label="Serve o objectivo" rules={[{ required: true, message: 'Um indicador tem de servir um objectivo.' }]}><Select showSearch optionFilterProp="label" options={objectivos} /></Form.Item></Col>
          <Col xs={24} md={10}><Form.Item name="tipo" label="Tipo" extra="Os indutores avisam a tempo; os de resultado só contam a história depois."><Select options={opcoes(catalogos.tipos)} /></Form.Item></Col>
        </Row>
        <Form.Item name="origem" label="De onde vem o valor" extra="Sem origem o indicador não é medido — e o plano não pode ser aprovado." rules={[{ required: true, message: 'Escolha de onde vem o valor do indicador.' }]}>
          <Select options={catalogos.origens.map((o) => ({ value: o.id, label: o.disponivel ? o.nome : `${o.nome} (em breve)`, disabled: !o.disponivel }))} />
        </Form.Item>

        {origem === 'MANUAL' && <Alert type="info" showIcon style={{ marginBottom: 12 }} title="O valor é escrito, no fim de cada período, por quem for indicado como dono. Enquanto não for entregue, o indicador aparece como «por medir»." />}
        {origem === 'MODULO' && (
          <div style={{ marginBottom: 12 }}>
            <Row gutter={12}>
              <Col xs={24} md={10}><Form.Item name="modulo" label="Dados de" rules={[{ required: true, message: 'Escolha de que dados do módulo vem o valor.' }]}><Select options={catalogos.fontes_modulo.map((f) => ({ value: f.id, label: f.nome }))} onChange={() => { form.setFieldValue('campo', undefined); setFiltros([]); }} /></Form.Item></Col>
              <Col xs={24} md={7}><Form.Item name="operacao" label="Operação" rules={[{ required: true, message: 'Escolha a operação: contar, somar ou calcular a média.' }]}><Select options={opcoes(catalogos.operacoes)} /></Form.Item></Col>
              <Col xs={24} md={7}>
                <Form.Item name="campo" label="Campo" rules={[{ required: operacao !== 'CONTAGEM' && operacao !== undefined && (fonte?.campos.length ?? 0) > 0, message: 'Escolha o campo.' }]}>
                  <Select disabled={operacao === 'CONTAGEM' || !fonte || fonte.campos.length === 0} placeholder={fonte && fonte.campos.length === 0 ? '— sem campos numéricos —' : undefined} options={(fonte?.campos ?? []).map((c) => ({ value: c.id, label: c.nome }))} />
                </Form.Item>
              </Col>
            </Row>
            {filtros.map((f, i) => {
              const def = fonte?.filtros.find((x) => x.id === f.campo);
              const semValor = f.operador === 'PREENCHIDO' || f.operador === 'VAZIO';
              return (
                <Row key={i} gutter={8} style={{ marginBottom: 6 }}>
                  <Col xs={24} sm={8}><Select aria-label={`Campo do filtro ${i + 1}`} style={{ width: '100%' }} value={f.campo || undefined} placeholder="Campo" onChange={(c) => mexerFiltro(i, { campo: c, valor: null })} options={(fonte?.filtros ?? []).map((x) => ({ value: x.id, label: x.nome }))} /></Col>
                  <Col xs={12} sm={6}><Select aria-label={`Operador do filtro ${i + 1}`} style={{ width: '100%' }} value={f.operador || undefined} placeholder="Operador" onChange={(o) => mexerFiltro(i, { operador: o })} options={opcoes(catalogos.operadores)} /></Col>
                  <Col xs={12} sm={8}>
                    {def?.opcoes && !semValor
                      ? <Select aria-label={`Valor do filtro ${i + 1}`} style={{ width: '100%' }} value={f.valor ?? undefined} onChange={(v) => mexerFiltro(i, { valor: v })} options={def.opcoes.map((o) => ({ value: o, label: o }))} />
                      : <Input aria-label={`Valor do filtro ${i + 1}`} disabled={semValor} value={f.valor ?? ''} maxLength={100} onChange={(e) => mexerFiltro(i, { valor: e.target.value })} />}
                  </Col>
                  <Col xs={24} sm={2}><Button danger icon={<DeleteOutlined />} aria-label={`Retirar o filtro ${i + 1}`} onClick={() => setFiltros((fs) => fs.filter((_x, k) => k !== i))} /></Col>
                </Row>
              );
            })}
            <Button size="small" icon={<PlusOutlined />} disabled={!fonte} onClick={() => setFiltros((fs) => [...fs, { campo: '', operador: 'IGUAL', valor: '' }])}>Filtro</Button>
          </div>
        )}

        <Row gutter={12}>
          <Col xs={24} sm={8}><Form.Item name="polaridade" label="Polaridade"><Select options={opcoes(catalogos.polaridades)} /></Form.Item></Col>
          <Col xs={12} sm={8}><Form.Item name="frequencia" label="Frequência" extra={indicador && (indicador.metas_count || indicador.medicoes_count) ? 'Já tem metas ou medições: não se muda.' : undefined}><Select disabled={!!indicador && !!(indicador.metas_count || indicador.medicoes_count)} options={opcoes(catalogos.frequencias)} /></Form.Item></Col>
          <Col xs={12} sm={8}><Form.Item name="formato" label="Formato"><Select options={opcoes(catalogos.formatos)} /></Form.Item></Col>
        </Row>
        <Row gutter={12}>
          <Col xs={12} sm={6}><Form.Item name="linha_base" label="Linha de base" extra="Onde estamos hoje."><InputNumber style={{ width: '100%' }} stringMode disabled={indicador?.oculto} placeholder={indicador?.oculto ? 'Reservado' : undefined} /></Form.Item></Col>
          <Col xs={12} sm={6}><Form.Item name="linha_base_periodo" label="Período da base"><Input maxLength={8} placeholder="2026" /></Form.Item></Col>
          <Col xs={12} sm={6}><Form.Item name="tolerancia_verde" label="Verde a partir de (%)"><InputNumber min={0.01} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={12} sm={6}>
            <Form.Item name="tolerancia_amarela" label="Amarelo a partir de (%)" dependencies={['tolerancia_verde']}
              rules={[({ getFieldValue }) => ({ validator: (_r, v) => (v > 0 && v <= getFieldValue('tolerancia_verde') ? Promise.resolve() : Promise.reject(new Error('As tolerâncias têm de ser positivas e a amarela menor ou igual à verde.'))) })]}>
              <InputNumber min={0.01} style={{ width: '100%' }} />
            </Form.Item>
          </Col>
        </Row>
        <Form.Item name="agregacao" label="No desdobramento, os contributos das unidades" extra="Kwanzas e contagens somam-se; percentagens e notas fazem média."><Select allowClear placeholder="Pelo formato" options={opcoes(catalogos.agregacoes)} /></Form.Item>
        <Row gutter={12}>
          <Col xs={24} sm={10}>
            <Form.Item name="dono_colaborador_id" label="Dono" dependencies={['origem', 'dono_externo']}
              rules={[({ getFieldValue }) => ({ validator: (_r, v) => (getFieldValue('origem') !== 'MANUAL' || v || getFieldValue('dono_externo') ? Promise.resolve() : Promise.reject(new Error('Um indicador de recolha manual precisa de dono: quem o entrega.'))) })]}>
              <Select allowClear showSearch optionFilterProp="label" placeholder="— escolha —" options={catalogos.colaboradores.map((c) => ({ value: c.id, label: c.nome }))} />
            </Form.Item>
          </Col>
          <Col xs={24} sm={8}><Form.Item name="dono_externo" label="Dono externo (se não for colaborador)"><Input maxLength={150} /></Form.Item></Col>
          <Col xs={24} sm={6}><Form.Item name="prazo_dias" label="Prazo de entrega (dias)"><InputNumber min={0} max={120} style={{ width: '100%' }} /></Form.Item></Col>
        </Row>
        <Form.Item name="descricao" label="Descrição / fórmula em palavras"><Input.TextArea rows={2} maxLength={5000} /></Form.Item>
        <Form.Item name="ativo" label="Activo" valuePropName="checked"><Switch /></Form.Item>
      </Form>
      {teste && (
        <Alert type={typeof teste === 'string' ? 'warning' : 'success'} showIcon role="status"
          title={typeof teste === 'string' ? `Não deu para medir: ${teste}` : `${teste.rotulo}: ${formatarValorIndicador(teste.valor, form.getFieldValue('formato') ?? 'num', teste.oculto)}${teste.detalhe ? ` · ${teste.detalhe}` : ''}`} />
      )}
    </Modal>
  );
}
