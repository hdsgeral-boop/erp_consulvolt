import { Card, Checkbox, Col, Flex, Row, Skeleton, Space, Table, Tabs, Tag, Typography } from 'antd';
import type { ColumnsType } from 'antd/es/table';
import { useQuery } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { formatarKz } from '@/utilitarios/formatacao';
import { useCargos } from '@/modulos/rh/comum/consultas';
import { BotaoCsv } from '@/modulos/contab/comum/Componentes';
import { CartaoKpi } from '@/modulos/geral/comum/componentes';
import { construirArvore, mapaPessoal, type LinhaMapa } from './comum/arvore';
import { linhasPorUnidade, taxaOcupacao, type CargoMapa, type ContagemMapa, type LinhaUnidadeMapa, type MapaPessoalApi } from './comum/mapa';
import { useEstrutura } from './Estrutura';

const contagem = (n: number, cor: string) => (n ? <Tag color={cor}>{n}</Tag> : 0);

/**
 * Estrutura orgânica › Mapa de pessoal (est_mapa): lugares previstos, ocupados, em aberto e acima do previsto por
 * unidade orgânica, por cargo e por posto (GET /rh/estrutura/mapa, ADR-064). A massa salarial (ilíquido do último
 * processamento fechado) só vem do servidor a quem tem est_ver_salarios.
 */
export default function MapaPessoal() {
  const mapa = useQuery({ queryKey: ['rh', 'estrutura', 'mapa'], queryFn: () => obter<MapaPessoalApi>('/rh/estrutura/mapa') });
  const estrutura = useEstrutura();
  const cargos = useCargos();
  const [soAlertas, setSoAlertas] = useState(false);
  const [ramo, setRamo] = useState(true);

  const postos = useMemo(() => mapaPessoal(construirArvore(estrutura.data?.unidades ?? [], true), cargos.nome), [estrutura.data, cargos.nome]);
  const unidades = useMemo(() => linhasPorUnidade(mapa.data?.por_unidade ?? [], true), [mapa.data]);

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
      <CabecalhoPagina titulo="Mapa de pessoal" subtitulo="Lugares previstos e ocupados por unidade orgânica, cargo e posto de trabalho" />
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
        <Tabs items={[
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
                <Table<LinhaUnidadeMapa> size="small" rowKey="chave" dataSource={linhasUnidade} pagination={false} scroll={{ x: 'max-content', y: 600 }}
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
                <Table<CargoMapa> size="small" rowKey={(l) => String(l.cargo_funcao_id ?? 'sem')} dataSource={linhasCargo} pagination={false} scroll={{ x: 'max-content', y: 600 }}
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
                <Table<LinhaMapa> size="small" rowKey="chave" loading={estrutura.isLoading} dataSource={linhasPosto} pagination={false} scroll={{ x: 'max-content', y: 600 }}
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
