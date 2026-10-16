import { Alert, Button, Col, Form, InputNumber, Radio, Row, Select, Space, Typography, message } from 'antd';
import { MinusCircleOutlined, PlusOutlined } from '@ant-design/icons';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { notificarErro } from '@/utilitarios/erros';
import { formatarKz } from '@/utilitarios/formatacao';
import type { ItemProdutividade } from '../api';
import { useColaboradores } from './consultas';
import { SeletorColaborador } from './componentes';

export type ModoProdutividadeMassa = 'ACRESCENTAR' | 'SUBSTITUIR' | 'RETIRAR';

interface Valores {
  colaboradores?: number[];
  itens: { item_id: number; preco_unitario?: number | null }[];
  modo: ModoProdutividadeMassa;
  preco_existente: 'SUBSTITUIR' | 'MANTER';
}

export interface ResultadoProdutividadeMassa { actualizados: string[]; sem_alteracao: string[]; inactivos: string[]; sem_contrato: string[]; simulacao: boolean }

/** Texto do resultado (simulação ou aplicação) da produtividade em massa. */
export function resumoProdutividadeMassa(r: ResultadoProdutividadeMassa): string {
  return `${r.actualizados.length} contrato(s) a alterar · ${r.sem_alteracao.length} já assim`
    + `${r.inactivos.length ? ` · ${r.inactivos.length} inactivo(s) ignorado(s)` : ''}${r.sem_contrato.length ? ` · ${r.sem_contrato.length} sem contrato ignorado(s)` : ''}.`;
}

/**
 * #13/#26 — Contratos › aplicar itens de produtividade em massa (contratosProdutividadeEmMassa, js/modules/rh/contratos_produtividade.js):
 * um ou mais itens, com o preço do item ou um preço próprio, aplicados aos contratos dos colaboradores escolhidos (ou aos
 * contratos marcados na lista). Acrescentar (substituindo ou mantendo o preço dos que já lá estão), substituir a lista ou
 * retirar. Contratos inactivos/cessados e colaboradores sem contrato ficam de fora. Primeiro simula; depois aplica.
 */
