import { Card, Checkbox, Col, Flex, Row, Select, Skeleton, Space, Table, Tabs, Tag, Typography } from 'antd';
import type { ColumnsType } from 'antd/es/table';
import { useQuery } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoModulo } from '@/componentes/CabecalhoModulo';
import { BotaoImprimir, BotoesExportar } from '@/componentes/impressao';
import { scrollTabela } from '@/componentes/responsivo';
import { formatarKz } from '@/utilitarios/formatacao';
import { useCargos, useColaboradores } from '@/modulos/rh/comum/consultas';
import { BotaoCsv } from '@/modulos/contab/comum/Componentes';
import { CartaoKpi } from '@/modulos/geral/comum/componentes';
import { aplanar, construirArvore, mapaPessoal, type LinhaMapa } from './comum/arvore';
import { documentoMapaLegado, linhasMapaLegado, totaisMapaLegado, type ColaboradorEstrutura } from './comum/legado';
import { documentoMapaPessoal, linhasPorUnidade, taxaOcupacao, type CargoMapa, type ContagemMapa, type LinhaUnidadeMapa, type MapaPessoalApi } from './comum/mapa';
import { useEstrutura, useSalariosEstrutura } from './Estrutura';
import './comum/estrutura.css';
import { useSeparadorUrl } from '@/componentes/navegacao/useSeparadorUrl';

const contagem = (n: number, cor: string) => (n ? <Tag color={cor}>{n}</Tag> : 0);

type VistaMapa = 'legado' | 'agregadas';

/**
 * Estrutura orgânica › Mapa de pessoal (est_mapa). Vista por omissão = a do sistema anterior (estrutura_ui.js
 * renderEstMapa): uma só tabela UNIDADE · CARGO · VAGAS · OCUPADOS · EM ABERTO · ACIMA · PESSOAS · MASSA SALARIAL.
 * Alternativa: as vistas agregadas por unidade orgânica, por cargo e por posto (GET /rh/estrutura/mapa, ADR-064).
 * A massa salarial (ilíquido do último processamento fechado) só aparece com est_ver_salarios.
 */
