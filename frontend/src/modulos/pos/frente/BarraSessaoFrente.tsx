import type { CSSProperties } from 'react';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { formatarKz } from '@/utilitarios/formatacao';
import { TIPOS_TERMINAL } from '../comum/dados';
import { COR_TIPO_MEIO, pilulasDeSaldo } from '../comum/frente';
import { meiosActivos } from '../comum/PainelPagamentos';
import type { Terminal } from '../comum/tipos';
import { useRelatorioX } from './FechoZ';

/** Cor e ícone do distintivo «Modo: …» (renderPOSTab, js/ui_sales.js:6458-6465). */
const MODOS: Record<string, { cor: string; icone: string }> = {
  LOJA: { cor: '#2563eb', icone: 'store' },
  RESTAURANTE: { cor: '#10b981', icone: 'utensils' },
  HOTELARIA: { cor: '#d97706', icone: 'hotel' },
  LAVANDARIA: { cor: '#0891b2', icone: 'tshirt' },
};

const distintivo = (cor: string): CSSProperties => ({
  background: `${cor}26`, color: cor, border: `1px solid ${cor}40`, fontWeight: 800, fontSize: '0.8rem', padding: '0.35rem 0.85rem',
  borderRadius: 4, display: 'flex', alignItems: 'center', gap: '0.35rem', whiteSpace: 'nowrap',
});
const separador = <span aria-hidden style={{ width: 1, background: '#cbd5e1', height: 20, margin: '0 0.25rem' }} />;
const pilula = (fundo: string, borda: string, cor: string): CSSProperties => ({
  background: fundo, border: `1px solid ${borda}`, borderRadius: 4, padding: '2px 8px', fontSize: '0.75rem', color: cor, display: 'flex', alignItems: 'center', gap: 4, whiteSpace: 'nowrap',
});
const botao: CSSProperties = { borderRadius: 4, padding: '0.35rem 0.8rem', display: 'flex', alignItems: 'center', gap: '0.3rem', fontWeight: 700, fontSize: '0.8rem', cursor: 'pointer' };

/**
 * Parte esquerda da barra superior da frente de caixa (renderPOSTab + ajustarEcraPOS do legado): «Modo: Loja», terminal
 * activo com ⇄ (trocar de terminal), «Fundo» e «Vendas» acumuladas da sessão (relatório X do servidor, actualizado depois
 * de cada venda), «# sessão · operador», «Relatório X» e «Fechar Caixa» a vermelho.
 */
