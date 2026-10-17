import { Button, Flex, Form, Input, InputNumber, Select, Space, Typography, message, theme } from 'antd';
import { ApartmentOutlined, CopyOutlined, DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import { useState, type ReactNode } from 'react';
import { ModalRepartirLinha, linhasRepartidas } from '@/componentes/reparticao';
import { useEcra } from '@/componentes/responsivo';
import { SeletorAux, SeletorConta, SeletorTerceiro, SeletorUnidade } from './Seletores';

export interface LinhaEditor {
  codigo_conta?: string;
  tipo_dc?: 'D' | 'C';
  valor?: number;
  descricao?: string;
  terceiro_id?: number;
  numero_documento?: string;
  centro_custo_id?: number;
  unidade_negocio_id?: number;
  nota_demonstracao_id?: number;
  nota_fluxo_caixa_id?: number;
  /** Só para mostrar o terceiro pré-preenchido (não se envia). */
  _terceiro?: string;
}

export interface CamposEditor {
  terceiro?: boolean;
  numeroDocumento?: boolean;
  centroCusto?: boolean;
  unidade?: boolean;
  notas?: boolean;
}

/**
 * Disposição das colunas, cada ecrã como o seu legado (os dois divergem):
 * - `lancamentos` (ui_lancamentos.js:650-672): Conta · D/C · Valor · Terceiro · Notas Dem / Fluxo · UNº / CC (com «Repartir») ·
 *   Acção; «Adicionar Linha» por baixo, ao lado de «Equilibrar», com os totais à direita.
 * - `tesouraria` (ui_tesouraria.js:699-725, 896-960): Conta Contrapartida · Terceiro · Doc · Nota Demo · Nota Fluxo ·
 *   U. Negócio / C. Custo · Descrição · D/C · Valor · Ações; «Adicionar Linha» no cabeçalho dos itens; uma linha nova herda
 *   o terceiro da anterior.
 * Por omissão é `tesouraria` (o formulário da Tesouraria não passa a prop); os Lançamentos pedem `lancamentos`.
 */
export type DisposicaoEditor = 'lancamentos' | 'tesouraria';

interface Coluna {
  chave: string;
  titulo: string;
  largura: string;
  celula: (name: number) => ReactNode;
}

/** Colunas visíveis de cada disposição, pela ordem do legado (exportado para os testes). */
export function colunasEditor(disposicao: DisposicaoEditor, campos: CamposEditor): string[] {
  const temUnCc = !!(campos.unidade || campos.centroCusto);
  if (disposicao === 'lancamentos') {
    return ['conta', 'dc', 'valor', campos.terceiro && 'terceiro', campos.notas && 'notas', temUnCc && 'uncc', 'descricao', 'accoes'].filter(Boolean) as string[];
  }
  return [
    'conta', campos.terceiro && 'terceiro', campos.numeroDocumento && 'doc', campos.notas && 'nota_demo', campos.notas && 'nota_fluxo', temUnCc && 'uncc', 'descricao', 'dc', 'valor', 'accoes',
  ].filter(Boolean) as string[];
}

/**
 * Editor de linhas a débito/crédito (Form.List «linhas»). Usa-se dentro de um <Form>; o equilíbrio e os totais
 * mostram-se fora (IndicadorEquilibrio), porque a regra muda: lançamento manual exige D = C, documento de tesouraria não.
 */
export function EditorLinhasDC({
  nome = 'linhas',
  campos = {},
  minimo = 1,
  prefixosConta,
  rotuloValor = 'Valor (Kz)',
  disposicao = 'tesouraria',
  extraRodape,
  rodapeDireita,
  accoesLinha,
}: {
  nome?: string;
  campos?: CamposEditor;
  minimo?: number;
  prefixosConta?: string[];
  /** Cabeçalho da coluna do valor (ex.: «Valor (USD)» num lançamento em moeda estrangeira). */
  rotuloValor?: string;
  disposicao?: DisposicaoEditor;
  /** Botões ao lado de «Adicionar Linha» (Lançamentos: «Equilibrar»). */
  extraRodape?: ReactNode;
  /** Conteúdo à direita da barra de «Adicionar Linha» (Lançamentos: totais D/C). */
  rodapeDireita?: ReactNode;
  /** Botões extra na coluna de acções de cada linha (Tesouraria: «Explorar pendentes»). */
  accoesLinha?: (indice: number) => ReactNode;
}) {
  const forma = Form.useFormInstance();
  const { token } = theme.useToken();
  const { telemovel } = useEcra();
  const tesouraria = disposicao === 'tesouraria';
  // Fase C (R4): «Repartir linha» por unidades de negócio (ui_lancamentos.js:1495, ui_tesouraria.js:1113) — só com a coluna da unidade
  const [aRepartir, setARepartir] = useState<number | null>(null);
  const linhaARepartir = aRepartir !== null ? (forma.getFieldValue([nome, aRepartir]) as LinhaEditor | undefined) : undefined;
  const substituirLinha = (indice: number, novas: LinhaEditor[]) => {
    const actuais = [...((forma.getFieldValue(nome) as LinhaEditor[] | undefined) ?? [])];
    actuais.splice(indice, 1, ...novas);
    forma.setFieldValue(nome, actuais);
    message.success(`Linha repartida por ${novas.length} unidades.`);
  };
  const item = { style: { marginBottom: 0 } };
  const botaoRepartir = (name: number) =>
    campos.unidade && (
      <Button
        type="text"
        icon={<ApartmentOutlined />}
        style={{ color: '#7c3aed' }}
        aria-label="Repartir linha por unidades"
        title="Repartir a linha por várias unidades de negócio"
        onClick={() => setARepartir(name)}
      />
    );

  const celulas: Record<string, Omit<Coluna, 'chave'>> = {
    conta: {
      titulo: tesouraria ? 'Conta Contrapartida' : 'Conta',
      largura: 'minmax(180px, 2.2fr)',
      celula: (name) => (
        <Form.Item {...item} name={[name, 'codigo_conta']} rules={[{ required: true, message: 'Conta' }]}>
          <SeletorConta style={{ width: '100%' }} prefixos={prefixosConta} />
        </Form.Item>
      ),
    },
    dc: {
      titulo: 'D/C',
      largura: tesouraria ? '120px' : '70px',
      celula: (name) => (
        <Form.Item {...item} name={[name, 'tipo_dc']} rules={[{ required: true, message: 'D/C' }]}>
          <Select
            options={tesouraria ? [{ value: 'D', label: 'D (DÉBITO)' }, { value: 'C', label: 'C (CRÉDITO)' }] : [{ value: 'D', label: 'D' }, { value: 'C', label: 'C' }]}
            aria-label="Débito ou crédito"
          />
        </Form.Item>
      ),
    },
    valor: {
      titulo: rotuloValor,
      largura: 'minmax(110px, 1.1fr)',
      celula: (name) => (
        <Form.Item {...item} name={[name, 'valor']} rules={[{ required: true, message: 'Valor' }]}>
          <InputNumber min={0.01} precision={2} style={{ width: '100%' }} placeholder="0,00" decimalSeparator="," aria-label={rotuloValor} />
        </Form.Item>
      ),
    },
    terceiro: {
      titulo: 'Terceiro',
      largura: 'minmax(150px, 1.6fr)',
      celula: (name) => (
        <Form.Item {...item} name={[name, 'terceiro_id']}>
          <SeletorTerceiro style={{ width: '100%' }} rotuloInicial={forma.getFieldValue([nome, name, '_terceiro'])} />
        </Form.Item>
      ),
    },
    doc: {
      titulo: 'Doc',
      largura: 'minmax(90px, 1fr)',
      celula: (name) => (
        <Form.Item {...item} name={[name, 'numero_documento']}>
          <Input placeholder="Nº Doc" maxLength={100} aria-label="N.º do documento liquidado" />
        </Form.Item>
      ),
    },
    notas: {
      titulo: 'Notas Dem / Fluxo',
      largura: 'minmax(180px, 2fr)',
      celula: (name) => (
        <Flex gap={4}>
          <Form.Item {...item} name={[name, 'nota_demonstracao_id']} style={{ marginBottom: 0, flex: 1, minWidth: 0 }}>
            <SeletorAux tabela="notas-demonstracao" placeholder="Nota Dem" style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item {...item} name={[name, 'nota_fluxo_caixa_id']} style={{ marginBottom: 0, flex: 1, minWidth: 0 }}>
            <SeletorAux tabela="notas-fluxo-caixa" placeholder="Nota Fluxo" style={{ width: '100%' }} />
          </Form.Item>
        </Flex>
      ),
    },
    nota_demo: {
      titulo: 'Nota Demo',
      largura: 'minmax(90px, 1fr)',
      celula: (name) => (
        <Form.Item {...item} name={[name, 'nota_demonstracao_id']}>
          <SeletorAux tabela="notas-demonstracao" placeholder="Nota" style={{ width: '100%' }} />
        </Form.Item>
      ),
    },
    nota_fluxo: {
      titulo: 'Nota Fluxo',
      largura: 'minmax(90px, 1fr)',
      celula: (name) => (
        <Form.Item {...item} name={[name, 'nota_fluxo_caixa_id']}>
          <SeletorAux tabela="notas-fluxo-caixa" placeholder="Fluxo" style={{ width: '100%' }} />
        </Form.Item>
      ),
    },
    uncc: {
      titulo: tesouraria ? 'U. Negócio / C. Custo' : 'UNº / CC',
      largura: tesouraria ? 'minmax(130px, 1.4fr)' : 'minmax(200px, 2fr)',
      celula: (name) => (
        <Flex gap={4} vertical={tesouraria} align={tesouraria ? 'stretch' : 'center'}>
          {campos.unidade && (
            <Form.Item {...item} name={[name, 'unidade_negocio_id']} style={{ marginBottom: 0, flex: 1, minWidth: 0 }}>
              <SeletorUnidade style={{ width: '100%' }} />
            </Form.Item>
          )}
          {campos.centroCusto && (
            <Form.Item {...item} name={[name, 'centro_custo_id']} style={{ marginBottom: 0, flex: 1, minWidth: 0 }}>
              <SeletorAux tabela="centros-custo" placeholder="C. Custo" style={{ width: '100%' }} />
            </Form.Item>
          )}
          {/* legado (ui_lancamentos.js:1612): «Repartir» na própria célula UNº / CC */}
          {!tesouraria && botaoRepartir(name)}
        </Flex>
      ),
    },
    descricao: {
      titulo: 'Descrição',
      largura: 'minmax(140px, 1.5fr)',
      celula: (name) => (
        <Form.Item {...item} name={[name, 'descricao']}>
          <Input placeholder={tesouraria ? 'Descrição' : 'Descrição da linha (opcional)'} maxLength={1000} aria-label="Descrição da linha" />
        </Form.Item>
      ),
    },
    accoes: {
      titulo: tesouraria ? 'Ações' : 'Acção',
      largura: 'auto',
      celula: () => null, // preenchida no ciclo (precisa de add/remove)
    },
  };
  const colunas = colunasEditor(disposicao, campos);
  const grelha = colunas.map((c) => celulas[c].largura).join(' ');
  const estiloGrelha: React.CSSProperties = telemovel
    ? { display: 'grid', gridTemplateColumns: '1fr', gap: 6 }
    : { display: 'grid', gridTemplateColumns: grelha, gap: 8, alignItems: 'start', minWidth: tesouraria ? 1150 : 1050 };

  return (
    <Form.List
      name={nome}
      rules={[{ validator: async (_, v: unknown[]) => (v && v.length >= minimo ? undefined : Promise.reject(new Error(`Acrescente pelo menos ${minimo} linha(s).`))) }]}
    >
      {(linhas, { add, remove }, { errors }) => {
        // legado da tesouraria: a linha nova herda o terceiro da anterior (o n.º de documento não, de propósito)
        const acrescentar = () => {
          const ultima = (forma.getFieldValue(nome) as LinhaEditor[] | undefined)?.at(-1);
          add(tesouraria && ultima?.terceiro_id ? { tipo_dc: 'D', terceiro_id: ultima.terceiro_id, _terceiro: ultima._terceiro } : { tipo_dc: 'D' });
        };
        const botaoAdicionar = (
          <Button icon={<PlusOutlined />} onClick={acrescentar}>
            Adicionar Linha
          </Button>
        );
        const accoes = (name: number) => (
          <Space size={2}>
            <Button
              type="text"
              icon={<CopyOutlined />}
              aria-label="Duplicar linha"
              title="Duplicar linha (com o sentido D/C trocado)"
              onClick={() => {
                const l = forma.getFieldValue([nome, name]) as LinhaEditor;
                add({ ...l, tipo_dc: l?.tipo_dc === 'D' ? 'C' : 'D' }, name + 1);
              }}
            />
            {accoesLinha?.(name)}
            {tesouraria && botaoRepartir(name)}
            <Button danger type="text" icon={<DeleteOutlined />} aria-label="Remover linha" title="Remover linha" onClick={() => remove(name)} />
          </Space>
        );
        return (
          <>
            {tesouraria && (
              // legado: «Adicionar Linha» no cabeçalho dos «Itens de Contrapartida», à direita
              <Flex justify="flex-end" style={{ marginBottom: 8 }}>
                {botaoAdicionar}
              </Flex>
            )}
            <div style={{ overflowX: telemovel ? undefined : 'auto' }} data-disposicao={disposicao}>
              {!telemovel && (
                <div className="cabecalho-linhas" style={{ ...estiloGrelha, background: token.colorFillAlter, borderBottom: `1px solid ${token.colorBorderSecondary}`, padding: '6px 4px', marginBottom: 6 }} role="row">
                  {colunas.map((c) => (
                    <Typography.Text key={c} strong type="secondary" role="columnheader" style={{ fontSize: 12, whiteSpace: 'nowrap' }}>
                      {celulas[c].titulo}
                    </Typography.Text>
                  ))}
                </div>
              )}
              {linhas.map(({ key, name }) => (
                <div key={key} role="row" style={{ ...estiloGrelha, borderBottom: `1px dashed ${token.colorBorderSecondary}`, padding: '4px 4px 8px', marginBottom: 6 }}>
                  {colunas.map((c) => (
                    <div key={c} style={{ minWidth: 0 }}>
                      {telemovel && c !== 'accoes' && (
                        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                          {celulas[c].titulo}
                        </Typography.Text>
                      )}
                      {c === 'accoes' ? accoes(name) : celulas[c].celula(name)}
                    </div>
                  ))}
                </div>
              ))}
            </div>
            <Form.ErrorList errors={errors} />
            {!tesouraria && (
              <Flex wrap gap={16} justify="space-between" align="center" style={{ marginTop: 12 }}>
                <Space wrap>
                  {botaoAdicionar}
                  {extraRodape}
                </Space>
                {rodapeDireita}
              </Flex>
            )}
            {tesouraria && (extraRodape || rodapeDireita) && (
              <Flex wrap gap={16} justify="space-between" align="center" style={{ marginTop: 12 }}>
                <Space wrap>{extraRodape}</Space>
                {rodapeDireita}
              </Flex>
            )}
            {aRepartir !== null && (
              <ModalRepartirLinha
                valor={Number(linhaARepartir?.valor) || 0}
                subtitulo={linhaARepartir?.codigo_conta ? `Conta ${linhaARepartir.codigo_conta}` : undefined}
                aoFechar={() => setARepartir(null)}
                aoRepartir={(r) => {
                  if (linhaARepartir) substituirLinha(aRepartir, linhasRepartidas(linhaARepartir, r));
                  setARepartir(null);
                }}
              />
            )}
          </>
        );
      }}
    </Form.List>
  );
}

/** Linhas do editor → corpo do pedido (remove campos internos e vazios). */
export function linhasParaApi(linhas: LinhaEditor[] | undefined): Record<string, unknown>[] {
  return (linhas ?? []).map(({ _terceiro, ...l }) => {
    void _terceiro;
    return Object.fromEntries(Object.entries(l).filter(([, v]) => v !== undefined && v !== null && v !== ''));
  });
}
