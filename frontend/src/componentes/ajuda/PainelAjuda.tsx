import { QuestionCircleOutlined, WarningOutlined } from '@ant-design/icons';
import { Button, Drawer, Input, Tag, Typography } from 'antd';
import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { useLocation } from 'react-router-dom';
import { larguraGaveta } from '@/componentes/responsivo/utilitarios';
import { useSessao } from '@/sessao/SessaoContexto';
import { ajudaDoEcra, localizarAjuda, pesquisarAjuda } from './ajuda';

/**
 * Painel lateral de ajuda (M-04; legado js/ajuda.js): botão «Ajuda» na barra superior e tecla F1 em qualquer ecrã; Esc
 * fecha. Mostra para que serve o ecrã, como usar, dicas, as regras importantes do módulo, os outros ecrãs do módulo e
 * pesquisa em toda a ajuda. Acompanha a navegação enquanto está aberto (sem pesquisa activa).
 */
interface ContextoAjuda {
  aberta: boolean;
  abrir: (ecra?: string) => void;
  fechar: () => void;
  alternar: () => void;
}

const Contexto = createContext<ContextoAjuda | null>(null);

export function AjudaProvider({ children }: { children: ReactNode }) {
  const [aberta, setAberta] = useState(false);
  const [ecraFixo, setEcraFixo] = useState<string | null>(null);
  const abrir = useCallback((ecra?: string) => {
    setEcraFixo(ecra ?? null);
    setAberta(true);
  }, []);
  const fechar = useCallback(() => setAberta(false), []);
  const alternar = useCallback(() => setAberta((a) => !a), []);

  useEffect(() => {
    const tecla = (ev: KeyboardEvent) => {
      if (ev.key === 'F1') {
        ev.preventDefault();
        setEcraFixo(null);
        setAberta((a) => !a);
      }
    };
    window.addEventListener('keydown', tecla, true);
    return () => window.removeEventListener('keydown', tecla, true);
  }, []);

  const valor = useMemo(() => ({ aberta, abrir, fechar, alternar }), [aberta, abrir, fechar, alternar]);
  return (
    <Contexto.Provider value={valor}>
      {children}
      <PainelAjuda aberta={aberta} ecraFixo={ecraFixo} aoFechar={fechar} aoMudarEcra={setEcraFixo} />
    </Contexto.Provider>
  );
}

export function useAjuda(): ContextoAjuda {
  return useContext(Contexto) ?? { aberta: false, abrir: () => undefined, fechar: () => undefined, alternar: () => undefined };
}

/** Botão «Ajuda» (barra superior). Em ecrã estreito só o ícone. */
export function BotaoAjuda({ compacto }: { compacto?: boolean }) {
  const { alternar } = useAjuda();
  return (
    <Button className="erp-botao-ajuda" icon={<QuestionCircleOutlined />} onClick={alternar} aria-label="Ajuda" title="Ajuda deste ecrã (F1)">
      {compacto ? null : 'Ajuda'}
    </Button>
  );
}

function PainelAjuda({ aberta, ecraFixo, aoFechar, aoMudarEcra }: { aberta: boolean; ecraFixo: string | null; aoFechar: () => void; aoMudarEcra: (id: string | null) => void }) {
  const { menu } = useSessao();
  const local = useLocation();
  const [termo, setTermo] = useState('');
  useEffect(() => aoMudarEcra(null), [local.pathname, aoMudarEcra]);
  useEffect(() => {
    if (!aberta) setTermo('');
  }, [aberta]);
  const info = ecraFixo ? ajudaDoEcra(ecraFixo, menu) : localizarAjuda(local.pathname, menu);
  const resultados = termo.trim() ? pesquisarAjuda(termo, menu) : null;
  const lista = (itens: string[], ordenada: boolean) => (ordenada ? <ol>{itens.map((t, i) => <li key={i}>{t}</li>)}</ol> : <ul>{itens.map((t, i) => <li key={i}>{t}</li>)}</ul>);

  return (
    <Drawer
      className="erp-painel-ajuda"
      open={aberta}
      onClose={aoFechar}
      placement="right"
      width={larguraGaveta(460)}
      title={
        <div>
          <Tag color="blue" style={{ textTransform: 'uppercase', fontSize: 11 }}>{info.modulo.nome}</Tag>
          <div id="erp-ajuda-titulo" className="erp-ajuda-titulo">{resultados ? 'Pesquisa na ajuda' : info.titulo}</div>
        </div>
      }
      aria-labelledby="erp-ajuda-titulo"
      footer={<Typography.Text type="secondary" style={{ fontSize: 12 }}><kbd>F1</kbd> abrir/fechar · <kbd>Esc</kbd> fechar · <kbd>Ctrl</kbd>+<kbd>K</kbd> procurar ecrãs</Typography.Text>}
    >
      <Input.Search allowClear placeholder="Pesquisar em toda a ajuda (ex.: IVA, stock, contrato)" aria-label="Pesquisar na ajuda" value={termo} onChange={(e) => setTermo(e.target.value)} style={{ marginBottom: 16 }} />
      <div className="erp-ajuda-corpo">
        {resultados ? (
          <section>
            <h4>{resultados.length} resultado(s) para «{termo}»</h4>
            {resultados.length ? (
              resultados.map((r) => (
                <button key={r.id} type="button" className="erp-ajuda-resultado" onClick={() => { setTermo(''); aoMudarEcra(r.id); }}>
                  <strong>{r.titulo}</strong>
                  <small>{r.modulo} · {r.serve}</small>
                </button>
              ))
            ) : (
              <p>Nenhum tópico encontrado. Experimente outra palavra, por exemplo «IVA», «stock» ou «contrato».</p>
            )}
          </section>
        ) : (
          <>
            {info.ecra ? (
              <section><h4>Para que serve</h4><p>{info.ecra.serve}</p></section>
            ) : (
              <section><h4>Sobre o módulo</h4><p>{info.modulo.resumo}</p></section>
            )}
            {!!info.ecra?.passos.length && <section><h4>Como usar</h4>{lista(info.ecra.passos, true)}</section>}
            {!!info.ecra?.dicas.length && <section><h4>Dicas</h4>{lista(info.ecra.dicas, false)}</section>}
            {!!info.modulo.regras.length && (
              <section className="erp-ajuda-regras"><h4><WarningOutlined aria-hidden /> Regras importantes do módulo</h4>{lista(info.modulo.regras, false)}</section>
            )}
            {info.ecra && <section><h4>Sobre o módulo {info.modulo.nome}</h4><p>{info.modulo.resumo}</p></section>}
            {info.outros.length > 0 && (
              <section>
                <h4>Ecrãs deste módulo</h4>
                <div className="erp-ajuda-ecras">
                  {info.outros.map((o) => <button key={o.id} type="button" onClick={() => aoMudarEcra(o.id)} title="Ver a ajuda deste ecrã">{o.titulo}</button>)}
                </div>
              </section>
            )}
            <section>
              <h4>Atalhos gerais</h4>
              <ul>
                <li><kbd>F1</kbd> abre esta ajuda em qualquer ecrã; <kbd>Esc</kbd> fecha.</li>
                <li><kbd>Ctrl</kbd>+<kbd>K</kbd> procura um ecrã pelo nome; a estrela ★ na barra junta o ecrã aos favoritos.</li>
                <li>«Imprimir», «PDF» e «Excel» exportam o que está filtrado no ecrã.</li>
                <li>O botão ☰ recolhe o menu lateral; «Modo responsivo» mostra os ecrãs como num telemóvel.</li>
              </ul>
            </section>
          </>
        )}
      </div>
    </Drawer>
  );
}
