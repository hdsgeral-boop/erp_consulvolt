import { Card, Checkbox, Empty, Flex, Pagination, Radio, Spin, Typography, theme, type SpinProps, type TableProps } from 'antd';
import type { TablePaginationConfig } from 'antd/es/table';
import type { Key, MouseEvent, ReactNode } from 'react';
import { useEffect, useMemo, useState } from 'react';
import { conteudoCelula, conteudoVazio, derivarEstrutura, textoSimples, tituloColuna, type ColunaVista, type EstruturaCartao } from './derivarCartao';

/** Opções do cartão da vista em grade. */
export interface OpcoesCartao<T> {
  /** Máximo de campos por cartão (por omissão 6). */
  maxCampos?: number;
  /** Largura mínima de cada cartão em px (por omissão 260; em telemóvel ocupa a largura toda). */
  larguraMinima?: number;
  /** Cartão personalizado: recebe a linha, o índice e as acções já prontas (rodapé derivado da coluna de acções). */
  render?: (linha: T, indice: number, contexto: { accoes: ReactNode; estrutura: EstruturaCartao<T> }) => ReactNode;
}

export interface PropsGradeCartoes<T> {
  linhas: readonly T[];
  colunas: readonly ColunaVista<T>[];
  rowKey?: TableProps<T>['rowKey'];
  carregando?: boolean | SpinProps;
  /** Paginação no formato da tabela; `false` = sem paginação. */
  paginacao?: TablePaginationConfig | false;
  /** `true` = as linhas já são a página actual (paginação do servidor, ex.: TabelaApi). */
  paginacaoServidor?: boolean;
  onRow?: TableProps<T>['onRow'];
  rowSelection?: TableProps<T>['rowSelection'];
  cartao?: OpcoesCartao<T>;
  vazio?: ReactNode;
  /** Rótulo acessível da lista (ex.: «Clientes»). */
  rotulo?: string;
}

const TAMANHO_PAGINA_GRADE = 12;

/** A `position` é só da tabela (não da `Pagination`). */
function semPosicao(conf: TablePaginationConfig): Omit<TablePaginationConfig, 'position'> {
  const { position: _posicao, ...resto } = conf;
  return resto;
}

export function chaveLinha<T>(linha: T, indice: number, rowKey: TableProps<T>['rowKey']): Key {
  if (typeof rowKey === 'function') return rowKey(linha, indice) as Key;
  const campo = (rowKey ?? 'key') as keyof T;
  const v = (linha as Record<string, unknown>)[campo as string];
  if (v !== undefined && v !== null) return v as Key;
  const id = (linha as { id?: Key }).id;
  return id ?? indice;
}

