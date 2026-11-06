import { Button, Checkbox, Col, Flex, Form, Input, InputNumber, Row, Select, Skeleton, Switch, Typography, message, theme } from 'antd';
import { MinusCircleOutlined, PlusOutlined, SaveOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { ModalFormulario, SeccaoFormulario } from '@/componentes/formularios';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarKz } from '@/utilitarios/formatacao';
import { useAccao } from '@/componentes/Accoes';
import { type ColunaApi } from '@/componentes/TabelaApi';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { SeletorConta } from '@/modulos/compras/comum/Seletores';
import { GRUPOS_LAV, useDefinicoesLav, usePecas, useServicosLav } from './dados';
import { chaveOpcao, MODOS_PRECO, opcoesPeca, rotuloOpcao, unidadeOpcao, type DefinicoesLav, type Peca, type PrecoServicoPeca, type ServicoLav } from './tipos';
import { ImportarTabela } from './ImportarTabela';
import { CAMPOS_PECA, CAMPOS_SERVICO, dadosPeca, dadosServico, matrizDaPeca, precosServicoDaMatriz, validarOpcoesPeca, type OpcaoForm } from './formularios';

import { IconeLegado, TabelaComModos } from '@/componentes/vistas';
import { Seccao } from '../contas/Seccao';
import '../comum/pos.css';
import { BotaoModeloLav } from './ImportarTabela';

/** «Venda por» como no antigo (lavandaria.js:1303). */
export function rotuloVendaPor(unidade: string | null | undefined): string {
  return unidade === 'KG' ? 'Quilograma (kg)' : 'Peça';
}

/** Preço da tabela de peças: «1 500,00 / peça» ou «por definir» (lavandaria.js:1304). */
export function textoPrecoPeca(p: Pick<Peca, 'preco' | 'unidade'>): string {
  const preco = Number(p.preco ?? 0);
  return preco > 0 ? `${formatarKz(preco)} / ${p.unidade === 'KG' ? 'kg' : 'peça'}` : 'por definir';
}

/** Opções de preço da peça em texto: «Peça 1 500,00 · Tamanho M 1 800,00» (a 1.ª manda). */
export function textoOpcoesPeca(p: Pick<Peca, 'preco' | 'unidade' | 'opcoes_preco'>): string {
  const ops = opcoesPeca(p);
  return ops.some((o) => Number(o.preco) > 0) ? ops.map((o) => `${rotuloOpcao(o)} ${formatarKz(Number(o.preco))}`).join(' · ') : 'por definir';
}

/** «Venda por» da tabela: os rótulos das opções («Peça, Tamanho M»). */
const vendaPor = (p: Pick<Peca, 'preco' | 'unidade' | 'opcoes_preco'>) => opcoesPeca(p).map((o) => rotuloOpcao(o)).join(', ');

