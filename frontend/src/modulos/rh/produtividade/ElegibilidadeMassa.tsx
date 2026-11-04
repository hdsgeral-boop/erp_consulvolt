import { Alert, Button, Col, Form, InputNumber, Radio, Row, Select, Space, Table, Typography, message } from 'antd';
import { ImportOutlined, MinusCircleOutlined, PlusOutlined } from '@ant-design/icons';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useState, type ClipboardEvent } from 'react';
import { enviar, obter } from '@/api/cliente';
import { ColarExcelBotao } from '@/componentes/formularios/ColarExcelBotao';
import { colagemParaLinhas, pareceColagemDeGrade, posicaoColagem, type ResultadoColagemGrade } from '@/componentes/formularios/colagemGrade';
import { ModalFormulario } from '@/componentes/formularios/ModalFormulario';
import type { CampoFormulario } from '@/componentes/formularios/tipos';
import { larguraModal } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { formatarKz } from '@/utilitarios/formatacao';
import type { ItemProdutividade } from '../api';
import { useColaboradores } from '../comum/consultas';
import { SeletorColaborador } from '../comum/componentes';
import { ImportarExcel } from '../comum/ImportarExcel';

export type ModoElegibilidadeMassa = 'ACRESCENTAR' | 'SUBSTITUIR' | 'RETIRAR';

interface Valores {
  colaboradores?: number[];
  itens: { item_id: number; preco_unitario?: number | null }[];
  modo: ModoElegibilidadeMassa;
  preco_existente: 'SUBSTITUIR' | 'MANTER';
}

export interface DetalheElegibilidadeMassa { colaborador_id: number; colaborador: string; acrescentados: string[]; alterados: string[]; retirados: string[] }

export interface ResultadoElegibilidadeMassa {
  actualizados: string[];
  sem_alteracao: string[];
  inactivos: string[];
  acrescentadas: number;
  alteradas: number;
  retiradas: number;
  detalhe: DetalheElegibilidadeMassa[];
  simulacao: boolean;
}

/** Texto do resultado (pré-visualização ou aplicação) da atribuição em massa. */
export function resumoElegibilidadeMassa(r: ResultadoElegibilidadeMassa): string {
  return `${r.actualizados.length} colaborador(es) a alterar (${r.acrescentadas} acrescentada(s), ${r.alteradas} alterada(s), ${r.retiradas} retirada(s)) · ${r.sem_alteracao.length} já assim`
    + `${r.inactivos.length ? ` · ${r.inactivos.length} não activo(s) ignorado(s)` : ''}.`;
}

/** Corpo do POST /rh/produtividade/elegibilidade/massa a partir do formulário da grelha. */
export function corpoElegibilidadeMassa(v: Valores, simular: boolean) {
  return {
    colaboradores: v.colaboradores ?? [], modo: v.modo, preco_existente: v.preco_existente, simular,
    itens: v.itens.map((i) => ({ item_id: i.item_id, preco_unitario: v.modo === 'RETIRAR' ? null : i.preco_unitario ?? null })),
  };
}

/**
 * Grelha da atribuição em massa (antes «Produtividade em massa» nos Contratos, #13/#26): um ou mais itens, com o preço do item ou um
 * preço próprio, atribuídos à elegibilidade dos colaboradores escolhidos. Acrescentar (substituindo ou mantendo o preço dos que
 * já a têm), substituir a lista ou retirar. Primeiro pré-visualiza (nada é gravado); depois aplica, tudo-ou-nada. Os itens
 * podem ser colados do Excel e a lista de colaboradores/itens importada de um modelo .xlsx.
 */
