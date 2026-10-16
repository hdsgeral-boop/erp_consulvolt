import { Button, Card, DatePicker, Input, Select } from 'antd';
import { RollbackOutlined } from '@ant-design/icons';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { NavActivos } from './comum/NavActivos';
import { BotaoCartao } from './inventario/BotaoCartao';
import { BotoesExportar } from '@/componentes/impressao';
import { BarraFiltros } from '@/componentes/responsivo';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { BotaoCsv, ValorKz } from '@/modulos/contab/comum/Componentes';
import { somar } from '@/utilitarios/decimal';
import { ModalMotivo, useAccao } from '@/componentes/Accoes';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { pedidoTodasPaginas } from './comum/impressao';
import { EtiquetaActivos } from './comum/componentes';
import { filtroPeriodo, useListaPaginada } from './comum/paginacao';
import type { Abate } from './comum/tipos';
import { ModalAbate } from './ModalAbate';

import { TabelaComModos } from '@/componentes/vistas';
/** Activos › Abates e vendas (ecrã activos_abates): simular, abater/vender e anular (por estorno do lançamento). */
export default function Abates() {
  const { pode } = useSessao();
  const [novo, setNovo] = useState(false);
  const [anular, setAnular] = useState<Abate | null>(null);
  const [tipo, setTipo] = useState<string>();
  const [texto, setTexto] = useState('');
  const [periodo, setPeriodo] = useState<[Dayjs | null, Dayjs | null] | null>(null);
  // paginado e filtrado no servidor (ADR-064)
  const q = useListaPaginada<Abate>(['activos', 'abates'], '/ativos/abates', { tipo, pesquisa: texto || undefined, ...filtroPeriodo(periodo) });
  const accao = useAccao({ invalidar: [['activos']], aoSucesso: () => setAnular(null) });
  const linhas = q.itens;

  const colunas: ColunaApi<Abate>[] = [
    // colunas e ordem do legado (renderDisposalsView, ui_assets.js:2378-2384); Documento e Aquisição são extras em «Colunas ▾»
    { title: 'Nº Inventário', key: 'inv', render: (_, r) => <strong>{r.ativo_imobilizado?.codigo ?? '—'}</strong>, valorImpressao: (r) => r.ativo_imobilizado?.codigo ?? '—' },
    { title: 'Data', dataIndex: 'data', render: formatarData, valorImpressao: (r) => formatarData(r.data) },
    { title: 'Activo', key: 'a', render: (_, r) => r.ativo_imobilizado?.descricao ?? `#${r.ativo_imobilizado_id}`, valorImpressao: (r) => r.ativo_imobilizado?.descricao ?? `#${r.ativo_imobilizado_id}` },
    { title: 'Tipo', dataIndex: 'tipo', render: (v) => <EtiquetaActivos valor={v} />, valorImpressao: (r) => r.tipo },
    { title: 'Terceiro / Conta', key: 't', render: (_, r) => r.terceiro?.nome ?? '—', valorImpressao: (r) => r.terceiro?.nome ?? '—' },
    { title: 'Motivo', dataIndex: 'descricao', ellipsis: true, width: 220, render: (v) => v ?? '—', valorImpressao: (r) => r.descricao ?? '—' },
    { title: 'Valor', dataIndex: 'valor', align: 'right', total: true, render: (v) => <ValorKz valor={v} discretoSeZero />, valorImpressao: (r) => formatarKz(r.valor), totalImpressao: (ls) => formatarKz(somar(ls.map((l) => l.valor))) },
    { title: 'Documento', dataIndex: 'numero_documento', ocultaPorOmissao: true, render: (v) => v ?? '—' },
    { title: 'Aquisição', key: 'aq', align: 'right', ocultaPorOmissao: true, render: (_, r) => <ValorKz valor={r.ativo_imobilizado?.valor_aquisicao} /> },
    {
      title: '', key: 'acc', align: 'right', valorImpressao: () => '',
      render: (_, r) => pode('activos_abater') && <Button size="small" danger icon={<RollbackOutlined />} onClick={() => setAnular(r)}>Anular</Button>,
    },
  ];

  return (
    <>
      <NavActivos actual="activos_abates" />
      <Card
        title="Histórico de Abates"
        extra={pode('activos_abater') && <BotaoCartao icone="plus" variante="perigo" onClick={() => setNovo(true)}>Novo Abate</BotaoCartao>}
      >
        <BarraFiltros accoes={<>
          <BotoesExportar desactivado={!linhas.length} obterPedido={() => pedidoTodasPaginas('/ativos/abates', { tipo, pesquisa: texto || undefined, ...filtroPeriodo(periodo) }, {
            titulo: 'Abates e vendas de activos',
            periodo: periodo?.[0] || periodo?.[1] ? `${periodo?.[0]?.format('DD/MM/YYYY') ?? '…'} a ${periodo?.[1]?.format('DD/MM/YYYY') ?? '…'}` : undefined,
            filtros: [tipo ? `Tipo: ${tipo}` : null, texto ? `Pesquisa: ${texto}` : null],
            colunas,
          })} />
          <BotaoCsv nome="abates_pagina" linhas={linhas} colunas={[
            { titulo: 'Data', valor: (l) => formatarData(l.data) }, { titulo: 'Documento', valor: (l) => l.numero_documento },
            { titulo: 'Activo', valor: (l) => l.ativo_imobilizado?.codigo }, { titulo: 'Descrição', valor: (l) => l.ativo_imobilizado?.descricao },
            { titulo: 'Tipo', valor: (l) => l.tipo }, { titulo: 'Valor', valor: (l) => l.valor, numerico: true }, { titulo: 'Terceiro', valor: (l) => l.terceiro?.nome },
          ]} />
        </>}>
            <Input.Search placeholder="Activo ou descrição" allowClear onSearch={setTexto} style={{ width: 260 }} />
            <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} allowEmpty={[true, true]} placeholder={['Desde', 'Até']} />
            <Select placeholder="Tipo" allowClear value={tipo} onChange={setTipo} style={{ width: 160 }}
              options={[{ value: 'FIM_VIDA', label: 'Fim de vida' }, { value: 'VENDA', label: 'Venda' }, { value: 'SINISTRO', label: 'Sinistro' }]} />
        </BarraFiltros>
        <TabelaComModos<Abate>
          rowKey="id"
          size="middle"
          loading={q.isFetching}
          dataSource={linhas}
          pagination={q.paginacao}
          scroll={{ x: 'max-content' }}
          columns={colunas}
        />
      </Card>
      <ModalAbate aberto={novo} aoFechar={() => setNovo(false)} />
      <ModalMotivo
        aberto={!!anular}
        titulo={`Anular o abate de ${anular?.ativo_imobilizado?.codigo ?? ''}`}
        textoOk="Anular abate"
        aviso="O lançamento é estornado e o activo volta ao estado «Activo»."
        carregando={accao.isPending}
        aoFechar={() => setAnular(null)}
        aoConfirmar={(motivo) => anular && accao.mutate({ url: `/ativos/abates/${anular.id}/anular`, dados: { motivo } })}
      />
    </>
  );
}
