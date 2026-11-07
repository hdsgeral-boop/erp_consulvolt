import { PlusOutlined } from '@ant-design/icons';
import { Alert, Button, Card, Col, DatePicker, Empty, Row, Select, Space, Statistic, Table, Tag, message } from 'antd';
import { useMutation, useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useEffect, useMemo, useState } from 'react';
import { CabecalhoModulo } from '@/componentes/CabecalhoModulo';
import { BotoesExportar, tabelaHtml, type PedidoImpressao } from '@/componentes/impressao';
import { notificarErro } from '@/utilitarios/erros';
import { useSessao } from '@/sessao/SessaoContexto';
import { CHAVES, listarImoveis } from './api';
import {
  caducarReservas, CHAVES_PK, listarEspacos, obterAvisosAreas, obterAvisosFinanceiros, obterCalendario, obterListasParque, obterResumoAreas, type Espaco, type FichaReserva, type ReservaLinha,
} from './apiParque';
import { DetalheReserva } from './DetalheReserva';
import { useRefrescar } from './hooks';
import { formatarKz } from './logica';
import { COR_RESERVA, resumoDias, ROTULO_RESERVA } from './logicaParque';
import { ModalEspaco } from './ModalEspaco';
import { ModalReserva } from './ModalReserva';
import './imobiliario.css';

type Aba = 'calendario' | 'espacos';

/**
 * Gestão Imobiliária › Áreas Comuns e Reservas (ecrã imo_areas): o que o prédio partilha e quem o tem marcado. Uma área comum não é fracção: não se arrenda nem conta
 * para a ocupação. Ver: imo_areas_view · espaços: imo_area_editar · marcar: imo_reserva_marcar · aprovar e recusar: imo_reserva_aprovar · autorizar devedores:
 * imo_reserva_devedor · facturar: imo_reserva_facturar · receber: imo_reserva_receber · vistoria: imo_reserva_vistoria · caução: imo_reserva_caucao · cancelar:
 * imo_reserva_cancelar (também recusadas no servidor). Quem pode aprovar ou cancelar dispara, ao abrir, a verificação dos prazos de pagamento.
 */