/** Separador «Peças» (renderPecas, lavandaria.js:1288-1307): tabela de peças com modelos e importação. */
export function TabelaPecas() {
  const { pode } = useSessao();
  const pecas = usePecas(true);
  const servicos = useServicosLav(true);
  const [editar, setEditar] = useState<Peca | 'nova' | null>(null);
  const gerir = pode('lav_tabelas');
  const colunasPecas: ColunaApi<Peca>[] = [
    { title: 'Código', dataIndex: 'codigo', render: (v) => <b>{v}</b> },
    { title: 'Peça', dataIndex: 'nome', render: (v) => <b>{v}</b> },
    { title: 'Tipo de tecido', dataIndex: 'tecido', render: (v) => v || '—' },
    { title: 'Cor', dataIndex: 'cor', render: (v) => v || '—' },
    { title: 'Venda por', dataIndex: 'unidade', render: (_, p) => vendaPor(p), valorFiltro: (p) => vendaPor(p), valorImpressao: (p) => vendaPor(p) },
    {
      title: 'Preço (IVA incl.)',
      dataIndex: 'preco',
      align: 'right',
      render: (_, p) => {
        const n = (p.precos_servico ?? []).length;
        return (
          <>
            <span style={{ fontWeight: 700, color: opcoesPeca(p).some((o) => Number(o.preco) > 0) ? undefined : '#b91c1c' }}>
              {opcoesPeca(p).length > 1
                ? opcoesPeca(p).map((o) => (
                    <div key={chaveOpcao(o)}>
                      {rotuloOpcao(o)} {formatarKz(Number(o.preco))}
                      {unidadeOpcao(o) === 'KG' ? ' / kg' : ''}
                    </div>
                  ))
                : textoPrecoPeca({ preco: String(opcoesPeca(p)[0].preco), unidade: unidadeOpcao(opcoesPeca(p)[0]) })}
            </span>
            {n > 0 && (
              <div>
                <small style={{ color: '#2563eb', fontWeight: 600 }} title={(p.precos_servico ?? []).map((x) => `${servicos.data?.find((s) => s.id === x.produto_id)?.nome ?? `#${x.produto_id}`}: ${formatarKz(x.preco)}`).join('\n')}>
                  + {n} preço(s) por serviço
                </small>
              </div>
            )}
          </>
        );
      },
      valorImpressao: (p) => `${textoOpcoesPeca(p)}${(p.precos_servico ?? []).length ? ` (+ ${(p.precos_servico ?? []).length} preço(s) por serviço)` : ''}`,
    },
    { title: 'Estado', dataIndex: 'ativo', render: (v) => (v === false ? 'Inactiva' : 'Activa'), valorFiltro: (p) => (p.ativo === false ? 'Inactiva' : 'Activa'), valorImpressao: (p) => (p.ativo === false ? 'Inactiva' : 'Activa') },
  ];
  return (
    <Seccao
      titulo="Tabela de peças"
      descricao="Tipo de peça, tecido e cor habituais, unidade de venda (por peça ou por kg) e preço. O código é atribuído automaticamente."
      extra={
        gerir && (
          <>
            <BotaoModeloLav tipo="pecas" />
            <ImportarTabela tipo="pecas" />
            <BotaoModeloLav tipo="precos" />
            <ImportarTabela tipo="precos" />
            <Button type="primary" icon={<IconeLegado icone="plus" />} onClick={() => setEditar('nova')}>
              Nova peça
            </Button>
          </>
        )
      }
    >
      <TabelaComModos<Peca>
        idVista="pecas"
        modos={false}
        rowKey="id"
        size="small"
        scroll={scrollTabela()}
        loading={pecas.isFetching}
        dataSource={pecas.data}
        columns={colunasPecas}
        onRow={(p) => ({ style: p.ativo === false ? { opacity: 0.55 } : undefined })}
        impressao={{ titulo: 'Tabela de peças da lavandaria' }}
        accoes={(p) => [{ icone: 'edit', titulo: 'Editar', oculto: !gerir, onClick: () => setEditar(p) }]}
        vazio={{ icone: 'tshirt', titulo: 'Sem peças.', texto: 'Ex.: Camisa, Calças, Fato, Vestido, Edredão, Roupa diversa (kg).' }}
      />
      <ModalPeca alvo={editar} servicos={servicos.data ?? []} aoFechar={() => setEditar(null)} />
    </Seccao>
  );
}

/** Rótulo do serviço na matriz de preços do editor. */
const nomeServico = (s: ServicoLav) => `${s.codigo ? `${s.codigo} · ` : ''}${s.nome}`;

function ModalPeca({ alvo, servicos, aoFechar }: { alvo: Peca | 'nova' | null; servicos: ServicoLav[]; aoFechar: () => void }) {
  const [form] = Form.useForm();
  const p = alvo && alvo !== 'nova' ? alvo : null;
  const gravar = useAccao({ invalidar: [['pos', 'lavandaria']], aoSucesso: aoFechar, tituloErro: 'Não foi possível gravar a peça' });
  const comPrecos = (p?.precos_servico ?? []).length > 0;
  // entradas antigas sem modo de uma peça com várias opções: seguem como estão (valem para todas as opções)
  const [semModo, setSemModo] = useState<PrecoServicoPeca[]>([]);
  const opcoes = Form.useWatch('opcoes_preco', form) as OpcaoForm[] | undefined;
  useEffect(() => {
    if (!alvo) return;
    form.resetFields();
    const { matriz, semModo: antigas } = matrizDaPeca(p);
    setSemModo(antigas);
    form.setFieldsValue(
      p
        ? { ...p, opcoes_preco: opcoesPeca(p).map((o) => ({ modo: o.modo, tamanho: o.tamanho, preco: Number(o.preco) })), ativo: p.ativo !== false, matriz }
        : { opcoes_preco: [{ modo: 'PECA', tamanho: '', preco: 0 }], ativo: true, matriz: {} },
    );
  }, [alvo, p, form]);
  /** O corpo do pedido lê a lista e a matriz do formulário (o onFinish do antd só devolve os subcampos registados). */
  const gravarPeca = (v: Record<string, unknown>) => {
    const ops = (form.getFieldValue('opcoes_preco') ?? v.opcoes_preco ?? []) as OpcaoForm[];
    const erro = validarOpcoesPeca(ops);
    if (erro) return void message.warning(erro);
    const dados = dadosPeca({ ...v, opcoes_preco: ops, precos_servico: precosServicoDaMatriz(form.getFieldValue('matriz'), ops, semModo) });
    gravar.mutate(p ? { metodo: 'put', url: `/pos/lavandaria/pecas/${p.id}`, dados } : { url: '/pos/lavandaria/pecas', dados });
  };
  const opcoesValidas = (opcoes ?? []).filter((o) => o?.modo !== 'TAMANHO' || String(o?.tamanho ?? '').trim());
  return (
    <ModalFormulario
      chave="pos.lavandaria.pecas"
      titulo={p ? `Peça ${p.nome}` : 'Nova peça'}
      aberto={!!alvo}
      aoFechar={aoFechar}
      criacao={!p}
      form={form}
      campos={CAMPOS_PECA}
      nomeRegistos="peças"
      invalidar={[['pos', 'lavandaria']]}
      gravarLinha={(r) => enviar('post', '/pos/lavandaria/pecas', dadosPeca(r))}
      width={larguraModal(760)}
      okText="Gravar"
      cancelText="Cancelar"
      confirmLoading={gravar.isPending}
      onOk={() => form.submit()}
    >
      <Form form={form} layout="vertical" onFinish={gravarPeca}>
        <Row gutter={[12, 0]}>
          <Col xs={24} sm={18}>
            <Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }]}>
              <Input maxLength={255} />
            </Form.Item>
          </Col>
          <Col xs={24} sm={6}>
            <Form.Item name="ativo" label="Activa" valuePropName="checked">
              <Switch />
            </Form.Item>
          </Col>
          <Col xs={24} sm={12}>
            <Form.Item name="tecido" label="Tecido" rules={[{ max: 20, message: 'No máximo 20 caracteres.' }]}>
              <Input maxLength={20} />
            </Form.Item>
          </Col>
          <Col xs={24} sm={12}>
            <Form.Item name="cor" label="Cor" rules={[{ max: 10, message: 'No máximo 10 caracteres.' }]}>
              <Input maxLength={10} />
            </Form.Item>
          </Col>
        </Row>
        {/* Opções de preço (lav_prices): à peça, ao quilo ou por tamanho; a 1.ª manda na lista e nos mapas */}
        <SeccaoFormulario titulo="Cobrar por — opções de preço">
          <Typography.Paragraph type="secondary" style={{ marginBottom: 8 }}>
            Cada opção tem o seu preço (IVA incluído). A primeira é a que aparece por omissão; ao receber a peça escolhe-se a opção («Cobrar por»).
          </Typography.Paragraph>
          <Form.List name="opcoes_preco">
            {(campos, { add, remove }) => (
              <>
                {campos.map((c, k) => (
                  <Flex key={c.key} gap={8} align="baseline" wrap>
                    <Form.Item name={[c.name, 'modo']} style={{ width: 180, maxWidth: '100%' }}>
                      <Select aria-label={`Modo da opção ${k + 1}`} options={Object.entries(MODOS_PRECO).map(([value, label]) => ({ value, label }))} />
                    </Form.Item>
                    {opcoes?.[c.name]?.modo === 'TAMANHO' ? (
                      <Form.Item name={[c.name, 'tamanho']} style={{ width: 160, maxWidth: '100%' }}>
                        <Input aria-label={`Tamanho da opção ${k + 1}`} placeholder="Tamanho (ex.: M, Casal)" maxLength={60} list="lav-tamanhos" />
                      </Form.Item>
                    ) : null}
                    <Form.Item name={[c.name, 'preco']}>
                      <InputNumber<number> aria-label={`Preço da opção ${k + 1}`} min={0} precision={2} decimalSeparator="," placeholder="Preço" style={{ width: 150, maxWidth: '100%' }} />
                    </Form.Item>
                    <MinusCircleOutlined aria-label={`Retirar a opção ${k + 1}`} style={{ visibility: campos.length > 1 ? 'visible' : 'hidden' }} onClick={() => campos.length > 1 && remove(c.name)} />
                  </Flex>
                ))}
                <datalist id="lav-tamanhos">
                  {['XS', 'S', 'M', 'L', 'XL', 'XXL', 'Criança', 'Pequeno', 'Médio', 'Grande', 'Casal', 'Solteiro'].map((t) => (
                    <option key={t} value={t} />
                  ))}
                </datalist>
                <Flex gap={8} wrap>
                  <Button type="dashed" icon={<PlusOutlined />} onClick={() => add({ modo: 'PECA', tamanho: '', preco: 0 })}>
                    Opção
                  </Button>
                  <Button type="dashed" icon={<PlusOutlined />} onClick={() => add({ modo: 'TAMANHO', tamanho: '', preco: 0 })}>
                    Tamanho
                  </Button>
                </Flex>
              </>
            )}
          </Form.List>
        </SeccaoFormulario>
        {/* Matriz serviço × opção («Preços por serviço» do legado): vazio ou 0 = o preço da própria opção */}
        <SeccaoFormulario titulo="Preços específicos por serviço e opção" recolhida={!comPrecos}>
          <Typography.Paragraph type="secondary" style={{ marginBottom: 8 }}>
            Só os serviços cujo preço difere do preço da opção. Vazio ou 0 = usa o preço da própria opção.
          </Typography.Paragraph>
          {servicos.length === 0 ? <Typography.Text type="secondary">Ainda não há serviços.</Typography.Text> : null}
          {servicos.map((sv) => (
            <div key={sv.id} style={{ marginBottom: 8 }}>
              <div style={{ fontWeight: 600, fontSize: 13 }}>{nomeServico(sv)}</div>
              <Flex gap={8} wrap>
                {opcoesValidas.map((o) => (
                  <Form.Item key={chaveOpcao(o)} label={<small>{rotuloOpcao(o)}</small>} name={['matriz', String(sv.id), chaveOpcao(o)]} style={{ marginBottom: 4 }}>
                    <InputNumber<number> aria-label={`${nomeServico(sv)} — ${rotuloOpcao(o)}`} min={0} precision={2} decimalSeparator="," placeholder={String(Number(o.preco ?? 0))} style={{ width: 140, maxWidth: '100%' }} />
                  </Form.Item>
                ))}
              </Flex>
            </div>
          ))}
        </SeccaoFormulario>
      </Form>
    </ModalFormulario>
  );
}

