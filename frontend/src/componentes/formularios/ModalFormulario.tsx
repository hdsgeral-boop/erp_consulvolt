import { QueryClientContext } from '@tanstack/react-query';
import { Button, Modal, type FormInstance, type ModalProps } from 'antd';
import { useContext, useEffect, useRef, useState, type ReactNode } from 'react';
import { larguraModal } from '@/componentes/responsivo';
import type { ModoVista } from '@/componentes/vistas/preferenciaVista';
import { AlternarFormulario } from './AlternarFormulario';
import { deFormulario, paraFormulario } from './conversoes';
import { PainelLinhas } from './PainelLinhas';
import { useVistaFormulario } from './preferenciaFormulario';
import type { CampoFormulario, ResumoLinhas } from './tipos';
import type { EstadoFormularioLinhas } from './useFormularioLinhas';

export interface PropsModalFormulario extends Omit<ModalProps, 'open' | 'title' | 'onCancel' | 'children'> {
  /** Chave do formulário (preferência da vista por utilizador; ex.: `projectos.fases`). */
  chave: string;
  titulo: ReactNode;
  aberto: boolean;
  aoFechar: () => void;
  /** `true` = criação de um registo novo (mostra «Grade | Linhas»); na edição só há a grade. */
  criacao: boolean;
  /** Campos da vista Linhas (e da ligação grade ↔ linhas). */
  campos: CampoFormulario[];
  /**
   * Grava UM registo (uma linha) e devolve a promessa da API — a mesma chamada da grade (mesmo URL e mesma
   * transformação dos valores). Normalmente: `(r) => enviar('post', url, dadosDe(r))`.
   */
  gravarLinha: (registo: Record<string, unknown>, contexto: { numero: number }) => Promise<unknown>;
  /** Chaves de consulta a invalidar depois de gravar linhas (uma só vez, no fim). */
  invalidar?: unknown[][];
  /** No fim da gravação em linhas. */
  aoConcluirLinhas?: (r: ResumoLinhas) => void;
  /** Fecha o modal quando todas as linhas foram gravadas (omissão: sim). */
  fecharAoGravarTudo?: boolean;
  /** Formulário da grade: dá as omissões das linhas e recebe a 1.ª linha ao voltar à grade. */
  form?: FormInstance;
  /** Nota da vista Linhas (omissão: a do legado). */
  nota?: ReactNode;
  /** Nome dos registos na confirmação: «Registar fases / milestones». */
  nomeRegistos?: string;
  /** Largura do modal na vista Linhas (omissão 1100 px, limitada ao ecrã). */
  larguraLinhas?: number;
  linhasIniciais?: number;
  /** Conteúdo da vista Grade (o formulário existente). */
  children: ReactNode;
}

/**
 * Modal de formulário com as vistas «Grade» (o formulário de sempre, um registo) e «Linhas» (tabela com um registo por
 * linha) — legado: js/shared/formulario_linhas.js. O selector só aparece na criação; a última vista escolhida fica
 * guardada por utilizador e por formulário. Na vista Linhas o rodapé do modal tem só «Fechar»: a gravação faz-se no
 * botão «Registar linhas» do painel, linha a linha, pela mesma chamada da grade.
 */
export function ModalFormulario({
  chave, titulo, aberto, aoFechar, criacao, campos, gravarLinha, invalidar, aoConcluirLinhas, fecharAoGravarTudo = true, form, nota, nomeRegistos,
  larguraLinhas = 1100, linhasIniciais, children, width, footer, destroyOnHidden = true, ...resto
}: PropsModalFormulario) {
  const [preferida, guardar] = useVistaFormulario(chave);
  const vista: ModoVista = criacao ? preferida : 'grade';
  const refEstado = useRef<EstadoFormularioLinhas | null>(null);
  const cliente = useContext(QueryClientContext);
  // omissões da tabela: os valores já postos na grade (ex.: milestone escolhido ao abrir «Nova tarefa neste milestone»)
  const [omissoes, setOmissoes] = useState<Record<string, unknown> | undefined>(undefined);
  const lerOmissoes = () => {
    if (!form) return undefined;
    const o: Record<string, unknown> = {};
    for (const c of campos) {
      const v = deFormulario(c, form.getFieldValue(c.nome));
      if (v !== undefined) o[c.nome] = v;
    }
    return o;
  };

  const emLinhas = vista === 'linhas';
  // ao abrir já em Linhas: relê as omissões depois dos efeitos do ecrã (que preenchem a grade ao abrir)
  useEffect(() => {
    if (!aberto) {
      setOmissoes(undefined);
      return;
    }
    if (!emLinhas) return;
    const t = setTimeout(() => setOmissoes(lerOmissoes()), 0);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [aberto, emLinhas]);

  const mudar = (v: ModoVista) => {
    if (v === 'grade' && form && refEstado.current) {
      // leva a primeira linha preenchida para os campos da grade (legado: mudar → preencherPorCampos)
      const primeira = refEstado.current.preenchidas[0];
      if (primeira) form.setFieldsValue(Object.fromEntries(refEstado.current.campos.map((c) => [c.nome, paraFormulario(c, primeira.valores[c.nome])])));
    }
    if (v === 'linhas') setOmissoes(lerOmissoes());
    guardar(v);
  };

  const concluir = (r: ResumoLinhas) => {
    if (r.gravadas && cliente) invalidar?.forEach((k) => void cliente.invalidateQueries({ queryKey: k }));
    aoConcluirLinhas?.(r);
    if (fecharAoGravarTudo && r.gravadas && !r.falhadas) aoFechar();
  };

  return (
    <Modal
      {...resto}
      destroyOnHidden={destroyOnHidden}
      open={aberto}
      onCancel={aoFechar}
      width={emLinhas ? larguraModal(larguraLinhas) : width}
      footer={emLinhas ? [<Button key="fechar" onClick={aoFechar}>Fechar</Button>] : footer}
      title={
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap', marginRight: 28 }}>
          <span style={{ flex: 1, minWidth: 0 }}>{titulo}</span>
          {criacao && <AlternarFormulario vista={vista} aoMudar={mudar} />}
        </div>
      }
    >
      <div hidden={emLinhas}>{children}</div>
      {emLinhas && aberto && (
        <PainelLinhas
          chave={chave}
          campos={campos}
          gravar={gravarLinha}
          omissoes={omissoes ?? lerOmissoes()}
          linhasIniciais={linhasIniciais}
          nota={nota}
          nomeRegistos={nomeRegistos}
          aoConcluir={concluir}
          refEstado={refEstado}
        />
      )}
    </Modal>
  );
}
