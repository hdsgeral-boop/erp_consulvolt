import { App, Button, Modal } from 'antd';
import { useState, type CSSProperties, type MouseEvent, type ReactNode } from 'react';
import { IconeFa } from '../fluxos/IconeFa';
import './padroesLegado.css';

/**
 * Acções por linha como no sistema anterior: botões-ícone pequenos com contorno (`btn btn-outline btn-sm` + ícone Font
 * Awesome + `title`), lado a lado na coluna «AÇÕES». Variantes de cor (primário, perigo, sucesso, aviso, info), versão
 * preenchida (`cheio`, como `btn-danger`/`btn-success` do antigo), desactivado com dica (ex.: «Eliminação bloqueada:
 * documento contabilizado») e confirmação própria (Modal do Ant Design) para acções destrutivas.
 */
export type VarianteAccao = 'normal' | 'primario' | 'perigo' | 'sucesso' | 'aviso' | 'info';

export interface ConfirmacaoAccao {
  titulo?: string;
  texto?: ReactNode;
  textoOk?: string;
  textoCancelar?: string;
  /** Botão OK a vermelho (por omissão: sim quando a variante é «perigo»). */
  perigo?: boolean;
}

export interface AccaoLinha {
  /** Chave React (por omissão: o ícone + título). */
  chave?: string;
  /** Ícone Font Awesome do antigo: «eye», «fa-eye» ou «fas fa-eye» (nomes da v5 e alguns da v6). */
  icone: string;
  /** Texto da dica (`title`) e nome acessível — obrigatório (o botão só tem ícone). */
  titulo: string;
  onClick?: () => unknown;
  variante?: VarianteAccao;
  /** Preenchido em degradê (btn-danger/btn-success/btn-primary do antigo) em vez de só contorno. */
  cheio?: boolean;
  /** Cor própria do ícone (o antigo usava `style="color:…"`). */
  cor?: string;
  /** `true` ou o texto da dica a mostrar (ex.: «Eliminação bloqueada: documento contabilizado»). */
  desactivado?: boolean | string;
  /** Pede confirmação antes de executar (`true` = texto genérico; texto = pergunta; objecto = completo). */
  confirmar?: boolean | string | ConfirmacaoAccao;
  /** Não mostra o botão. */
  oculto?: boolean;
  carregando?: boolean;
  /** Texto ao lado do ícone (raro no antigo; ex.: «Simular»). */
  texto?: ReactNode;
}

/** Nomes da v6 → nome da v5 usado no catálogo (iconesFa.ts). */
const SINONIMOS: Record<string, string> = {
  'trash-can': 'trash-alt',
  'pen-to-square': 'edit',
  xmark: 'times',
  'magnifying-glass': 'search',
  'rotate-left': 'undo-alt',
  'arrow-rotate-left': 'undo',
  'arrow-rotate-right': 'redo',
  'diagram-project': 'project-diagram',
  'table-columns': 'columns',
  'square-pen': 'pen-square',
  pencil: 'pencil-alt',
  gear: 'cog',
  'circle-info': 'info-circle',
  'circle-check': 'check-circle',
  'circle-xmark': 'times-circle',
  'right-left': 'exchange-alt',
  'arrows-rotate': 'sync-alt',
  'clock-rotate-left': 'history',
  'up-right-from-square': 'external-link-alt',
  'link-slash': 'unlink',
  'square-check': 'check-square',
  'file-lines': 'file-alt',
  'building-columns': 'university',
};

/** «fas fa-eye» / «fa-eye» / «eye» → «eye». */
export function nomeIconeFa(icone: string): string {
  const partes = icone.trim().split(/\s+/).filter((p) => !/^fa[srlbd]?$|^fa-(solid|regular|light|brands)$/.test(p));
  const nome = (partes.pop() ?? '').replace(/^fa-/, '');
  return SINONIMOS[nome] ?? nome;
}