/** Separador «Serviços» (renderServicos, lavandaria.js:1375-1396): tabela de serviços com modelo e importação. */
export function TabelaServicos() {
  const { pode } = useSessao();
  const servicos = useServicosLav(true);
  const [editar, setEditar] = useState<ServicoLav | 'novo' | null>(null);
  const gerir = pode('lav_tabelas');
  // nomes das contas de proveitos (a mesma consulta do SeletorConta; sem permissão de contabilidade mostra só o código)
  const contas = useQuery({
    queryKey: ['plano', 'movimento', '62'],
    queryFn: () => obter<{ codigo: string; descricao: string }[]>('/contabilidade/plano-contas', { prefixo: '62', tipo: 'M' }),
    staleTime: 600_000,
    retry: false,
  });
  const textoConta = (s: ServicoLav) => {
    if (!String(s.codigo_conta ?? '').startsWith('62')) return 'por definir';
    const nome = contas.data?.find((c) => c.codigo === s.codigo_conta)?.descricao;
    return nome ? `${s.codigo_conta} - ${nome}` : String(s.codigo_conta);
  };
  const colunasServicos: ColunaApi<ServicoLav>[] = [
    { title: 'Código', dataIndex: 'codigo', render: (v) => <b>{v}</b> },
    { title: 'Serviço', dataIndex: 'nome', render: (v) => <b>{v}</b> },
    { title: 'Grupo', dataIndex: 'lavandaria_grupo', render: (v: string) => GRUPOS_LAV[v] ?? v, valorFiltro: (s) => GRUPOS_LAV[s.lavandaria_grupo] ?? s.lavandaria_grupo },
    { title: 'IVA', dataIndex: 'taxa_imposto', align: 'right', render: (v) => `${Number(v)}%` },
    {
      title: 'Conta de proveito',
      dataIndex: 'codigo_conta',
      render: (_, s) => <span style={{ color: textoConta(s) === 'por definir' ? '#b91c1c' : undefined }}>{textoConta(s)}</span>,
      valorFiltro: (s) => textoConta(s),
      valorImpressao: (s) => textoConta(s),
    },
    { title: 'Prazo', dataIndex: 'lavandaria_dias_entrega', align: 'right', render: (v) => `${Number(v ?? 0)} dia(s)` },
    { title: 'Orçamento', dataIndex: 'lavandaria_requer_orcamento', render: (v) => (v ? 'Sim' : 'Não'), valorFiltro: (s) => (s.lavandaria_requer_orcamento ? 'Sim' : 'Não') },
    { title: 'Estado', dataIndex: 'lavandaria_ativa', render: (v) => (v === false ? 'Inactivo' : 'Activo'), valorFiltro: (s) => (s.lavandaria_ativa === false ? 'Inactivo' : 'Activo') },
  ];
  return (
    <Seccao
      titulo="Tabela de serviços"
      descricao="Cada serviço tem conta de proveitos da classe 62, IVA, prazo e indicação de orçamento. O preço é definido na tabela de peças. O código é automático."
      extra={
        gerir && (
          <>
            <BotaoModeloLav tipo="servicos" />
            <ImportarTabela tipo="servicos" />
            <Button type="primary" icon={<IconeLegado icone="plus" />} onClick={() => setEditar('novo')}>
              Novo serviço
            </Button>
          </>
        )
      }
    >
      <TabelaComModos<ServicoLav>
        idVista="servicos"
        modos={false}
        rowKey="id"
        size="small"
        scroll={scrollTabela()}
        loading={servicos.isFetching}
        dataSource={servicos.data}
        columns={colunasServicos}
        onRow={(s) => ({ style: s.lavandaria_ativa === false ? { opacity: 0.55 } : undefined })}
        impressao={{ titulo: 'Tabela de serviços da lavandaria' }}
        accoes={(s) => [{ icone: 'edit', titulo: 'Editar', oculto: !gerir, onClick: () => setEditar(s) }]}
        vazio={{ icone: 'list', titulo: 'Sem serviços.' }}
      />
      <ModalServico alvo={editar} aoFechar={() => setEditar(null)} />
    </Seccao>
  );
}

