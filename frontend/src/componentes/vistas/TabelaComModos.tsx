import type { TableProps } from 'antd';
import type { ReactNode } from 'react';
import { AlternarVista } from './AlternarVista';
import type { ColunaLegado } from './colunasTabela';
import { GradeCartoes, type OpcoesCartao } from './GradeCartoes';
import { chaveEcraVista, useModoVista, type EstadoModoVista, type ModoVista } from './preferenciaVista';
import { TabelaLegado, type PropsPadroesLista } from './TabelaLegado';

export interface PropsVistaLista<T> {
  /**
   * Sufixo da chave da preferência quando há várias listas no mesmo ecrã (a chave base é o caminho do ecrã).
   */
  idVista?: string;
  /** `false` = só linhas, sem alternância (mapas e relatórios de leitura). Por omissão: com alternância. */
  modos?: boolean;
  /** Omissão própria deste ecrã (antes da automática: grade no telemóvel, linhas nos ecrãs largos). */
  modoOmissao?: ModoVista;
  /** Opções do cartão da grade (máximo de campos, largura mínima, render personalizado). */
  cartao?: OpcoesCartao<T>;
  /**
   * Estado da vista controlado de fora (de `useModoVista`), quando o controlo «Linhas | Grade» está noutra barra do
   * ecrã (`<AlternarVista vista={vista} />`). Nesse caso a tabela não desenha o controlo.
   */
  vista?: EstadoModoVista;
  /** Conteúdo extra na caixa da lista (à esquerda dos botões Excel/PDF/Imprimir/Colunas). */
  barra?: ReactNode;
  /** Rótulo acessível da lista em grade. */
  rotulo?: string;
}

export interface PropsTabelaComModos<T> extends Omit<TableProps<T>, 'columns'>, PropsVistaLista<T>, PropsPadroesLista<T> {
  columns?: ColunaLegado<T>[];
}

/**
 * Tabela local (dados já carregados) com os dois modos de vista — «Linhas» (a `Table` do Ant Design) e «Grade» (cartões
 * derivados das mesmas colunas) — e o padrão das listas do sistema anterior (ver `PropsPadroesLista`): caixa com
 * «N registos encontrados» e «Colunas ▾»/«Filtro personalizado», filtros por coluna, linha «TOTAL:», paginação
 * «Mostrar [N] registos», coluna «AÇÕES», selecção múltipla e estado vazio. Aceita todas as props da `Table`; as
 * colunas podem marcar `principal`, `subtitulo`, `etiqueta`, `accoes`, `noCartao`, `rotuloCartao` (grade) e `total`,
 * `filtravel`, `ocultavel`, `ocultaPorOmissao` (padrão do antigo).
 */
export function TabelaComModos<T extends object>(props: PropsTabelaComModos<T>) {
  const { modos = true } = props;
  if (!modos) {
    const { idVista, modos: _m, modoOmissao: _o, cartao: _c, vista: _v, rotulo: _r, ...resto } = props;
    return <TabelaLegado<T> {...resto} chaveLista={chaveEcraVista(idVista)} />;
  }
  return <TabelaComModosActiva {...props} />;
}

function TabelaComModosActiva<T extends object>({ idVista, modos: _m, modoOmissao, cartao, vista: vistaExterna, rotulo, ...resto }: PropsTabelaComModos<T>) {
  const vistaInterna = useModoVista(idVista, { omissao: modoOmissao });
  const vista = vistaExterna ?? vistaInterna;
  return (
    <TabelaLegado<T>
      {...resto}
      chaveLista={chaveEcraVista(idVista)}
      extraDireita={vistaExterna ? undefined : <AlternarVista vista={vista} />}
      renderAlternativo={
        vista.modo === 'grade'
          ? ({ linhas, colunas, paginacao, rowSelection, vazio }) => (
              <GradeCartoes<T>
                linhas={linhas}
                colunas={colunas.filter((c) => !c.hidden)}
                rowKey={resto.rowKey}
                carregando={resto.loading}
                paginacao={paginacao}
                onRow={resto.onRow}
                rowSelection={rowSelection}
                cartao={cartao}
                vazio={vazio}
                rotulo={rotulo}
              />
            )
          : undefined
      }
    />
  );
}