export function GrelhaElegibilidadeMassa({ aoConcluir }: { aoConcluir?: () => void }) {
  const cliente = useQueryClient();
  const colaboradores = useColaboradores();
  const itens = useQuery({ queryKey: ['rh', 'produtividade', 'itens'], queryFn: () => obter<ItemProdutividade[]>('/rh/produtividade/itens'), staleTime: 300_000 });
  const activos = (itens.data ?? []).filter((i) => i.ativo);
  const [form] = Form.useForm<Valores>();
  const modo = Form.useWatch('modo', form);
  const [plano, setPlano] = useState<ResultadoElegibilidadeMassa | null>(null);
  const [aEnviar, setAEnviar] = useState(false);
  const [importar, setImportar] = useState(false);

  // Colagem do Excel nos itens: item (pelo código ou descrição, da lista de itens activos) e preço unitário.
  const camposColaveis: CampoFormulario[] = [
    { nome: 'item_id', rotulo: 'Item', tipo: 'select', opcoes: activos.map((i) => ({ value: i.id, label: `${i.codigo} - ${i.descricao}` })) },
    { nome: 'preco_unitario', rotulo: 'Preço unitário', tipo: 'numero', min: 0, precisao: 4 },
  ];
  const aplicarColagemItens = (linhasColadas: Record<string, unknown>[], aPartirDoIndice?: number) => {
    const actuais = [...((form.getFieldValue('itens') as Valores['itens'] | undefined) ?? [])];
    const inicio = aPartirDoIndice ?? actuais.length;
    linhasColadas.forEach((valores, i) => {
      const idx = inicio + i;
      if (idx < actuais.length) actuais[idx] = { ...actuais[idx], ...valores };
      else actuais.push({ ...valores } as Valores['itens'][number]);
    });
    form.setFieldValue('itens', actuais);
    setPlano(null);
  };
  const avisoColagemItens = (r: ResultadoColagemGrade, n: number) => {
    if (!n) return;
    if (r.naoReconhecidos) {
      const det = Object.entries(r.exemplos).map(([k, s]) => `${k}: ${s.join(', ')}`).join('; ');
      void message.warning(`${n} linha(s) acrescentada(s)${r.comCabecalho ? ' (colunas pelo cabeçalho)' : ''}. ${r.naoReconhecidos} valor(es) não reconhecido(s) — corrija-os antes de aplicar: ${det}`);
    } else {
      void message.success(`${n} linha(s) acrescentada(s)${r.comCabecalho ? ' (colunas reconhecidas pelo cabeçalho)' : ''}.`);
    }
  };
  const colarItensBloco = (texto: string) => {
    const r = colagemParaLinhas(texto, camposColaveis);
    aplicarColagemItens(r.linhas);
    avisoColagemItens(r, r.linhas.length);
  };
  const aoColarCelulaItem = (e: ClipboardEvent<HTMLDivElement>) => {
    const texto = e.clipboardData?.getData('text') ?? '';
    if (!pareceColagemDeGrade(texto)) return;
    const pos = posicaoColagem(e.target);
    if (!pos) return;
    e.preventDefault();
    const r = colagemParaLinhas(texto, camposColaveis, pos.coluna);
    aplicarColagemItens(r.linhas, pos.linha);
    avisoColagemItens(r, r.linhas.length);
  };

  const executar = async (simular: boolean) => {
    const v = await form.validateFields();
    if (!(v.colaboradores ?? []).length) {
      message.warning('Escolha os colaboradores.');
      return;
    }
    setAEnviar(true);
    try {
      const { dados, mensagem } = await enviar<ResultadoElegibilidadeMassa>('post', '/rh/produtividade/elegibilidade/massa', corpoElegibilidadeMassa(v, simular));
      setPlano(dados);
      if (!simular) {
        message.success(mensagem);
        void cliente.invalidateQueries({ queryKey: ['rh'] });
        aoConcluir?.();
      }
    } catch (e) {
      notificarErro(e);
    } finally {
      setAEnviar(false);
    }
  };

  if (!itens.isLoading && !activos.length) {
    return <Alert type="warning" showIcon title="Ainda não há itens de produtividade activos." description="Configure-os em RH › Produtividade › Itens." />;
  }
  return (
    <>
      <Typography.Paragraph type="secondary">
        Só os colaboradores elegíveis para um item podem ter registos de produtividade nesse item. Deixe o preço em branco para usar o preço do item.
        Para muitos colaboradores de uma vez, use «Importar Excel» (NIF, código do item e preço próprio) ou a vista «Linhas».
      </Typography.Paragraph>
      <Form form={form} layout="vertical" initialValues={{ modo: 'ACRESCENTAR', preco_existente: 'SUBSTITUIR', itens: [{}] }} onValuesChange={() => setPlano(null)}>
        <Form.List name="itens">{(campos, { add, remove }) => (
          <>
            <div onPaste={aoColarCelulaItem}>
              {campos.map(({ key, name }) => (
                <Row key={key} gutter={8} align="middle" data-colar-linha={name}>
                  <Col xs={24} sm={14} data-colar-coluna={0}>
                    <Form.Item name={[name, 'item_id']} rules={[{ required: true, message: 'Item.' }]}>
                      <Select showSearch optionFilterProp="label" placeholder="Item de produtividade" loading={itens.isLoading}
                        options={activos.map((i) => ({ value: i.id, label: `${i.codigo} — ${i.descricao} (${formatarKz(Number(i.preco_unitario))}${i.unidade ? `/${i.unidade}` : ''})` }))} />
                    </Form.Item>
                  </Col>
                  <Col xs={20} sm={8} data-colar-coluna={1}>
                    <Form.Item name={[name, 'preco_unitario']}>
                      <InputNumber min={0} precision={4} decimalSeparator="," placeholder="igual ao item" disabled={modo === 'RETIRAR'} style={{ width: '100%' }} />
                    </Form.Item>
                  </Col>
                  <Col xs={4} sm={2}>{campos.length > 1 && <Button type="text" danger icon={<MinusCircleOutlined />} aria-label="Retirar item" onClick={() => remove(name)} />}</Col>
                </Row>
              ))}
            </div>
            <Space size={8} wrap style={{ marginBottom: 12 }}>
              <Button size="small" icon={<PlusOutlined />} onClick={() => add({})}>Acrescentar item</Button>
              <ColarExcelBotao tamanho="small" colunas={camposColaveis.map((c) => c.rotulo)} aoColar={colarItensBloco} />
            </Space>
          </>
        )}</Form.List>
        <Form.Item name="modo" label="O que fazer à elegibilidade dos colaboradores escolhidos">
          <Radio.Group style={{ display: 'flex', flexDirection: 'column', gap: 4 }} options={[
            { value: 'ACRESCENTAR', label: 'Acrescentar estes itens, mantendo os que já têm' },
            { value: 'SUBSTITUIR', label: 'Substituir a lista: ficam só estes itens (mantêm-se os ganhos por entregáveis)' },
            { value: 'RETIRAR', label: 'Retirar estes itens da elegibilidade' },
          ]} />
        </Form.Item>
        {modo === 'ACRESCENTAR' && (
          <Form.Item name="preco_existente" label="Se o colaborador já for elegível para o item">
            <Radio.Group options={[{ value: 'SUBSTITUIR', label: 'Substituir o preço pelo indicado' }, { value: 'MANTER', label: 'Manter o preço actual' }]} />
          </Form.Item>
        )}
        <Form.Item name="colaboradores" label="Colaboradores" rules={[{ required: true, message: 'Escolha os colaboradores.' }]}>
          <SeletorColaborador mode="multiple" apenasActivos maxTagCount="responsive" style={{ width: '100%' }} />
        </Form.Item>
        <Button size="small" style={{ marginTop: -12, marginBottom: 12 }}
          onClick={() => form.setFieldsValue({ colaboradores: colaboradores.lista.filter((c) => c.estado === 'ACTIVO').map((c) => c.id) as never })}>Todos os activos</Button>
      </Form>
      {plano && (
        <Alert type={plano.simulacao ? 'info' : 'success'} showIcon style={{ marginBottom: 12 }} title={plano.simulacao ? 'Pré-visualização (nada foi gravado)' : 'Aplicado'}
          description={<>
            {resumoElegibilidadeMassa(plano)}
            {plano.detalhe.length > 0 && (
              <Table size="small" style={{ marginTop: 8 }} rowKey="colaborador_id" pagination={{ pageSize: 8, hideOnSinglePage: true }} dataSource={plano.detalhe} columns={[
                { title: 'Colaborador', dataIndex: 'colaborador' },
                { title: 'Acrescenta', dataIndex: 'acrescentados', render: (v: string[]) => v.join(', ') },
                { title: 'Altera o preço', dataIndex: 'alterados', render: (v: string[]) => v.join(', ') },
                { title: 'Retira', dataIndex: 'retirados', render: (v: string[]) => v.join(', ') },
              ]} />
            )}
          </>} />
      )}
      <Space wrap>
        <Button icon={<ImportOutlined />} onClick={() => setImportar(true)}>Importar Excel</Button>
        <Button loading={aEnviar} onClick={() => void executar(true)}>Pré-visualizar</Button>
        <Button type="primary" danger={modo !== 'ACRESCENTAR'} loading={aEnviar} disabled={!plano?.simulacao || !plano.actualizados.length} onClick={() => void executar(false)}>Aplicar</Button>
      </Space>
      <ImportarExcel aberto={importar} titulo="Importar elegibilidade (Excel)" url="/rh/produtividade/elegibilidade/importar" modelo="elegibilidade" ficheiroModelo="Template_Elegibilidade.xlsx"
        comDecisao="IGNORAR" invalidar={[['rh']]} aoFechar={() => setImportar(false)}
        ajuda="Folha «Elegibilidade»: NIF, código do item e preço próprio (opcional). Acrescenta os itens à elegibilidade de cada colaborador; uma linha com erro e nada é gravado. Use primeiro «Simular»." />
    </>
  );
}