function ModalServico({ alvo, aoFechar }: { alvo: ServicoLav | 'novo' | null; aoFechar: () => void }) {
  const [form] = Form.useForm();
  const s = alvo && alvo !== 'novo' ? alvo : null;
  const gravar = useAccao({ invalidar: [['pos', 'lavandaria'], ['logistica', 'catalogo']], aoSucesso: aoFechar, tituloErro: 'Não foi possível gravar o serviço' });
  useEffect(() => {
    if (!alvo) return;
    form.resetFields();
    form.setFieldsValue(
      s
        ? { nome: s.nome, grupo: s.lavandaria_grupo, codigo_conta: s.codigo_conta, taxa_imposto: Number(s.taxa_imposto), dias_entrega: s.lavandaria_dias_entrega, requer_orcamento: !!s.lavandaria_requer_orcamento, ativa: s.lavandaria_ativa !== false }
        : { grupo: 'LAVANDARIA', taxa_imposto: 14, dias_entrega: 2, requer_orcamento: false, ativa: true },
    );
  }, [alvo, s, form]);
  return (
    <ModalFormulario
      chave="pos.lavandaria.servicos"
      titulo={s ? `Serviço ${s.nome}` : 'Novo serviço'}
      aberto={!!alvo}
      aoFechar={aoFechar}
      criacao={!s}
      form={form}
      campos={CAMPOS_SERVICO}
      nomeRegistos="serviços"
      invalidar={[['pos', 'lavandaria'], ['logistica', 'catalogo']]}
      gravarLinha={(r) => enviar('post', '/pos/lavandaria/servicos', dadosServico(r))}
      width={larguraModal(620)}
      okText="Gravar"
      cancelText="Cancelar"
      confirmLoading={gravar.isPending}
      onOk={() => form.submit()}
    >
      <Form form={form} layout="vertical" onFinish={(v) => gravar.mutate(s ? { metodo: 'put', url: `/pos/lavandaria/servicos/${s.id}`, dados: dadosServico(v) } : { url: '/pos/lavandaria/servicos', dados: dadosServico(v) })}>
        <Row gutter={[12, 0]}>
          <Col xs={24} sm={14}>
            <Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }]}>
              <Input maxLength={255} />
            </Form.Item>
          </Col>
          <Col xs={24} sm={10}>
            <Form.Item name="grupo" label="Grupo">
              <Select options={Object.entries(GRUPOS_LAV).map(([value, label]) => ({ value, label }))} />
            </Form.Item>
          </Col>
          <Col xs={24} sm={8}>
            <Form.Item name="taxa_imposto" label="IVA (%)">
              <InputNumber<number> min={0} precision={2} style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          <Col xs={24} sm={8}>
            <Form.Item name="dias_entrega" label="Prazo (dias)">
              <InputNumber<number> min={0} precision={0} style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          <Col xs={12} sm={4}>
            <Form.Item name="requer_orcamento" label="Requer orçamento" valuePropName="checked">
              <Switch />
            </Form.Item>
          </Col>
          <Col xs={12} sm={4}>
            <Form.Item name="ativa" label="Activo" valuePropName="checked">
              <Switch />
            </Form.Item>
          </Col>
        </Row>
        {/* contas e isenção: secção recolhível (a conta 62 é obrigatória; a de IVA e a isenção são as menos usadas) */}
        <SeccaoFormulario titulo="Contabilidade e IVA">
          <Row gutter={[12, 0]}>
            <Col xs={24} sm={12}>
              <Form.Item name="codigo_conta" label="Conta de proveitos (62)" rules={[{ required: true, message: 'Indique a conta.' }]}>
                <SeletorConta prefixo="62" />
              </Form.Item>
            </Col>
            <Col xs={24} sm={12}>
              <Form.Item name="conta_iva_liquidado" label="Conta de IVA liquidado">
                <SeletorConta prefixo="34" />
              </Form.Item>
            </Col>
            <Col xs={24} sm={8}>
              <Form.Item name="codigo_isencao_fe" label="Código de isenção">
                <Input maxLength={10} />
              </Form.Item>
            </Col>
          </Row>
        </SeccaoFormulario>
      </Form>
    </ModalFormulario>
  );
}