export function BarraSessaoFrente({
  terminal,
  podeRelatorioX,
  podeFechar,
  aoTrocarTerminal,
  aoRelatorioX,
  aoFecharCaixa,
  aoVendas,
  aoMovimentos,
}: {
  terminal: Terminal;
  podeRelatorioX: boolean;
  podeFechar: boolean;
  aoTrocarTerminal: () => void;
  aoRelatorioX: () => void;
  aoFecharCaixa: () => void;
  /** F1b: consulta/reimpressão das vendas da sessão. */
  aoVendas?: () => void;
  /** F1b: suprimentos e sangrias. */
  aoMovimentos?: () => void;
}) {
  const sessao = terminal.sessao_aberta;
  const x = useRelatorioX(sessao?.id, !!sessao && podeRelatorioX);
  const modo = MODOS[terminal.tipo] ?? MODOS.LOJA;
  const fundo = x.data?.sessao.fundo_maneio_abertura;
  const pilulas = pilulasDeSaldo(meiosActivos(terminal.meios_pagamento), x.data?.totais_por_metodo ?? [], x.data?.numerario_esperado);
  const totalCaixa = x.data ? pilulas.reduce((a, m) => a + m.valor, 0) : null;
  return (
    <>
      <span style={distintivo(modo.cor)} title="Tipo de Terminal">
        <IconeFa nome={modo.icone} /> Modo: {TIPOS_TERMINAL[terminal.tipo] ?? terminal.tipo}
      </span>
      {separador}
      <span style={distintivo('#7c3aed')} title="Terminal Ativo">
        <IconeFa nome="desktop" /> {terminal.nome}
      </span>
      <button
        type="button"
        onClick={aoTrocarTerminal}
        title="Trocar de Terminal Ativo"
        aria-label="Trocar de Terminal Ativo"
        style={{ ...botao, background: '#fff', border: '1px solid rgba(124,58,237,0.2)', color: '#7c3aed', padding: '0.35rem 0.6rem' }}
      >
        <IconeFa nome="exchange-alt" />
      </button>
      {separador}
      {podeRelatorioX && (
        <span className="pos-saldo-sessao" style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', flexWrap: 'wrap' }}>
          <span style={{ color: '#64748b', textTransform: 'uppercase', fontSize: '0.7rem', fontWeight: 800, display: 'flex', alignItems: 'center', gap: 4 }}>
            <IconeFa nome="cash-register" style={{ color: '#2563eb' }} /> Saldo:
          </span>
          <span style={pilula('#f1f5f9', '#e2e8f0', '#475569')} title="Fundo de Maneio Inicial">
            <small style={{ color: '#64748b', fontWeight: 600 }}>Fundo:</small> <strong>{fundo !== undefined ? `${formatarKz(fundo)} Kz` : '…'}</strong>
          </span>
          {pilulas.map((m) => {
            const c = COR_TIPO_MEIO[m.tipo] ?? COR_TIPO_MEIO.NUMERARIO;
            return (
              <span key={m.chave} className="pos-pilula-meio" data-tipo={m.tipo} style={pilula(c.fundo, c.borda, c.texto)} title={m.tipo === 'NUMERARIO' ? `${m.nome} em caixa (fundo + vendas)` : `${m.nome} cobrado na sessão`}>
                <small style={{ color: c.cor, fontWeight: 600 }}>{m.nome}:</small> <strong>{x.data ? `${formatarKz(m.valor)} Kz` : '…'}</strong>
              </span>
            );
          })}
          <span style={pilula('rgba(16,185,129,0.08)', 'rgba(16,185,129,0.15)', '#065f46')} title="Vendas acumuladas da sessão">
            <small style={{ color: '#047857', fontWeight: 600 }}>Vendas:</small> <strong data-vendas={x.data?.total_vendas}>{x.data ? `${formatarKz(x.data.total_vendas)} Kz` : '…'}</strong>
          </span>
          <span style={pilula('rgba(30,41,59,0.08)', 'rgba(30,41,59,0.15)', '#0f172a')} title="Saldo total da caixa (numerário em caixa + meios não numerários)">
            <small style={{ color: '#334155', fontWeight: 600 }}>Total:</small> <strong style={{ fontWeight: 800 }}>{totalCaixa !== null ? `${formatarKz(totalCaixa)} Kz` : '…'}</strong>
          </span>
        </span>
      )}
      {sessao && (
        <span className="pos-sessao-info" style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', fontSize: '0.75rem', color: '#475569' }}>
          <span title="Sessão de caixa">
            <IconeFa nome="hashtag" /> {sessao.codigo_sessao}
          </span>
          <span title="Operador">
            <IconeFa nome="user" /> {sessao.nome_operador ?? '—'}
          </span>
        </span>
      )}
      {podeRelatorioX && (
        <button type="button" onClick={aoRelatorioX} title="Relatório X (parcial; não fecha a caixa)" style={{ ...botao, background: '#fff', border: '1px solid #cbd5e1', color: '#334155' }}>
          <IconeFa nome="file-alt" /> Relatório X
        </button>
      )}
      {aoVendas && (
        <button type="button" onClick={aoVendas} title="Consultar e reimprimir as vendas da sessão" style={{ ...botao, background: '#fff', border: '1px solid #cbd5e1', color: '#334155' }}>
          <IconeFa nome="receipt" /> Vendas
        </button>
      )}
      {aoMovimentos && (
        <button type="button" onClick={aoMovimentos} title="Suprimentos e sangrias da caixa" style={{ ...botao, background: '#fff', border: '1px solid #cbd5e1', color: '#334155' }}>
          <IconeFa nome="exchange-alt" /> Suprimento / Sangria
        </button>
      )}
      {podeFechar && (
        <button type="button" onClick={aoFecharCaixa} title="Fechar a caixa (fecho Z)" style={{ ...botao, background: '#ef4444', border: '1px solid #ef4444', color: '#fff' }}>
          <IconeFa nome="lock" /> Fechar Caixa
        </button>
      )}
    </>
  );
}
