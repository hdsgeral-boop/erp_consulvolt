import { PlusOutlined } from '@ant-design/icons';
import { Alert, Button, Card, Col, Empty, Input, Row, Select, Space, Statistic, Table, Tag } from 'antd';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import { CabecalhoModulo } from '@/componentes/CabecalhoModulo';
import { notificarErro } from '@/utilitarios/erros';
import { useSessao } from '@/sessao/SessaoContexto';
import { CHAVES_NEG, listarNegocios, obterAvisosNegocios, obterListasNegocios, obterResumoNegocios, type Negocio } from './apiNegocios';
import { DetalheNegocio } from './DetalheNegocio';
import { COR_FASE, formatarMoeda, ROTULO_FASE, ROTULO_TIPO, rotuloResultado } from './logicaNegocios';
import { ModalNegocio } from './ModaisNegocio';
import './imobiliario.css';

/**
 * Gestão Imobiliária › Compra e Venda (ecrã imo_negocios): cada negócio é um processo — negociação, promessa, escritura, registo, concluído. É a escritura que transmite a propriedade e fixa os
 * impostos. Ver: imo_negocios_view · criar e editar: imo_neg_editar · aprovar: imo_neg_aprovar · fases, cancelar e contratos: imo_neg_fase · concluir: imo_neg_concluir · abater o bem vendido:
 * imo_neg_abater · pagamentos: imo_neg_pagamento (também recusadas no servidor; quem aprova não paga e quem conclui não abate).
 */
