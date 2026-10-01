import { Alert, Card, Checkbox, Col, Flex, Row, Skeleton, Space, Table, Tag, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { useCargos } from '@/modulos/rh/comum/consultas';
import { BotaoCsv } from '@/modulos/contab/comum/Componentes';
import { CartaoKpi } from '@/modulos/geral/comum/componentes';
import { construirArvore, mapaPessoal, totaisMapa, type LinhaMapa } from './comum/arvore';
import { useEstrutura } from './Estrutura';

interface PainelEstrutura {
  kpis: { id: string; rotulo: string; valor: unknown; formato: string; subtitulo?: string | null }[];
}

/** Estrutura orgânica › Mapa de pessoal (est_mapa): vagas previstas, ocupadas, em aberto e acima do previsto, por unidade e posto. */
export default function MapaPessoal() {
  const { pode } = useSessao();
  const estrutura = useEstrutura();
  const cargos = useCargos();
  const [soAlertas, setSoAlertas] = useState(false);
  const verSalarios = pode('est_ver_salarios');
  // a massa salarial só existe agregada no painel da estrutura (GET /gestao/paineis/estrutura, que respeita est_ver_salarios)
  const painel = useQuery({
    queryKey: ['gestao', 'painel', 'estrutura', 'mapa'],
    queryFn: () => obter<PainelEstrutura>('/gestao/paineis/estrutura'),
    enabled: verSalarios,
    retry: false,
  });
  const massa = painel.data?.kpis.find((k) => k.id === 'massa_salarial');

  const linhas = useMemo(() => mapaPessoal(construirArvore(estrutura.data?.unidades ?? [], true), cargos.nome), [estrutura.data, cargos.nome]);
  const visiveis = soAlertas ? linhas.filter((l) => l.livres > 0 || l.acima > 0) : linhas;
  const t = totaisMapa(linhas);

  if (estrutura.isLoading) return <Skeleton active />;

  return (
    <>
      <CabecalhoPagina titulo="Mapa de pessoal" subtitulo="Lugares previstos e ocupados em cada unidade orgânica e posto de trabalho" />
      <Row gutter={[12, 12]} style={{ marginBottom: 12 }}>
        <Col xs={12} md={verSalarios ? 4 : 6}><CartaoKpi rotulo="Vagas previstas" valor={t.vagas} formato="num" /></Col>
        <Col xs={12} md={verSalarios ? 4 : 6}><CartaoKpi rotulo="Ocupadas" valor={t.ocupados} formato="num" subtitulo={t.taxa !== null ? `${t.taxa}% de ocupação` : null} /></Col>
        <Col xs={12} md={verSalarios ? 4 : 6}><CartaoKpi rotulo="Em aberto" valor={t.livres} formato="num" alerta={t.livres > 0} /></Col>
        <Col xs={12} md={verSalarios ? 4 : 6}><CartaoKpi rotulo="Acima do previsto" valor={t.acima} formato="num" alerta={t.acima > 0} subtitulo={`${estrutura.data?.sem_unidade ?? 0} colaborador(es) sem unidade`} /></Col>
        {verSalarios && (
          <Col xs={24} md={8}>
            {massa ? <CartaoKpi rotulo={massa.rotulo} valor={massa.valor} formato={massa.formato} subtitulo={massa.subtitulo} /> : <Card size="small" style={{ height: '100%' }}><Typography.Text type="secondary">Massa salarial indisponível{painel.error ? ' (exige acesso ao Dashboard)' : ''}.</Typography.Text></Card>}
          </Col>
        )}
      </Row>
      <Card>
        <Flex justify="space-between" wrap gap={8} style={{ marginBottom: 12 }}>
          <Checkbox checked={soAlertas} onChange={(e) => setSoAlertas(e.target.checked)}>Só postos com vagas em aberto ou acima do previsto</Checkbox>
          <BotaoCsv<LinhaMapa>
            nome="mapa_pessoal"
            linhas={visiveis}
            colunas={[
              { titulo: 'Unidade', valor: (l) => l.unidade },
              { titulo: 'Posto', valor: (l) => l.posto },
              { titulo: 'Chefia', valor: (l) => l.chefia },
              { titulo: 'Vagas', valor: (l) => l.vagas, numerico: true },
              { titulo: 'Ocupados', valor: (l) => l.ocupados, numerico: true },
              { titulo: 'Em aberto', valor: (l) => l.livres, numerico: true },
              { titulo: 'Acima', valor: (l) => l.acima, numerico: true },
            ]}
          />
        </Flex>
        <Table<LinhaMapa>
          size="small"
          rowKey="chave"
          dataSource={visiveis}
          pagination={false}
          scroll={{ x: 'max-content', y: 600 }}
          columns={[
            { title: 'Unidade', dataIndex: 'unidade', render: (v: string, l) => <span style={{ paddingLeft: l.nivel * 16, fontWeight: l.nivel === 0 ? 600 : 400 }}>{v}</span> },
            { title: 'Posto', dataIndex: 'posto', render: (v: string | null, l) => (v ? <Space size={4}>{v}{l.chefia && <Tag color="purple">chefia</Tag>}</Space> : <Typography.Text type="secondary">sem postos</Typography.Text>) },
            { title: 'Vagas', dataIndex: 'vagas', align: 'right' },
            { title: 'Ocupados', dataIndex: 'ocupados', align: 'right' },
            { title: 'Em aberto', dataIndex: 'livres', align: 'right', render: (n: number) => (n ? <Tag color="orange">{n}</Tag> : 0) },
            { title: 'Acima', dataIndex: 'acima', align: 'right', render: (n: number) => (n ? <Tag color="red">{n}</Tag> : 0) },
          ]}
          summary={() => (
            <Table.Summary fixed>
              <Table.Summary.Row>
                <Table.Summary.Cell index={0} colSpan={2}><strong>Total</strong></Table.Summary.Cell>
                <Table.Summary.Cell index={1} align="right"><strong>{t.vagas}</strong></Table.Summary.Cell>
                <Table.Summary.Cell index={2} align="right"><strong>{t.ocupados}</strong></Table.Summary.Cell>
                <Table.Summary.Cell index={3} align="right"><strong>{t.livres}</strong></Table.Summary.Cell>
                <Table.Summary.Cell index={4} align="right"><strong>{t.acima}</strong></Table.Summary.Cell>
              </Table.Summary.Row>
            </Table.Summary>
          )}
        />
        {verSalarios && <Alert style={{ marginTop: 12 }} type="info" showIcon message="A massa salarial por unidade e por cargo ainda não tem API; mostra-se só o total da empresa (ilíquido do último processamento fechado)." />}
      </Card>
    </>
  );
}
