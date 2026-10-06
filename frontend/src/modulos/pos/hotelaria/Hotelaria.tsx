import { Alert, Badge, Button, Card, Checkbox, Col, DatePicker, Empty, Flex, InputNumber, Row, Select, Skeleton, Space, Tabs, Tag, Typography } from 'antd';
import { LoginOutlined, LogoutOutlined, SaveOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import type { Dayjs } from 'dayjs';
import { useEffect, useMemo, useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { TabelaApi } from '@/componentes/TabelaApi';
import { BotoesExportar, tabelaHtml } from '@/componentes/impressao';
import { BarraFiltros, scrollTabela } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import { useAccao } from '@/componentes/Accoes';
import { EstadoPOS, opcoesEstadoPOS, rotuloEstadoPOS } from '../comum/estados';
import { BarraSessao, useTerminalDeTrabalho } from '../comum/SessaoTerminal';
import { Checkout } from './Checkout';
import { DetalheEstadia, ModalCheckin } from './Estadia';
import type { Estadia, Quarto } from './tipos';

import { TabelaComModos } from '@/componentes/vistas';
/**
 * POS › Hotelaria (ecrã pos_hotelaria): mapa de quartos, check-in, consumos, check-out com rateio dos pagamentos,
 * anulação, histórico de estadias e tarifas dos quartos (ADR-050).
 */
export default function Hotelaria() {
  const { pode } = useSessao();
  const trabalho = useTerminalDeTrabalho('HOTELARIA');
  return (
    <>
      <CabecalhoPagina titulo="Hotelaria" accoes={<BarraSessao tipo="HOTELARIA" {...trabalho} />} />
      {trabalho.carregando ? (
        <Skeleton active />
      ) : (
        <Tabs
          destroyOnHidden
          items={[
            { key: 'mapa', label: 'Mapa de quartos', children: <Mapa terminalId={trabalho.terminalId} terminal={trabalho.terminal} /> },
            { key: 'estadias', label: 'Estadias', children: <ListaEstadias terminal={trabalho.terminal} /> },
            ...(pode('hotel_quartos') ? [{ key: 'tarifas', label: 'Tarifas dos quartos', children: <Tarifas /> }] : []),
          ]}
        />
      )}
    </>
  );
}

function useQuartos(terminalId: number | undefined) {
  return useQuery({ queryKey: ['pos', 'hotelaria', 'quartos', terminalId], queryFn: () => obter<Quarto[]>('/pos/hotelaria/quartos', { terminal_pos_id: terminalId }), refetchInterval: 60_000 });
}

function Mapa({ terminalId, terminal }: { terminalId: number | undefined; terminal: ReturnType<typeof useTerminalDeTrabalho>['terminal'] }) {
  const { pode } = useSessao();
  const quartos = useQuartos(terminalId);
  const [checkin, setCheckin] = useState<Quarto | null>(null);
  const [estadia, setEstadia] = useState<number | null>(null);
  const [seleccao, setSeleccao] = useState<number[]>([]);
  const [checkout, setCheckout] = useState<{ id: number; nome_quarto: string }[] | null>(null);
  useEffect(() => {
    if (quartos.error) notificarErro(quartos.error, 'Erro ao carregar o mapa de quartos');
  }, [quartos.error]);
  const ocupados = (quartos.data ?? []).filter((q) => q.estadia);
  const sessaoAberta = !!terminal?.sessao_aberta;
  const podeCheckout = pode('hotel_checkout') && sessaoAberta;
  const seleccionados = useMemo(() => ocupados.filter((q) => seleccao.includes(q.estadia!.id)).map((q) => ({ id: q.estadia!.id, nome_quarto: q.nome })), [ocupados, seleccao]);

  if (quartos.isLoading) return <Skeleton active />;
  if (!quartos.data?.length) return <Empty description="Não há quartos. Marque produtos como quarto nas tarifas ou no catálogo de produtos." />;

  const contagem = { livres: quartos.data.length - ocupados.length, ocupados: ocupados.length, atrasados: ocupados.filter((q) => q.estadia!.atrasado).length };

  return (
    <>
      <Flex justify="space-between" wrap gap={8} style={{ marginBottom: 12 }}>
        <Space wrap>
          <Badge status="success" text={`${contagem.livres} livre(s)`} />
          <Badge status="error" text={`${contagem.ocupados} ocupado(s)`} />
          {contagem.atrasados > 0 && <Badge status="warning" text={`${contagem.atrasados} com saída atrasada`} />}
        </Space>
        <BotoesExportar
          tamanho="small"
          obterPedido={() => ({
            titulo: 'Mapa de quartos',
            filtros: [`${contagem.livres} livre(s) · ${contagem.ocupados} ocupado(s)${contagem.atrasados ? ` · ${contagem.atrasados} com saída atrasada` : ''}`],
            conteudo: documentoMapaQuartos(quartos.data ?? []),
          })}
        />
        {podeCheckout && seleccionados.length > 0 && (
          <Button type="primary" icon={<LogoutOutlined />} onClick={() => setCheckout(seleccionados)}>
            Check-out de {seleccionados.length} quarto(s)
          </Button>
        )}
      </Flex>
      <Row gutter={[12, 12]}>
        {quartos.data.map((q) => {
          const e = q.estadia;
          const cor = !e ? '#52c41a' : e.atrasado ? '#faad14' : '#ff4d4f';
          return (
            <Col key={q.produto_id} xs={12} sm={8} md={6} xl={4}>
              <Card
                size="small"
                hoverable
                style={{ borderTop: `4px solid ${cor}`, height: '100%' }}
                onClick={() => (e ? setEstadia(e.id) : pode('hotel_estadias') && setCheckin(q))}
                title={
                  <Flex justify="space-between" align="center">
                    <span>{q.codigo}</span>
                    {e && podeCheckout && (
                      <Checkbox
                        checked={seleccao.includes(e.id)}
                        onClick={(ev) => ev.stopPropagation()}
                        onChange={(ev) => setSeleccao((s) => (ev.target.checked ? [...s, e.id] : s.filter((x) => x !== e.id)))}
                        aria-label={`Seleccionar ${q.nome} para check-out`}
                      />
                    )}
                  </Flex>
                }
              >
                <Typography.Text strong ellipsis style={{ display: 'block' }}>
                  {q.nome}
                </Typography.Text>
                {e ? (
                  <>
                    <Typography.Text ellipsis style={{ display: 'block' }}>
                      {e.nome_hospede}
                    </Typography.Text>
                    <Typography.Text type="secondary" style={{ fontSize: 12, display: 'block' }}>
                      Saída {formatarDataHora(e.saida_prevista_em)}
                    </Typography.Text>
                    <Flex justify="space-between" align="center">
                      {e.atrasado ? <Tag color="warning">Atrasado</Tag> : <Tag color="error">Ocupado</Tag>}
                      <b>{formatarKz(e.total_em_aberto)}</b>
                    </Flex>
                  </>
                ) : (
                  <>
                    <Typography.Text type="secondary" style={{ fontSize: 12, display: 'block' }}>
                      Diária {formatarKz(q.preco_por_dia)} · hora {formatarKz(q.preco_por_hora)}
                    </Typography.Text>
                    <Flex justify="space-between" align="center">
                      <Tag color="success">Livre</Tag>
                      {pode('hotel_estadias') && <LoginOutlined />}
                    </Flex>
                  </>
                )}
              </Card>
            </Col>
          );
        })}
      </Row>
      <ModalCheckin quarto={checkin} terminal={terminal} aoFechar={() => setCheckin(null)} />
      <DetalheEstadia
        id={estadia}
        terminal={terminal}
        aoFechar={() => setEstadia(null)}
        aoCheckout={(e: Estadia) => {
          setEstadia(null);
          setCheckout([{ id: e.id, nome_quarto: e.nome_quarto }]);
        }}
      />
      {terminal && (
        <Checkout
          aberto={!!checkout}
          estadias={checkout ?? []}
          terminal={terminal}
          aoFechar={() => {
            setCheckout(null);
            setSeleccao([]);
          }}
        />
      )}
    </>
  );
}

function ListaEstadias({ terminal }: { terminal: ReturnType<typeof useTerminalDeTrabalho>['terminal'] }) {
  const [estado, setEstado] = useState<string>();
  const [periodo, setPeriodo] = useState<[Dayjs | null, Dayjs | null] | null>(null);
  const [aberta, setAberta] = useState<number | null>(null);
  return (
    <>
      <BarraFiltros>
        <Select allowClear placeholder="Estado" style={{ width: 160 }} value={estado} onChange={setEstado} options={opcoesEstadoPOS(['ABERTA', 'FECHADA', 'ANULADA'])} />
        <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} />
      </BarraFiltros>
      <Alert
        type="info"
        showIcon
        style={{ marginBottom: 12 }}
        message="Se a listagem falhar com «erro interno», é a lacuna conhecida do GET /api/pos/hotelaria/estadias (registada para o backend); as estadias abertas continuam acessíveis no mapa."
      />
      <TabelaApi<Estadia>
        url="/pos/hotelaria/estadias"
        chaveConsulta={['pos', 'hotelaria', 'estadias']}
        filtros={{ estado, de: dataApi(periodo?.[0]), ate: dataApi(periodo?.[1]) }}
        impressao={{
          titulo: 'Estadias',
          periodo: periodo?.[0] && periodo[1] ? `${periodo[0].format('DD/MM/YYYY')} a ${periodo[1].format('DD/MM/YYYY')}` : undefined,
          filtros: [`Estado: ${estado ? rotuloEstadoPOS(estado) : 'Todos'}`],
        }}
        onRow={(e) => ({ onClick: () => setAberta(e.id), style: { cursor: 'pointer' } })}
        columns={[
          { title: 'Quarto', dataIndex: 'nome_quarto' },
          { title: 'Hóspede', dataIndex: 'nome_hospede' },
          { title: 'Entrada', dataIndex: 'entrada_em', render: (v) => formatarDataHora(v), responsive: ['md'] },
          { title: 'Saída', render: (_, e) => formatarDataHora(e.saida_em ?? e.saida_prevista_em) },
          { title: 'Modalidade', responsive: ['md'], render: (_, e) => `${e.modo === 'HORA' ? 'Hora' : 'Diária'} × ${Number(e.quantidade_final ?? e.quantidade)}` },
          { title: 'Preço', dataIndex: 'preco_unitario', align: 'right', render: (v) => formatarKz(v) },
          { title: 'Factura', dataIndex: 'numero_venda', render: (v) => v ?? '—', responsive: ['lg'] },
          { title: 'Estado', dataIndex: 'estado', render: (v) => <EstadoPOS estado={v} /> },
        ]}
      />
      <DetalheEstadia id={aberta} terminal={terminal} aoFechar={() => setAberta(null)} aoCheckout={() => setAberta(null)} />
    </>
  );
}

function Tarifas() {
  const quartos = useQuartos(undefined);
  const [edicao, setEdicao] = useState<Record<number, { preco_por_dia?: number | null; preco_por_hora?: number | null; horas_minimas?: number | null }>>({});
  const gravar = useAccao({ invalidar: [['pos', 'hotelaria'], ['logistica', 'catalogo']] });
  return (
    <>
      <Typography.Paragraph type="secondary">Os quartos são produtos marcados como quarto (criados no catálogo de produtos). Os preços incluem IVA.</Typography.Paragraph>
      <TabelaComModos<Quarto> idVista="quartos"
        rowKey="produto_id"
        size="small"
        scroll={scrollTabela()}
        pagination={false}
        loading={quartos.isFetching}
        dataSource={quartos.data}
        columns={[
          { title: 'Código', dataIndex: 'codigo' },
          { title: 'Quarto', dataIndex: 'nome' },
          { title: 'IVA', dataIndex: 'taxa_imposto', render: (v) => `${Number(v)}%` },
          ...(['preco_por_dia', 'preco_por_hora', 'horas_minimas'] as const).map((campo) => ({
            title: campo === 'preco_por_dia' ? 'Diária' : campo === 'preco_por_hora' ? 'Hora' : 'Mín. horas',
            key: campo,
            render: (_: unknown, q: Quarto) => (
              <InputNumber<number>
                min={0}
                precision={campo === 'horas_minimas' ? 1 : 2}
                decimalSeparator=","
                style={{ width: 130, maxWidth: '100%' }}
                value={edicao[q.produto_id]?.[campo] ?? Number(q[campo])}
                onChange={(v) => setEdicao((e) => ({ ...e, [q.produto_id]: { ...e[q.produto_id], [campo]: v } }))}
              />
            ),
          })),
          { title: 'Estado', dataIndex: 'estado', render: (v) => <EstadoPOS estado={v} /> },
          {
            title: '',
            key: 'a',
            render: (_, q) =>
              edicao[q.produto_id] && (
                <Button
                  size="small"
                  type="primary"
                  icon={<SaveOutlined />}
                  loading={gravar.isPending}
                  onClick={() =>
                    gravar.mutate(
                      { metodo: 'put', url: `/pos/hotelaria/quartos/${q.produto_id}`, dados: { preco_por_dia: Number(q.preco_por_dia), preco_por_hora: Number(q.preco_por_hora), horas_minimas: Number(q.horas_minimas), ...edicao[q.produto_id] } },
                      { onSuccess: () => setEdicao(({ [q.produto_id]: _x, ...resto }) => resto) },
                    )
                  }
                >
                  Gravar
                </Button>
              ),
          },
        ]}
      />
    </>
  );
}

/** Mapa de quartos (ocupação actual) para impressão. */
export function documentoMapaQuartos(quartos: Quarto[]): string {
  return tabelaHtml<Quarto>({
    linhas: quartos,
    colunas: [
      { titulo: 'Código', valor: (q) => q.codigo },
      { titulo: 'Quarto', valor: (q) => q.nome },
      { titulo: 'Situação', valor: (q) => (!q.estadia ? 'Livre' : q.estadia.atrasado ? 'Ocupado (saída atrasada)' : 'Ocupado') },
      { titulo: 'Hóspede', valor: (q) => q.estadia?.nome_hospede ?? '' },
      { titulo: 'Saída prevista', valor: (q) => (q.estadia ? formatarDataHora(q.estadia.saida_prevista_em) : '') },
      { titulo: 'Diária', valor: (q) => q.preco_por_dia, formato: 'moeda' },
      { titulo: 'Hora', valor: (q) => q.preco_por_hora, formato: 'moeda' },
      { titulo: 'Em aberto', valor: (q) => q.estadia?.total_em_aberto ?? null, formato: 'moeda', somar: true },
    ],
    totais: true,
  });
}
