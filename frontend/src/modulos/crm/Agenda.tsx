import { Badge, Button, Card, Col, DatePicker, Flex, Row, Segmented, Select, Space, Tabs } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { obter, obterPagina } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { tabelaHtml } from '@/componentes/impressao';
import { formatarDataHora } from '@/utilitarios/formatacao';
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

/** Subtítulo do antigo (renderCrmAgenda, crm_ui_gestao.js:193). */
export const SUBTITULO_AGENDA_CRM = 'Chamadas, emails, reuniões e tarefas — incluindo as criadas automaticamente quando um negócio muda de etapa.';
/** Filtro «Responsável» do antigo (crm_ui_gestao.js:195): As minhas / Minha equipa / Todos e depois os utilizadores. */
export const OPCOES_RESPONSAVEL_AGENDA = [{ value: '__eu', label: 'As minhas' }, { value: '__equipa', label: 'Minha equipa' }, { value: '', label: 'Todos' }];
/** Horizonte «Próximos» do antigo (crm_ui_gestao.js:196). */
export const OPCOES_DIAS_AGENDA = [7, 14, 30].map((d) => ({ value: d, label: `${d} dias` }));

/**
 * Responsável a pedir à API. «Minha equipa»: o antigo usa EstruturaOrg.equipaDe e, sem estrutura, só o próprio
 * (crm_ui_gestao.js:209); a API da agenda filtra por um responsável, por isso fica igual a «As minhas».
 */
export const responsavelDaAgenda = (escolha: string, eu: string | undefined) => (escolha === '__eu' || escolha === '__equipa' ? eu || undefined : escolha || undefined);

