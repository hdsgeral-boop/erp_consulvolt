import { Flex, Table, type TableProps } from 'antd';
import type { ColumnsType } from 'antd/es/table';
import type { ReactNode } from 'react';
import { AlternarVista } from './AlternarVista';
import type { ColunaVista } from './derivarCartao';
import { GradeCartoes, type OpcoesCartao } from './GradeCartoes';
import { useModoVista, type EstadoModoVista, type ModoVista } from './preferenciaVista';

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
   * ecrã (`<AlternarVista vista={vista} />`). Nesse caso a tabela não desenha a sua barra.
   */
  vista?: EstadoModoVista;
  /** Conteúdo extra à esquerda do controlo, na barra da lista. */
  barra?: ReactNode;
  /** Rótulo acessível da lista em grade. */
  rotulo?: string;
}

export interface PropsTabelaComModos<T> extends Omit<TableProps<T>, 'columns'>, PropsVistaLista<T> {
  columns?: ColunaVista<T>[];
}

/**
 * Tabela local (dados já carregados) com os dois modos de vista — «Linhas» (a `Table` do Ant Design, sem alterações) e
 * «Grade» (cartões derivados das mesmas colunas). Aceita todas as props da `Table`; as colunas podem marcar
 * `principal`, `subtitulo`, `etiqueta`, `accoes`, `noCartao` e `rotuloCartao` (ver `ExtrasColunaVista`).
 */
export function TabelaComModos<T extends object>(props: PropsTabelaComModos<T>) {
  const { modos = true } = props;
  if (!modos) {
    const { idVista: _i, modos: _m, modoOmissao: _o, cartao: _c, vista: _v, barra, rotulo: _r, columns, scroll, ...resto } = props;
    const tabela = <Table<T> {...resto} columns={columns as ColumnsType<T>} scroll={scroll} />;
    return barra ? (
      <>
        <Flex justify="flex-end" align="center" gap={8} wrap style={{ marginBottom: 8 }}>
          {barra}
        </Flex>
        {tabela}
      </>
    ) : (
      tabela
    );
  }
  return <TabelaComModosActiva {...props} />;
}

function TabelaComModosActiva<T extends object>({ idVista, modos: _m, modoOmissao, cartao, vista: vistaExterna, barra, rotulo, columns, scroll, ...resto }: PropsTabelaComModos<T>) {
  const vistaInterna = useModoVista(idVista, { omissao: modoOmissao });
  const vista = vistaExterna ?? vistaInterna;
  const conteudo =
    vista.modo === 'grade' ? (
      <GradeCartoes<T>
        linhas={(resto.dataSource ?? []) as readonly T[]}
        colunas={columns ?? []}
        rowKey={resto.rowKey}
        carregando={resto.loading}
        paginacao={resto.pagination}
        onRow={resto.onRow}
        rowSelection={resto.rowSelection}
        cartao={cartao}
        vazio={resto.locale?.emptyText as ReactNode}
        rotulo={rotulo}
      />
    ) : (
      <Table<T> {...resto} columns={columns as ColumnsType<T>} scroll={scroll} />
    );
  if (vistaExterna) return conteudo;
  return (
    <>
      <Flex justify="flex-end" align="center" gap={8} wrap style={{ marginBottom: 8 }} className="erp-barra-vista">
        {barra}
        <AlternarVista vista={vista} />
      </Flex>
      {conteudo}
    </>
  );
}
