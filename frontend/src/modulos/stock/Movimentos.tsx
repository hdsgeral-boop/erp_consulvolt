import { Button, Card, Col, DatePicker, Flex, Input, Row, Select, Typography } from 'antd';
import { useEcraPequeno } from '@/componentes/responsivo';
import type { Dayjs } from 'dayjs';
import { useState, type ReactNode } from 'react';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { RecalculoValorizacoes } from './comum/RecalculoValorizacoes';
import { dataApi, formatarData, formatarDataHora, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { useArmazens, useMapaProdutos } from '@/modulos/compras/comum/referencias';
import { SeletorArmazem, SeletorProduto } from '@/modulos/compras/comum/Seletores';
import { ExtractoArtigo } from './comum/ExtractoArtigo';
import { eMigracao, rotuloTipoMovimento, sentidoDe, type MovimentoArmazem } from './comum/paridade';
import { TIPOS_MOVIMENTO } from './comum/tipos';

interface Filtros {
  produto?: number;
  armazem?: number;
  tipo?: string;
  de?: Dayjs | null;
  ate?: Dayjs | null;
  referencia: string;
}

const VAZIO: Filtros = { referencia: '' };

/** Rótulo dos filtros como no legado (0.65rem, negrito, maiúsculas, #475569). */
function Campo({ rotulo, children }: { rotulo: string; children: ReactNode }) {
  return (
    <label style={{ display: 'block' }}>
      <span style={{ display: 'block', fontSize: '0.65rem', fontWeight: 800, color: '#475569', textTransform: 'uppercase', marginBottom: 6 }}>{rotulo}</span>
      {children}
    </label>
  );
}

/**
 * Armazém › histórico (ecrã armazem_movimentos), como o «histórico de Movimentações» do legado (renderMovimentosTab,
 * js/ui_warehouse.js:1226): filtros com rótulos, «Limpar» / «Recalcular Valorizações» / «Filtrar», terceiro (cliente ou
 * fornecedor), nome do armazém e tipo «Saída»/«Entrada»/«Ajuste (saída)». Clicar numa linha abre o extracto do artigo.
 */
export default function Movimentos() {
  const [rascunho, setRascunho] = useState<Filtros>(VAZIO);
  const [aplicados, setAplicados] = useState<Filtros>(VAZIO);
  const [extracto, setExtracto] = useState<MovimentoArmazem | null>(null);
  const [recalcular, setRecalcular] = useState(false);
  const { pode } = useSessao();
  const pequeno = useEcraPequeno();
  const produtos = useMapaProdutos();
  const armazens = useArmazens();
  const nomeArmazemFiltro = (id: number) => armazens.data?.find((a) => a.id === id)?.nome ?? 'N/D';
  const nomeProduto = (id: number) => {
    const p = produtos.get(id);
    return p ? `${p.codigo ? `${p.codigo} — ` : ''}${p.nome}` : `#${id}`;
  };
  // legado (ui_warehouse.js:1290): na tabela só o nome do produto
  const soNomeProduto = (id: number) => produtos.get(id)?.nome ?? 'N/D';
  const aplicar = (f: Filtros) => {
    setRascunho(f);
    setAplicados(f);
  };
  const mudar = (parcial: Partial<Filtros>) => aplicar({ ...rascunho, ...parcial });   // como no legado: os selectores filtram logo

  const nomeArmazem = (m: MovimentoArmazem) => `${m.armazem ?? 'N/D'}${m.armazem_contraparte_id ? ` ⇄ ${m.armazem_contraparte ?? 'N/D'}` : ''}`;
  const valorComSinal = (m: MovimentoArmazem) => `${sentidoDe(m) === 'E' ? '+' : '-'}${formatarKz(m.valor ?? Number(m.quantidade) * Number(m.preco_unitario ?? 0))}`;

  // Colunas e ordem do legado (ui_warehouse.js:1275-1282); preço unitário, custo médio após e utilizador em «Colunas ▾».
  const colunas: ColunaApi<MovimentoArmazem>[] = [
    { title: 'Data/Hora', dataIndex: 'data', valorImpressao: (m) => (eMigracao(m) ? formatarData(m.data) : formatarDataHora(m.data)), render: (v: string, m) => <small>{eMigracao(m) ? formatarData(v) : formatarDataHora(v)}</small> },
    { title: 'Produto', dataIndex: 'produto_id', valorImpressao: (m) => nomeProduto(m.produto_id), render: (v: number) => <strong>{soNomeProduto(v)}</strong> },
    { title: 'Terceiro (Cli/For)', dataIndex: 'terceiro', render: (v: string | null) => v?.trim() || <Typography.Text type="secondary">-</Typography.Text>, valorImpressao: (m) => m.terceiro?.trim() || '-' },
    { title: 'Armazém', key: 'armazem', valorImpressao: nomeArmazem, render: (_, m) => nomeArmazem(m) },
    { title: 'Tipo', key: 'tipo', align: 'center', valorImpressao: rotuloTipoMovimento, // legado: tipo em texto e em maiúsculas (SAÍDA / ENTRADA)
      render: (_, m) => rotuloTipoMovimento(m).toUpperCase() },
    { title: 'Qtd', dataIndex: 'quantidade', align: 'right', render: (q: string) => <strong>{formatarNumero(q)}</strong> },
    { title: 'Valor Movimento', dataIndex: 'valor', align: 'right', valorImpressao: valorComSinal, render: (_, m) => <strong style={{ color: sentidoDe(m) === 'E' ? '#10b981' : '#ef4444' }}>{valorComSinal(m)} Kz</strong> },
    { title: 'Documento / Referência', dataIndex: 'referencia', ellipsis: true, render: (v: string | null) => (v ? <Typography.Link><IconeFa nome="external-link-alt" style={{ fontSize: '0.7rem', marginRight: 4, opacity: 0.6 }} />{v}</Typography.Link> : '-') },
    { title: 'Preço unit. (Kz)', dataIndex: 'preco_unitario', align: 'right', ocultaPorOmissao: true, render: (v: string | null) => formatarKz(v) },
    { title: 'Custo médio após', dataIndex: 'custo_medio_apos', align: 'right', ocultaPorOmissao: true, render: (v: string | null) => formatarKz(v) },
    { title: 'Utilizador', dataIndex: 'criado_por', ocultaPorOmissao: true, render: (v) => v || '—' },
  ];

  return (
    <>
      <Card
        title={<><IconeFa nome="history" /> histórico de Movimentações</>}
        extra={
          <Flex gap={8} wrap>
            <Button size="small" title="Limpar Filtros" icon={<IconeFa nome="undo" />} onClick={() => aplicar(VAZIO)}>Limpar</Button>
            {pode('armazem_recalcular') && (
              <Button size="small" title="Recalcular valores em falta nos movimentos históricos" icon={<IconeFa nome="calculator" />} onClick={() => setRecalcular(true)}>
                Recalcular Valorizações
              </Button>
            )}
            <Button size="small" type="primary" icon={<IconeFa nome="search" />} onClick={() => setAplicados(rascunho)}>Filtrar</Button>
          </Flex>
        }
      >
        <div style={{ background: '#f1f5f9', padding: pequeno ? 12 : 20, borderRadius: 12, border: '1px solid #e2e8f0', marginBottom: 16 }}>
          <Row gutter={[12, 12]} align="bottom">
            <Col xs={24} md={8} xl={6}>
              <Campo rotulo="Material">
                <SeletorProduto allowClear placeholder="[Todos]" style={{ width: '100%' }} value={rascunho.produto} onChange={(v) => mudar({ produto: v })} />
              </Campo>
            </Col>
            <Col xs={24} sm={12} md={8} xl={4}>
              <Campo rotulo="Armazém">
                <SeletorArmazem allowClear placeholder="[Todos]" style={{ width: '100%' }} value={rascunho.armazem} onChange={(v) => mudar({ armazem: v })} />
              </Campo>
            </Col>
            <Col xs={24} sm={12} md={8} xl={3}>
              <Campo rotulo="Tipo">
                <Select allowClear placeholder="[Todos]" style={{ width: '100%' }} value={rascunho.tipo} onChange={(v) => mudar({ tipo: v })} options={Object.entries(TIPOS_MOVIMENTO).map(([value, label]) => ({ value, label: label.toUpperCase() }))} />
              </Campo>
            </Col>
            <Col xs={12} md={6} xl={3}>
              <Campo rotulo="Desde">
                <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} value={rascunho.de} onChange={(v) => mudar({ de: v })} />
              </Campo>
            </Col>
            <Col xs={12} md={6} xl={3}>
              <Campo rotulo="Até">
                <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} value={rascunho.ate} onChange={(v) => mudar({ ate: v })} />
              </Campo>
            </Col>
            <Col xs={24} md={12} xl={5}>
              <Campo rotulo="Referência">
                <Input placeholder="Procurar..." allowClear value={rascunho.referencia} onChange={(e) => setRascunho({ ...rascunho, referencia: e.target.value })} onPressEnter={() => setAplicados(rascunho)} />
              </Campo>
            </Col>
          </Row>
        </div>
        <TabelaApi<MovimentoArmazem>
          modos={false}
          url="/logistica/paridade/movimentos"
          chaveConsulta={['logistica', 'paridade', 'movimentos']}
          porPagina={50}
          filtros={{ produto_id: aplicados.produto, armazem_id: aplicados.armazem, tipo: aplicados.tipo, referencia: aplicados.referencia.trim() || undefined, de: dataApi(aplicados.de), ate: dataApi(aplicados.ate) }}
          columns={colunas}
          size={pequeno ? 'small' : 'middle'}
          vazio={{ icone: 'history', titulo: 'Nenhum movimento encontrado com os filtros selecionados.' }}
          impressao={{
            titulo: 'histórico de Movimentações',
            periodo: aplicados.de && aplicados.ate ? `${aplicados.de.format('DD/MM/YYYY')} a ${aplicados.ate.format('DD/MM/YYYY')}` : undefined,
            filtros: [
              !!aplicados.produto && `Material: ${nomeProduto(aplicados.produto)}`,
              !!aplicados.armazem && `Armazém: ${nomeArmazemFiltro(aplicados.armazem)}`,
              aplicados.tipo && `Tipo: ${TIPOS_MOVIMENTO[aplicados.tipo] ?? aplicados.tipo}`,
              aplicados.referencia.trim() && `Referência: ${aplicados.referencia.trim()}`,
            ],
          }}
          onRow={(r) => ({ onClick: () => setExtracto(r), style: { cursor: 'pointer' } })}
        />
      </Card>
      <ExtractoArtigo key={extracto?.id ?? 'nenhum'} produtoId={extracto?.produto_id ?? null} armazemInicial={extracto?.armazem ? extracto.armazem_id ?? undefined : undefined} aoFechar={() => setExtracto(null)} />
      {recalcular && <RecalculoValorizacoes aberto aoFechar={() => setRecalcular(false)} />}
    </>
  );
}
