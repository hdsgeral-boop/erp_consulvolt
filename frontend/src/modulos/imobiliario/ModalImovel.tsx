import { DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import { AutoComplete, Button, Col, DatePicker, Form, Input, InputNumber, Modal, Row, Select, Space } from 'antd';
import { useMutation, useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useEffect, useMemo } from 'react';
import { larguraModal } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { gravarImovel, listarEdificios, listarTerceiros, type DocumentoLigado, type Imovel, type Listas } from './api';
import { useRefrescar } from './hooks';

type Valores = Record<string, unknown> & { documentos?: DocumentoLigado[] };

const DATAS = ['valor_patrimonial_data', 'data_aquisicao'] as const;

/** Converte o imóvel (datas ISO) para os valores do formulário e vice-versa (datas para texto, vazios para null). */
const paraForm = (i: Imovel): Valores => ({ ...i, ...Object.fromEntries(DATAS.map((d) => [d, i[d] ? dayjs(i[d] as string) : null])) });
export function dadosImovel(v: Valores): Record<string, unknown> {
  const saida: Record<string, unknown> = {};
  for (const [k, x] of Object.entries(v)) {
    if ((DATAS as readonly string[]).includes(k)) saida[k] = x ? dayjs(x as string).format('YYYY-MM-DD') : null;
    else saida[k] = x === undefined || x === '' ? null : x;
  }
  return saida;
}

/** Criar e editar um imóvel (imo_imovel_editar). As regras de negócio são do servidor; aqui só se esconde o que não se aplica ao tipo e ao papel. */
export function ModalImovel({ imovel, listas, aoFechar }: { imovel: Imovel | null | undefined; listas: Listas | undefined; aoFechar: (gravado?: Imovel) => void }) {
  const [form] = Form.useForm<Valores>();
  const aberto = imovel !== undefined;
  const refrescar = useRefrescar();
  const papel = (Form.useWatch('papel', form) as string | undefined) ?? 'PROPRIO';
  const tipo = (Form.useWatch('tipo', form) as string | undefined) ?? 'PREDIO';
  const tipoDef = listas?.tipos_imovel.find((t) => t.valor === tipo);
  const papelDef = listas?.papeis.find((p) => p.valor === papel);
  const edificios = useQuery({ queryKey: ['imobiliario', 'edificios'], queryFn: listarEdificios, enabled: aberto });
  const terceiros = useQuery({ queryKey: ['imobiliario', 'terceiros'], queryFn: () => listarTerceiros(), enabled: aberto });

  useEffect(() => {
    if (!aberto) return;
    form.resetFields();
    form.setFieldsValue(imovel ? paraForm(imovel) : { tipo: 'PREDIO', papel: 'PROPRIO', estado: 'ACTIVO', isencao_detencao: 'NAO', documentos: [] });
  }, [aberto, imovel, form]);

  const gravar = useMutation({
    mutationFn: (dados: Record<string, unknown>) => gravarImovel(dados as Partial<Imovel>, imovel?.id),
    onError: (e) => notificarErro(e, 'Não foi possível gravar o imóvel'),
    onSuccess: async (i) => { await refrescar(); aoFechar(i); },
  });

  const submeter = () => {
    // antd 6: o onFinish só devolve subcampos de Form.List com Form.Item; lê-se a lista directamente do formulário
    const v = { ...form.getFieldsValue(true), documentos: (form.getFieldValue('documentos') as DocumentoLigado[] | undefined) ?? [] } as Valores;
    gravar.mutate(dadosImovel(v));
  };

  // só os edifícios do tipo de propriedade certo (arrendado: alheios; próprio: próprios); os já ligados a outro imóvel ficam desactivados
  const edificiosPossiveis = useMemo(() => (edificios.data ?? []).filter((e) => (papel === 'ARRENDADO' ? e.propriedade === 'ALHEIO' : e.propriedade !== 'ALHEIO')), [edificios.data, papel]);

  return (
    <Modal title={imovel ? `Editar imóvel «${imovel.codigo}»` : 'Novo imóvel'} open={aberto} onCancel={() => aoFechar()} onOk={() => form.submit()} okText="Gravar" cancelText="Cancelar"
      confirmLoading={gravar.isPending} width={larguraModal(860)} destroyOnHidden>
      <Form form={form} layout="vertical" onFinish={submeter} disabled={gravar.isPending}>
        <Row gutter={12}>
          <Col xs={24} md={6}><Form.Item name="codigo" label="Código" extra={imovel ? undefined : 'Em branco: o sistema atribui PR0001, PR0002…'}><Input maxLength={20} placeholder="Automático" /></Form.Item></Col>
          <Col xs={24} md={18}><Form.Item name="nome" label="Designação do imóvel" rules={[{ required: true, message: 'Dê uma designação ao imóvel.' }]}><Input maxLength={200} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="tipo" label="Tipo"><Select options={(listas?.tipos_imovel ?? []).map((o) => ({ value: o.valor, label: o.rotulo }))} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="papel" label="Papel da empresa"><Select options={(listas?.papeis ?? []).map((o) => ({ value: o.valor, label: o.rotulo }))} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="estado" label="Estado"><Select options={(listas?.estados_imovel ?? []).map((o) => ({ value: o.valor, label: o.rotulo }))} /></Form.Item></Col>
          {papelDef?.exige_proprietario && (
            <Col xs={24} md={12}>
              <Form.Item name="proprietario_terceiro_id" label="Proprietário (terceiro)" rules={[{ required: true, message: 'Escolha o proprietário.' }]}>
                <Select showSearch optionFilterProp="label" loading={terceiros.isLoading} placeholder="— proprietário —"
                  options={(terceiros.data ?? []).map((t) => ({ value: t.id, label: `${t.nome}${t.nif ? ` · ${t.nif}` : ''}` }))} />
              </Form.Item>
            </Col>
          )}
          {papel !== 'MEDIACAO' && (
            <Col xs={24} md={12}>
              <Form.Item name="ativo_edificio_id" label="Edifício do imobilizado" extra="Liga à planta, inventário e manutenção do módulo Activos.">
                <Select allowClear showSearch optionFilterProp="label" loading={edificios.isLoading} placeholder="— sem ligação —"
                  options={edificiosPossiveis.map((e) => ({ value: e.id, label: `${e.codigo ? `${e.codigo} · ` : ''}${e.descricao ?? 'Edifício'}${e.ligado_ao_imovel && e.id !== imovel?.ativo_edificio_id ? ` (já ligado a ${e.ligado_ao_imovel})` : ''}`, disabled: !!e.ligado_ao_imovel && e.id !== imovel?.ativo_edificio_id }))} />
              </Form.Item>
            </Col>
          )}
          {tipoDef?.pisos && (
            <>
              <Col xs={12} md={6}><Form.Item name="n_pisos" label="N.º de pisos"><InputNumber min={0} max={300} precision={0} style={{ width: '100%' }} /></Form.Item></Col>
              <Col xs={12} md={6}><Form.Item name="piso_inicial" label="Piso inicial" extra="0 = R/C; -1 = cave"><InputNumber min={-20} max={300} precision={0} style={{ width: '100%' }} /></Form.Item></Col>
            </>
          )}
          {tipoDef?.habitacoes && (
            <Col xs={24} md={6}><Form.Item name="n_habitacoes" label="N.º de habitações" rules={[{ required: true, message: 'Indique o número de habitações.' }]}><InputNumber min={0} precision={0} style={{ width: '100%' }} /></Form.Item></Col>
          )}
          <Col xs={24}><Form.Item name="morada" label="Morada"><Input maxLength={300} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="municipio" label="Município"><Input maxLength={100} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="provincia" label="Província"><AutoComplete options={(listas?.provincias ?? []).map((p) => ({ value: p }))} filterOption={(t, o) => (o?.value ?? '').toLowerCase().includes(t.toLowerCase())} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="coordenadas" label="Coordenadas"><Input maxLength={60} placeholder="-8.8383, 13.2344" /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="matriz_predial" label="Artigo matricial"><Input maxLength={60} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="registo_predial" label="Registo predial"><Input maxLength={120} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="licenca_utilizacao" label="Licença de utilização"><Input maxLength={120} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="area_terreno" label="Área do terreno (m²)"><InputNumber min={0} precision={2} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="area_bruta" label="Área bruta (m²)"><InputNumber min={0} precision={2} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="area_util" label="Área útil (m²)"><InputNumber min={0} precision={2} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="valor_patrimonial" label="Valor patrimonial (Kz)" extra="Base do Imposto Predial da detenção; nunca é calculado."><InputNumber min={0} precision={2} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="valor_patrimonial_data" label="Data do valor patrimonial"><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="isencao_detencao" label="Isenção da detenção"><Select options={(listas?.isencoes_detencao ?? []).map((o) => ({ value: o.valor, label: o.rotulo }))} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="valor_aquisicao" label="Valor de aquisição (Kz)"><InputNumber min={0} precision={2} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="data_aquisicao" label="Data de aquisição"><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24}><Form.Item name="notas" label="Notas"><Input.TextArea rows={2} maxLength={5000} /></Form.Item></Col>
        </Row>
        <Form.List name="documentos">
          {(campos, { add, remove }) => (
            <>
              <div className="imo-subtitulo">Documentos (ligações)</div>
              {campos.map((c, i) => (
                <Row gutter={8} key={c.key} align="bottom">
                  <Col xs={24} md={5}><Form.Item name={[c.name, 'tipo']} label={i === 0 ? 'Tipo' : undefined}><Select placeholder="Tipo" options={(listas?.tipos_documento ?? []).map((o) => ({ value: o.valor, label: o.rotulo }))} /></Form.Item></Col>
                  <Col xs={24} md={6}><Form.Item name={[c.name, 'nome']} label={i === 0 ? 'Nome' : undefined}><Input maxLength={200} placeholder="Nome" /></Form.Item></Col>
                  <Col xs={24} md={8}><Form.Item name={[c.name, 'url']} label={i === 0 ? 'Endereço (http/https)' : undefined}><Input maxLength={1000} placeholder="https://…" /></Form.Item></Col>
                  <Col xs={20} md={4}><Form.Item name={[c.name, 'data']} label={i === 0 ? 'Data' : undefined}><Input maxLength={10} placeholder="AAAA-MM-DD" /></Form.Item></Col>
                  <Col xs={4} md={1}><Form.Item><Button danger icon={<DeleteOutlined />} aria-label={`Remover o documento ${i + 1}`} onClick={() => remove(c.name)} /></Form.Item></Col>
                </Row>
              ))}
              <Space><Button icon={<PlusOutlined />} onClick={() => add({ tipo: 'OUTRO', nome: '', url: '', data: '' })}>Documento</Button></Space>
            </>
          )}
        </Form.List>
      </Form>
    </Modal>
  );
}
