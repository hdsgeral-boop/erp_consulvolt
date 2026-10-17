import { AutoComplete, Button, Card, Col, Form, Input, Row, Space, Tooltip, theme } from 'antd';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { useAccao } from '@/componentes/Accoes';
import { AlternarFormulario, ModalFormulario, PainelLinhas, SeccaoFormulario, useVistaFormulario } from '@/componentes/formularios';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { larguraModal } from '@/componentes/responsivo';
import { TabelaComModos } from '@/componentes/vistas';
import { useSessao } from '@/sessao/SessaoContexto';
import { ModalImportarProdutos, type EntidadeImportacao } from './ModalImportarProdutos';
import { CAMPOS_CATEGORIA, CONTAS_CATEGORIA, contaNaClasse, corpoCategoria, type Categoria } from './formularioProduto';

/** Categorias da empresa, com as contas e o n.º de produtos (GET /logistica/categorias-produtos). */
export function useCategorias() {
  return useQuery({ queryKey: ['logistica', 'categorias'], queryFn: () => obter<Categoria[]>('/logistica/categorias-produtos'), staleTime: 300_000 });
}

/** Maior prefixo comum (para pedir ao servidor só a classe e filtrar o resto aqui). */
function prefixoComum(prefixos: string[]): string {
  if (!prefixos.length) return '';
  let p = prefixos[0];
  for (const x of prefixos) while (!x.startsWith(p)) p = p.slice(0, -1);
  return p;
}

/**
 * Conta do plano filtrada pelas classes adequadas (ex.: existências 22 a 27). Só as contas de movimento se escolhem; as
 * totalizadoras aparecem bloqueadas. O servidor volta a validar a conta e a classe.
 */
export function SeletorContaClasses({ value, onChange, prefixos, disabled, id }: { value?: string | null; onChange?: (v: string) => void; prefixos: string[]; disabled?: boolean; id?: string }) {
  const prefixo = prefixoComum(prefixos);
  const consulta = useQuery({
    queryKey: ['plano', 'contas', prefixo],
    queryFn: () => obter<{ codigo: string; descricao: string; tipo?: string | null }[]>('/contabilidade/plano-contas', { prefixo: prefixo || undefined }),
    staleTime: 600_000,
    retry: false,
  });
  return (
    <AutoComplete
      id={id}
      value={value ?? undefined}
      onChange={(v: string) => onChange?.(v)}
      disabled={disabled}
      placeholder={`Classe ${prefixos.join(', ')}`}
      allowClear
      options={(consulta.data ?? [])
        .filter((c) => prefixos.some((p) => c.codigo.startsWith(p)))
        .slice(0, 2000)
        .map((c) => {
          const bloqueada = c.tipo != null && c.tipo !== 'M';
          return { value: c.codigo, label: `${c.codigo} - ${c.descricao}${bloqueada ? ' (totalizadora)' : ''}`, disabled: bloqueada };
        })}
      filterOption={(i, o) => String(o?.label ?? '').toLowerCase().includes(i.toLowerCase())}
    />
  );
}

/** Contas da categoria no formulário (grade): uma por natureza, com a classe validada. */
export function CamposContasCategoria({ desactivado }: { desactivado?: boolean }) {
  return (
    <Row gutter={16}>
      {CONTAS_CATEGORIA.map((c) => (
        <Col xs={24} md={12} key={c.campo}>
          <Form.Item
            name={c.campo}
            label={c.rotulo}
            rules={[{ validator: async (_, v) => (contaNaClasse(v, c.prefixos) ? undefined : Promise.reject(new Error(`A conta tem de começar por ${c.prefixos.join(', ')}.`))) }]}
          >
            <SeletorContaClasses prefixos={c.prefixos} disabled={desactivado} />
          </Form.Item>
        </Col>
      ))}
    </Row>
  );
}

/** Conta numa célula: código ou «—». */
const celula = (v: string | null | undefined, cor: string) => (v ? <span style={{ fontFamily: 'monospace' }}>{v}</span> : <span style={{ color: cor }}>—</span>);