export default function MapaPessoal() {
  const [vista, setVista] = useState<VistaMapa>('legado');
  const salarios = useSalariosEstrutura();
  const estrutura = useEstrutura();
  const colaboradores = useColaboradores();
  const cargos = useCargos();
  const [filtro, setFiltro] = useState<'' | 'aberto' | 'exc'>('');
  const arvore = useMemo(() => construirArvore(estrutura.data?.unidades ?? []), [estrutura.data]);
  const lista = colaboradores.lista as ColaboradorEstrutura[];
  const linhas = useMemo(() => linhasMapaLegado(arvore, lista, cargos.nome, salarios.mapa), [arvore, lista, cargos.nome, salarios.mapa]);
  const visiveis = linhas.filter((l) => !filtro || (filtro === 'aberto' ? l.emAberto > 0 : l.acima > 0));
  const tot = totaisMapaLegado(linhas);
  const ids = new Set(aplanar(arvore).map((n) => n.unidade.id));
  const activos = lista.filter((c) => c.estado === 'ACTIVO');
  const semUnidade = activos.filter((c) => !c.unidade_organica_id || !ids.has(c.unidade_organica_id));
  const massaTotal = salarios.mapa ? [...salarios.mapa.values()].reduce((s, v) => s + Math.round(v * 100), 0) / 100 : null;
  const ind = { activos: activos.length, semUnidade: semUnidade.length, massaTotal };
  const pedido = (paraExcel: boolean) => ({
    titulo: 'Mapa de pessoal',
    filtros: filtro === 'aberto' ? 'Só cargos com vagas em aberto' : filtro === 'exc' ? 'Só cargos acima das vagas' : 'Tudo',
    orientacao: 'paisagem' as const,
    conteudo: documentoMapaLegado(visiveis, ind, salarios.ver, paraExcel),
  });

  return (
    <>
      <CabecalhoModulo
        titulo="Mapa de pessoal"
        subtitulo={`Por unidade e cargo: vagas previstas, ocupadas, em aberto e acima do previsto, com as pessoas afectas${salarios.ver ? ' e a massa salarial mensal (último processamento fechado)' : ''}.`}
        accoes={vista === 'legado' ? (
          <div className="est-accoes">
            <BotaoImprimir modo="excel" chave="estrutura/mapa_pessoal" desactivado={!linhas.length} obterPedido={() => pedido(true)} />
            <BotaoImprimir chave="estrutura/mapa_pessoal" desactivado={!linhas.length} obterPedido={() => pedido(false)} />
          </div>
        ) : undefined}
      />
      <div className="org-barra">
        <div className="org-grupo">
          <label className="org-rot" htmlFor="est-m-vista">Vista</label>
          <Select id="est-m-vista" style={{ minWidth: 230 }} value={vista} onChange={setVista}
            options={[{ value: 'legado', label: 'Unidade e cargo (uma tabela)' }, { value: 'agregadas', label: 'Por unidade, cargo e posto' }]} />
        </div>
        {vista === 'legado' && (
          <div className="org-grupo">
            <label className="org-rot" htmlFor="est-m-filtro">Mostrar</label>
            <Select id="est-m-filtro" style={{ minWidth: 260 }} value={filtro} onChange={setFiltro}
              options={[{ value: '', label: 'Tudo' }, { value: 'aberto', label: 'Só cargos com vagas em aberto' }, { value: 'exc', label: 'Só cargos acima das vagas' }]} />
          </div>
        )}
      </div>
      {vista === 'agregadas' ? <VistasAgregadas /> : estrutura.isLoading || colaboradores.isLoading ? <Skeleton active /> : (
        <>
          <div className="est-kpis">
            <div className="est-kpi"><span>Colaboradores activos</span><strong>{activos.length}</strong></div>
            <div className="est-kpi"><span>Vagas previstas</span><strong>{tot.vagas}</strong></div>
            <div className="est-kpi"><span>Vagas ocupadas</span><strong>{tot.vagasOcupadas}</strong></div>
            <div className="est-kpi"><span>Vagas em aberto</span><strong style={{ color: tot.emAberto ? '#b45309' : undefined }}>{tot.emAberto}</strong></div>
            <div className="est-kpi"><span>Acima das vagas</span><strong style={{ color: tot.acima ? '#b91c1c' : undefined }}>{tot.acima}</strong></div>
            <div className="est-kpi"><span>Sem unidade</span><strong style={{ color: semUnidade.length ? '#b45309' : undefined }}>{semUnidade.length}</strong></div>
            {salarios.ver && <div className="est-kpi"><span>Massa salarial mensal</span><strong>{formatarKz((massaTotal ?? 0).toFixed(2))}</strong></div>}
          </div>
          {!linhas.length ? <div className="est-info">Defina a estrutura e os cargos para obter o mapa de pessoal.</div> : (
            <div className="est-painel" style={{ padding: 0, overflowX: 'auto' }}>
              <table className="est-tabela">
                <thead>
                  <tr><th>Unidade</th><th>Cargo</th><th className="n">Vagas</th><th className="n">Ocupados</th><th className="n">Em aberto</th><th className="n">Acima</th><th>Pessoas</th>{salarios.ver && <th className="n">Massa salarial</th>}</tr>
                </thead>
                <tbody>
                  {visiveis.map((l) => (
                    <tr key={l.chave}>
                      <td style={{ paddingLeft: `${0.45 + l.nivel * 0.9}rem` }}>{l.unidade}</td>
                      <td>{l.cargo}{l.chefia && <> <span className="est-tag info">chefia</span></>}</td>
                      <td className="n">{l.vagas || '—'}</td>
                      <td className="n">{l.ocupados}</td>
                      <td className="n">{l.emAberto ? <span className="est-tag aberto">{l.emAberto}</span> : '—'}</td>
                      <td className="n">{l.acima ? <span className="est-tag exc">{l.acima}</span> : '—'}</td>
                      <td style={{ fontSize: '0.78rem' }}>{l.pessoas.join(', ')}</td>
                      {salarios.ver && <td className="n">{formatarKz(l.massa.toFixed(2))}</td>}
                    </tr>
                  ))}
                  <tr className="total">
                    <td colSpan={2}>Total</td><td className="n">{tot.vagas}</td><td className="n">{tot.ocupados}</td><td className="n">{tot.emAberto}</td><td className="n">{tot.acima}</td><td />
                    {salarios.ver && <td className="n">{formatarKz(tot.massa.toFixed(2))}</td>}
                  </tr>
                </tbody>
              </table>
            </div>
          )}
          {semUnidade.length > 0 && <div className="est-aviso" style={{ marginTop: '0.6rem' }}><b>Sem unidade:</b> {semUnidade.map((c) => c.nome_completo).join(', ')}</div>}
        </>
      )}
    </>
  );
}

