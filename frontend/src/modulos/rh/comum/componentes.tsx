import { Empty, Input, Select, Tag, Tooltip, type SelectProps } from 'antd';
import { useRef, useState, type ReactNode } from 'react';
import { BotoesExportar, type PedidoImpressao } from '@/componentes/impressao';
import { CORES_ESTADO, FASES_AVALIACAO, ROTULOS_ESTADO, type PeriodoSalarial } from '../api';
import { useColaboradores } from './consultas';
import { mesPorExtenso } from './regras';
import './impressao.css';

/** Etiqueta de estado com a cor e o rótulo do módulo. */
export function EstadoTag({ estado }: { estado: string | null | undefined }) {
  if (!estado) return <>—</>;
  return <Tag color={CORES_ESTADO[estado] ?? 'default'}>{ROTULOS_ESTADO[estado] ?? estado}</Tag>;
}

/** Fase da avaliação de desempenho (ADR-041). */
export function FaseTag({ fase }: { fase: string }) {
  const f = FASES_AVALIACAO[fase];
  return <Tag color={f?.cor}>{f?.rotulo ?? fase}</Tag>;
}

/** Selecção de colaborador (pesquisa por nome ou NIF), a partir da lista completa em cache. */
export function SeletorColaborador({ apenasActivos = false, ids, ...props }: SelectProps<number> & { apenasActivos?: boolean; ids?: number[] }) {
  const { lista, isLoading } = useColaboradores();
  const filtro = ids ? new Set(ids) : null;
  const opcoes = lista
    .filter((c) => (!apenasActivos || c.estado === 'ACTIVO') && (!filtro || filtro.has(c.id)))
    .map((c) => ({ value: c.id, label: c.nome_completo, nif: c.nif }));
  return (
    <Select<number>
      showSearch
      allowClear
      placeholder="Colaborador"
      loading={isLoading}
      optionFilterProp="label"
      filterOption={(t, o) => `${o?.label ?? ''} ${(o as { nif?: string } | undefined)?.nif ?? ''}`.toLowerCase().includes(t.toLowerCase())}
      options={opcoes}
      style={{ minWidth: 260 }}
      {...props}
    />
  );
}

/** Selecção de período salarial (MM/AAAA, com o estado). */
export function SeletorPeriodo({ periodos, valor, aoMudar, apenas, carregando }: {
  periodos: PeriodoSalarial[] | undefined;
  valor: number | undefined;
  aoMudar: (id: number | undefined) => void;
  apenas?: PeriodoSalarial['estado'][];
  carregando?: boolean;
}) {
  return (
    <Select<number>
      placeholder="Período salarial"
      style={{ width: 260 }}
      loading={carregando}
      value={valor}
      onChange={aoMudar}
      options={(periodos ?? [])
        .filter((p) => !apenas || apenas.includes(p.estado))
        .map((p) => ({ value: p.id, label: `${p.mes_ano} — ${ROTULOS_ESTADO[p.estado] ?? p.estado}${p.contabilizado ? ' · contabilizado' : ''}` }))}
    />
  );
}

/** Caixa de pesquisa local (listas não paginadas pelo servidor). */
export function PesquisaLocal({ aoMudar, placeholder = 'Pesquisar' }: { aoMudar: (v: string) => void; placeholder?: string }) {
  const [v, setV] = useState('');
  return <Input.Search allowClear placeholder={placeholder} style={{ width: 240 }} value={v} onChange={(e) => { setV(e.target.value); aoMudar(e.target.value); }} />;
}

export function contem(texto: unknown, termo: string): boolean {
  return !termo || String(texto ?? '').toLowerCase().includes(termo.toLowerCase());
}

/**
 * Botões «Imprimir» e «PDF» dos mapas, recibos e documentos do RH (motor comum de impressão: logótipo e nome da
 * empresa no cabeçalho, papel/orientação automáticos). Imprime a área .rh-impressao do mesmo modal (se o botão
 * estiver num modal) ou a área visível da página. O título/período do CabecalhoMapa passam para o cabeçalho do documento.
 */