/** Separador «Definições» (renderDefinicoes, lavandaria.js:1442-1467). */
export function Definicoes() {
  const { pode } = useSessao();
  const definicoes = useDefinicoesLav();
  const { token } = theme.useToken();
  const [form] = Form.useForm();
  const gravar = useAccao<DefinicoesLav>({ invalidar: [['pos', 'lavandaria', 'definicoes']], tituloErro: 'Não foi possível gravar as definições' });
  useEffect(() => {
    if (definicoes.data)
      form.setFieldsValue({
        ...definicoes.data,
        percentagem_armazenagem_dia: Number(definicoes.data.percentagem_armazenagem_dia),
        percentagem_adiantamento: Number(definicoes.data.percentagem_adiantamento),
        percentagem_urgencia: Number(definicoes.data.percentagem_urgencia),
        valor_taxa_recolha: Number(definicoes.data.valor_taxa_recolha),
        valor_taxa_entrega: Number(definicoes.data.valor_taxa_entrega),
        taxa_extras: Number(definicoes.data.taxa_extras),
      });
  }, [definicoes.data, form]);
  if (!definicoes.data) return <Skeleton active />;
  const n = (nome: string, rotulo: string, extra: Record<string, unknown> = {}) => (
    <Form.Item name={nome} label={rotulo}>
      <InputNumber<number> min={0} decimalSeparator="," style={{ width: '100%' }} {...extra} />
    </Form.Item>
  );
  // cartão, rótulos e caixas de verificação do antigo (renderDefinicoes, lavandaria.js:1447-1466)
  return (
    <div style={{ background: token.colorBgContainer, border: `1px solid ${token.colorBorderSecondary}`, borderRadius: 4, padding: '1rem', maxWidth: 780 }}>
      <h3 style={{ margin: '0 0 0.7rem', fontSize: '1.05rem' }}>Definições da lavandaria e alfaiataria</h3>
      <Form form={form} layout="vertical" className="lav-definicoes-form" disabled={!pode('lav_tabelas')} onFinish={(v) => gravar.mutate({ metodo: 'put', url: '/pos/lavandaria/definicoes', dados: { ...v, estados_entrada: undefined } })}>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: '0.7rem' }}>
          <Form.Item label="Taxa de armazenagem">
            <Form.Item name="taxa_armazenagem_ativa" valuePropName="checked" noStyle>
              <Checkbox style={{ height: 32, display: 'flex', alignItems: 'center' }}>Cobrar peças não levantadas</Checkbox>
            </Form.Item>
          </Form.Item>
          {n('dias_armazenagem_gratis', 'Prazo de levantamento (dias)', { precision: 0 })}
          {n('percentagem_armazenagem_dia', 'Taxa de armazenagem (% do serviço por dia)', { precision: 2 })}
          {n('percentagem_adiantamento', 'Adiantamento mínimo do Consumidor Final (%)', { precision: 2, max: 100 })}
          {n('percentagem_urgencia', 'Taxa de urgência (%)', { precision: 2 })}
          {n('fator_prazo_urgencia', 'Prazo do urgente (fracção do prazo normal)', { precision: 2, min: 0.1, max: 1, step: 0.05 })}
          {n('valor_taxa_recolha', 'Taxa de recolha ao domicílio', { precision: 2 })}
          {n('valor_taxa_entrega', 'Taxa de entrega ao domicílio', { precision: 2 })}
          {n('dias_reclamacao', 'Prazo de reclamação (dias após a entrega)', { precision: 0 })}
          <Form.Item name="conta_extras" label="Conta de proveitos das taxas (classe 62)">
            <SeletorConta prefixo="62" placeholder="— seleccionar —" />
          </Form.Item>
          {n('taxa_extras', 'IVA das taxas (%)', { precision: 2 })}
          <Form.Item name="conta_compensacao" label="Conta das indemnizações por danos (custo)">
            <SeletorConta prefixo="7" placeholder="— seleccionar —" />
          </Form.Item>
        </div>
        <Form.Item name="faturar_no_adiantamento" valuePropName="checked" style={{ margin: '0.9rem 0 0' }}>
          <Checkbox style={{ fontSize: '0.85rem', alignItems: 'flex-start' }}>
            <strong>Emitir a factura logo no primeiro pagamento (adiantamento)</strong>
            <br />
            <small style={{ color: token.colorTextSecondary }} data-testid="ajuda-faturar-adiantamento">
              <strong>Ligada:</strong> quando o cliente paga uma ordem «Facturar na entrega» que ainda não tem factura, o sistema emite
              logo a factura dos serviços da ordem. Se pagar o valor total, sai uma Factura-Recibo; se pagar só uma parte, sai a
              Factura do total e um Recibo do valor pago.
              <br />
              <strong>Desligada:</strong> o pagamento fica só como Recibo de adiantamento e a factura é emitida na entrega das peças.
              <br />
              <em>Exemplo:</em> ordem de 10 000 Kz com adiantamento de 4 000 Kz — ligada: Factura de 10 000 Kz + Recibo de 4 000 Kz;
              desligada: Recibo de 4 000 Kz agora e Factura na entrega. Recomenda-se ligada, porque o IVA é devido quando se recebe
              um adiantamento. As ordens com «Facturar já» são sempre facturadas na recepção.
            </small>
          </Checkbox>
        </Form.Item>
        {pode('lav_tabelas') && (
          <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: '1rem' }}>
            <Button type="primary" htmlType="submit" icon={<SaveOutlined />} loading={gravar.isPending}>
              Gravar
            </Button>
          </div>
        )}
      </Form>
    </div>
  );
}
