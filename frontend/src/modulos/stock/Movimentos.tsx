import { Button, Card, DatePicker, Flex, Input, Select, Tag } from 'antd';
import { CalculatorOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { TabelaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { RecalculoValorizacoes } from './comum/RecalculoValorizacoes';
import { dataApi, formatarDataHora, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { NomeArmazem, NomeProduto } from '@/modulos/compras/comum/referencias';
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

  const colunas: ColumnsType<Movimento> = [
    { title: 'Data', dataIndex: 'data', render: formatarDataHora },
    { title: 'Tipo', dataIndex: 'tipo', render: (t: string, r) => <Tag color={r.sentido === 'E' ? 'green' : 'volcano'}>{TIPOS_MOVIMENTO[t] ?? t} · {r.sentido === 'E' ? 'entrada' : 'saída'}</Tag> },
    { title: 'Produto', dataIndex: 'produto_id', render: (v: number) => <NomeProduto id={v} /> },
    { title: 'Armazém', dataIndex: 'armazem_id', render: (v: number, r) => <><NomeArmazem id={v} />{r.armazem_contraparte_id ? <> ⇄ <NomeArmazem id={r.armazem_contraparte_id} /></> : null}</> },
    { title: 'Quantidade', dataIndex: 'quantidade', align: 'right', render: (q: string, r) => `${r.sentido === 'S' ? '−' : '+'}${formatarNumero(q)}` },
    { title: 'Preço unit. (Kz)', dataIndex: 'preco_unitario', align: 'right', render: (v: string | null) => formatarKz(v) },
    { title: 'Valor (Kz)', dataIndex: 'valor', align: 'right', render: (v: string | null) => formatarKz(v) },
    { title: 'Custo médio após', dataIndex: 'custo_medio_apos', align: 'right', render: (v: string | null) => formatarKz(v) },
    { title: 'Referência', dataIndex: 'referencia', ellipsis: true, render: (v) => v || '—' },
    { title: 'Utilizador', dataIndex: 'criado_por', render: (v) => v || '—' },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo="Histórico de movimentos"
        subtitulo="Entradas, saídas, transferências e ajustes de stock (clique numa linha para ver o extracto do artigo)"
        accoes={pode('armazem_recalcular') && <Button icon={<CalculatorOutlined />} onClick={() => setRecalcular(true)}>Recalcular valorizações</Button>}
      />
      <Card>
        <Flex gap={8} wrap style={{ marginBottom: 16 }}>
          <SeletorProduto allowClear style={{ width: 300 }} value={produto} onChange={setProduto} />
          <SeletorArmazem allowClear placeholder="Todos os armazéns" style={{ width: 220 }} value={armazem} onChange={setArmazem} />
          <Select placeholder="Tipo" allowClear style={{ width: 170 }} value={tipo} onChange={setTipo} options={Object.entries(TIPOS_MOVIMENTO).map(([value, label]) => ({ value, label }))} />
          <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} />
          <Input.Search placeholder="Referência" allowClear style={{ width: 220 }} onSearch={setReferencia} />
        </Flex>
        <TabelaApi<Movimento>
          url="/logistica/movimentos"
          chaveConsulta={['logistica', 'movimentos']}
          porPagina={50}
          filtros={{ produto_id: produto, armazem_id: armazem, tipo, referencia, de: dataApi(periodo?.[0]), ate: dataApi(periodo?.[1]) }}
          columns={colunas}
          onRow={(r) => ({ onClick: () => setExtracto(r), style: { cursor: 'pointer' } })}
        />
      </Card>
      <ExtractoArtigo key={extracto?.id ?? 'nenhum'} produtoId={extracto?.produto_id ?? null} armazemInicial={extracto?.armazem_id} aoFechar={() => setExtracto(null)} />
      {recalcular && <RecalculoValorizacoes aberto aoFechar={() => setRecalcular(false)} />}
    </>
  );
}