/** Ícone FA a partir do nome do antigo (aceita «fa-…»). */
export function IconeLegado({ icone, rotulo, className, style }: { icone: string; rotulo?: string; className?: string; style?: CSSProperties }) {
  return <IconeFa nome={nomeIconeFa(icone)} rotulo={rotulo} className={className} style={style} />;
}

function textoConfirmacao(a: AccaoLinha): ConfirmacaoAccao {
  if (typeof a.confirmar === 'object') return a.confirmar;
  if (typeof a.confirmar === 'string') return { titulo: a.confirmar };
  return { titulo: `${a.titulo}?`, texto: a.variante === 'perigo' ? 'Esta operação não pode ser desfeita.' : undefined };
}

/** Um botão-ícone de acção (o mesmo aspecto em listas, cartões da grade e barras de detalhe). */
export function BotaoAccao({ accao }: { accao: AccaoLinha }) {
  const { modal } = App.useApp();
  const [ocupado, setOcupado] = useState(false);
  if (accao.oculto) return null;
  const variante = accao.variante ?? 'normal';
  const desactivado = !!accao.desactivado;
  const dica = typeof accao.desactivado === 'string' && accao.desactivado ? accao.desactivado : accao.titulo;

  const executar = async () => {
    if (!accao.onClick) return;
    const r = accao.onClick();
    if (r && typeof (r as Promise<unknown>).then === 'function') {
      setOcupado(true);
      try {
        await r;
      } catch {
        /* o erro é tratado (notificado) por quem executa a acção */
      } finally {
        setOcupado(false);
      }
    }
  };

  const aoClicar = (e: MouseEvent) => {
    // a linha pode ter onRow/onClick (abrir detalhe): a acção não o dispara
    e.stopPropagation();
    if (desactivado) return;
    if (!accao.confirmar) {
      void executar();
      return;
    }
    const c = textoConfirmacao(accao);
    const confirmar = modal?.confirm ?? Modal.confirm;
    confirmar({
      title: c.titulo ?? `${accao.titulo}?`,
      content: c.texto,
      okText: c.textoOk ?? 'Confirmar',
      cancelText: c.textoCancelar ?? 'Cancelar',
      okButtonProps: { danger: c.perigo ?? variante === 'perigo' },
      onOk: executar,
    });
  };

  const classes = ['erp-accao-linha', `erp-accao-${variante}`, accao.cheio ? 'erp-accao-cheio' : ''].filter(Boolean).join(' ');
  const botao = (
    <Button
      size="small"
      title={desactivado ? undefined : dica}
      className={classes}
      aria-label={dica === accao.titulo ? accao.titulo : `${accao.titulo} — ${dica}`}
      disabled={desactivado}
      loading={accao.carregando || ocupado}
      onClick={aoClicar}
      icon={<IconeLegado icone={accao.icone} style={accao.cor && !desactivado ? { color: accao.cor } : undefined} />}
    >
      {accao.texto}
    </Button>
  );
  // Dica nativa (`title`), como no antigo — leve mesmo com centenas de botões. Os navegadores não mostram o title de
  // um botão desactivado: nesse caso a dica fica num <span> à volta.
  return desactivado ? (
    <span title={dica} style={{ display: 'inline-block', cursor: 'not-allowed' }}>
      {botao}
    </span>
  ) : (
    botao
  );
}

/** Grupo de acções de uma linha (fica numa linha; em telemóvel pode quebrar). */
export function AccoesLinha({ accoes, rotulo = 'Acções' }: { accoes: (AccaoLinha | null | undefined | false)[]; rotulo?: string }) {
  const visiveis = accoes.filter((a): a is AccaoLinha => !!a && !a.oculto);
  if (!visiveis.length) return null;
  return (
    <span className="erp-accoes-linha" role="group" aria-label={rotulo}>
      {visiveis.map((a, i) => (
        <BotaoAccao key={a.chave ?? `${a.icone}:${a.titulo}:${i}`} accao={a} />
      ))}
    </span>
  );
}