export default function AreasComuns() {
  const { pode } = useSessao();
  const refrescar = useRefrescar();
  const [aba, setAba] = useState<Aba | null>(null);
  const [imovel, setImovel] = useState<number | null>(null);
  const [espacoFiltro, setEspacoFiltro] = useState<number | null>(null);
  const [intervalo, setIntervalo] = useState<[dayjs.Dayjs, dayjs.Dayjs]>([dayjs(), dayjs().add(30, 'day')]);
  const [editarEspaco, setEditarEspaco] = useState<Espaco | null | undefined>(undefined);
  const [marcar, setMarcar] = useState<{ reserva: FichaReserva | null; espaco: number | null } | undefined>(undefined);
  const [aberta, setAberta] = useState<number | null>(null);
  const p = {
    editarArea: pode('imo_area_editar'), marcar: pode('imo_reserva_marcar'), aprovar: pode('imo_reserva_aprovar'), devedor: pode('imo_reserva_devedor'), facturar: pode('imo_reserva_facturar'),
    receber: pode('imo_reserva_receber'), vistoria: pode('imo_reserva_vistoria'), caucao: pode('imo_reserva_caucao'), cancelar: pode('imo_reserva_cancelar'),
  };
  const de = intervalo[0].format('YYYY-MM-DD');
  const ate = intervalo[1].format('YYYY-MM-DD');
  const listas = useQuery({ queryKey: CHAVES_PK.listas, queryFn: obterListasParque, staleTime: 5 * 60_000 });
  const imoveis = useQuery({ queryKey: CHAVES.imoveis, queryFn: listarImoveis });
  const espacos = useQuery({ queryKey: CHAVES_PK.espacos, queryFn: listarEspacos });
  const resumo = useQuery({ queryKey: CHAVES_PK.resumo(imovel), queryFn: () => obterResumoAreas(imovel) });
  const avisosAreas = useQuery({ queryKey: CHAVES_PK.avisos(imovel), queryFn: () => obterAvisosAreas(imovel) });
  const avisosFin = useQuery({ queryKey: ['imobiliario', 'areas', 'avisos-financeiros', imovel], queryFn: () => obterAvisosFinanceiros(imovel) });
  const calendario = useQuery({ queryKey: CHAVES_PK.calendario(imovel, espacoFiltro, de, ate), queryFn: () => obterCalendario(imovel, espacoFiltro, de, ate) });
  useEffect(() => { if (espacos.error) notificarErro(espacos.error, 'Não foi possível carregar os espaços'); }, [espacos.error]);
  useEffect(() => { if (calendario.error) notificarErro(calendario.error, 'Não foi possível carregar o calendário'); }, [calendario.error]);
  useEffect(() => { if (resumo.error) notificarErro(resumo.error, 'Não foi possível carregar os indicadores'); }, [resumo.error]);

  const todos = espacos.data ?? [];
  const doImovel = useMemo(() => todos.filter((e) => !imovel || e.imovel_id === imovel), [todos, imovel]);
  const reservaveis = useMemo(() => doImovel.filter((e) => e.activo && e.regime !== 'GRATUITO'), [doImovel]);
  const abaActiva: Aba = aba ?? (espacos.isSuccess && todos.length === 0 ? 'espacos' : 'calendario');

  // os prazos de pagamento esgotados verificam-se ao abrir (a tarefa agendada faz o mesmo de 15 em 15 minutos)
  const caducar = useMutation({
    mutationFn: () => caducarReservas(imovel),
    onSuccess: async (r) => {
      if (r.dados.caducadas + r.dados.confirmadas > 0) {
        message.warning(`${r.dados.caducadas} reserva(s) caíram por não terem sido pagas dentro do prazo — o horário ficou livre.${r.dados.confirmadas ? ` ${r.dados.confirmadas} ficaram confirmadas.` : ''}`, 8);
        await refrescar();
      }
    },
  });
  const verificar = caducar.mutate;
  useEffect(() => { if (p.aprovar || p.cancelar) verificar(); }, [imovel, p.aprovar, p.cancelar, verificar]);

  const r = resumo.data;
  const avisos = [...(avisosAreas.data ?? []), ...(avisosFin.data ?? [])];

  const pedido = (): PedidoImpressao => ({
    titulo: 'Reservas de áreas comuns', subtitulo: `de ${intervalo[0].format('DD/MM/YYYY')} a ${intervalo[1].format('DD/MM/YYYY')}`, orientacao: 'retrato',
    conteudo: tabelaHtml<ReservaLinha & { dia: string }>({
      colunas: [
        { titulo: 'Dia', valor: (x) => `${x.dia}, ${x.data}` }, { titulo: 'Horas', valor: (x) => `${x.hora_inicio}–${x.hora_fim}` }, { titulo: 'Espaço', valor: (x) => x.espaco_nome ?? '' },
        { titulo: 'Quem', valor: (x) => x.reservante ?? '' }, { titulo: 'Fracção', valor: (x) => x.fraccao ?? '' }, { titulo: 'Motivo', valor: (x) => x.motivo ?? '', quebrar: true },
        { titulo: 'Valor', valor: (x) => x.valor, formato: 'moeda' }, { titulo: 'Estado', valor: (x) => ROTULO_RESERVA[x.estado] },
      ],
      linhas: (calendario.data ?? []).flatMap((d) => d.reservas.map((x) => ({ ...x, dia: d.dia }))), vazio: 'Nada marcado neste período.',
    }),
  });

  const colunasEspacos = [
    { title: 'Espaço', dataIndex: 'nome', key: 'nome', render: (v: string, e: Espaco) => <span><strong>{v}</strong><br /><small>{listas.data?.tipos_area.find((t) => t.valor === e.tipo)?.rotulo ?? e.tipo}{e.localizacao ? ` · ${e.localizacao}` : ''}</small></span> },
    { title: 'Imóvel', dataIndex: 'imovel_nome', key: 'imovel', render: (v: string | null, e: Espaco) => v ?? e.imovel_codigo ?? '—' },
    { title: 'Regime', dataIndex: 'regime', key: 'regime', render: (v: string) => listas.data?.regimes.find((x) => x.valor === v)?.rotulo ?? v },
    { title: 'Horário', key: 'horario', render: (_: unknown, e: Espaco) => (e.regime === 'GRATUITO' ? 'livre' : `${e.hora_abre}–${e.hora_fecha} · ${resumoDias(e.dias_semana, listas.data?.dias ?? [])}`) },
    { title: 'Regras', key: 'regras', render: (_: unknown, e: Espaco) => {
      const x = [e.duracao_min || e.duracao_max ? `${e.duracao_min ?? '—'}–${e.duracao_max ?? '—'} h` : null, e.limite_mes_fraccao ? `${e.limite_mes_fraccao}×/mês por fracção` : null, e.antecedencia_min_dias ? `min. ${e.antecedencia_min_dias} d` : null,
        e.exige_aprovacao ? 'com aprovação' : null, e.capacidade ? `${e.capacidade} pessoas` : null].filter(Boolean);
      return x.length ? x.join(' · ') : '—';
    } },
    { title: 'Preço', key: 'preco', align: 'right' as const, render: (_: unknown, e: Espaco) => (e.regime === 'RESERVA_PAGA' ? `${formatarKz(e.preco)} ${listas.data?.unidades_preco.find((u) => u.valor === e.preco_unidade)?.rotulo ?? ''}${e.caucao ? ` · caução ${formatarKz(e.caucao)}` : ''}` : '—') },
    { title: 'Estado', dataIndex: 'activo', key: 'activo', render: (v: boolean) => <Tag color={v ? 'green' : 'default'}>{v ? 'Activo' : 'Inactivo'}</Tag> },
    { title: 'Acções', key: 'accoes', render: (_: unknown, e: Espaco) => (
      <Space>
        {p.marcar && e.activo && e.regime !== 'GRATUITO' && <Button size="small" onClick={() => setMarcar({ reserva: null, espaco: e.id })}>Marcar</Button>}
        {p.editarArea && <Button size="small" aria-label={`Editar ${e.nome}`} onClick={() => setEditarEspaco(e)}>Editar</Button>}
      </Space>
    ) },
  ];

  return (
    <>
      <CabecalhoModulo titulo="Áreas Comuns" icone="university" subtitulo="Os espaços que o prédio partilha e quem os tem marcados. Uma área comum não é uma fracção: não se arrenda nem conta para a ocupação."
        accoes={<Space wrap>
          {p.marcar && <Button type="primary" icon={<PlusOutlined />} disabled={reservaveis.length === 0} onClick={() => setMarcar({ reserva: null, espaco: espacoFiltro })}>Marcar</Button>}
          {p.editarArea && <Button icon={<PlusOutlined />} onClick={() => setEditarEspaco(null)}>Novo espaço</Button>}
        </Space>} />
      <Row gutter={[12, 12]} className="imo-kpis">
        <Col xs={12} md={5}><Card size="small"><Statistic title="Espaços" value={r?.espacos ?? 0} loading={resumo.isLoading} /></Card></Col>
        <Col xs={12} md={5}><Card size="small"><Statistic title="Reservas por vir" value={r?.futuras ?? 0} loading={resumo.isLoading} /></Card></Col>
        <Col xs={12} md={5}><Card size="small"><Statistic title="Por aprovar" value={r?.por_aprovar ?? 0} loading={resumo.isLoading} /></Card></Col>
        <Col xs={12} md={5}><Card size="small"><Statistic title="Por fechar" value={r?.por_fechar ?? 0} loading={resumo.isLoading} /></Card></Col>
        <Col xs={12} md={4}><Card size="small"><Statistic title="Hoje" value={r?.hoje ?? 0} loading={resumo.isLoading} /></Card></Col>
      </Row>
      {avisos.length > 0 && <Alert type="warning" showIcon style={{ marginTop: 12 }} title={`${avisos.length} ponto(s) a tratar`} description={<ul className="imo-lista">{avisos.map((a) => <li key={a}>{a}</li>)}</ul>} />}
      <Card style={{ marginTop: 12 }}>
        <Space wrap style={{ marginBottom: 12 }}>
          <label htmlFor="imo-areas-imovel">Imóvel</label>
          <Select id="imo-areas-imovel" allowClear style={{ minWidth: 260 }} showSearch optionFilterProp="label" value={imovel ?? undefined} placeholder="Todos os imóveis" loading={imoveis.isLoading}
            options={(imoveis.data ?? []).map((i) => ({ value: i.id, label: `${i.codigo} · ${i.nome}` }))} onChange={(v) => { setImovel(v ?? null); setEspacoFiltro(null); }} />
          <Space.Compact>
            <Button type={abaActiva === 'calendario' ? 'primary' : 'default'} aria-pressed={abaActiva === 'calendario'} onClick={() => setAba('calendario')}>Calendário</Button>
            <Button type={abaActiva === 'espacos' ? 'primary' : 'default'} aria-pressed={abaActiva === 'espacos'} onClick={() => setAba('espacos')}>Espaços</Button>
          </Space.Compact>
        </Space>
        {abaActiva === 'espacos' ? (
          <Table<Espaco> rowKey="id" size="middle" loading={espacos.isFetching} dataSource={doImovel} columns={colunasEspacos} pagination={false} scroll={{ x: 'max-content' }}
            locale={{ emptyText: <Empty description="Ainda não há espaços comuns registados. Salão de festas, ginásio, piscina, churrasqueira, sala de reuniões — o que o prédio partilha." /> }} />
        ) : (
          <>
            <Space wrap style={{ marginBottom: 12 }}>
              <label htmlFor="imo-areas-periodo">Período</label>
              <DatePicker.RangePicker id="imo-areas-periodo" format="DD/MM/YYYY" allowClear={false} value={intervalo} onChange={(v) => { if (v?.[0] && v[1]) setIntervalo([v[0], v[1]]); }} />
              <label htmlFor="imo-areas-espaco">Espaço</label>
              <Select id="imo-areas-espaco" allowClear style={{ minWidth: 200 }} placeholder="Todos" value={espacoFiltro ?? undefined} options={doImovel.map((e) => ({ value: e.id, label: e.nome }))} onChange={(v) => setEspacoFiltro(v ?? null)} />
              <BotoesExportar desactivado={!(calendario.data ?? []).length} obterPedido={pedido} />
            </Space>
            {reservaveis.length === 0 && !espacos.isLoading ? (
              <Empty description={todos.length === 0 ? 'Ainda não há espaços comuns registados. Para aparecer no calendário, o espaço tem de ter regime de reserva.' : 'Nenhum dos espaços registados aceita reservas — são todos de uso livre, ou estão inactivos.'}>
                {p.editarArea && <Button type="primary" onClick={() => setEditarEspaco(null)}>Criar o primeiro espaço</Button>}
                {todos.length > 0 && <Button onClick={() => setAba('espacos')}>Ver os espaços</Button>}
              </Empty>
            ) : (calendario.data ?? []).length === 0 ? <Empty description={`Nada marcado entre ${intervalo[0].format('DD/MM/YYYY')} e ${intervalo[1].format('DD/MM/YYYY')}.`} /> : (calendario.data ?? []).map((d) => (
              <section key={d.data} className="imo-dia-calendario" aria-label={`${d.dia}, ${d.data}`}>
                <h3>{d.dia}, {d.data} <small>· {d.reservas.length} reserva(s)</small></h3>
                {d.reservas.map((x) => (
                  <div key={x.id} className="imo-reserva-linha">
                    <span className="imo-reserva-horas">{x.hora_inicio}<br />{x.hora_fim}</span>
                    <span><strong>{x.espaco_nome} — {x.reservante}</strong>{x.fraccao ? ` (${x.fraccao})` : ''}{x.motivo ? <><br /><small>{x.motivo}</small></> : null}
                      {x.divida_na_marcacao > 0 && <><br /><small>autorizada com {formatarKz(x.divida_na_marcacao)} em dívida — {x.autorizacao_por}</small></>}</span>
                    {x.valor > 0 && <span>{formatarKz(x.valor)}{x.caucao > 0 ? ` + caução ${formatarKz(x.caucao)}` : ''}</span>}
                    <Tag color={COR_RESERVA[x.estado]}>{ROTULO_RESERVA[x.estado]}</Tag>
                    <Button size="small" aria-label={`Abrir a reserva de ${x.espaco_nome} em ${x.data} às ${x.hora_inicio}`} onClick={() => setAberta(x.id)}>Abrir</Button>
                  </div>
                ))}
              </section>
            ))}
          </>
        )}
      </Card>
      <ModalEspaco espaco={editarEspaco} imovelInicial={imovel} listas={listas.data} aoFechar={() => setEditarEspaco(undefined)} />
      <ModalReserva reserva={marcar?.reserva} espacoInicial={marcar?.espaco ?? null} espacos={todos} podeAutorizar={p.devedor} aoFechar={(g) => { setMarcar(undefined); if (g) setAberta(g.id); }} />
      <DetalheReserva id={aberta} listas={listas.data} pode={p} aoEditar={(x) => { setAberta(null); setMarcar({ reserva: x, espaco: x.espaco_id }); }} aoFechar={() => setAberta(null)} />
    </>
  );
}
