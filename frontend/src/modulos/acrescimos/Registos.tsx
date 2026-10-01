import { Button, Card, Flex, Form, Input, InputNumber, Modal, Select, Tag, Typography } from 'antd';
import { PlusOutlined, SettingOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import { useEffect, useState } from 'react';
import { Route, Routes, useNavigate } from 'react-router-dom';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { TabelaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { SeletorDiario } from '@/modulos/contab/comum/Seletores';
import { ValorKz } from '@/modulos/contab/comum/Componentes';
import { SeletorConta } from '@/modulos/compras/comum/Seletores';
import { useAccao } from '@/modulos/compras/comum/accoes';
import { formatarData } from '@/utilitarios/formatacao';
import { EtiquetaAD, useDefinicoes } from './comum/componentes';
import type { ItemAD } from './comum/tipos';
import { DetalheItem } from './DetalheItem';
import { ModalItem } from './ModalItem';

/** Acréscimos e diferimentos › Registos (ecrã ad_registos): itens, plano de quotas, regularizar, terminar e definições do módulo. */
export default function Registos() {
  return (
    <Routes>
      <Route index element={<Lista />} />
      <Route path=":id" element={<DetalheItem />} />
    </Routes>
  );
}

function Lista() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const def = useDefinicoes();
  const [tipo, setTipo] = useState<string>();
  const [estado, setEstado] = useState('ABERTOS');
  const [texto, setTexto] = useState('');
  const [novo, setNovo] = useState(false);
  const [definicoes, setDefinicoes] = useState(false);

  const colunas: ColumnsType<ItemAD> = [
    { title: 'N.º', dataIndex: 'id', render: (v) => <strong>#{v}</strong> },
    { title: 'Tipo', dataIndex: 'tipo', render: (v) => <EtiquetaAD valor={v} /> },
    { title: 'Natureza', dataIndex: 'natureza', render: (v) => <EtiquetaAD valor={v} /> },
    { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 300 },
    { title: 'Terceiro', key: 't', render: (_, r) => r.terceiro?.nome?.trim() ?? '—' },
    { title: 'Período', key: 'p', render: (_, r) => `${formatarData(r.data_inicio)} → ${formatarData(r.data_fim)}` },
    { title: 'Contas', key: 'c', render: (_, r) => `${r.conta_resultado} / ${r.conta_balanco}` },
    { title: 'Valor (Kz)', dataIndex: 'valor', align: 'right', render: (v) => <ValorKz valor={v} forte /> },
    { title: 'Reconhecido', dataIndex: 'reconhecido', align: 'right', render: (v) => <ValorKz valor={v} discretoSeZero /> },
    { title: 'Estado', key: 'e', render: (_, r) => <><EtiquetaAD valor={r.estado} />{r.sem_documento && <Tag color="red">Sem documento</Tag>}</> },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo="Acréscimos e diferimentos"
        subtitulo="Registos, plano de reconhecimento mensal, regularização e término"
        accoes={
          <>
            {pode('ad_definicoes_edit') && <Button icon={<SettingOutlined />} onClick={() => setDefinicoes(true)}>Definições</Button>}
            {pode('ad_editar') && <Button type="primary" icon={<PlusOutlined />} onClick={() => setNovo(true)}>Novo registo</Button>}
          </>
        }
      />
      <Card>
        <Flex gap={8} wrap style={{ marginBottom: 16 }}>
          <Input.Search placeholder="Descrição, conta, documento, terceiro" allowClear onSearch={setTexto} style={{ width: 300 }} />
          <Select placeholder="Tipo" allowClear value={tipo} onChange={setTipo} style={{ width: 150 }} options={[{ value: 'ACRESCIMO', label: 'Acréscimos' }, { value: 'DIFERIMENTO', label: 'Diferimentos' }]} />
          <Select value={estado} onChange={setEstado} style={{ width: 240 }}
            options={[{ value: 'ABERTOS', label: 'Em aberto' }, { value: 'TODOS', label: 'Todos' }, ...Object.entries(def.data?.estados ?? {}).map(([value, label]) => ({ value, label }))]} />
        </Flex>
        <TabelaApi<ItemAD>
          url="/acrescimos/itens"
          chaveConsulta={['acrescimos', 'itens']}
          filtros={{ tipo, estado, texto }}
          columns={colunas}
          onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })}
        />
      </Card>
      <ModalItem aberto={novo} aoFechar={() => setNovo(false)} aoGravar={(it) => navegar(String(it.id))} />
      <ModalDefinicoes aberto={definicoes} aoFechar={() => setDefinicoes(false)} />
    </>
  );
}

/** Contas 37 por tipo/natureza, diário do módulo e prazo para o documento real dos acréscimos. */
function ModalDefinicoes({ aberto, aoFechar }: { aberto: boolean; aoFechar: () => void }) {
  const def = useDefinicoes();
  const [form] = Form.useForm();
  const accao = useAccao({ invalidar: [['acrescimos']], aoSucesso: () => aoFechar() });
  useEffect(() => {
    if (aberto && def.data) form.setFieldsValue({ contas: def.data.contas, diario_id: def.data.diario_id ?? undefined, prazo_documento_dias: def.data.prazo_documento_dias });
  }, [aberto, def.data, form]);
  return (
    <Modal title="Definições de acréscimos e diferimentos" open={aberto} onCancel={aoFechar} onOk={() => form.submit()} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} width={640} destroyOnClose>
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ metodo: 'put', url: '/acrescimos/definicoes', dados: { ...v, diario_id: v.diario_id ?? null } })}>
        {Object.entries(def.data?.rotulos_contas ?? {}).map(([chave, rotulo]) => (
          <Form.Item key={chave} name={['contas', chave]} label={`${rotulo} (${chave.replace('_', ' / ').toLowerCase()})`}>
            <SeletorConta prefixo="37" />
          </Form.Item>
        ))}
        <Flex gap={12} wrap>
          <Form.Item name="diario_id" label="Diário dos lançamentos"><SeletorDiario allowClear style={{ width: 280 }} /></Form.Item>
          <Form.Item name="prazo_documento_dias" label="Prazo do documento real (dias)"><InputNumber min={0} max={3650} style={{ width: 160 }} /></Form.Item>
        </Flex>
        <Typography.Text type="secondary">O prazo define a data limite dos acréscimos sem data indicada (fim do período + prazo).</Typography.Text>
      </Form>
    </Modal>
  );
}