export function BotaoImprimir({ texto = 'Imprimir', desactivado, titulo }: { texto?: string; desactivado?: boolean; titulo?: string }) {
  const ancora = useRef<HTMLSpanElement>(null);
  const obterPedido = (): PedidoImpressao | null => {
    const area = encontrarArea(ancora.current);
    if (!area) return null;
    const cab = area.querySelector('.rh-mapa-cabecalho');
    const tituloMapa = cab?.querySelector('.rh-mapa-titulo')?.textContent?.trim();
    const periodo = cab?.querySelector('.rh-mapa-periodo')?.textContent?.replace(/^Período:\s*/, '').trim();
    const tituloModal = ancora.current?.closest('.ant-modal-content')?.querySelector('.ant-modal-title')?.textContent?.trim();
    return {
      titulo: tituloMapa || titulo || tituloModal || document.title,
      periodo: periodo || undefined,
      orientacao: area.classList.contains('rh-impressao-paisagem') ? 'paisagem' : 'auto',
      conteudo: area,
      cssExtra: CSS_IMPRESSAO_RH,
    };
  };
  return (
    <span ref={ancora} style={{ display: 'inline-flex' }}>
      <BotoesExportar obterPedido={obterPedido} desactivado={desactivado} textoImprimir={texto} />
    </span>
  );
}

/** No documento impresso, o nome da empresa, o título e o período do CabecalhoMapa já estão no cabeçalho comum. */
const CSS_IMPRESSAO_RH = `
.imp-conteudo > .rh-impressao > .rh-mapa-cabecalho:first-child .rh-mapa-empresa,
.imp-conteudo > .rh-impressao > .rh-mapa-cabecalho:first-child .rh-mapa-titulo,
.imp-conteudo > .rh-impressao > .rh-mapa-cabecalho:first-child .rh-mapa-periodo { display: none; }
.imp-conteudo .rh-tabela-mapa { font-size: 8.5pt; }
.imp-conteudo .rh-recibo { border-color: #000; }
.imp-conteudo .rh-recibo-quebra { break-after: page; page-break-after: always; }
.imp-conteudo .rh-documento { border: 0; padding: 0; }
`;

/** Área a imprimir: a do modal do botão; senão a primeira área visível fora de modais. */
function encontrarArea(botao: Element | null): HTMLElement | null {
  const modal = botao?.closest('.ant-modal-content');
  if (modal) return modal.querySelector<HTMLElement>('.rh-impressao');
  const areas = Array.from(document.querySelectorAll<HTMLElement>('.rh-impressao')).filter((a) => !a.closest('.ant-modal-root, .ant-modal-wrap'));
  return areas.find((a) => a.getClientRects().length > 0) ?? areas[0] ?? null;
}

/** Área imprimível: no ecrã é um bloco normal; na impressão só ela aparece (impressao.css). */
export function AreaImpressao({ children, paisagem }: { children: ReactNode; paisagem?: boolean }) {
  return <div className={`rh-impressao${paisagem ? ' rh-impressao-paisagem' : ''}`}>{children}</div>;
}

/** Cabeçalho dos mapas impressos (empresa, título e período). */
export function CabecalhoMapa({ empresa, titulo, mesAno, extra }: { empresa?: string | null; titulo: string; mesAno?: string; extra?: ReactNode }) {
  return (
    <div className="rh-mapa-cabecalho">
      <div className="rh-mapa-empresa">{empresa ?? ''}</div>
      <div className="rh-mapa-titulo">{titulo}</div>
      {mesAno && <div className="rh-mapa-periodo">Período: {mesPorExtenso(mesAno)} ({mesAno})</div>}
      {extra}
    </div>
  );
}

export function SemPeriodo({ texto = 'Escolha um período salarial.' }: { texto?: string }) {
  return <Empty description={texto} style={{ padding: 32 }} />;
}

export function Ajuda({ texto, children }: { texto: ReactNode; children: ReactNode }) {
  return <Tooltip title={texto}>{children}</Tooltip>;
}

/** Descarrega texto como ficheiro (CSV com BOM para o Excel reconhecer o UTF-8). */
export function descarregar(nome: string, conteudo: string, tipo = 'text/csv;charset=utf-8') {
  const blob = new Blob(['﻿' + conteudo], { type: tipo });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = nome;
  a.click();
  URL.revokeObjectURL(url);
}