export default function CompraVenda() {
  const { pode } = useSessao();
  const ano = dayjs().year();
  const [tipo, setTipo] = useState('');
  const [estado, setEstado] = useState('');
  const [texto, setTexto] = useState('');
  const [aberto, setAberto] = useState<number | null>(null);
  const [novo, setNovo] = useState(false);
  const listas = useQuery({ queryKey: CHAVES_NEG.listas, queryFn: obterListasNegocios, staleTime: 5 * 60_000 });
  const resumo = useQuery({ queryKey: CHAVES_NEG.resumo(ano), queryFn: () => obterResumoNegocios(ano) });
  const avisos = useQuery({ queryKey: CHAVES_NEG.avisos, queryFn: obterAvisosNegocios });
  const lista = useQuery({ queryKey: CHAVES_NEG.lista(tipo, estado, texto), queryFn: () => listarNegocios({ tipo, estado, texto }) });
  useEffect(() => { if (lista.error) notificarErro(lista.error, 'Não foi possível carregar os negócios'); }, [lista.error]);
  useEffect(() => { if (resumo.error) notificarErro(resumo.error, 'Não foi possível carregar os indicadores'); }, [resumo.error]);
  const r = resumo.data;
  const pontos = avisos.data ?? [];
  const resultado = (r?.mais_valias ?? 0) + (r?.menos_valias ?? 0);

  return (
    <>
      <CabecalhoModulo titulo="Compra e Venda de Imóveis" icone="handshake" subtitulo="Cada negócio é um processo: negociação, promessa, escritura, registo. É a escritura que transmite a propriedade e fixa os impostos."
        accoes={pode('imo_neg_editar') ? <Button type="primary" icon={<PlusOutlined />} onClick={() => setNovo(true)}>Novo negócio</Button> : undefined} />
      <Row gutter={[12, 12]} className="imo-kpis">
        <Col xs={12} md={5}><Card size="small"><Statistic title={`Compras escrituradas em ${ano}`} value={r?.compras ?? 0} loading={resumo.isLoading} suffix={<small> — {formatarMoeda(r?.investido ?? 0)}</small>} /></Card></Col>
        <Col xs={12} md={5}><Card size="small"><Statistic title={`Vendas em ${ano}`} value={r?.vendas ?? 0} loading={resumo.isLoading} suffix={<small> — {formatarMoeda(r?.encaixado ?? 0)}</small>} /></Card></Col>
        <Col xs={12} md={5}><Card size="small"><Statistic title={`Resultado das vendas (${resultado >= 0 ? 'mais-valias' : 'menos-valias'})`} value={formatarMoeda(resultado)} loading={resumo.isLoading} /></Card></Col>
        <Col xs={12} md={5}><Card size="small"><Statistic title="Impostos da transmissão" value={formatarMoeda(r?.impostos ?? 0)} loading={resumo.isLoading} /></Card></Col>
        <Col xs={24} md={4}><Card size="small"><Statistic title="Processos abertos" value={r?.abertos ?? 0} loading={resumo.isLoading} suffix={r && r.sinais_em_curso > 0 ? <small> — {formatarMoeda(r.sinais_em_curso)} de sinais</small> : undefined} /></Card></Col>
      </Row>
      {pontos.length > 0 && <Alert type="warning" showIcon style={{ marginTop: 12 }} title={`${pontos.length} ponto(s) a tratar`} description={<ul className="imo-lista">{pontos.slice(0, 8).map((a) => <li key={`${a.tipo}-${a.negocio_id}`}>{a.texto}</li>)}</ul>} />}
      <Card style={{ marginTop: 12 }}>
        <Space wrap style={{ marginBottom: 12 }}>
          <label htmlFor="imo-neg-tipo">Tipo</label>
          <Select id="imo-neg-tipo" allowClear style={{ minWidth: 200 }} placeholder="Os dois" value={tipo || undefined} options={(listas.data?.tipos ?? []).map((t) => ({ value: t.chave, label: t.rotulo }))} onChange={(v) => setTipo(v ?? '')} />
          <label htmlFor="imo-neg-fase">Fase</label>
          <Select id="imo-neg-fase" allowClear style={{ minWidth: 220 }} placeholder="Todas" value={estado || undefined} options={(listas.data?.fases ?? []).map((t) => ({ value: t.chave, label: t.rotulo }))} onChange={(v) => setEstado(v ?? '')} />
          <Input.Search allowClear aria-label="Procurar por número, imóvel, fracção ou contraparte" placeholder="Número, imóvel, fracção ou contraparte" style={{ width: 320 }} onSearch={setTexto} />
        </Space>
        <Table<Negocio> rowKey="id" size="middle" loading={lista.isFetching} dataSource={lista.data ?? []} pagination={{ pageSize: 25, hideOnSinglePage: true }} scroll={{ x: 'max-content' }}
          locale={{ emptyText: <Empty description="Ainda não há negócios registados." /> }}
          columns={[
            { title: 'N.º', dataIndex: 'numero', key: 'n', render: (v: string) => <strong>{v}</strong> },
            { title: 'Tipo', dataIndex: 'tipo', key: 't', render: (v: Negocio['tipo']) => ROTULO_TIPO[v] },
            { title: 'Imóvel / fracção', key: 'i', render: (_: unknown, n: Negocio) => <span>{n.imovel_nome ? `${n.imovel_codigo} · ${n.imovel_nome}` : '—'}<br /><small>{n.fracao_designacao ?? (n.imovel_nome ? 'imóvel inteiro' : '')}</small></span> },
            { title: 'Contraparte', dataIndex: 'terceiro_nome', key: 'c', render: (v: string | null) => v ?? '—' },
            { title: 'Escritura', key: 'e', render: (_: unknown, n: Negocio) => n.data_escritura ?? (n.data_promessa ? `promessa ${n.data_promessa}` : '—') },
            { title: 'Preço', dataIndex: 'preco', key: 'p', align: 'right', render: (v: number) => formatarMoeda(v) },
            { title: 'Impostos', dataIndex: 'total_impostos', key: 'im', align: 'right', render: (v: number) => formatarMoeda(v) },
            { title: 'Resultado', key: 'r', align: 'right', render: (_: unknown, n: Negocio) => (n.resultado_contabilistico === null ? '—' : <span>{rotuloResultado(n.resultado_contabilistico)}<br /><strong>{formatarMoeda(n.resultado_contabilistico)}</strong></span>) },
            { title: 'Fase', dataIndex: 'estado', key: 'f', render: (v: Negocio['estado'], n: Negocio) => <span><Tag color={COR_FASE[v]}>{ROTULO_FASE[v]}</Tag>{n.aprovado_em === null && v !== 'CANCELADO' && v !== 'CONCLUIDO' ? <Tag>por aprovar</Tag> : null}</span> },
            { title: 'Acções', key: 'ac', render: (_: unknown, n: Negocio) => <Button size="small" aria-label={`Abrir o negócio ${n.numero}`} onClick={() => setAberto(n.id)}>Abrir</Button> },
          ]} />
      </Card>
      <ModalNegocio negocio={novo ? null : undefined} listas={listas.data} aoFechar={(g) => { setNovo(false); if (g) setAberto(g.id); }} />
      <DetalheNegocio id={aberto} listas={listas.data} pode={pode} aoFechar={() => setAberto(null)} />
    </>
  );
}
