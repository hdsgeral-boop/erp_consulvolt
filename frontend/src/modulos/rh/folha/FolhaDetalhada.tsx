import { Button, Space, Tooltip, Typography } from 'antd';
import { WarningOutlined } from '@ant-design/icons';
import { useMemo, useState, type Key, type ReactNode } from 'react';
import { IconeFa } from '@/componentes/fluxos';
import { useImpressao } from '@/componentes/impressao';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { TabelaComModos, type AccaoLinha } from '@/componentes/vistas';
import { formatarKz } from '@/utilitarios/formatacao';
import type { DetalhePeriodo, ResultadoSalarial } from '../api';
import { pedidoSimulacaoPeriodo } from '../comum/simulacao';
import { colunasFolha, colunasRemuneracoes, CORES_CABECALHO, csvFolha, descarregarCsv, horasRubrica, separarFolhas, totaisFolha, type GrupoFolha } from './regrasFolha';

interface Props {
  periodo: DetalhePeriodo;
  nome: (r: ResultadoSalarial) => string;
  carregando?: boolean;
  /** Título do cartão (ex.: «Mapa Salarial Detalhado — 09/2026»). */
  titulo?: ReactNode;
  /** Acções por linha (ex.: «Detalhar», «Imprimir»). */
  accoes?: (r: ResultadoSalarial) => (AccaoLinha | null | undefined | false)[];
  /** Selecção por linha (Relatórios e Recibos: «Gerar Recibos Selecionados»). */
  seleccao?: { chaves: Key[]; aoMudar: (chaves: Key[], linhas: ResultadoSalarial[]) => void };
  /** Botões extra à esquerda (ex.: «Gerar Recibos Selecionados», «Extrair»). */
  extra?: (grupo: GrupoFolha, linhas: ResultadoSalarial[]) => ReactNode;
  /** Grupo controlado de fora (barra «Folha: Colaboradores / Avençados» dos Relatórios). */
  grupo?: GrupoFolha;
  aoMudarGrupo?: (g: GrupoFolha) => void;
  /** Esconde os botões de grupo (quando a barra de grupos está noutro sítio). */
  semBotoesGrupo?: boolean;
  /**
   * Colunas: «folha» = Folha de Colaboradores dos Processamentos (legado `tabelaDetalhada`); «remuneracoes» = Mapa
   * Detalhado de Remunerações dos Relatórios (legado `loadReportTabs`, cabeçalho claro como no antigo).
   */
  variante?: 'folha' | 'remuneracoes';
  /** Título da coluna de acções (o Mapa de Remunerações do antigo usa «Ação»). */
  tituloAccoes?: ReactNode;
}

const cabecalho = (cor: string) => () => ({ style: { background: cor, color: '#fff', fontWeight: 700, whiteSpace: 'nowrap' as const } });
/** Mapa de Remunerações do antigo: cabeçalho claro; só Total Ilíquido (#f1f5f9) e Líquido (#d1fae5) com fundo. */
const cabecalhoClaro = (chave: string) => () => ({ style: { whiteSpace: 'nowrap' as const, ...(chave === 'bruto' ? { background: '#f1f5f9' } : chave === 'liquido' ? { background: '#d1fae5' } : {}) } });

/**
 * Mapa salarial detalhado com uma coluna por rubrica e folhas separadas «Folha de Colaboradores (N)» / «Folha de
 * Avençados (N)», cada uma com a sua linha TOTAIS (legado `renderMapaProcessamento`). Imprimir (com assinaturas),
 * Excel e Exportar CSV da folha visível.
 */