/** CRM › Agenda comercial (crm_agenda): actividades em atraso, de hoje e próximas (renderCrmAgenda, crm_ui_gestao.js:188-217), e pesquisa de todas as actividades. */
export default function AgendaComercial() {
  const { pode, utilizador } = useSessao();
  const config = useConfigCRM();
  const [escolhaResp, setEscolhaResp] = useState('__eu');
  const responsavel = responsavelDaAgenda(escolhaResp, utilizador?.nome_utilizador) ?? '';
  const [dias, setDias] = useState(7);
  // lista de responsáveis (o antigo lista os utilizadores da empresa): os que têm actividades, mais o próprio
  const responsaveis = useQuery({
    queryKey: ['crm', 'atividades', 'responsaveis'],
    queryFn: () => obterPagina<Actividade>('/crm/atividades', { por_pagina: 200 }),
    staleTime: 300_000,
    select: (r) => [...new Set([utilizador?.nome_utilizador, ...r.itens.map((a) => a.responsavel)].filter((x): x is string => !!x))].sort((a, b) => a.localeCompare(b, 'pt')),
  });
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

  const [separador, setSeparador] = useState('agenda');
  const tipos = config.data?.tipos_atividade ?? {};
  /** Lista impressa das actividades (agenda: agrupada em atraso/hoje/próximos; todas: com os filtros actuais). */
  const pedidoImpressao = () => {
    type L = { grupo: string; a: Actividade };
    const linhas: L[] =
      separador === 'agenda'
        ? [
            ...(agenda.data?.em_atraso ?? []).map((a) => ({ grupo: 'Em atraso', a })),
            ...(agenda.data?.hoje ?? []).map((a) => ({ grupo: 'Hoje', a })),
            ...(agenda.data?.proximos ?? []).map((a) => ({ grupo: `Próximos ${dias} dias`, a })),
          ]
        : (todas.data?.itens ?? []).map((a) => ({ grupo: '', a }));
    return {
      titulo: separador === 'agenda' ? 'Agenda comercial' : 'Actividades comerciais',
      filtros: [responsavel ? `Responsável: ${responsavel}` : 'Todos os responsáveis', separador === 'todas' && filtros.estado && `Estado: ${filtros.estado.toLowerCase()}`, separador === 'todas' && filtros.tipo && `Tipo: ${tipos[filtros.tipo] ?? filtros.tipo}`],
      conteudo: tabelaHtml({
        colunas: [
          { titulo: 'Data prevista', valor: (l: L) => (l.a.data_prevista ? formatarDataHora(l.a.data_prevista) : '') },
          { titulo: 'Tipo', valor: (l) => tipos[l.a.tipo] ?? l.a.tipo },
          { titulo: 'Actividade', valor: (l) => l.a.titulo ?? '', quebrar: true },
          { titulo: 'Conta / oportunidade', valor: (l) => [l.a.conta_crm?.nome, l.a.oportunidade_crm?.titulo].filter(Boolean).join(' · '), quebrar: true },
          { titulo: 'Responsável', valor: (l) => l.a.responsavel ?? '' },
          { titulo: 'Situação', valor: (l) => (l.a.concluida ? `Concluída${l.a.resultado ? ` — ${l.a.resultado}` : ''}` : l.a.vencida ? 'Vencida' : 'Pendente'), quebrar: true },
        ],
        linhas,
        agrupar: separador === 'agenda' ? { chave: (l) => l.grupo } : undefined,
        vazio: 'Sem actividades.',
      }),
    };
  };

  // contagem antes do título e «Nada.» quando vazio (crm_ui_gestao.js:213-214)
  const bloco = (titulo: string, lista: Actividade[] | undefined, cor: string) => (
    <Card size="small" title={<Space size={6} wrap><Badge count={lista?.length ?? 0} color={cor} showZero overflowCount={999} />{titulo}</Space>} style={{ height: '100%' }} loading={agenda.isLoading}>
      <ListaActividades actividades={lista ?? []} podeEditar={editar} aoEditar={setActividade} vazio={<span style={{ color: '#64748b' }}>Nada.</span>} />
    </Card>
  );
  const rotulo = { fontSize: '0.75rem', fontWeight: 700, color: '#475569' } as const;

  return (
    <>
      <CabecalhoPagina
        titulo="Agenda comercial"
        subtitulo={SUBTITULO_AGENDA_CRM}
        accoes={editar && <Button type="primary" icon={<PlusOutlined />} onClick={() => setActividade('nova')}>Nova actividade</Button>}
        impressao={pedidoImpressao}
      />
      <Card size="small" style={{ marginBottom: 12 }}>
        {/* legado: rótulo por cima de cada filtro (crm_ui_gestao.js:195-196) */}
        <Flex gap={12} wrap align="flex-end">
          <Flex vertical gap={2}>
            <span style={rotulo}>Responsável</span>
            <Select
              aria-label="Responsável"
              showSearch
              optionFilterProp="label"
              style={{ width: 200, maxWidth: '100%' }}
              value={escolhaResp}
              onChange={setEscolhaResp}
              loading={responsaveis.isLoading}
              options={[...OPCOES_RESPONSAVEL_AGENDA, ...(responsaveis.data ?? []).map((u) => ({ value: u, label: u }))]}
            />
          </Flex>
          <Flex vertical gap={2}>
            <span style={rotulo}>Próximos</span>
            <Select aria-label="Próximos" style={{ width: 110 }} value={dias} onChange={setDias} options={OPCOES_DIAS_AGENDA} />
          </Flex>
        </Flex>
      </Card>
      <Tabs
        activeKey={separador}
        onChange={setSeparador}
        items={[
          {
            key: 'agenda',
            label: 'Agenda',
            children: (
              <Row gutter={[12, 12]}>
                <Col span={24}>{bloco('Em atraso', agenda.data?.em_atraso, '#b91c1c')}</Col>
                <Col span={24}>{bloco('Hoje', agenda.data?.hoje, '#b45309')}</Col>
                <Col span={24}>{bloco(`Próximos ${dias} dias`, agenda.data?.proximos, '#1d4ed8')}</Col>
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
                  <Select allowClear placeholder="Tipo" style={{ width: 160, maxWidth: '100%' }} value={filtros.tipo} onChange={(t?: string) => setFiltros({ ...filtros, tipo: t })} options={Object.entries(config.data?.tipos_atividade ?? {}).map(([value, label]) => ({ value, label }))} />
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