/** Vistas agregadas (sistema novo): por unidade orgânica, por cargo e por posto de trabalho. */
function VistasAgregadas() {
  const mapa = useQuery({ queryKey: ['rh', 'estrutura', 'mapa'], queryFn: () => obter<MapaPessoalApi>('/rh/estrutura/mapa') });
  const estrutura = useEstrutura();
  const cargos = useCargos();
  const [soAlertas, setSoAlertas] = useState(false);
  const [ramo, setRamo] = useState(true);

  const postos = useMemo(() => mapaPessoal(construirArvore(estrutura.data?.unidades ?? [], true), cargos.nome), [estrutura.data, cargos.nome]);
  const unidades = useMemo(() => linhasPorUnidade(mapa.data?.por_unidade ?? [], true), [mapa.data]);
  const [sep, setSep] = useSeparadorUrl(['unidades', 'cargos', 'postos']);

  if (mapa.isLoading) return <Skeleton active />;
  const d = mapa.data;
  if (!d) return <Card><Typography.Text type="danger">Não foi possível obter o mapa de pessoal.</Typography.Text></Card>;
  const t = d.totais;
  const verSalarios = d.ver_salarios;
  const taxa = taxaOcupacao(t);
  const alerta = (c: ContagemMapa) => c.em_aberto > 0 || c.acima > 0;
  const colunasNumeros = <T extends object>(valor: (l: T) => ContagemMapa): ColumnsType<T> => [
    { title: 'Previstos', key: 'prev', align: 'right', render: (_, l) => valor(l).previstos },
    { title: 'Ocupados', key: 'ocup', align: 'right', render: (_, l) => valor(l).ocupados },
    { title: 'Em aberto', key: 'aberto', align: 'right', render: (_, l) => contagem(valor(l).em_aberto, 'orange') },
    { title: 'Acima', key: 'acima', align: 'right', render: (_, l) => contagem(valor(l).acima, 'red') },
    { title: 'Colaboradores', key: 'colab', align: 'right', render: (_, l) => valor(l).colaboradores },
    ...(verSalarios ? [{ title: 'Massa salarial (Kz)', key: 'massa', align: 'right' as const, render: (_: unknown, l: T) => formatarKz(valor(l).massa_salarial ?? '0.00') }] : []),
  ];
  const csvNumeros = <T,>(valor: (l: T) => ContagemMapa) => [
    { titulo: 'Previstos', valor: (l: T) => valor(l).previstos, numerico: true },
    { titulo: 'Ocupados', valor: (l: T) => valor(l).ocupados, numerico: true },
    { titulo: 'Em aberto', valor: (l: T) => valor(l).em_aberto, numerico: true },
    { titulo: 'Acima', valor: (l: T) => valor(l).acima, numerico: true },
    { titulo: 'Colaboradores', valor: (l: T) => valor(l).colaboradores, numerico: true },
    ...(verSalarios ? [{ titulo: 'Massa salarial', valor: (l: T) => valor(l).massa_salarial ?? '', numerico: true }] : []),
  ];

  const numUnidade = (l: LinhaUnidadeMapa) => (ramo ? l.ramo : l.propria);
  const linhasUnidade = soAlertas ? unidades.filter((l) => alerta(numUnidade(l))) : unidades;
  const linhasCargo = soAlertas ? d.por_cargo.filter(alerta) : d.por_cargo;
  const linhasPosto = soAlertas ? postos.filter((l) => l.livres > 0 || l.acima > 0) : postos;
  const filtro = <Checkbox checked={soAlertas} onChange={(e) => setSoAlertas(e.target.checked)}>Só com lugares em aberto ou acima do previsto</Checkbox>;
  const kpiCol = verSalarios ? 4 : 6;

  return (
    <>
      <Flex justify="end" style={{ marginBottom: 12 }}>
        <BotoesExportar
          obterPedido={() => ({
            titulo: 'Mapa de pessoal',
            filtros: [soAlertas ? 'Só com lugares em aberto ou acima do previsto' : 'Todos os lugares', ramo ? 'Unidades com as subunidades' : 'Unidades sem as subunidades'],
            conteudo: documentoMapaPessoal(d, linhasUnidade, linhasCargo, linhasPosto, { ramo, verSalarios }),
          })}
        />
      </Flex>
      <Row gutter={[12, 12]} style={{ marginBottom: 12 }}>
        <Col xs={12} md={kpiCol}><CartaoKpi rotulo="Lugares previstos" valor={t.previstos} formato="num" subtitulo={`${t.postos} posto(s)`} /></Col>
        <Col xs={12} md={kpiCol}><CartaoKpi rotulo="Ocupados" valor={t.ocupados} formato="num" subtitulo={taxa !== null ? `${taxa}% de ocupação` : null} /></Col>
        <Col xs={12} md={kpiCol}><CartaoKpi rotulo="Em aberto" valor={t.em_aberto} formato="num" alerta={t.em_aberto > 0} /></Col>
        <Col xs={12} md={kpiCol}><CartaoKpi rotulo="Acima do previsto" valor={t.acima} formato="num" alerta={t.acima > 0}
          subtitulo={`${t.colaboradores} activo(s); ${d.sem_unidade.colaboradores} sem unidade`} /></Col>
        {verSalarios && (
          <Col xs={24} md={8}>
            <CartaoKpi rotulo="Massa salarial mensal" valor={t.massa_salarial ?? '0.00'} formato="kz"
              subtitulo={d.periodo_salarial ? `Ilíquido do processamento de ${d.periodo_salarial}` : 'Sem processamento fechado'} />
          </Col>
        )}
      </Row>
      <Card>
        <Tabs activeKey={sep} onChange={setSep} items={[
          {
            key: 'unidades',
            label: 'Por unidade orgânica',
            children: (
              <>
                <Flex justify="space-between" wrap gap={8} style={{ marginBottom: 12 }}>
                  <Space wrap>{filtro}<Checkbox checked={ramo} onChange={(e) => setRamo(e.target.checked)}>Somar as subunidades</Checkbox></Space>
                  <BotaoCsv<LinhaUnidadeMapa> nome="mapa_pessoal_unidades" linhas={linhasUnidade}
                    colunas={[{ titulo: 'Código', valor: (l) => l.unidade.codigo ?? '' }, { titulo: 'Unidade', valor: (l) => l.unidade.nome }, ...csvNumeros(numUnidade)]} />
                </Flex>
                <Table<LinhaUnidadeMapa> size="small" rowKey="chave" dataSource={linhasUnidade} pagination={false} scroll={scrollTabela(600)}
                  columns={[
                    { title: 'Unidade', key: 'u', render: (_, l) => <span style={{ paddingLeft: l.nivel * 16, fontWeight: l.nivel === 0 ? 600 : 400 }}>{l.unidade.codigo ? `${l.unidade.codigo} — ` : ''}{l.unidade.nome}</span> },
                    ...colunasNumeros(numUnidade),
                  ]}
                  summary={() => <LinhaTotal c={d.sem_unidade} rotulo="Sem unidade orgânica" verSalarios={verSalarios} />} />
              </>
            ),
          },
          {
            key: 'cargos',
            label: 'Por cargo',
            children: (
              <>
                <Flex justify="space-between" wrap gap={8} style={{ marginBottom: 12 }}>
                  {filtro}
                  <BotaoCsv<CargoMapa> nome="mapa_pessoal_cargos" linhas={linhasCargo} colunas={[{ titulo: 'Cargo', valor: (l) => l.nome }, ...csvNumeros((l: CargoMapa) => l)]} />
                </Flex>
                <Table<CargoMapa> size="small" rowKey={(l) => String(l.cargo_funcao_id ?? 'sem')} dataSource={linhasCargo} pagination={false} scroll={scrollTabela(600)}
                  columns={[
                    { title: 'Cargo', dataIndex: 'nome', render: (v: string, l) => (l.cargo_funcao_id === null ? <Typography.Text type="secondary">{v}</Typography.Text> : v) },
                    ...colunasNumeros((l: CargoMapa) => l),
                  ]}
                  summary={() => <LinhaTotal c={t} rotulo="Total" verSalarios={verSalarios} />} />
              </>
            ),
          },
          {
            key: 'postos',
            label: 'Por posto de trabalho',
            children: (
              <>
                <Flex justify="space-between" wrap gap={8} style={{ marginBottom: 12 }}>
                  {filtro}
                  <BotaoCsv<LinhaMapa> nome="mapa_pessoal_postos" linhas={linhasPosto} colunas={[
                    { titulo: 'Unidade', valor: (l) => l.unidade }, { titulo: 'Posto', valor: (l) => l.posto }, { titulo: 'Chefia', valor: (l) => l.chefia },
                    { titulo: 'Vagas', valor: (l) => l.vagas, numerico: true }, { titulo: 'Ocupados', valor: (l) => l.ocupados, numerico: true },
                    { titulo: 'Em aberto', valor: (l) => l.livres, numerico: true }, { titulo: 'Acima', valor: (l) => l.acima, numerico: true },
                  ]} />
                </Flex>
                <Table<LinhaMapa> size="small" rowKey="chave" loading={estrutura.isLoading} dataSource={linhasPosto} pagination={false} scroll={scrollTabela(600)}
                  columns={[
                    { title: 'Unidade', dataIndex: 'unidade', render: (v: string, l) => <span style={{ paddingLeft: l.nivel * 16, fontWeight: l.nivel === 0 ? 600 : 400 }}>{v}</span> },
                    { title: 'Posto', dataIndex: 'posto', render: (v: string | null, l) => (v ? <Space size={4}>{v}{l.chefia && <Tag color="purple">chefia</Tag>}</Space> : <Typography.Text type="secondary">sem postos</Typography.Text>) },
                    { title: 'Vagas', dataIndex: 'vagas', align: 'right' },
                    { title: 'Ocupados', dataIndex: 'ocupados', align: 'right' },
                    { title: 'Em aberto', dataIndex: 'livres', align: 'right', render: (n: number) => contagem(n, 'orange') },
                    { title: 'Acima', dataIndex: 'acima', align: 'right', render: (n: number) => contagem(n, 'red') },
                  ]} />
              </>
            ),
          },
        ]} />
        {verSalarios && (
          <Typography.Paragraph type="secondary" style={{ marginTop: 12, marginBottom: 0 }}>
            A massa salarial é o ilíquido do último processamento fechado ou validado{d.periodo_salarial ? ` (${d.periodo_salarial})` : ''}, atribuído à unidade e ao cargo actuais de cada colaborador.
          </Typography.Paragraph>
        )}
      </Card>
    </>
  );
}

function LinhaTotal({ c, rotulo, verSalarios }: { c: ContagemMapa; rotulo: string; verSalarios: boolean }) {
  const valores: (number | string)[] = [c.previstos, c.ocupados, c.em_aberto, c.acima, c.colaboradores, ...(verSalarios ? [formatarKz(c.massa_salarial ?? '0.00')] : [])];
  return (
    <Table.Summary fixed>
      <Table.Summary.Row>
        <Table.Summary.Cell index={0}><strong>{rotulo}</strong></Table.Summary.Cell>
        {valores.map((v, i) => <Table.Summary.Cell key={i} index={i + 1} align="right"><strong>{v}</strong></Table.Summary.Cell>)}
      </Table.Summary.Row>
    </Table.Summary>
  );
}