/**
 * Atribuir em massa (Produtividade › Elegibilidade): vista «Grade» (a grelha acima, com os modos e a pré-visualização) e vista «Linhas»
 * (uma linha por colaborador e item, com preço próprio opcional; colar do Excel; cada linha grava-se com as regras da elegibilidade individual).
 */
export function AtribuirEmMassa({ aberto, aoFechar }: { aberto: boolean; aoFechar: () => void }) {
  const colaboradores = useColaboradores();
  const itens = useQuery({ queryKey: ['rh', 'produtividade', 'itens'], queryFn: () => obter<ItemProdutividade[]>('/rh/produtividade/itens'), staleTime: 300_000 });
  const campos: CampoFormulario[] = [
    { nome: 'colaborador_id', rotulo: 'Colaborador', tipo: 'select', obrigatorio: true, largura: 260, aliases: ['nome', 'nome completo', 'nif'],
      opcoes: colaboradores.lista.filter((c) => c.estado === 'ACTIVO').map((c) => ({ value: c.id, label: c.nome_completo })) },
    { nome: 'item_produtividade_id', rotulo: 'Item', tipo: 'select', obrigatorio: true, largura: 260, aliases: ['codigo', 'código do item', 'item de produtividade'],
      opcoes: (itens.data ?? []).filter((i) => i.ativo).map((i) => ({ value: i.id, label: `${i.codigo} - ${i.descricao}` })) },
    { nome: 'preco_unitario', rotulo: 'Preço próprio', tipo: 'numero', min: 0, precisao: 4, aliases: ['preco', 'preço', 'preco proprio'] },
    { nome: 'nota', rotulo: 'Nota', tipo: 'texto', maxLength: 255 },
  ];
  return (
    <ModalFormulario chave="rh.elegibilidade" titulo="Atribuir elegibilidade em massa" aberto={aberto} aoFechar={aoFechar} criacao width={larguraModal(760)} footer={null}
      campos={campos} nomeRegistos="elegibilidades" invalidar={[['rh']]} fecharAoGravarTudo={false}
      nota="Uma linha por colaborador e item (preço próprio opcional: vazio = preço do item). Pode colar do Excel. Cada linha é gravada com as regras da elegibilidade individual."
      gravarLinha={(r) => enviar('post', '/rh/produtividade/elegibilidade', {
        colaborador_id: r.colaborador_id, item_produtividade_id: r.item_produtividade_id, preco_unitario: r.preco_unitario ?? null, nota: (r.nota as string | undefined)?.trim() || null, ativo: true,
      })}>
      <GrelhaElegibilidadeMassa aoConcluir={aoFechar} />
    </ModalFormulario>
  );
}