/**
 * Vendas › Categorias. Legado (js/ui_sales.js:5406-5429, 5489): «Nova Categoria» (só o nome) e a listagem. Ronda 3: a
 * categoria define as contas contabilísticas dos seus produtos (o produto herda-as) — criação e edição com Grade | Linhas;
 * as contas só se alteram com a permissão «Definir as contas contabilísticas das categorias».
 */
export function Categorias() {
  const { pode } = useSessao();
  const { token } = theme.useToken();
  const podeGerir = pode('vendas_produtos_gerir', 'vendas_categorias_contas');
  const podeContas = pode('vendas_categorias_contas');
  const podeEliminar = pode('vendas_dados_del');
  const consulta = useCategorias();
  const [importar, setImportar] = useState<EntidadeImportacao | null>(null);
  const [edicao, setEdicao] = useState<Categoria | null>(null);
  const [formNova] = Form.useForm<Record<string, string>>();
  const [form] = Form.useForm<Record<string, string>>();
  const [comContas, setComContas] = useState(false);
  const adicionar = useAccao({ invalidar: [['logistica']], aoSucesso: () => { formNova.resetFields(); setComContas(false); }, tituloErro: 'Não foi possível criar a categoria' });
  const gravar = useAccao({ invalidar: [['logistica']], aoSucesso: () => setEdicao(null), tituloErro: 'Não foi possível gravar a categoria' });
  const eliminar = useAccao({ invalidar: [['logistica', 'categorias']] });
  // «Grade | Linhas» no cabeçalho do cartão «Nova Categoria» (legado: formularios_linhas_config.js › categorias)
  const [vista, guardarVista] = useVistaFormulario('vendas.categorias');
  const cliente = useQueryClient();

  useEffect(() => {
    if (edicao) {
      form.resetFields();
      form.setFieldsValue({ nome: edicao.nome, descricao: edicao.descricao ?? '', ...Object.fromEntries(CONTAS_CATEGORIA.map((c) => [c.campo, (edicao as unknown as Record<string, string | null>)[c.campo] ?? ''])) });
    }
  }, [edicao, form]);

  const colunasContas = CONTAS_CATEGORIA.slice(0, 6).map((c) => ({
    title: c.rotulo.replace(/ \(.*\)$/, ''),
    key: c.campo,
    ocultaPorOmissao: c.campo === 'conta_iva_dedutivel',
    render: (_: unknown, r: Categoria) => celula((r as unknown as Record<string, string | null>)[c.campo], token.colorTextQuaternary),
    valorImpressao: (r: Categoria) => (r as unknown as Record<string, string | null>)[c.campo] ?? '',
  }));

  return (
    <>
      <ModalImportarProdutos entidade={importar} aoFechar={() => setImportar(null)} />
      {podeGerir && (
        <Card title="Nova Categoria" style={{ marginBottom: '2rem' }} extra={<AlternarFormulario vista={vista} aoMudar={guardarVista} />}>
          {vista === 'linhas' ? (
            <PainelLinhas
              chave="vendas.categorias"
              campos={podeContas ? CAMPOS_CATEGORIA : CAMPOS_CATEGORIA.filter((c) => !CONTAS_CATEGORIA.some((x) => x.campo === c.nome))}
              nomeRegistos="categorias"
              nota="Uma linha por categoria. As contas (colunas opcionais em «Colunas») são herdadas por todos os produtos da categoria."
              gravar={(r) => enviar('post', '/logistica/categorias-produtos', corpoCategoria(r))}
              aoConcluir={(r) => { if (r.gravadas) void cliente.invalidateQueries({ queryKey: ['logistica', 'categorias'] }); }}
            />
          ) : (
            <Form form={formNova} layout="vertical" onFinish={(v) => adicionar.mutate({ url: '/logistica/categorias-produtos', dados: corpoCategoria(v) })}>
              <div style={{ display: 'flex', gap: '1rem', alignItems: 'flex-end', flexWrap: 'wrap' }}>
                <Form.Item name="nome" label="Nome da Categoria:" rules={[{ required: true, message: 'Indique o nome.' }, { max: 255 }]} style={{ flex: 1, minWidth: 220, marginBottom: 0 }}>
                  <Input placeholder="Ex: Bebidas, Servicos de Consultoria..." />
                </Form.Item>
                {podeContas && <Button onClick={() => setComContas((x) => !x)} icon={<IconeFa nome="calculator" />} aria-expanded={comContas}>Contas</Button>}
                <Button type="primary" htmlType="submit" loading={adicionar.isPending} icon={<IconeFa nome="plus" />}>Adicionar</Button>
              </div>
              {comContas && (
                <div style={{ marginTop: 16 }}>
                  <SeccaoFormulario titulo="Contas contabilísticas (herdadas pelos produtos)">
                    <CamposContasCategoria />
                  </SeccaoFormulario>
                </div>
              )}
            </Form>
          )}
        </Card>
      )}
      <Card title="Listagem de Categorias" extra={podeGerir && <Button icon={<IconeFa nome="file-import" />} onClick={() => setImportar('categorias')}>Importar</Button>}>
        <TabelaComModos<Categoria>
          idVista="categorias"
          rowKey="id"
          loading={consulta.isFetching}
          dataSource={consulta.data ?? []}
          columns={[
            { title: 'Nome', dataIndex: 'nome', total: 'contagem', render: (v: string, r) => (r.descricao ? <Tooltip title={r.descricao}><span>{v}</span></Tooltip> : v) },
            { title: 'Produtos', dataIndex: 'produtos', align: 'right', render: (v?: number) => v ?? 0 },
            ...colunasContas,
          ]}
          impressao={{ titulo: 'Categorias de produtos', orientacao: 'paisagem' }}
          accoes={(c) => [
            podeGerir && { icone: 'fa-edit', titulo: 'Editar', onClick: () => setEdicao(c) },
            podeEliminar && { icone: 'fa-trash', titulo: 'Eliminar', variante: 'perigo', cheio: true, confirmar: { titulo: `Eliminar a categoria ${c.nome}?`, texto: 'É recusado se houver produtos nesta categoria.' }, onClick: () => eliminar.mutateAsync({ metodo: 'delete', url: `/logistica/categorias-produtos/${c.id}` }) },
          ]}
          vazio={{ icone: 'tags', titulo: 'Sem categorias', texto: 'Acrescente a primeira categoria acima.' }}
        />
        {/* edição: só a grade (a criação em linhas é no cartão «Nova Categoria») */}
        <ModalFormulario
          chave="vendas.categorias"
          titulo={`Editar categoria${edicao ? ` ${edicao.nome}` : ''}`}
          aberto={edicao !== null}
          aoFechar={() => setEdicao(null)}
          criacao={false}
          destroyOnHidden={false}
          campos={CAMPOS_CATEGORIA}
          gravarLinha={(r) => enviar('post', '/logistica/categorias-produtos', corpoCategoria(r))}
          okText="Gravar"
          cancelText="Cancelar"
          confirmLoading={gravar.isPending}
          onOk={() => form.submit()}
          width={larguraModal(760)}
        >
          <Form form={form} layout="vertical" onFinish={(v) => edicao && gravar.mutate({ metodo: 'put', url: `/logistica/categorias-produtos/${edicao.id}`, dados: corpoCategoria(v, podeContas) })}>
            <Form.Item name="nome" label="Nome da Categoria" rules={[{ required: true, message: 'Indique o nome.' }, { max: 255 }]}><Input /></Form.Item>
            <Form.Item name="descricao" label="Descrição" rules={[{ max: 2000 }]}><Input.TextArea rows={2} /></Form.Item>
            <SeccaoFormulario titulo="Contas contabilísticas (herdadas pelos produtos da categoria)">
              <Space direction="vertical" style={{ width: '100%' }}>
                {!podeContas && <span style={{ color: token.colorTextSecondary }}>Só de leitura: alterar as contas exige a permissão «Definir as contas contabilísticas das categorias».</span>}
                <CamposContasCategoria desactivado={!podeContas} />
              </Space>
            </SeccaoFormulario>
          </Form>
        </ModalFormulario>
      </Card>
    </>
  );
}