export function ProdutividadeMassa({ contratos = [], aoConcluir }: { contratos?: number[]; aoConcluir?: () => void }) {
  const cliente = useQueryClient();
  const colaboradores = useColaboradores();
  const itens = useQuery({ queryKey: ['rh', 'produtividade', 'itens'], queryFn: () => obter<ItemProdutividade[]>('/rh/produtividade/itens'), staleTime: 300_000 });
  const activos = (itens.data ?? []).filter((i) => i.ativo);
  const [form] = Form.useForm<Valores>();
  const modo = Form.useWatch('modo', form);
  const [plano, setPlano] = useState<ResultadoProdutividadeMassa | null>(null);
  const [aEnviar, setAEnviar] = useState(false);

  const executar = async (simular: boolean) => {
    const v = await form.validateFields();
    if (!contratos.length && !(v.colaboradores ?? []).length) {
      message.warning('Escolha os colaboradores (ou marque os contratos na lista).');
      return;
    }
    setAEnviar(true);
    try {
      const { dados, mensagem } = await enviar<ResultadoProdutividadeMassa>('post', '/rh/contratos/produtividade-massa', {
        contratos, colaboradores: v.colaboradores ?? [], modo: v.modo, preco_existente: v.preco_existente, simular,
        itens: v.itens.map((i) => ({ item_id: i.item_id, preco_unitario: v.modo === 'RETIRAR' ? null : i.preco_unitario ?? null })),
      });
      setPlano(dados);
      if (!simular) {
        message.success(mensagem);
        void cliente.invalidateQueries({ queryKey: ['rh'] });
        aoConcluir?.();
      }
    } catch (e) {
      notificarErro(e);
    } finally {
      setAEnviar(false);
    }
  };

  if (!itens.isLoading && !activos.length) {
    return <Alert type="warning" showIcon message="Ainda não há itens de produtividade activos." description="Configure-os em RH › Produtividade › Itens." />;
  }
  return (
    <>
      <Typography.Paragraph type="secondary">
        Só os colaboradores com itens no contrato podem ter subsídio de produtividade. Deixe o preço em branco para usar o preço do item.
        {contratos.length > 0 && <> Partimos dos <b>{contratos.length}</b> contrato(s) marcados na lista.</>}
      </Typography.Paragraph>
      <Form form={form} layout="vertical" initialValues={{ modo: 'ACRESCENTAR', preco_existente: 'SUBSTITUIR', itens: [{}] }} onValuesChange={() => setPlano(null)}>
        <Form.List name="itens">{(campos, { add, remove }) => (
          <>
            {campos.map(({ key, name }) => (
              <Row key={key} gutter={8} align="middle">
                <Col xs={24} sm={14}>
                  <Form.Item name={[name, 'item_id']} rules={[{ required: true, message: 'Item.' }]}>
                    <Select showSearch optionFilterProp="label" placeholder="Item de produtividade" loading={itens.isLoading}
                      options={activos.map((i) => ({ value: i.id, label: `${i.codigo} — ${i.descricao} (${formatarKz(Number(i.preco_unitario))}${i.unidade ? `/${i.unidade}` : ''})` }))} />
                  </Form.Item>
                </Col>
                <Col xs={20} sm={8}>
                  <Form.Item name={[name, 'preco_unitario']}>
                    <InputNumber min={0} precision={4} decimalSeparator="," placeholder="igual ao item" disabled={modo === 'RETIRAR'} style={{ width: '100%' }} />
                  </Form.Item>
                </Col>
                <Col xs={4} sm={2}>{campos.length > 1 && <Button type="text" danger icon={<MinusCircleOutlined />} aria-label="Retirar item" onClick={() => remove(name)} />}</Col>
              </Row>
            ))}
            <Button size="small" icon={<PlusOutlined />} onClick={() => add({})} style={{ marginBottom: 12 }}>Acrescentar item</Button>
          </>
        )}</Form.List>
        <Form.Item name="modo" label="O que fazer nos contratos escolhidos">
          <Radio.Group style={{ display: 'flex', flexDirection: 'column', gap: 4 }} options={[
            { value: 'ACRESCENTAR', label: 'Acrescentar estes itens, mantendo os que já lá estão' },
            { value: 'SUBSTITUIR', label: 'Substituir a lista: ficam só estes itens' },
            { value: 'RETIRAR', label: 'Retirar estes itens dos contratos' },
          ]} />
        </Form.Item>
        {modo === 'ACRESCENTAR' && (
          <Form.Item name="preco_existente" label="Se o item já estiver no contrato">
            <Radio.Group options={[{ value: 'SUBSTITUIR', label: 'Substituir o preço pelo indicado' }, { value: 'MANTER', label: 'Manter o preço actual do contrato' }]} />
          </Form.Item>
        )}
        <Form.Item name="colaboradores" label={contratos.length ? 'Mais colaboradores (opcional)' : 'Colaboradores'}>
          <SeletorColaborador mode="multiple" apenasActivos maxTagCount="responsive" style={{ width: '100%' }} />
        </Form.Item>
        <Button size="small" style={{ marginTop: -12, marginBottom: 12 }}
          onClick={() => form.setFieldsValue({ colaboradores: colaboradores.lista.filter((c) => c.estado === 'ACTIVO').map((c) => c.id) as never })}>Todos os activos</Button>
      </Form>
      {plano && (
        <Alert type={plano.simulacao ? 'info' : 'success'} showIcon style={{ marginBottom: 12 }} message={plano.simulacao ? 'Simulação (nada foi gravado)' : 'Aplicado'}
          description={<>
            {resumoProdutividadeMassa(plano)}
            {plano.actualizados.length > 0 && <div style={{ fontSize: 12, marginTop: 4 }}>A alterar: {plano.actualizados.slice(0, 15).join(', ')}{plano.actualizados.length > 15 ? '…' : ''}</div>}
          </>} />
      )}
      <Space wrap>
        <Button loading={aEnviar} onClick={() => void executar(true)}>Simular</Button>
        <Button type="primary" danger={modo !== 'ACRESCENTAR'} loading={aEnviar} disabled={!plano?.simulacao || !plano.actualizados.length} onClick={() => void executar(false)}>Aplicar</Button>
      </Space>
    </>
  );
}
