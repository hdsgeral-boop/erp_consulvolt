import { Alert, Button, Card, Col, Flex, Form, Input, InputNumber, Modal, Row, Select, Statistic, Tag, Typography } from 'antd';
import { PlusOutlined, SettingOutlined } from '@ant-design/icons';
import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { obterPagina } from '@/api/cliente';
import { formatarKz } from '@/utilitarios/formatacao';
import { indicadoresAD } from './comum/regras';
import { Route, Routes, useNavigate } from 'react-router-dom';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
import { BarraFiltros, larguraModal } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { SeletorDiario } from '@/modulos/contab/comum/Seletores';
import { ValorKz } from '@/modulos/contab/comum/Componentes';
import { SeletorConta } from '@/modulos/compras/comum/Seletores';
import { useAccao } from '@/componentes/Accoes';
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

  // cartões do legado (ad_ui.js): acréscimos em curso (já reconhecido) e diferimentos em curso (por reconhecer)
  const emCurso = useQuery({ queryKey: ['acrescimos', 'itens', 'indicadores'], queryFn: () => obterPagina<ItemAD>('/acrescimos/itens', { estado: 'ABERTOS', por_pagina: 500 }) });
  const ind = indicadoresAD(emCurso.data?.itens ?? []);
  const semDefinicoes = !!def.data && (!def.data.diario_id || Object.values(def.data.contas ?? {}).some((c) => !c));

  const colunas: ColunaApi<ItemAD>[] = [
    { title: 'N.º', dataIndex: 'id', render: (v) => <strong>#{v}</strong> },
    { title: 'Tipo', dataIndex: 'tipo', render: (v) => <EtiquetaAD valor={v} /> },
    { title: 'Natureza', dataIndex: 'natureza', render: (v) => <EtiquetaAD valor={v} />, responsive: ['md'] },
    { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 300 },
    { title: 'Terceiro', key: 't', responsive: ['lg'], render: (_, r) => r.terceiro?.nome?.trim() ?? '—' },
    { title: 'Período', key: 'p', responsive: ['md'], render: (_, r) => `${formatarData(r.data_inicio)} → ${formatarData(r.data_fim)}` },
    { title: 'Contas', key: 'c', responsive: ['lg'], render: (_, r) => `${r.conta_resultado} / ${r.conta_balanco}` },
    { title: 'Valor (Kz)', dataIndex: 'valor', align: 'right', render: (v) => <ValorKz valor={v} forte /> },
    { title: 'Reconhecido', dataIndex: 'reconhecido', align: 'right', render: (v) => <ValorKz valor={v} discretoSeZero />, responsive: ['md'] },
    { title: 'Estado', key: 'e', render: (_, r) => <><EtiquetaAD valor={r.estado} />{r.sem_documento && <Tag color="red">Sem documento</Tag>}</> },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo="Acréscimos e Diferimentos"
        subtitulo={
          <>
            Acréscimos antecipam gastos ou rendimentos do período cujo pagamento/recebimento é futuro; diferimentos transferem para os períodos seguintes o
            que já foi pago ou recebido. Os lançamentos mensais saem da <strong>Proposta mensal</strong>.
          </>
        }
        accoes={
          <>
            {pode('ad_definicoes_edit') && <Button icon={<SettingOutlined />} onClick={() => setDefinicoes(true)}>Definições</Button>}
            {pode('ad_editar') && <Button type="primary" icon={<PlusOutlined />} onClick={() => setNovo(true)}>Novo registo</Button>}
          </>
        }
      />
      {semDefinicoes && pode('ad_definicoes_edit') && (
        <Alert
          type="warning"
          showIcon
          icon={<SettingOutlined />}
          style={{ marginBottom: 16 }}
          message={
            <>
              Defina o diário e confirme as contas 37 em <strong>Definições</strong> antes de contabilizar.
            </>
          }
          action={<Button size="small" onClick={() => setDefinicoes(true)}>Definições</Button>}
        />
      )}
      <Row gutter={[16, 16]} style={{ marginBottom: 16 }}>
        <Col xs={24} sm={12} lg={6}>
          <Card size="small">
            <Statistic title="Acréscimos em curso" value={ind.acrescimos} loading={emCurso.isLoading} />
            <Typography.Text type="secondary">{formatarKz(ind.acrescimosReconhecido)} Kz já reconhecidos</Typography.Text>
          </Card>
        </Col>
        <Col xs={24} sm={12} lg={6}>
          <Card size="small">
            <Statistic title="Diferimentos em curso" value={ind.diferimentos} loading={emCurso.isLoading} />
            <Typography.Text type="secondary">{formatarKz(ind.diferimentosPorReconhecer)} Kz por reconhecer</Typography.Text>
          </Card>
        </Col>
      </Row>
      <Card>
        <BarraFiltros>
          <Select placeholder="Tipo" aria-label="Tipo" allowClear value={tipo} onChange={setTipo} style={{ width: 150 }} options={[{ value: 'ACRESCIMO', label: 'Acréscimos' }, { value: 'DIFERIMENTO', label: 'Diferimentos' }]} />
          <Select value={estado} aria-label="Estado" onChange={setEstado} style={{ width: 240 }}
            options={[{ value: 'ABERTOS', label: 'Em curso' }, { value: 'TODOS', label: 'Todos' }, ...Object.entries(def.data?.estados ?? {}).map(([value, label]) => ({ value, label }))]} />
          <Input.Search placeholder="Descrição, conta, terceiro, documento…" aria-label="Procurar" allowClear onSearch={setTexto} style={{ width: 320 }} />
        </BarraFiltros>
        <TabelaApi<ItemAD>
          url="/acrescimos/itens"
          chaveConsulta={['acrescimos', 'itens']}
          filtros={{ tipo, estado, texto }}
          columns={colunas}
          impressao={{
            titulo: 'Lista de acréscimos e diferimentos',
            filtros: [
              `Estado: ${estado === 'ABERTOS' ? 'Em curso' : estado === 'TODOS' ? 'Todos' : def.data?.estados?.[estado] ?? estado}`,
              tipo && `Tipo: ${tipo === 'ACRESCIMO' ? 'Acréscimos' : 'Diferimentos'}`,
              texto && `Pesquisa: ${texto}`,
            ],
          }}
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
    <Modal title="Definições de acréscimos e diferimentos" open={aberto} onCancel={aoFechar} onOk={() => form.submit()} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} width={larguraModal(640)} destroyOnHidden>
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ metodo: 'put', url: '/acrescimos/definicoes', dados: { ...v, diario_id: v.diario_id ?? null } })}>
        {Object.entries(def.data?.rotulos_contas ?? {}).map(([chave, rotulo]) => (
          <Form.Item key={chave} name={['contas', chave]} label={`${rotulo} (${chave.replace('_', ' / ').toLowerCase()})`}>
            <SeletorConta prefixo="37" />
          </Form.Item>
        ))}
        <Flex gap={12} wrap>
          <Form.Item name="diario_id" label="Diário dos lançamentos"><SeletorDiario allowClear style={{ width: 280, maxWidth: '100%' }} /></Form.Item>
          <Form.Item name="prazo_documento_dias" label="Prazo do documento real (dias)"><InputNumber min={0} max={3650} style={{ width: 160 }} /></Form.Item>
        </Flex>
        <Typography.Text type="secondary">O prazo define a data limite dos acréscimos sem data indicada (fim do período + prazo).</Typography.Text>
      </Form>
    </Modal>
  );
}
