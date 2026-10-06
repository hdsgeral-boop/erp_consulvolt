/**
 * Tabela local (dados já carregados, sem paginação do servidor) com barra de filtros responsiva e «Imprimir»/«PDF»
 * pelo motor comum (`@/componentes/impressao`): o logótipo e o nome da empresa são postos pelo motor.
 */
import type { TableProps } from 'antd';
import type { ReactNode } from 'react';
import { BotoesExportar, prepararTexto, tabelaHtml, type PedidoImpressao } from '@/componentes/impressao';
import { BarraFiltros, scrollTabela } from '@/componentes/responsivo';
import { colunasParaImpressao, type ColunaApi, type ExtrasColunaImpressao } from '@/componentes/TabelaApi';
import { AlternarVista, TabelaComModos, useModoVista, type OpcoesCartao } from '@/componentes/vistas';

type Filtros = PedidoImpressao['filtros'];

/** Pedido de impressão de linhas já carregadas (colunas Ant Design → texto do `render`; sem título = fora). */
export async function pedidoTabelaLocal<T>(o: Omit<PedidoImpressao, 'conteudo'> & { colunas: ColunaApi<T>[]; linhas: T[]; totais?: string }): Promise<PedidoImpressao> {
  const { colunas, linhas, totais, ...resto } = o;
  await prepararTexto();
  const temTotais = colunas.some((c) => (c as ExtrasColunaImpressao<T>).totalImpressao);
  return { ...resto, conteudo: tabelaHtml({ colunas: colunasParaImpressao(colunas, linhas), linhas, totais: temTotais ? totais ?? 'Total' : false }) };
}

interface Props<T> extends Omit<TableProps<T>, 'columns'> {
  titulo: string;
  subtitulo?: string;
  filtros?: Filtros;
  /** Filtros mostrados no ecrã (à esquerda da barra). */
  filtrosEcra?: ReactNode;
  /** Acções extra à direita, antes de «Imprimir»/«PDF». */
  accoes?: ReactNode;
  columns: ColunaApi<T>[];
  /** Modos «Linhas»/«Grade» (por omissão ligados; `false` = só linhas). */
  modos?: boolean;
  /** Sufixo da preferência de vista quando há várias tabelas no ecrã. */
  idVista?: string;
  cartao?: OpcoesCartao<T>;
}

export function TabelaLocalImprimivel<T extends object>({ titulo, subtitulo, filtros, filtrosEcra, accoes, columns, dataSource, scroll, modos = true, idVista, cartao, ...props }: Props<T>) {
  const linhas = (dataSource ?? []) as T[];
  const vista = useModoVista(idVista);
  return (
    <>
      <BarraFiltros
        style={{ marginBottom: 12 }}
        accoes={
          <>
            {accoes}
            <BotoesExportar desactivado={!linhas.length} obterPedido={() => pedidoTabelaLocal({ titulo, subtitulo, filtros, colunas: columns, linhas })} />
            {modos && <AlternarVista vista={vista} />}
          </>
        }
      >
        {filtrosEcra}
      </BarraFiltros>
      <TabelaComModos<T> columns={columns} dataSource={dataSource} scroll={scroll ?? scrollTabela()} {...props} modos={modos} vista={vista} cartao={cartao} rotulo={titulo} />
    </>
  );
}
