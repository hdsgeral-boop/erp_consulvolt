import { PlusOutlined } from '@ant-design/icons';
import { Alert, Button, Card, Col, Empty, Input, Row, Select, Space, Statistic, Table, Tabs, Tag, message } from 'antd';
import { useMutation, useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import { CabecalhoModulo } from '@/componentes/CabecalhoModulo';
import { notificarErro } from '@/utilitarios/erros';
import { useSessao } from '@/sessao/SessaoContexto';
import { CHAVES, listarImoveis } from './api';
import {
  CHAVES_OB, gerarTodosPlanos, listarOrdens, listarPlanos, obterAvisosObras, obterListasObras, obterResumoObras, type OrdemLinha, type Plano,
} from './apiObras';
import { BensFraccao } from './BensFraccao';
import { DetalheOrdem } from './DetalheOrdem';
import { useRefrescar } from './hooks';
import { COR_OBRA, ROTULO_OBRA, ROTULO_TIPO_OBRA } from './logicaObras';
import { ModalOrdem } from './ModaisObra';
import { ModalCalendario, ModalPlano } from './ModaisPlano';
import './imobiliario.css';

/**
 * Gestão Imobiliária › Apetrechamento e Manutenção (ecrã imo_obras): as ordens de trabalho do imóvel (obra, apetrechamento, manutenção, reparação, vistoria), os planos que as geram e os bens
 * das fracções. As necessidades seguem o fluxo de compras: o que há em stock vai ao armazém, o que falta vai a pedido de compra. Ver: imo_obras_view · editar ordens: imo_obra_editar ·
 * aprovar: imo_obra_aprovar · pedir material: imo_obra_pedir · entregas: imo_obra_receber · começar, concluir e cancelar: imo_obra_fechar · debitar ao inquilino: imo_sub_debitar ·
 * planos: imo_plano_editar e imo_plano_gerar · bens: imo_bens_editar (também recusadas no servidor).
 */
export default function Obras() {
  const { pode } = useSessao();
  const refrescar = useRefrescar();
  const [estado, setEstado] = useState('');
  const [texto, setTexto] = useState('');
  const [imovel, setImovel] = useState<number | null>(null);
  const [aberta, setAberta] = useState<number | null>(null);
  const [nova, setNova] = useState<boolean>(false);
  const [plano, setPlano] = useState<Plano | null | undefined>(undefined);
  const [calendario, setCalendario] = useState<Plano | null>(null);
  const listas = useQuery({ queryKey: CHAVES_OB.listas, queryFn: obterListasObras, staleTime: 5 * 60_000 });
  const imoveis = useQuery({ queryKey: CHAVES.imoveis, queryFn: listarImoveis });
  const resumo = useQuery({ queryKey: CHAVES_OB.resumo, queryFn: obterResumoObras });
  const avisos = useQuery({ queryKey: CHAVES_OB.avisos, queryFn: obterAvisosObras });
  const ordens = useQuery({ queryKey: CHAVES_OB.ordens(estado, texto, imovel), queryFn: () => listarOrdens({ estado, texto, imovel_id: imovel }) });
  const planos = useQuery({ queryKey: CHAVES_OB.planos, queryFn: listarPlanos });
  useEffect(() => { if (ordens.error) notificarErro(ordens.error, 'Não foi possível carregar as ordens'); }, [ordens.error]);
  useEffect(() => { if (resumo.error) notificarErro(resumo.error, 'Não foi possível carregar os indicadores'); }, [resumo.error]);
  const r = resumo.data;
  const emDivida = (r?.planos_em_falta ?? 0) + (r?.planos_por_gerar ?? 0) > 0;
  const gerarTodos = useMutation({
    mutationFn: () => gerarTodosPlanos(), onError: (e) => notificarErro(e, 'Não foi possível gerar as ordens dos planos'),
    onSuccess: async (x) => {
      message.success(x.mensagem);
      if (x.dados.falhas.length) message.warning(`Não foi possível gerar: ${x.dados.falhas.map((f) => f.plano).join(', ')}.`, 8);
      await refrescar();
    },
  });
  const hoje = dayjs().format('YYYY-MM-DD');
  const editarOrdens = pode('imo_obra_editar');
  const lista = avisos.data ?? [];

  const abaOrdens = (
    <>
      <Space wrap style={{ marginBottom: 12 }}>
        <label htmlFor="imo-obras-estado">Estado</label>
        <Select id="imo-obras-estado" allowClear style={{ minWidth: 190 }} placeholder="Todos" value={estado || undefined} options={(listas.data?.estados ?? []).map((e) => ({ value: e.chave, label: e.rotulo }))} onChange={(v) => setEstado(v ?? '')} />
        <label htmlFor="imo-obras-imovel">Imóvel</label>
        <Select id="imo-obras-imovel" allowClear showSearch optionFilterProp="label" style={{ minWidth: 240 }} placeholder="Todos os imóveis" value={imovel ?? undefined} loading={imoveis.isLoading}
          options={(imoveis.data ?? []).map((i) => ({ value: i.id, label: `${i.codigo} · ${i.nome}` }))} onChange={(v) => setImovel(v ?? null)} />
        <Input.Search allowClear aria-label="Procurar por número ou descrição" placeholder="Número ou descrição" style={{ width: 240 }} onSearch={setTexto} />
      </Space>
      <Table<OrdemLinha> rowKey="id" size="middle" loading={ordens.isFetching} dataSource={ordens.data ?? []} pagination={{ pageSize: 25, hideOnSinglePage: true }} scroll={{ x: 'max-content' }}
        locale={{ emptyText: <Empty description="Sem ordens. Abra uma a partir de uma fracção («Bens das fracções» → comparar com a ficha) ou directamente aqui." /> }}
        columns={[
          { title: 'N.º', dataIndex: 'numero', key: 'n', render: (v: string) => <strong>{v}</strong> },
          { title: 'Tipo', dataIndex: 'tipo', key: 't', render: (v: OrdemLinha['tipo']) => ROTULO_TIPO_OBRA[v] },
          { title: 'Imóvel / fracção', key: 'i', render: (_: unknown, o: OrdemLinha) => <span>{o.imovel_codigo} · {o.imovel_nome}{o.fracao_designacao ? <><br /><small>{o.fracao_designacao}</small></> : null}</span> },
          { title: 'Descrição', dataIndex: 'descricao', key: 'd', ellipsis: true, width: 260 },
          { title: 'Prioridade', dataIndex: 'prioridade', key: 'p', render: (v: string) => listas.data?.prioridades.find((x) => x.chave === v)?.rotulo ?? v },
          { title: 'Quem paga', key: 'q', render: (_: unknown, o: OrdemLinha) => <span>{listas.data?.quem_paga.find((x) => x.chave === o.quem_paga)?.rotulo ?? o.quem_paga}{o.debitavel ? <Tag style={{ marginLeft: 6 }} color="cyan">a debitar</Tag> : null}</span> },
          { title: 'Prevista', key: 'pr', render: (_: unknown, o: OrdemLinha) => <span>{o.data_prevista ?? '—'}{o.em_atraso || (o.data_prevista && o.data_prevista < hoje && o.estado !== 'CONCLUIDA' && o.estado !== 'CANCELADA') ? <Tag style={{ marginLeft: 6 }} color="red">em atraso</Tag> : null}</span> },
          { title: 'Aprovação', key: 'a', render: (_: unknown, o: OrdemLinha) => (o.aprovada ? 'Aprovada' : o.estado === 'CONCLUIDA' || o.estado === 'CANCELADA' ? '—' : 'Por aprovar') },
          { title: 'Estado', dataIndex: 'estado', key: 'e', render: (v: OrdemLinha['estado']) => <Tag color={COR_OBRA[v]}>{ROTULO_OBRA[v]}</Tag> },
          { title: 'Acções', key: 'ac', render: (_: unknown, o: OrdemLinha) => <Button size="small" aria-label={`Abrir a ordem ${o.numero}`} onClick={() => setAberta(o.id)}>Abrir</Button> },
        ]} />
    </>
  );

  const abaPlanos = (
    <>
      <p>Uma vistoria trimestral ou uma pintura anual não é uma ordem, é um calendário. O plano guarda as datas e gera as ordens.</p>
      {pode('imo_plano_editar') && <Button type="primary" icon={<PlusOutlined />} style={{ marginBottom: 12 }} onClick={() => setPlano(null)}>Novo plano</Button>}
      <Table<Plano> rowKey="id" size="middle" loading={planos.isFetching} dataSource={planos.data ?? []} pagination={false} scroll={{ x: 'max-content' }}
        locale={{ emptyText: <Empty description="Ainda não há planos. Crie um para a vistoria periódica ou para a manutenção que se repete." /> }}
        columns={[
          { title: 'Plano', dataIndex: 'nome', key: 'n', render: (v: string, p: Plano) => <span><strong>{v}</strong><br /><small>{p.descricao.slice(0, 60)}</small></span> },
          { title: 'Tipo', dataIndex: 'tipo', key: 't', render: (v: Plano['tipo']) => ROTULO_TIPO_OBRA[v] },
          { title: 'Onde', key: 'o', render: (_: unknown, p: Plano) => `${p.imovel_codigo ?? ''} · ${p.imovel_nome ?? ''}${p.fracao_designacao ? ` — ${p.fracao_designacao}` : ' — imóvel inteiro'}` },
          { title: 'Periodicidade', dataIndex: 'periodicidade', key: 'pe', render: (v: string) => listas.data?.periodicidades.find((x) => x.chave === v)?.rotulo ?? v },
          { title: 'Datas', key: 'd', render: (_: unknown, p: Plano) => `${p.n_datas ?? p.datas.length} (${p.concluidas ?? 0} feita(s))` },
          { title: 'Próxima', key: 'x', render: (_: unknown, p: Plano) => p.proxima_data ?? 'terminado' },
          { title: 'Em falta', key: 'f', render: (_: unknown, p: Plano) => ((p.em_falta ?? 0) > 0 ? <strong style={{ color: '#b91c1c' }}>{p.em_falta}</strong> : '—') },
          { title: 'Estado', dataIndex: 'activo', key: 'a', render: (v: boolean) => <Tag color={v ? 'green' : 'default'}>{v ? 'Activo' : 'Inactivo'}</Tag> },
          { title: 'Acções', key: 'ac', render: (_: unknown, p: Plano) => (
            <Space>
              <Button size="small" aria-label={`Calendário de ${p.nome}`} onClick={() => setCalendario(p)}>Calendário</Button>
              {pode('imo_plano_editar') && <Button size="small" aria-label={`Editar o plano ${p.nome}`} onClick={() => setPlano(p)}>Editar</Button>}
            </Space>) },
        ]} />
    </>
  );

  return (
    <>
      <CabecalhoModulo titulo="Apetrechamento e Manutenção" icone="tools" subtitulo="As necessidades seguem o fluxo de compras: o que há em stock vai ao armazém, o que falta vai a pedido de compra."
        accoes={<Space wrap>
          {editarOrdens && <Button type="primary" icon={<PlusOutlined />} onClick={() => setNova(true)}>Nova ordem</Button>}
          {pode('imo_plano_gerar') && emDivida && <Button loading={gerarTodos.isPending} onClick={() => gerarTodos.mutate()}>Gerar as ordens em dívida</Button>}
        </Space>} />
      <Row gutter={[12, 12]} className="imo-kpis">
        <Col xs={12} md={4}><Card size="small"><Statistic title="Abertas" value={r?.abertas ?? 0} loading={resumo.isLoading} /></Card></Col>
        <Col xs={12} md={4}><Card size="small"><Statistic title="Aguarda material" value={r?.aguarda_material ?? 0} loading={resumo.isLoading} /></Card></Col>
        <Col xs={12} md={4}><Card size="small"><Statistic title="Em curso" value={r?.em_curso ?? 0} loading={resumo.isLoading} /></Card></Col>
        <Col xs={12} md={4}><Card size="small"><Statistic title="Urgentes por fechar" value={r?.urgentes ?? 0} loading={resumo.isLoading} /></Card></Col>
        <Col xs={12} md={4}><Card size="small"><Statistic title="Com prazo ultrapassado" value={r?.atrasadas ?? 0} loading={resumo.isLoading} /></Card></Col>
        <Col xs={12} md={4}><Card size="small"><Statistic title="Por debitar ao inquilino" value={r?.por_debitar ?? 0} loading={resumo.isLoading} /></Card></Col>
      </Row>
      {lista.length > 0 && <Alert type="warning" showIcon style={{ marginTop: 12 }} title={`${lista.length} ponto(s) a tratar`} description={<ul className="imo-lista">{lista.slice(0, 12).map((a) => <li key={`${a.tipo}-${a.ordem_id ?? a.plano_id}`}>{a.texto}</li>)}</ul>} />}
      <Card style={{ marginTop: 12 }}>
        <Tabs defaultActiveKey="ordens" items={[
          { key: 'ordens', label: 'Ordens', children: abaOrdens },
          { key: 'planos', label: `Planos${r ? ` (${r.planos_activos})` : ''}`, children: abaPlanos },
          { key: 'bens', label: 'Bens das fracções', children: <BensFraccao listas={listas.data} pode={pode} aoAbrirOrdem={(o) => setAberta(o.id)} /> },
        ]} />
      </Card>
      <ModalOrdem ordem={nova ? null : undefined} listas={listas.data} aoFechar={(g) => { setNova(false); if (g) setAberta(g.id); }} />
      <DetalheOrdem id={aberta} listas={listas.data} pode={pode} aoFechar={() => setAberta(null)} />
      <ModalPlano plano={plano} listas={listas.data} aoFechar={() => setPlano(undefined)} />
      <ModalCalendario plano={calendario} podeGerar={pode('imo_plano_gerar')} aoAbrirOrdem={(id) => setAberta(id)} aoFechar={() => setCalendario(null)} />
    </>
  );
}
