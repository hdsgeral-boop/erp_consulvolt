import { App, Button, Modal, Tooltip } from 'antd';
import { useState, type Key, type ReactNode } from 'react';
import { IconeLegado, type ConfirmacaoAccao, type VarianteAccao } from './AccoesLinha';
import './padroesLegado.css';

/**
 * Selecção múltipla do sistema anterior: barra «Seleccionadas: N» com acções em massa (ex.: Lavandaria — «Atribuir
 * responsável», «Alterar estado», «Aprovar / recusar orçamentos») e o botão «Editar em massa (N)» (Produtos, Clientes,
 * Fornecedores, Activos).
 */
export interface AccaoMassa<T> {
  chave?: string;
  texto: ReactNode;
  /** Ícone Font Awesome do antigo. */
  icone?: string;
  variante?: Exclude<VarianteAccao, 'info' | 'aviso'>;
  /** Recebe as linhas seleccionadas (as que estão carregadas) e as chaves. */
  onClick: (linhas: T[], chaves: Key[]) => unknown;
  /** Desactiva (com dica, se texto). Sem selecção os botões ficam sempre desactivados. */
  desactivado?: boolean | string;
  /** Confirmação (texto com {n} = número de seleccionadas). */
  confirmar?: boolean | string | ConfirmacaoAccao;
  /** Só aparece com selecção (por omissão aparece sempre, desactivado). */
  soComSeleccao?: boolean;
}

export interface OpcoesEditarEmMassa<T> {
  onClick: (linhas: T[], chaves: Key[]) => unknown;
  /** Texto (por omissão «Editar em massa»); o número vai entre parênteses. */
  texto?: string;
  icone?: string;
}

export interface PropsBarraSeleccao<T> {
  linhas: T[];
  chaves: Key[];
  accoes?: AccaoMassa<T>[];
  editarEmMassa?: OpcoesEditarEmMassa<T>;
  /** Texto de ajuda à direita (ex.: «Marque as ordens em curso para acções em massa…»). */
  ajuda?: ReactNode;
  aoLimpar?: () => void;
  /** Rótulo do contador (por omissão «Seleccionadas»). */
  rotulo?: string;
}

const tipoBotao = (v?: AccaoMassa<unknown>['variante']) => (v === 'primario' ? 'primary' : undefined);

/** Botão «Editar em massa (N)» (desactivado sem selecção, com a dica do antigo «Seleccione registos na tabela»). */
export function BotaoEditarEmMassa<T>({ linhas, chaves, opcoes }: { linhas: T[]; chaves: Key[]; opcoes: OpcoesEditarEmMassa<T> }) {
  const n = chaves.length;
  return (
    <Tooltip title={n ? undefined : 'Seleccione registos na tabela'}>
      <Button size="small" disabled={!n} icon={<IconeLegado icone={opcoes.icone ?? 'pen-square'} />} onClick={() => opcoes.onClick(linhas, chaves)}>
        {opcoes.texto ?? 'Editar em massa'} ({n})
      </Button>
    </Tooltip>
  );
}

export function BarraSeleccao<T>({ linhas, chaves, accoes = [], editarEmMassa, ajuda, aoLimpar, rotulo = 'Seleccionadas' }: PropsBarraSeleccao<T>) {
  const { modal } = App.useApp();
  const [ocupado, setOcupado] = useState<string | null>(null);
  const n = chaves.length;
  const executar = async (a: AccaoMassa<T>, id: string) => {
    const r = a.onClick(linhas, chaves);
    if (r && typeof (r as Promise<unknown>).then === 'function') {
      setOcupado(id);
      try {
        await r;
      } catch {
        /* tratado por quem executa */
      } finally {
        setOcupado(null);
      }
    }
  };
  const aoClicar = (a: AccaoMassa<T>, id: string) => {
    if (!a.confirmar) return void executar(a, id);
    const c: ConfirmacaoAccao = typeof a.confirmar === 'object' ? a.confirmar : typeof a.confirmar === 'string' ? { titulo: a.confirmar } : {};
    const subst = (t?: string) => t?.replace(/\{n\}/g, String(n));
    (modal?.confirm ?? Modal.confirm)({
      title: subst(c.titulo) ?? `Aplicar a ${n} registo(s) seleccionado(s)?`,
      content: c.texto,
      okText: c.textoOk ?? 'Confirmar',
      cancelText: c.textoCancelar ?? 'Cancelar',
      okButtonProps: { danger: c.perigo ?? a.variante === 'perigo' },
      onOk: () => executar(a, id),
    });
  };
  return (
    <div className="erp-barra-seleccao" role="toolbar" aria-label="Acções sobre os registos seleccionados">
      <span className="erp-barra-seleccao-contador" aria-live="polite">
        {rotulo}: <span>{n}</span>
      </span>
      {editarEmMassa && <BotaoEditarEmMassa linhas={linhas} chaves={chaves} opcoes={editarEmMassa} />}
      {accoes
        .filter((a) => !a.soComSeleccao || n > 0)
        .map((a, i) => {
          const id = a.chave ?? String(i);
          const dica = typeof a.desactivado === 'string' ? a.desactivado : undefined;
          return (
            <Tooltip key={id} title={dica}>
              <Button
                size="small"
                type={tipoBotao(a.variante)}
                danger={a.variante === 'perigo'}
                disabled={!n || !!a.desactivado}
                loading={ocupado === id}
                icon={a.icone ? <IconeLegado icone={a.icone} /> : undefined}
                onClick={() => aoClicar(a, id)}
              >
                {a.texto}
              </Button>
            </Tooltip>
          );
        })}
      {aoLimpar && n > 0 && (
        <Button size="small" type="link" onClick={aoLimpar}>
          Limpar selecção
        </Button>
      )}
      {ajuda && <small className="erp-barra-seleccao-ajuda">{ajuda}</small>}
    </div>
  );
}
