import { Button, Card, DatePicker, Input, Select, Tag } from 'antd';
import { CalculatorOutlined } from '@ant-design/icons';
import { BarraFiltros, useEcraPequeno } from '@/componentes/responsivo';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { RecalculoValorizacoes } from './comum/RecalculoValorizacoes';
import { dataApi, formatarDataHora, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { NomeArmazem, NomeProduto, useArmazens, useMapaProdutos } from '@/modulos/compras/comum/referencias';
import { SeletorArmazem, SeletorProduto } from '@/modulos/compras/comum/Seletores';
import { ExtractoArtigo } from './comum/ExtractoArtigo';
import { TIPOS_MOVIMENTO, type Movimento } from './comum/tipos';

/** Armazém › Histórico de movimentos (ecrã armazem_movimentos): todos os movimentos de stock, com filtros e extracto do artigo. */
export default function Movimentos() {
  const [produto, setProduto] = useState<number>();
  const [armazem, setArmazem] = useState<number>();
  const [tipo, setTipo] = useState<string>();
  const [referencia, setReferencia] = useState('');
  const [periodo, setPeriodo] = useState<[Dayjs | null, Dayjs | null] | null>(null);
  const [extracto, setExtracto] = useState<Movimento | null>(null);
  const [recalcular, setRecalcular] = useState(false);
  const { pode } = useSessao();
  const pequeno = useEcraPequeno();
  const produtos = useMapaProdutos();
  const armazens = useArmazens();
  const nomeArmazem = (id: number | null | undefined) => (id ? armazens.data?.find((a) => a.id === id)?.nome ?? `#${id}` : '');
  const nomeProduto = (id: number) => {
    const p = produtos.get(id);
    return p ? `${p.codigo ? `${p.codigo} — ` : ''}${p.nome}` : `#${id}`;
  };

  const colunas: ColunaApi<Movimento>[] = [
    { title: 'Data', dataIndex: 'data', render: formatarDataHora },
    { title: 'Tipo', dataIndex: 'tipo', responsive: ['sm'], valorImpressao: (r) => `${TIPOS_MOVIMENTO[r.tipo] ?? r.tipo} · ${r.sentido === 'E' ? 'entrada' : 'saída'}`, render: (t: string, r) => <Tag color={r.sentido === 'E' ? 'green' : 'volcano'}>{TIPOS_MOVIMENTO[t] ?? t} · {r.sentido === 'E' ? 'entrada' : 'saída'}</Tag> },
    { title: 'Produto', dataIndex: 'produto_id', valorImpressao: (r) => nomeProduto(r.produto_id), render: (v: number) => <NomeProduto id={v} /> },
    { title: 'Armazém', dataIndex: 'armazem_id', responsive: ['md'], valorImpressao: (r) => `${nomeArmazem(r.armazem_id)}${r.armazem_contraparte_id ? ` ⇄ ${nomeArmazem(r.armazem_contraparte_id)}` : ''}`, render: (v: number, r) => <><NomeArmazem id={v} />{r.armazem_contraparte_id ? <> ⇄ <NomeArmazem id={r.armazem_contraparte_id} /></> : null}</> },
    { title: 'Quantidade', dataIndex: 'quantidade', align: 'right', render: (q: string, r) => `${r.sentido === 'S' ? '−' : '+'}${formatarNumero(q)}` },
    { title: 'Preço unit. (Kz)', dataIndex: 'preco_unitario', align: 'right', responsive: ['lg'], render: (v: string | null) => formatarKz(v) },
    { title: 'Valor (Kz)', dataIndex: 'valor', align: 'right', responsive: ['sm'], render: (v: string | null) => formatarKz(v) },
    { title: 'Custo médio após', dataIndex: 'custo_medio_apos', align: 'right', responsive: ['lg'], render: (v: string | null) => formatarKz(v) },
    { title: 'Referência', dataIndex: 'referencia', ellipsis: true, responsive: ['md'], render: (v) => v || '—' },
    { title: 'Utilizador', dataIndex: 'criado_por', responsive: ['lg'], render: (v) => v || '—' },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo="Histórico de movimentos"
        subtitulo="Entradas, saídas, transferências e ajustes de stock (clique numa linha para ver o extracto do artigo)"
        accoes={pode('armazem_recalcular') && <Button icon={<CalculatorOutlined />} onClick={() => setRecalcular(true)}>Recalcular valorizações</Button>}
      />
      <Card>
        <BarraFiltros>
          <SeletorProduto allowClear style={{ width: 300, maxWidth: '100%' }} value={produto} onChange={setProduto} />
          <SeletorArmazem allowClear placeholder="Todos os armazéns" style={{ width: 220, maxWidth: '100%' }} value={armazem} onChange={setArmazem} />
          <Select placeholder="Tipo" allowClear style={{ width: 170 }} value={tipo} onChange={setTipo} options={Object.entries(TIPOS_MOVIMENTO).map(([value, label]) => ({ value, label }))} />
          <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} />
          <Input.Search placeholder="Referência" allowClear style={{ width: 220, maxWidth: '100%' }} onSearch={setReferencia} />
        </BarraFiltros>
        <TabelaApi<Movimento>
          modos={false}
          url="/logistica/movimentos"
          chaveConsulta={['logistica', 'movimentos']}
          porPagina={50}
          filtros={{ produto_id: produto, armazem_id: armazem, tipo, referencia, de: dataApi(periodo?.[0]), ate: dataApi(periodo?.[1]) }}
          columns={colunas}
          size={pequeno ? 'small' : 'middle'}
          impressao={{
            titulo: 'Histórico de movimentos de stock',
            periodo: periodo?.[0] && periodo?.[1] ? `${periodo[0].format('DD/MM/YYYY')} a ${periodo[1].format('DD/MM/YYYY')}` : undefined,
            filtros: [
              !!produto && `Produto: ${nomeProduto(produto)}`,
              !!armazem && `Armazém: ${nomeArmazem(armazem)}`,
              tipo && `Tipo: ${TIPOS_MOVIMENTO[tipo] ?? tipo}`,
              referencia && `Referência: ${referencia}`,
            ],
          }}
          onRow={(r) => ({ onClick: () => setExtracto(r), style: { cursor: 'pointer' } })}
        />
      </Card>
      <ExtractoArtigo key={extracto?.id ?? 'nenhum'} produtoId={extracto?.produto_id ?? null} armazemInicial={extracto?.armazem_id} aoFechar={() => setExtracto(null)} />
      {recalcular && <RecalculoValorizacoes aberto aoFechar={() => setRecalcular(false)} />}
    </>
  );
}