/** Vista em grade: cartões responsivos (grelha CSS `auto-fill`, nunca mais largos do que o contentor). */
export function GradeCartoes<T>({ linhas, colunas, rowKey, carregando, paginacao, paginacaoServidor: servidorIndicado, onRow, rowSelection, cartao, vazio, rotulo }: PropsGradeCartoes<T>) {
  const { token } = theme.useToken();
  const estrutura = useMemo(() => derivarEstrutura(colunas, cartao?.maxCampos ?? 6), [colunas, cartao?.maxCampos]);
  const conf: TablePaginationConfig | false = paginacao === false ? false : paginacao ?? {};
  // paginação controlada com um total maior do que as linhas recebidas = já é a página do servidor
  const paginacaoServidor = servidorIndicado ?? (!!conf && conf.total !== undefined && conf.total > linhas.length);
  const [paginaLocal, setPaginaLocal] = useState(1);
  const [tamanhoLocal, setTamanhoLocal] = useState<number>((conf && (conf.pageSize ?? conf.defaultPageSize)) || TAMANHO_PAGINA_GRADE);
  const pagina = (conf && conf.current) || paginaLocal;
  const tamanho = (conf && conf.pageSize) || tamanhoLocal;
  const totalLocal = linhas.length;
  // volta à última página válida quando os dados encolhem (filtros)
  useEffect(() => {
    if (!paginacaoServidor && conf && !conf.current && (paginaLocal - 1) * tamanho >= totalLocal && paginaLocal > 1) setPaginaLocal(1);
  }, [totalLocal, tamanho, paginaLocal, paginacaoServidor, conf]);

  const visiveis = paginacaoServidor || conf === false ? linhas : linhas.slice((pagina - 1) * tamanho, pagina * tamanho);
  const indiceBase = paginacaoServidor || conf === false ? 0 : (pagina - 1) * tamanho;
  const minimo = cartao?.larguraMinima ?? 260;

  const seleccionadas = new Set<Key>((rowSelection?.selectedRowKeys ?? []) as Key[]);
  const alternarSeleccao = (linha: T, chave: Key, marcar: boolean) => {
    if (!rowSelection?.onChange) return;
    const unica = rowSelection.type === 'radio';
    const chaves = unica ? (marcar ? [chave] : []) : marcar ? [...seleccionadas, chave] : [...seleccionadas].filter((k) => k !== chave);
    const mapa = new Map(linhas.map((l, i) => [chaveLinha(l, i, rowKey), l] as const));
    mapa.set(chave, linha);
    rowSelection.onChange(chaves, chaves.map((k) => mapa.get(k)).filter((l): l is T => l !== undefined), { type: unica ? 'single' : 'multiple' });
  };

  const corpo = !visiveis.length ? (
    <div style={{ padding: '24px 0' }}>{vazio ?? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Sem registos" />}</div>
  ) : (
    <div
      role="list"
      aria-label={rotulo}
      className="erp-grade-cartoes"
      style={{ display: 'grid', gap: 12, gridTemplateColumns: `repeat(auto-fill, minmax(min(100%, ${minimo}px), 1fr))`, minWidth: 0 }}
    >
      {visiveis.map((linha, i) => {
        const indice = indiceBase + i;
        const chave = chaveLinha(linha, indice, rowKey);
        const accoes = estrutura.accoes ? conteudoCelula(estrutura.accoes, linha, indice) : null;
        const atributos = (onRow?.(linha, indice) ?? {}) as { onClick?: (e: MouseEvent<HTMLElement>) => void; onDoubleClick?: (e: MouseEvent<HTMLElement>) => void; className?: string };
        const tituloNo = estrutura.titulo ? conteudoCelula(estrutura.titulo, linha, indice) : null;
        const rotuloCartao = textoSimples(tituloNo) || `Registo ${indice + 1}`;
        const caixa = rowSelection ? rowSelection.getCheckboxProps?.(linha) : undefined;
        const seleccao = rowSelection ? (
          rowSelection.type === 'radio' ? (
            <Radio checked={seleccionadas.has(chave)} disabled={caixa?.disabled} onChange={(e) => alternarSeleccao(linha, chave, e.target.checked)} aria-label={`Seleccionar ${rotuloCartao}`} />
          ) : (
            <Checkbox checked={seleccionadas.has(chave)} disabled={caixa?.disabled} onChange={(e) => alternarSeleccao(linha, chave, e.target.checked)} aria-label={`Seleccionar ${rotuloCartao}`} />
          )
        ) : null;
        const accoesNo = conteudoVazio(accoes) ? null : (
          // os cliques nas acções (e nos seus popconfirms/menus) não abrem o registo
          <div className="erp-cartao-accoes" onClick={(e) => e.stopPropagation()} onDoubleClick={(e) => e.stopPropagation()}>
            <Flex wrap gap={4} justify="flex-end" align="center">
              {accoes}
            </Flex>
          </div>
        );
        const clicavel = !!(atributos.onClick || atributos.onDoubleClick);
        return (
          <div role="listitem" key={String(chave)} aria-label={rotuloCartao} style={{ minWidth: 0 }}>
            {cartao?.render ? (
              cartao.render(linha, indice, { accoes: accoesNo, estrutura })
            ) : (
              <Card
                size="small"
                hoverable={clicavel}
                className={['erp-cartao-registo', atributos.className].filter(Boolean).join(' ')}
                style={{ height: '100%', borderColor: seleccionadas.has(chave) ? token.colorPrimary : undefined }}
                styles={{ body: { padding: 12, display: 'flex', flexDirection: 'column', gap: 8, height: '100%' } }}
                onClick={atributos.onClick}
                onDoubleClick={atributos.onDoubleClick}
                tabIndex={clicavel ? 0 : undefined}
                onKeyDown={
                  clicavel
                    ? (e) => {
                        if (e.key === 'Enter' && e.target === e.currentTarget) (atributos.onClick ?? atributos.onDoubleClick)?.(e as unknown as MouseEvent<HTMLElement>);
                      }
                    : undefined
                }
              >
                <Flex gap={8} align="flex-start" justify="space-between" style={{ minWidth: 0 }}>
                  <Flex gap={8} align="flex-start" style={{ minWidth: 0, flex: 1 }}>
                    {seleccao && (
                      <span onClick={(e) => e.stopPropagation()} style={{ paddingTop: 2 }}>
                        {seleccao}
                      </span>
                    )}
                    <div style={{ minWidth: 0, flex: 1 }}>
                      {estrutura.subtitulo && (
                        <Typography.Text type="secondary" style={{ fontSize: 12, display: 'block', overflowWrap: 'anywhere' }}>
                          {conteudoCelula(estrutura.subtitulo, linha, indice)}
                        </Typography.Text>
                      )}
                      <div style={{ fontWeight: 600, fontSize: 15, lineHeight: 1.35, overflowWrap: 'anywhere' }}>{conteudoVazio(tituloNo) ? '—' : tituloNo}</div>
                    </div>
                  </Flex>
                  {estrutura.etiquetas.length > 0 && (
                    <Flex vertical gap={4} align="flex-end" style={{ flexShrink: 0, maxWidth: '45%' }}>
                      {estrutura.etiquetas.map((c, k) => (
                        <span key={k} style={{ maxWidth: '100%' }}>
                          {conteudoCelula(c, linha, indice)}
                        </span>
                      ))}
                    </Flex>
                  )}
                </Flex>
                {estrutura.campos.length > 0 && (
                  <dl style={{ margin: 0, display: 'grid', gridTemplateColumns: 'minmax(0, 2fr) minmax(0, 3fr)', columnGap: 8, rowGap: 4, fontSize: 13 }}>
                    {estrutura.campos.map((c, k) => {
                      const v = conteudoCelula(c, linha, indice);
                      return (
                        <div key={k} style={{ display: 'contents' }}>
                          <dt style={{ color: token.colorTextSecondary, overflowWrap: 'anywhere' }}>{c.rotuloCartao ?? tituloColuna(c)}</dt>
                          <dd style={{ margin: 0, overflowWrap: 'anywhere', textAlign: c.align === 'right' ? 'right' : 'left', fontVariantNumeric: 'tabular-nums' }}>
                            {conteudoVazio(v) ? '—' : v}
                          </dd>
                        </div>
                      );
                    })}
                  </dl>
                )}
                {accoesNo && <div style={{ marginTop: 'auto', paddingTop: 8, borderTop: `1px solid ${token.colorBorderSecondary}` }}>{accoesNo}</div>}
              </Card>
            )}
          </div>
        );
      })}
    </div>
  );

  const spin: SpinProps = typeof carregando === 'object' ? carregando : { spinning: !!carregando };
  const total = paginacaoServidor ? (conf && conf.total) || linhas.length : totalLocal;
  return (
    <Spin {...spin}>
      {corpo}
      {conf !== false && (paginacaoServidor || total > tamanho || conf.showSizeChanger) && total > 0 && (
        <Flex justify="flex-end" style={{ marginTop: 12 }}>
          <Pagination
            size="small"
            showTotal={(t) => `${t} registo(s)`}
            {...semPosicao(conf)}
            current={pagina}
            pageSize={tamanho}
            total={total}
            onChange={(p, s) => {
              if (!conf.current) setPaginaLocal(s !== tamanho ? 1 : p);
              if (!conf.pageSize) setTamanhoLocal(s);
              conf.onChange?.(p, s);
            }}
          />
        </Flex>
      )}
    </Spin>
  );
}
