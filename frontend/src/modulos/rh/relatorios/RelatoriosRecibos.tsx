import { Alert, Button, Card, Empty, Modal, Select, Space, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useCallback, useEffect, useMemo, useState, type Key } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { IconeFa } from '@/componentes/fluxos';
import { useImpressao } from '@/componentes/impressao';
import { larguraModal } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import type { DetalhePeriodo, ResultadoSalarial } from '../api';
import { AreaImpressao, BotaoImprimir } from '../comum/componentes';
import { useAvisarErro, useCargos, useColaboradores, usePeriodosSalariais } from '../comum/consultas';
import { ReciboSalario, useEmpresaRecibo } from '../comum/ReciboSalario';
import { pedidoSimulacaoPeriodo } from '../comum/simulacao';
import { FolhaDetalhada } from '../folha/FolhaDetalhada';
import { separarFolhas, type GrupoFolha } from '../folha/regrasFolha';
import { BotaoGerarPagamento } from '../folha/GerarPagamento';
import { MapaBanco, MapaInss, MapaIrt, MapaPagamentos, MapaRecibos } from './Mapas';

/** Botões dos mapas do antigo (loadReportTabs), pela mesma ordem, com o ecrã (permissão de vista) de cada um. */
export const MAPAS_RELATORIOS = [
  { vista: 'detalhado', ecra: 'rh_rel_remuneracoes', rotulo: 'Mapa de Remunerações', icone: 'list' },
  { vista: 'irt', ecra: 'rh_rel_irt', rotulo: 'Mapa Fiscal de IRT (Modelo Oficial)', icone: 'file-invoice' },
  { vista: 'inss', ecra: 'rh_rel_inss', rotulo: 'Mapa de INSS', icone: 'shield-alt' },
  { vista: 'pagar', ecra: 'rh_rel_pagamentos', rotulo: 'Salários a Pagar', icone: 'money-bill-wave' },
  { vista: 'banco', ecra: 'rh_rel_banco', rotulo: 'Ordem de Pagamento (Banco)', icone: 'university' },
  { vista: 'recibos', ecra: 'rh_rel_recibos', rotulo: 'Recibos de Salário', icone: 'receipt' },
] as const;

type Vista = (typeof MAPAS_RELATORIOS)[number]['vista'];

/**
 * RH › Relatórios e Recibos (legado `#/rh/relatorios`, js/app_v2.js `loadReportTabs`): ecrã único — «Selecione um
 * Período Validado» → barra «Folha: Colaboradores (N) / Avençados (N)» + «Gerar Pagamento de Salários» → botões dos
 * mapas → «Mapa Detalhado de Remunerações (Colunas)» com selecção, «Gerar Recibos Selecionados» e «Extrair». Os mapas
 * continuam também como páginas próprias (rh_rel_*). Só apresentação: os valores vêm do servidor (fotografia).
 */