export function FolhaDetalhada({ periodo, nome, carregando, titulo, accoes, seleccao, extra, grupo: grupoExterno, aoMudarGrupo, semBotoesGrupo, variante = 'folha', tituloAccoes }: Props) {
  const folhas = useMemo(() => separarFolhas(periodo.resultados, nome), [periodo.resultados, nome]);
  const inicial: GrupoFolha = folhas.colaboradores.length || !folhas.avencados.length ? 'colaboradores' : 'avencados';
  const [grupoInterno, setGrupoInterno] = useState<GrupoFolha>(inicial);
  const grupo = grupoExterno ?? grupoInterno;
  const mudarGrupo = (g: GrupoFolha) => { setGrupoInterno(g); aoMudarGrupo?.(g); };
  const dados = folhas[grupo];
  const defs = useMemo(() => (variante === 'remuneracoes' ? colunasRemuneracoes : colunasFolha)(dados, nome, periodo.resultados), [variante, dados, nome, periodo.resultados]);
  const totais = useMemo(() => totaisFolha(dados, defs), [dados, defs]);
  const { imprimir, aImprimir } = useImpressao();
  const rotuloGrupo = grupo === 'avencados' ? 'Avencados' : 'Colaboradores';

  const colunas: ColunaApi<ResultadoSalarial>[] = defs.map((c): ColunaApi<ResultadoSalarial> => {
    const base: ColunaApi<ResultadoSalarial> = {
      key: c.chave,
      title: c.titulo,
      onHeaderCell: variante === 'remuneracoes' ? cabecalhoClaro(c.chave) : cabecalho(CORES_CABECALHO[c.classe]),
      valorImpressao: (r: ResultadoSalarial) => String(c.valor(r, dados.indexOf(r))),
      ...(c.ocultaPorOmissao ? { ocultaPorOmissao: true } : {}),
    };
    if (c.chave === 'n') return { ...base, align: 'center', width: 48, filtravel: false, render: (_: unknown, r: ResultadoSalarial) => dados.indexOf(r) + 1 };
    if (c.chave === 'nome') {
      return {
        ...base, ocultavel: false, valorFiltro: (r: ResultadoSalarial) => nome(r),
        render: (_: unknown, r: ResultadoSalarial) => (
          <span style={{ fontWeight: 600, whiteSpace: 'nowrap' }}>
            {nome(r)}{r.avisos?.length > 0 && <Tooltip title={r.avisos.join(' ')}> <WarningOutlined style={{ color: '#d97706' }} /></Tooltip>}
          </span>
        ),
      };
    }
    if (!c.moeda) return { ...base, align: c.alinhamento ?? 'center', render: (_: unknown, r: ResultadoSalarial) => c.valor(r, 0) };
    const desconto = c.classe === 'desc';
    const nomeRubrica = c.rubrica ?? null;
    return {
      ...base,
      align: 'right',
      filtravel: false,
      total: () => <strong style={{ whiteSpace: 'nowrap' }}>{formatarKz(totais[c.chave])}</strong>,
      render: (_: unknown, r: ResultadoSalarial) => {
        const v = String(c.valor(r, 0));
        const h = nomeRubrica ? horasRubrica(r, nomeRubrica) : 0;
        if (!(Math.abs(Number(v)) > 0.004) && !h) return <span style={{ color: '#94a3b8' }}>-</span>;
        const estilo = c.classe === 'liq' ? { fontWeight: 800, color: '#166534' } : c.classe === 'iliq' ? { fontWeight: 700 } : c.classe === 'pat' ? { color: '#6b21a8' } : desconto ? { color: '#b91c1c' } : {};
        return (
          <span style={{ whiteSpace: 'nowrap', ...estilo }}>
            {formatarKz(v)}
            {h > 0 && <Typography.Text type="secondary" style={{ display: 'block', fontSize: '0.7em' }}>{String(h).replace('.', ',')} h</Typography.Text>}
          </span>
        );
      },
    };
  });

  const botaoGrupo = (g: GrupoFolha, rotulo: string, icone: string) => (
    <Button type={grupo === g ? 'primary' : 'default'} disabled={!folhas[g].length} onClick={() => mudarGrupo(g)} icon={<IconeFa nome={icone} />}>
      {rotulo} <span style={{ opacity: 0.8 }}>({folhas[g].length})</span>
    </Button>
  );

  return (
    <div className="rh-folha-detalhada">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 8, marginBottom: 8 }}>
        {titulo ? <Typography.Text strong style={{ fontSize: 16 }}>{titulo}</Typography.Text> : <span />}
        <Space wrap>
          {!semBotoesGrupo && botaoGrupo('colaboradores', 'Folha de Colaboradores', 'users')}
          {!semBotoesGrupo && botaoGrupo('avencados', 'Folha de Avençados', 'user-tie')}
          {extra?.(grupo, dados)}
          <Button icon={<IconeFa nome="print" />} loading={aImprimir} disabled={!dados.length}
            onClick={() => void imprimir(pedidoSimulacaoPeriodo({ resultados: periodo.resultados, nome, mesAno: periodo.mes_ano, estado: periodo.estado, grupo }))}>Imprimir</Button>
          <Button icon={<IconeFa nome="file-csv" />} disabled={!dados.length}
            onClick={() => descarregarCsv(csvFolha(dados, defs), `mapa_salarial_${rotuloGrupo}_${periodo.mes_ano.replace('/', '_')}.csv`)}>Exportar CSV</Button>
        </Space>
      </div>
      <TabelaComModos<ResultadoSalarial>
        rowKey="colaborador_id"
        size="small"
        modos={false}
        loading={carregando}
        columns={colunas}
        dataSource={dados}
        scroll={{ x: 'max-content', y: 620 }}
        rotuloTotal={`TOTAIS (${dados.length})`}
        impressao={{ titulo: periodo.estado === 'VALIDADO' ? 'Folha de Salários' : 'Mapa de Processamento Salarial', subtitulo: grupo === 'avencados' ? 'Folha de Avençados' : 'Folha de Colaboradores', periodo: periodo.mes_ano, orientacao: 'paisagem', nomeFicheiro: `Folha_${rotuloGrupo}_${periodo.mes_ano.replace('/', '_')}` }}
        accoes={accoes}
        tituloAccoes={tituloAccoes}
        seleccao={seleccao ? { chaves: seleccao.chaves, aoMudar: seleccao.aoMudar } : undefined}
        vazio={{ icone: 'users', titulo: grupo === 'avencados' ? 'Sem avençados neste período' : 'Sem colaboradores neste período' }}
      />
    </div>
  );
}
