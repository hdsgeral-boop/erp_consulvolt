import { Badge, Button, Card, Col, DatePicker, Flex, Input, InputNumber, Row, Segmented, Select, Space, Tabs } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { obter, obterPagina } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi } from '@/utilitarios/formatacao';
import { useConfigCRM } from './comum/dados';
import { ListaActividades, ModalActividade } from './comum/componentes';
import type { Actividade } from './comum/tipos';

interface Agenda {
  em_atraso: Actividade[];
  hoje: Actividade[];
  proximos: Actividade[];
}

/** CRM › Agenda comercial (crm_agenda): actividades em atraso, de hoje e próximas, e pesquisa de todas as actividades. */
export default function AgendaComercial() {
  const { pode, utilizador } = useSessao();
  const config = useConfigCRM();
  const [responsavel, setResponsavel] = useState<string>(utilizador?.nome_utilizador ?? '');
  const [dias, setDias] = useState(7);
  const [actividade, setActividade] = useState<Actividade | 'nova' | null>(null);
  const [filtros, setFiltros] = useState<{ tipo?: string; estado?: string; datas?: [Dayjs | null, Dayjs | null] | null }>({ estado: 'PENDENTES' });
  const editar = pode('crm_editar');
  const agenda = useQuery({ queryKey: ['crm', 'agenda', responsavel, dias], queryFn: () => obter<Agenda>('/crm/agenda', { responsavel: responsavel || undefined, dias }) });
  const todas = useQuery({
    queryKey: ['crm', 'atividades', responsavel, filtros],
    queryFn: () =>
      obterPagina<Actividade>('/crm/atividades', {
        responsavel: responsavel || undefined,
        tipo: filtros.tipo,
        estado: filtros.estado,
        de: dataApi(filtros.datas?.[0]),
        ate: dataApi(filtros.datas?.[1]),
        por_pagina: 200,
      }),
  });

  const bloco = (titulo: string, lista: Actividade[] | undefined, cor: string) => (
    <Card size="small" title={<Space>{titulo}<Badge count={lista?.length ?? 0} color={cor} showZero /></Space>} style={{ height: '100%' }} loading={agenda.isLoading}>
      <ListaActividades actividades={lista ?? []} podeEditar={editar} aoEditar={setActividade} vazio="Nada agendado." />
    </Card>
  );

  return (
    <>
      <CabecalhoPagina
        titulo="Agenda comercial"
        subtitulo="Chamadas, reuniões, emails e tarefas da equipa comercial"
        accoes={editar && <Button type="primary" icon={<PlusOutlined />} onClick={() => setActividade('nova')}>Nova actividade</Button>}
      />
      <Card size="small" style={{ marginBottom: 12 }}>
        <Flex gap={8} wrap align="center">
          <Input placeholder="Responsável (vazio = todos)" allowClear style={{ width: 220 }} value={responsavel} onChange={(e) => setResponsavel(e.target.value)} />
          <span>Próximos</span>
          <InputNumber min={0} max={365} value={dias} onChange={(v) => setDias(v ?? 7)} style={{ width: 80 }} />
          <span>dias</span>
        </Flex>
      </Card>
      <Tabs
        items={[
          {
            key: 'agenda',
            label: 'Agenda',
            children: (
              <Row gutter={[12, 12]}>
                <Col xs={24} lg={8}>{bloco('Em atraso', agenda.data?.em_atraso, '#cf1322')}</Col>
                <Col xs={24} lg={8}>{bloco('Hoje', agenda.data?.hoje, '#1677ff')}</Col>
                <Col xs={24} lg={8}>{bloco(`Próximos ${dias} dias`, agenda.data?.proximos, '#8c8c8c')}</Col>
              </Row>
            ),
          },
          {
            key: 'todas',
            label: 'Todas as actividades',
            children: (
              <Card size="small">
                <Flex gap={8} wrap style={{ marginBottom: 12 }}>
                  <Segmented
                    value={filtros.estado ?? ''}
                    onChange={(v) => setFiltros({ ...filtros, estado: (v as string) || undefined })}
                    options={[{ value: 'PENDENTES', label: 'Pendentes' }, { value: 'VENCIDAS', label: 'Vencidas' }, { value: 'CONCLUIDAS', label: 'Concluídas' }, { value: '', label: 'Todas' }]}
                  />
                  <Select allowClear placeholder="Tipo" style={{ width: 160 }} value={filtros.tipo} onChange={(t?: string) => setFiltros({ ...filtros, tipo: t })} options={Object.entries(config.data?.tipos_atividade ?? {}).map(([value, label]) => ({ value, label }))} />
                  <DatePicker.RangePicker format="DD/MM/YYYY" allowEmpty={[true, true]} value={filtros.datas ?? null} onChange={(v) => setFiltros({ ...filtros, datas: v })} />
                </Flex>
                <ListaActividades actividades={todas.data?.itens ?? []} podeEditar={editar} aoEditar={setActividade} vazio={todas.isLoading ? 'A carregar…' : 'Sem actividades.'} />
              </Card>
            ),
          },
        ]}
      />
      <ModalActividade actividade={actividade} aoFechar={() => setActividade(null)} />
    </>
  );
}