export default function RelatoriosRecibos() {
  const { menu } = useSessao();
  const periodos = usePeriodosSalariais();
  useAvisarErro(periodos.error, 'Erro ao carregar os períodos salariais');
  const validados = useMemo(() => (periodos.data ?? []).filter((p) => p.estado === 'VALIDADO'), [periodos.data]);
  const [id, setId] = useState<number>();
  useEffect(() => {
    if (id === undefined && validados.length) setId(validados[0].id);
  }, [validados, id]);
  const detalhe = useQuery({ queryKey: ['rh', 'salarios', 'periodo', String(id)], queryFn: () => obter<DetalhePeriodo>(`/rh/salarios/periodos/${id}`), enabled: id !== undefined });
  useAvisarErro(detalhe.error, 'Erro ao carregar o período');

  const ecrasRh = new Set((menu.find((m) => m.id === 'rh')?.ecras ?? []).map((e) => e.id));
  const mapas = MAPAS_RELATORIOS.filter((m) => ecrasRh.has(m.ecra));
  const [vista, setVista] = useState<Vista>('detalhado');
  const vistaActiva: Vista | undefined = mapas.some((m) => m.vista === vista) ? vista : mapas[0]?.vista;

  const colaboradores = useColaboradores();
  const nome = useCallback((r: ResultadoSalarial) => r.nome ?? colaboradores.nome(r.colaborador_id), [colaboradores]);
  const p = detalhe.data;
  const folhas = useMemo(() => (p ? separarFolhas(p.resultados, nome) : { colaboradores: [], avencados: [] }), [p, nome]);
  const [grupo, setGrupo] = useState<GrupoFolha>('colaboradores');
  useEffect(() => {
    if (p && !folhas[grupo].length && folhas.colaboradores.length + folhas.avencados.length) setGrupo(folhas.colaboradores.length ? 'colaboradores' : 'avencados');
  }, [p, folhas, grupo]);
  const grupoMapa = grupo === 'avencados' ? 'AVENCADOS' : 'COLABORADORES';

  const [seleccionados, setSeleccionados] = useState<Key[]>([]);
  const [recibos, setRecibos] = useState<ResultadoSalarial[] | null>(null);
  useEffect(() => setSeleccionados([]), [id, grupo]);
  const { exportarExcel } = useImpressao();

  const botaoGrupo = (g: GrupoFolha, rotulo: string, icone: string) => (
    <Button type={grupo === g ? 'primary' : 'default'} disabled={!folhas[g].length} icon={<IconeFa nome={icone} />} onClick={() => setGrupo(g)}>
      {rotulo} ({folhas[g].length})
    </Button>
  );

  return (
    <>
      <CabecalhoPagina titulo="Relatórios e Recibos" subtitulo="Mapas mensais do processamento salarial validado" />
      <Card className="rh-nao-imprimir" style={{ marginBottom: 12 }} styles={{ body: { padding: 12 } }}>
        <Space wrap align="center">
          <Typography.Text strong>Selecione um Período Validado</Typography.Text>
          <Select<number> style={{ width: 220 }} placeholder="-- Selecione --" loading={periodos.isLoading} value={id} onChange={setId} aria-label="Período validado"
            options={validados.map((x) => ({ value: x.id, label: x.mes_ano }))} />
        </Space>
      </Card>

      {!periodos.isLoading && !validados.length && <Empty description="Ainda não há processamentos validados (valide-os em Processamentos)." style={{ padding: 32 }} />}

      {p && (
        <>
          <div className="rh-nao-imprimir" style={{ marginBottom: 12, display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center', padding: 10, background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: 4 }}>
            <strong style={{ fontSize: '0.85rem', color: '#334155', marginRight: 4 }}>Folha:</strong>
            {botaoGrupo('colaboradores', 'Colaboradores', 'users')}
            {botaoGrupo('avencados', 'Avençados', 'user-tie')}
            <span style={{ marginLeft: 'auto' }}><BotaoGerarPagamento periodo={p} /></span>
          </div>
          {grupo === 'avencados' && (
            <Alert className="rh-nao-imprimir" type="warning" showIcon style={{ marginBottom: 12 }}
              message="Folha de avençados: sem INSS. Mapas aplicáveis — Remunerações, Lista de retenção IRT Grupo B (6,5%), Valores a pagar, Ordem bancária e Recibos." />
          )}
          <div className="rh-nao-imprimir" style={{ marginBottom: 16, display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            {mapas.map((m) => (
              <Button key={m.vista} type={vistaActiva === m.vista ? 'primary' : 'default'} ghost={vistaActiva === m.vista} icon={<IconeFa nome={m.icone} />} onClick={() => setVista(m.vista)}
                disabled={m.vista === 'inss' && grupo === 'avencados'}>
                {m.rotulo}
              </Button>
            ))}
          </div>
          {!mapas.length && <Alert type="info" showIcon message="Sem acesso a nenhum mapa nesta empresa." />}

          {vistaActiva === 'detalhado' && (
            <Card>
              <FolhaDetalhada periodo={p} nome={nome} carregando={detalhe.isFetching} grupo={grupo} aoMudarGrupo={setGrupo} semBotoesGrupo
                titulo="Mapa Detalhado de Remunerações (Colunas)" variante="remuneracoes"
                // coluna «Ação» do antigo: recibo individual em PDF (2 vias) — `generatePDFRecibo(empId)`
                tituloAccoes="Ação"
                accoes={ecrasRh.has('rh_rel_recibos') ? (r) => [{ icone: 'file-pdf', titulo: 'Recibo de vencimento (2 vias)', onClick: () => setRecibos([r]) }] : undefined}
                seleccao={{ chaves: seleccionados, aoMudar: (k) => setSeleccionados(k) }}
                extra={(_, linhas) => (
                  <>
                    {ecrasRh.has('rh_rel_recibos') && (
                      <Button type="primary" icon={<IconeFa nome="receipt" />} disabled={!seleccionados.length}
                        title={seleccionados.length ? undefined : 'Seleccione os colaboradores na tabela'}
                        onClick={() => setRecibos(linhas.filter((r) => seleccionados.includes(r.colaborador_id)))}>
                        Gerar Recibos Selecionados{seleccionados.length ? ` (${seleccionados.length})` : ''}
                      </Button>
                    )}
                    <Button type="primary" icon={<IconeFa nome="file-invoice" />} disabled={!linhas.length}
                      onClick={() => void exportarExcel({ ...pedidoSimulacaoPeriodo({ resultados: p.resultados, nome, mesAno: p.mes_ano, estado: p.estado, grupo }), titulo: 'Mapa Detalhado de Remunerações', nomeFicheiro: `Mapa_Remuneracoes_${p.mes_ano.replace('/', '_')}` })}>
                      Extrair
                    </Button>
                  </>
                )} />
            </Card>
          )}
          {vistaActiva === 'irt' && <MapaIrt periodoId={p.id} />}
          {vistaActiva === 'inss' && <MapaInss periodoId={p.id} />}
          {vistaActiva === 'pagar' && <MapaPagamentos key={`pagar-${grupoMapa}`} periodoId={p.id} grupo={grupoMapa} />}
          {vistaActiva === 'banco' && <MapaBanco key={`banco-${grupoMapa}`} periodoId={p.id} grupo={grupoMapa} />}
          {vistaActiva === 'recibos' && <MapaRecibos key={`recibos-${grupoMapa}`} periodoId={p.id} grupo={grupoMapa} />}
        </>
      )}

      <ModalRecibos resultados={recibos} mesAno={p?.mes_ano ?? ''} nome={nome} aoFechar={() => setRecibos(null)} />
    </>
  );
}

/** «Gerar Recibos Selecionados»: recibos de vencimento (2 vias) dos colaboradores marcados, para imprimir ou guardar em PDF. */
function ModalRecibos({ resultados, mesAno, nome, aoFechar }: { resultados: ResultadoSalarial[] | null; mesAno: string; nome: (r: ResultadoSalarial) => string; aoFechar: () => void }) {
  const empresa = useEmpresaRecibo();
  const colaboradores = useColaboradores();
  const cargos = useCargos();
  const lista = resultados ?? [];
  return (
    <Modal open={resultados !== null} onCancel={aoFechar} width={larguraModal(980)} destroyOnHidden
      title={<Space><IconeFa nome="receipt" />Recibos de vencimento — {mesAno} ({lista.length})</Space>}
      footer={<Space wrap><BotaoImprimir texto="Imprimir recibos" titulo={`Recibos de vencimento ${mesAno}`} desactivado={!lista.length} /><Button onClick={aoFechar}>Fechar</Button></Space>}>
      <AreaImpressao>
        {lista.map((r, i) => {
          const c = colaboradores.mapa.get(r.colaborador_id);
          return (
            <ReciboSalario key={r.colaborador_id} resultado={r} mesAno={mesAno} empresa={empresa} quebra={i < lista.length - 1}
              colaborador={{ nome: nome(r), nif: r.nif ?? c?.nif ?? null, numero_inss: r.numero_inss ?? c?.numero_inss ?? null, funcao: c?.cargo_funcao_id ? cargos.nome(c.cargo_funcao_id) : r.funcao ?? null }} />
          );
        })}
      </AreaImpressao>
    </Modal>
  );
}
