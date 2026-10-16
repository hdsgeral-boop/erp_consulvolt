import { QueryClientContext } from '@tanstack/react-query';
import { Card, type FormInstance } from 'antd';
import { useContext, useRef, useState, type ReactNode } from 'react';
import {
  AlternarFormulario,
  PainelLinhas,
  deFormulario,
  paraFormulario,
  type CampoFormulario,
  type EstadoFormularioLinhas,
  type ResumoLinhas,
} from '@/componentes/formularios';
import type { ModoVista } from '@/componentes/vistas/preferenciaVista';

interface Props {
  /** Chave do formulário (a mesma do legado: `diarios`, `terceiros`, `centros_custo`…). */
  chave: string;
  titulo: ReactNode;
  extra?: ReactNode;
  /** `true` = formulário de criação (mostra «Grade | Linhas»); em edição só há a grade. */
  criacao: boolean;
  /** Vista actual e mudança (de `useVistaFormulario(chave)`, no ecrã — que alarga a coluna na vista Linhas). */
  vista: ModoVista;
  aoMudarVista: (v: ModoVista) => void;
  campos: CampoFormulario[];
  /** Grava UMA linha pela mesma chamada e pela mesma transformação da grade. */
  gravarLinha: (registo: Record<string, unknown>) => Promise<unknown>;
  /** No fim da gravação em linhas (invalidar as listas). */
  aoConcluirLinhas?: (r: ResumoLinhas) => void;
  /** Chaves de consulta a invalidar no fim (uma só vez). */
  invalidar?: unknown[][];
  form: FormInstance;
  nota?: ReactNode;
  nomeRegistos?: string;
  /** O formulário de sempre (vista Grade). */
  children: ReactNode;
}

/**
 * Cartão «Novo …» das Tabelas Auxiliares com as vistas Grade | Linhas no cabeçalho, como o legado
 * (formularios_linhas_config.js: `contentor: noAux(...)`, `cabecalho: cabAux` — o selector entra no cabeçalho do
 * `.aux-form` e, em Linhas, o corpo do formulário dá lugar à tabela). Usa o mesmo painel dos modais
 * (`PainelLinhas`); a 1.ª linha preenchida volta para a grade e os valores da grade passam a omissões das linhas.
 */
export function CartaoGradeLinhas({ chave, titulo, extra, criacao, vista: preferida, aoMudarVista, campos, gravarLinha, aoConcluirLinhas, invalidar, form, nota, nomeRegistos, children }: Props) {
  const vista: ModoVista = criacao ? preferida : 'grade';
  const emLinhas = vista === 'linhas';
  const refEstado = useRef<EstadoFormularioLinhas | null>(null);
  const cliente = useContext(QueryClientContext);
  const lerOmissoes = () => {
    const o: Record<string, unknown> = {};
    for (const c of campos) {
      const v = deFormulario(c, form.getFieldValue(c.nome));
      if (v !== undefined) o[c.nome] = v;
    }
    return o;
  };
  const [omissoes, setOmissoes] = useState<Record<string, unknown> | undefined>(undefined);

  const mudar = (v: ModoVista) => {
    if (v === 'grade' && refEstado.current) {
      const primeira = refEstado.current.preenchidas[0];
      if (primeira) form.setFieldsValue(Object.fromEntries(refEstado.current.campos.map((c) => [c.nome, paraFormulario(c, primeira.valores[c.nome])])));
    }
    if (v === 'linhas') setOmissoes(lerOmissoes());
    aoMudarVista(v);
  };

  const concluir = (r: ResumoLinhas) => {
    if (r.gravadas && cliente) invalidar?.forEach((k) => void cliente.invalidateQueries({ queryKey: k }));
    aoConcluirLinhas?.(r);
  };

  return (
    <Card
      size="small"
      title={
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
          <span style={{ flex: 1, minWidth: 0 }}>{titulo}</span>
          {criacao && <AlternarFormulario vista={vista} aoMudar={mudar} />}
        </div>
      }
      extra={extra}
    >
      <div hidden={emLinhas}>{children}</div>
      {emLinhas && (
        <PainelLinhas
          chave={chave}
          campos={campos}
          gravar={(r) => gravarLinha(r)}
          omissoes={omissoes ?? lerOmissoes()}
          nota={nota}
          nomeRegistos={nomeRegistos}
          aoConcluir={concluir}
          refEstado={refEstado}
        />
      )}
    </Card>
  );
}
