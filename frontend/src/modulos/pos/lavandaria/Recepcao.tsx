import { Alert, AutoComplete, Button, Card, Checkbox, DatePicker, Input, InputNumber, Modal, Result, Select, Space, Typography, message } from 'antd';
import { CheckOutlined, ClearOutlined, DeleteOutlined, PlusOutlined, PrinterOutlined, SaveOutlined, TagsOutlined, UserAddOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useMemo, useState, type CSSProperties, type ReactNode } from 'react';
import { obter, obterPagina } from '@/api/cliente';
import { notificarErro } from '@/utilitarios/erros';
import { formatarKz } from '@/utilitarios/formatacao';
import { useSessao } from '@/sessao/SessaoContexto';
import { useAccao } from '@/componentes/Accoes';
import type { FichaTerceiro } from '@/modulos/compras/comum/referencias';
import { NovoClienteModal } from '../frente/NovoClienteModal';
import { deCentimos, pagamentosParaApi, resumirPagamentos, type Pagamento } from '../comum/calculos';
import { meiosActivos, novaChave, PainelPagamentos } from '../comum/PainelPagamentos';
import type { Terminal } from '../comum/tipos';
import { imprimirHtml, lerPreferencias, reimprimir, useCabecalhoTalao } from '../comum/impressao';
import { htmlEtiquetasOS, htmlTalaoOS } from './impressoes';
import { GRUPOS_LAV, useDefinicoesLav, usePecas, useServicosLav } from './dados';
import { precoPecaServico, type DefinicoesLav, type DetalheOrdem, type PedidoLav } from './tipos';

/** Unidade de venda da peça, como no antigo (UNIDADES, lavandaria.js:47). */
const UNIDADE: Record<string, string> = { PECA: 'peça', KG: 'kg' };
const BOM_ESTADO = 'Bom estado';
/** Sugestões de cor e tecido do antigo (datalists, lavandaria.js:49-50). */
const TECIDOS = ['Algodão', 'Linho', 'Seda', 'Lã', 'Poliéster', 'Viscose', 'Ganga', 'Couro', 'Camurça', 'Sintético', 'Misto'];
const CORES = ['Branco', 'Preto', 'Azul', 'Azul-marinho', 'Cinzento', 'Bege', 'Castanho', 'Vermelho', 'Verde', 'Amarelo', 'Rosa', 'Estampado', 'Várias'];

/** Linha da ordem em preparação (um cartão em «Peças da ordem»). Valores em Kz (IVA incluído). */
export interface LinhaNova {
  chave: string;
  peca_id: number;
  nome_peca: string;
  produto_id: number;
  nome_servico: string;
  grupo: string;
  unidade: 'PECA' | 'KG';
  quantidade: number;
  numero_pecas: number;
  preco: number;
  preco_tabela: number;
  dias_prazo: number;
  requer_orcamento: boolean;
  cor: string;
  tecido: string;
  estado_entrada: string;
  notas_entrada: string;
  valor_declarado: number | null;
}

interface Domicilio {
  ativa: boolean;
  morada: string;
  data: Dayjs | null;
  taxa: number | null;
}

const DOMICILIO_VAZIO: Domicilio = { ativa: false, morada: '', data: null, taxa: null };
const r2 = (v: number) => Math.round(v * 100) / 100;
const kz = (v: number) => `${formatarKz(v)} Kz`;

/** Consumidor Final como no antigo (ehConsumidorFinal, lavandaria.js:65): sem cliente, NIF só com 8–9 noves ou o nome. */
export function eConsumidorFinal(c: Pick<FichaTerceiro, 'nome' | 'nif'> | null | undefined): boolean {
  return !c || /^9{8,9}$/.test(String(c.nif ?? '').replace(/\D/g, '')) || /consumidor\s*final/i.test(c.nome ?? '');
}

/** Valor da linha: quantidade × preço (calcularNova/valorItem, lavandaria.js:551). */
const valorLinha = (l: Pick<LinhaNova, 'quantidade' | 'preco'>) => r2(l.quantidade * l.preco);

/** Totais, prazo prometido e etiquetas da ordem em preparação (calcularNova, lavandaria.js:551-563). */
export function calcularNova(linhas: LinhaNova[], urgente: boolean, recolha: Domicilio, entrega: Domicilio, def: DefinicoesLav | undefined, hoje: Dayjs = dayjs()) {
  const servicos = r2(linhas.reduce((t, l) => t + valorLinha(l), 0));
  const urgencia = urgente ? r2((servicos * Number(def?.percentagem_urgencia ?? 0)) / 100) : 0;
  const taxaRecolha = recolha.ativa ? r2(recolha.taxa ?? Number(def?.valor_taxa_recolha ?? 0)) : 0;
  const taxaEntrega = entrega.ativa ? r2(entrega.taxa ?? Number(def?.valor_taxa_entrega ?? 0)) : 0;
  const prazo = Math.max(0, ...linhas.map((l) => l.dias_prazo));
  const dias = urgente ? Math.max(1, Math.ceil(prazo * Number(def?.fator_prazo_urgencia ?? 1))) : Math.max(1, prazo);
  const etiquetas = linhas.reduce((t, l) => t + Math.max(1, Math.round(l.unidade === 'KG' ? l.numero_pecas : l.quantidade)), 0);
  return { servicos, urgencia, recolha: taxaRecolha, entrega: taxaEntrega, total: r2(servicos + urgencia + taxaRecolha + taxaEntrega), prometida: hoje.add(dias, 'day'), etiquetas };
}

/** Primeira falha que impede registar a ordem, pela ordem do antigo (lavConfirmarOrdem, lavandaria.js:597-606); null se pode. */
export function validarNova(linhas: LinhaNova[], cliente: number | undefined, recolha: Domicilio, entrega: Domicilio): string | null {
  if (!linhas.length) return 'Acrescente pelo menos uma peça à ordem.';
  if (!cliente) return 'Seleccione o cliente.';
  const semEstado = linhas.findIndex((l) => !l.estado_entrada);
  if (semEstado >= 0) return `Indique o estado à entrada da peça "${linhas[semEstado].nome_peca}" (linha ${semEstado + 1}).`;
  const semDescricao = linhas.findIndex((l) => l.estado_entrada !== BOM_ESTADO && !l.notas_entrada.trim());
  if (semDescricao >= 0) return `Descreva o dano registado na peça "${linhas[semDescricao].nome_peca}" (linha ${semDescricao + 1}).`;
  if (linhas.some((l) => l.quantidade <= 0)) return 'Indique a quantidade (peças ou kg) de todas as linhas.';
  if (linhas.some((l) => !l.requer_orcamento && l.preco <= 0)) return 'Indique o preço de todas as linhas (excepto orçamentos).';
  if (recolha.ativa && !recolha.morada.trim()) return 'Indique a morada da recolha ao domicílio.';
  if (entrega.ativa && !entrega.morada.trim()) return 'Indique a morada da entrega ao domicílio.';
  return null;
}

const CIANO = '#0891b2';

/** Secção numerada do antigo (seccao, lavandaria.js:455-459). */
function Seccao({ n, titulo, children }: { n: number; titulo: ReactNode; children: ReactNode }) {
  return (
    <Card size="small" style={{ marginBottom: 12 }} styles={{ body: { padding: '0.9rem 1rem' } }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12 }}>
        <span style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', width: 22, height: 22, borderRadius: 4, background: CIANO, color: '#fff', fontSize: 12, fontWeight: 800 }}>{n}</span>
        <Typography.Text strong style={{ fontSize: 15 }}>
          {titulo}
        </Typography.Text>
      </div>
      {children}
    </Card>
  );
}

function Campo({ rotulo, children, style }: { rotulo: ReactNode; children: ReactNode; style?: CSSProperties }) {
  return (
    <label style={{ display: 'flex', flexDirection: 'column', gap: 4, minWidth: 0, ...style }}>
      <Typography.Text strong style={{ fontSize: 12 }}>
        {rotulo}
      </Typography.Text>
      {children}
    </label>
  );
}

const pilha: CSSProperties = { display: 'flex', flexDirection: 'column', gap: 10 };
const duas: CSSProperties = { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 10 };

/** Cliente: pesquisa por nome/NIF; abre no Consumidor Final (como o antigo) e mantém o escolhido nas opções. */
function useClientes(pesquisa: string) {
  return useQuery({
    queryKey: ['terceiros', 'pesquisa', 'CLIENTE', pesquisa],
    queryFn: () => obterPagina<FichaTerceiro>('/terceiros', { papel: 'CLIENTE', pesquisa, por_pagina: 30, estrito: 1 }),
    retry: false,
  });
}

async function procurarConsumidorFinal(): Promise<FichaTerceiro | undefined> {
  for (const pesquisa of ['consumidor final', '999999999', '99999999']) {
    const r = await obterPagina<FichaTerceiro>('/terceiros', { papel: 'CLIENTE', pesquisa, por_pagina: 10, estrito: 1 });
    const cf = r.itens.find((t) => eConsumidorFinal(t));
    if (cf) return cf;
  }
  return undefined;
}

/**
 * Nova ordem de serviço da lavandaria, com a estrutura do protótipo (renderNovaOrdem, lavandaria.js:440-549): 1. Cliente
 * e condições, 2. Adicionar peça, 3. Peças da ordem (um cartão por peça), 4. Recolha e entrega ao domicílio,
 * 5. Observações; resumo fixo à direita e confirmação com o valor a receber agora (lavConfirmarOrdem).
 */
export function Recepcao({ terminal, aoRegistar }: { terminal: Terminal | undefined; aoRegistar?: (pedido: PedidoLav) => void }) {
  const { pode, empresa } = useSessao();
  const cabecalho = useCabecalhoTalao();
  const pecas = usePecas();
  // só os serviços configurados neste terminal (POS › Terminais › «Configurar serviços»; vazio = todos)
  const servicos = useServicosLav(false, terminal?.id);
  const definicoes = useDefinicoesLav();
  const def = definicoes.data;
  const estados = def?.estados_entrada?.length ? def.estados_entrada : [BOM_ESTADO];

  const [cliente, setCliente] = useState<FichaTerceiro>();
  const [pesquisa, setPesquisa] = useState('');
  const clientes = useClientes(pesquisa);
  const [novoCliente, setNovoCliente] = useState(false);
  const [modo, setModo] = useState<'ENTREGA' | 'RECEPCAO'>('ENTREGA');
  const [urgente, setUrgente] = useState(false);
  const [pecaId, setPecaId] = useState<number>();
  const [servicoId, setServicoId] = useState<number>();
  const [linhas, setLinhas] = useState<LinhaNova[]>([]);
  const [recolha, setRecolha] = useState<Domicilio>(DOMICILIO_VAZIO);
  const [entrega, setEntrega] = useState<Domicilio>(DOMICILIO_VAZIO);
  const [observacoes, setObservacoes] = useState('');
  const [confirmar, setConfirmar] = useState(false);
  const [valorAgora, setValorAgora] = useState(0);
  const [pagamentos, setPagamentos] = useState<Pagamento[]>([]);
  const [registada, setRegistada] = useState<PedidoLav | null>(null);
  const sessaoId = terminal?.sessao_aberta?.id;

  // como o antigo: a ordem nova abre no Consumidor Final
  useEffect(() => {
    if (cliente) return;
    let activo = true;
    procurarConsumidorFinal()
      .then((cf) => activo && cf && setCliente((c) => c ?? cf))
      .catch(() => undefined);
    return () => {
      activo = false;
    };
  }, [cliente]);

  const t = calcularNova(linhas, urgente, recolha, entrega, def);
  const cf = eConsumidorFinal(cliente);
  const pctAdiantamento = Number(def?.percentagem_adiantamento ?? 0);
  const minimo = cf ? r2((t.total * pctAdiantamento) / 100) : 0;
  const semTabelas = !pecas.isLoading && !servicos.isLoading && (!(pecas.data ?? []).length || !(servicos.data ?? []).length);
  const semEstado = linhas.filter((l) => !l.estado_entrada).length;

  const opcoesClientes = useMemo(() => {
    const lista = clientes.data?.itens ?? [];
    const todos = cliente && !lista.some((c) => c.id === cliente.id) ? [cliente, ...lista] : lista;
    return todos.map((c) => ({ value: c.id, label: `${c.nome.trim()}${c.nif ? ` · ${c.nif}` : ''}`, ficha: c }));
  }, [clientes.data, cliente]);

  const opcoesServicos = Object.entries(GRUPOS_LAV)
    .map(([g, rotulo]) => ({
      label: rotulo,
      options: (servicos.data ?? []).filter((s) => s.lavandaria_grupo === g).map((s) => ({ value: s.id, label: `${s.codigo ? `${s.codigo} · ` : ''}${s.nome}${s.lavandaria_requer_orcamento ? ' · orçamento' : ''}` })),
    }))
    .filter((g) => g.options.length);

  const adicionar = () => {
    const p = (pecas.data ?? []).find((x) => x.id === pecaId);
    const s = (servicos.data ?? []).find((x) => x.id === servicoId);
    if (!p || !s) {
      void message.warning('Seleccione a peça e o serviço.');
      return;
    }
    const preco = precoPecaServico(p, s.id);
    const orcamento = !!s.lavandaria_requer_orcamento;
    if (!orcamento && preco <= 0) {
      void message.warning(`A peça "${p.nome}" não tem preço por ${UNIDADE[p.unidade] ?? 'peça'} para o serviço "${s.nome}" (nem preço base). Complete-a em POS › Lavandaria › Peças.`);
      return;
    }
    setLinhas((ls) => [
      ...ls,
      {
        chave: novaChave(), peca_id: p.id, nome_peca: p.nome, produto_id: s.id, nome_servico: s.nome, grupo: s.lavandaria_grupo, unidade: p.unidade ?? 'PECA',
        quantidade: 1, numero_pecas: 1, preco, preco_tabela: preco,
        dias_prazo: Number(s.lavandaria_dias_entrega) || (s.lavandaria_grupo === 'ALFAIATARIA' ? 5 : 2),
        requer_orcamento: orcamento, cor: p.cor ?? '', tecido: p.tecido ?? '', estado_entrada: '', notas_entrada: '', valor_declarado: null,
      },
    ]);
  };

  const alterar = (chave: string, a: Partial<LinhaNova>) => setLinhas((ls) => ls.map((l) => (l.chave === chave ? { ...l, ...a } : l)));

  const limpar = () => {
    setRegistada(null);
    setCliente(undefined);
    setModo('ENTREGA');
    setUrgente(false);
    setPecaId(undefined);
    setServicoId(undefined);
    setLinhas([]);
    setRecolha(DOMICILIO_VAZIO);
    setEntrega(DOMICILIO_VAZIO);
    setObservacoes('');
    setConfirmar(false);
    setValorAgora(0);
    setPagamentos([]);
  };

  /** Talão da OS e/ou etiquetas da ordem acabada de registar. */
  const imprimirRegistada = async (id: number, o: 'talao' | 'etiquetas' | 'ambos') => {
    try {
      const d = await obter<DetalheOrdem>(`/pos/lavandaria/ordens/${id}`);
      const p = lerPreferencias(empresa?.id);
      const c = cabecalho({ terminal: terminal ? `${terminal.codigo} — ${terminal.nome}` : d.pedido.codigo_terminal });
      const enviarParaImpressora = (html: string) => (o === 'ambos' ? imprimirHtml(html) : reimprimir(html, p));
      if (o !== 'etiquetas') enviarParaImpressora(htmlTalaoOS(d, def, c, p));
      if (o !== 'talao') window.setTimeout(() => enviarParaImpressora(htmlEtiquetasOS(d, c, p)), o === 'ambos' ? 900 : 0);
    } catch (e) {
      notificarErro(e, 'Não foi possível imprimir a ordem de serviço');
    }
  };

  const registar = useAccao<{ pedido: PedidoLav }>({
    invalidar: [['pos']],
    tituloErro: 'Não foi possível registar a ordem',
    aoSucesso: (r) => {
      setConfirmar(false);
      setRegistada(r.pedido);
      aoRegistar?.(r.pedido);
      // «Registar e imprimir» do antigo (lavGravarOrdem): talão da OS e etiquetas logo a seguir
      void imprimirRegistada(r.pedido.id, 'ambos');
    },
  });

  const abrirConfirmacao = () => {
    const erro = validarNova(linhas, cliente?.id, recolha, entrega);
    if (erro) {
      void message.warning(erro);
      return;
    }
    if (!sessaoId) {
      void message.warning('O terminal não tem sessão aberta.');
      return;
    }
    const sugerido = modo === 'RECEPCAO' && cf ? t.total : minimo;
    setValorAgora(Math.round(sugerido * 100));
    setPagamentos([]);
    setConfirmar(true);
  };

  const resumoPag = resumirPagamentos(pagamentos, valorAgora);
  const gravar = () => {
    const valor = valorAgora / 100;
    if (valor + 0.005 < minimo) return void message.warning(`O Consumidor Final tem de pagar um adiantamento mínimo de ${kz(minimo)}.`);
    if (valor > t.total + 0.005) return void message.warning('O valor a receber não pode exceder o total da ordem.');
    if (valorAgora > 0 && !resumoPag.valido) return void message.warning(resumoPag.erros[0] ?? 'Indique como é pago o valor a receber.');
    const domicilio = (d: Domicilio) => (d.ativa ? { ativa: true, morada: d.morada.trim(), data: d.data ? d.data.format('YYYY-MM-DD') : undefined, taxa: d.taxa ?? undefined } : undefined);
    registar.mutate({
      url: `/pos/lavandaria/sessoes/${sessaoId}/ordens`,
      dados: {
        cliente_id: cliente?.id,
        modo_faturacao: modo,
        urgente,
        observacoes: observacoes.trim() || undefined,
        itens: linhas.map((l) => ({
          peca_id: l.peca_id,
          produto_id: l.produto_id,
          quantidade: l.quantidade,
          numero_pecas: l.unidade === 'KG' ? l.numero_pecas : undefined,
          ...(l.preco !== l.preco_tabela ? { preco: l.preco } : {}),
          cor: l.cor.trim() || undefined,
          tecido: l.tecido.trim() || undefined,
          estado_entrada: l.estado_entrada,
          notas_entrada: l.notas_entrada.trim() || undefined,
          valor_declarado: l.valor_declarado ?? undefined,
        })),
        recolha: domicilio(recolha),
        entrega: domicilio(entrega),
        ...(valorAgora > 0 ? { valor: deCentimos(valorAgora), pagamentos: pagamentosParaApi(pagamentos) } : {}),
      },
    });
  };

  if (registada)
    return (
      <Result
        status="success"
        title={`Ordem ${registada.numero_encomenda} registada`}
        subTitle={`Etiquetas: ${registada.itens.flatMap((i) => i.etiquetas ?? []).join(', ') || '—'}`}
        extra={
          <Space wrap style={{ justifyContent: 'center' }}>
            <Button icon={<PrinterOutlined />} onClick={() => void imprimirRegistada(registada.id, 'talao')}>
              Imprimir talão
            </Button>
            <Button icon={<TagsOutlined />} onClick={() => void imprimirRegistada(registada.id, 'etiquetas')}>
              Imprimir etiquetas
            </Button>
            <Button type="primary" onClick={limpar}>
              Nova ordem de serviço
            </Button>
          </Space>
        }
      />
    );

  if (!pode('lav_ordens')) return <Alert type="info" showIcon message="Não tem permissão para registar ordens de serviço." />;

  const cartao = (l: LinhaNova, k: number) => {
    const comDano = !!l.estado_entrada && l.estado_entrada !== BOM_ESTADO;
    const faltaDescricao = comDano && !l.notas_entrada.trim();
    const un = UNIDADE[l.unidade] ?? 'peça';
    return (
      <div
        key={l.chave}
        data-testid="cartao-peca"
        style={{ border: `1px solid ${l.estado_entrada && !faltaDescricao ? '#e2e8f0' : '#fca5a5'}`, borderLeft: `4px solid ${l.requer_orcamento ? '#b45309' : CIANO}`, borderRadius: 4, padding: '0.7rem 0.8rem' }}
      >
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 8, marginBottom: 10 }}>
          <div style={{ minWidth: 0 }}>
            <div style={{ fontWeight: 800, fontSize: 14 }}>
              {k + 1}. {l.nome_peca} <span style={{ fontWeight: 400, color: '#475569' }}>· {l.nome_servico}</span>
            </div>
            <div style={{ fontSize: 12, color: '#64748b', marginTop: 2 }}>
              {GRUPOS_LAV[l.grupo] ?? l.grupo} · venda por {un}
              {l.requer_orcamento && <b style={{ color: '#b45309' }}> · sujeito a orçamento</b>}
            </div>
          </div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexShrink: 0 }}>
            <span style={{ fontWeight: 800 }}>{kz(valorLinha(l))}</span>
            <Button size="small" danger icon={<DeleteOutlined />} aria-label={`Remover a peça ${k + 1}`} onClick={() => setLinhas((ls) => ls.filter((x) => x.chave !== l.chave))} />
          </div>
        </div>
        <div style={duas}>
          <Campo rotulo="Cor">
            <AutoComplete value={l.cor} options={CORES.map((v) => ({ value: v }))} filterOption onChange={(v) => alterar(l.chave, { cor: v })} />
          </Campo>
          <Campo rotulo="Tipo de tecido">
            <AutoComplete value={l.tecido} options={TECIDOS.map((v) => ({ value: v }))} filterOption onChange={(v) => alterar(l.chave, { tecido: v })} />
          </Campo>
        </div>
        <div style={{ ...duas, marginTop: 10 }}>
          <Campo rotulo={`Quantidade (${un})`}>
            <InputNumber<number> min={0} step={l.unidade === 'KG' ? 0.1 : 1} precision={l.unidade === 'KG' ? 3 : 0} decimalSeparator="," style={{ width: '100%' }} value={l.quantidade} onChange={(v) => alterar(l.chave, { quantidade: Math.max(0, v ?? 0) })} />
          </Campo>
          {l.unidade === 'KG' && (
            <Campo rotulo="N.º de peças (etiquetas)">
              <InputNumber<number> min={1} precision={0} style={{ width: '100%' }} value={l.numero_pecas} onChange={(v) => alterar(l.chave, { numero_pecas: Math.max(1, v ?? 1) })} />
            </Campo>
          )}
          <Campo rotulo={l.requer_orcamento ? 'Estimativa (IVA incl.)' : `Preço por ${un} (IVA incl.)`}>
            <InputNumber<number>
              min={0}
              precision={2}
              decimalSeparator=","
              style={{ width: '100%', ...(l.requer_orcamento ? {} : { background: '#f1f5f9' }) }}
              readOnly={!l.requer_orcamento}
              title={l.requer_orcamento ? 'Estimativa até à aprovação do orçamento' : 'Preço da tabela de peças'}
              value={l.preco}
              onChange={(v) => alterar(l.chave, { preco: Math.max(0, v ?? 0) })}
            />
          </Campo>
          <Campo rotulo="Valor da peça (opcional)">
            <InputNumber<number> min={0} precision={2} decimalSeparator="," style={{ width: '100%' }} placeholder="para indemnização" value={l.valor_declarado} onChange={(v) => alterar(l.chave, { valor_declarado: v })} />
          </Campo>
        </div>
        <div style={{ marginTop: 10, background: l.estado_entrada ? (comDano ? '#fffbeb' : '#f0fdf4') : '#fef2f2', borderRadius: 4, padding: '0.55rem 0.6rem', ...pilha }}>
          <Campo rotulo="Estado da peça à entrada *">
            <Select
              placeholder="— indicar o estado —"
              status={l.estado_entrada ? undefined : 'error'}
              value={l.estado_entrada || undefined}
              onChange={(v) => alterar(l.chave, { estado_entrada: v })}
              options={estados.map((e) => ({ value: e, label: e }))}
            />
          </Campo>
          <Campo rotulo={comDano ? 'Descrição do dano *' : 'Observações da peça'}>
            <Input.TextArea
              rows={2}
              maxLength={1000}
              status={faltaDescricao ? 'error' : undefined}
              placeholder={comDano ? 'Descreva o dano: local, dimensão, tipo…' : 'Opcional'}
              value={l.notas_entrada}
              onChange={(e) => alterar(l.chave, { notas_entrada: e.target.value })}
            />
          </Campo>
        </div>
      </div>
    );
  };

  const caixaDomicilio = (rotulo: string, d: Domicilio, set: (d: Domicilio) => void, taxaPadrao: string | undefined) => (
    <div style={{ border: '1px solid #e2e8f0', borderRadius: 4, padding: '0.6rem' }}>
      <Checkbox checked={d.ativa} onChange={(e) => set({ ...d, ativa: e.target.checked })}>
        <strong>{rotulo}</strong>
      </Checkbox>
      {d.ativa && (
        <div style={{ ...pilha, marginTop: 8 }}>
          <Campo rotulo="Morada *">
            <Input maxLength={500} placeholder="Rua, n.º, bairro, referência" status={d.morada.trim() ? undefined : 'warning'} value={d.morada} onChange={(e) => set({ ...d, morada: e.target.value })} />
          </Campo>
          <div style={duas}>
            <Campo rotulo="Data">
              <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} value={d.data} onChange={(v) => set({ ...d, data: v })} />
            </Campo>
            <Campo rotulo="Taxa (IVA incl.)">
              <InputNumber<number> min={0} precision={2} decimalSeparator="," style={{ width: '100%' }} value={d.taxa ?? Number(taxaPadrao ?? 0)} onChange={(v) => set({ ...d, taxa: v ?? 0 })} />
            </Campo>
          </div>
        </div>
      )}
    </div>
  );

  return (
    <>
      {!sessaoId && <Alert type="warning" showIcon style={{ marginBottom: 12 }} message="O terminal escolhido não tem sessão aberta: abra a caixa para registar a ordem." />}
      {terminal?.lavandaria_servicos_ids?.length === 0 && (
        <Alert type="error" showIcon style={{ marginBottom: 12 }} message="Este terminal ainda não tem serviços configurados. Configure-os em POS › Terminais antes de receber ordens." />
      )}
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 12, alignItems: 'flex-start' }}>
        <div style={{ flex: '1 1 480px', maxWidth: 780, minWidth: 0 }}>
          <Seccao n={1} titulo="Cliente e condições">
            <div style={pilha}>
              <Campo rotulo="Cliente">
                <div style={{ display: 'flex', gap: 6 }}>
                  <Select<number>
                    showSearch
                    allowClear
                    filterOption={false}
                    onSearch={setPesquisa}
                    loading={clientes.isFetching}
                    style={{ flex: 1, minWidth: 0 }}
                    placeholder="Pesquisar cliente (nome ou NIF)"
                    value={cliente?.id}
                    options={opcoesClientes}
                    onChange={(_, o) => setCliente(Array.isArray(o) ? undefined : (o as { ficha?: FichaTerceiro } | undefined)?.ficha)}
                  />
                  <Button icon={<UserAddOutlined />} title="Novo cliente" aria-label="Novo cliente" onClick={() => setNovoCliente(true)} />
                </div>
              </Campo>
              <div style={duas}>
                <Campo rotulo="Facturação">
                  <Select
                    value={modo}
                    onChange={setModo}
                    options={[
                      { value: 'ENTREGA', label: 'Na entrega' },
                      { value: 'RECEPCAO', label: 'Na recepção' },
                    ]}
                  />
                </Campo>
                <Campo rotulo="Serviço urgente">
                  <Checkbox checked={urgente} onChange={(e) => setUrgente(e.target.checked)} style={{ minHeight: 32, alignItems: 'center' }}>
                    <strong>+{formatarKz(Number(def?.percentagem_urgencia ?? 0))}% e prazo reduzido</strong>
                  </Checkbox>
                </Campo>
              </div>
            </div>
          </Seccao>

          <Seccao n={2} titulo="Adicionar peça">
            <div style={pilha}>
              <Campo rotulo="Peça">
                <Select<number>
                  showSearch
                  optionFilterProp="label"
                  placeholder="— seleccionar a peça —"
                  loading={pecas.isLoading}
                  value={pecaId}
                  onChange={setPecaId}
                  options={(pecas.data ?? []).map((p) => ({
                    value: p.id,
                    label: `${p.codigo ? `${p.codigo} · ` : ''}${p.nome}${p.tecido ? ` (${p.tecido})` : ''} · ${kz(Number(p.preco ?? 0))}/${UNIDADE[p.unidade] ?? 'peça'}`,
                  }))}
                />
              </Campo>
              <Campo rotulo="Serviço">
                <Select<number> showSearch optionFilterProp="label" placeholder="— seleccionar o serviço —" loading={servicos.isLoading} value={servicoId} onChange={setServicoId} options={opcoesServicos} />
              </Campo>
              <Button type="primary" block icon={<PlusOutlined />} disabled={semTabelas} onClick={adicionar}>
                Adicionar à ordem
              </Button>
              {semTabelas && <Typography.Text type="warning">Crie as peças e os serviços em POS › Lavandaria (Peças e Serviços).</Typography.Text>}
            </div>
          </Seccao>

          <Seccao
            n={3}
            titulo={
              <>
                Peças da ordem <span style={{ fontWeight: 400, color: '#64748b', fontSize: 12 }}>({linhas.length})</span>
              </>
            }
          >
            <div style={pilha}>
              {linhas.length ? (
                linhas.map(cartao)
              ) : (
                <div style={{ padding: '1rem', textAlign: 'center', color: '#94a3b8', border: '1px dashed #cbd5e1', borderRadius: 4 }}>
                  Ainda não há peças. Seleccione a peça e o serviço acima e carregue em Adicionar.
                </div>
              )}
            </div>
          </Seccao>

          <Seccao n={4} titulo="Recolha e entrega ao domicílio">
            <div style={pilha}>
              {caixaDomicilio('Recolha ao domicílio', recolha, setRecolha, def?.valor_taxa_recolha)}
              {caixaDomicilio('Entrega ao domicílio', entrega, setEntrega, def?.valor_taxa_entrega)}
            </div>
          </Seccao>

          <Seccao n={5} titulo="Observações">
            <Input.TextArea rows={3} maxLength={2000} placeholder="Instruções gerais da ordem (opcional)" value={observacoes} onChange={(e) => setObservacoes(e.target.value)} />
          </Seccao>
        </div>

        <Card size="small" style={{ flex: '0 1 300px', minWidth: 260, position: 'sticky', top: 8 }} styles={{ body: { padding: '1rem' } }}>
          <Typography.Text strong style={{ fontSize: 15, display: 'block', marginBottom: 10 }}>
            Resumo
          </Typography.Text>
          <table style={{ width: '100%', fontSize: 13, borderCollapse: 'collapse' }}>
            <tbody>
              <tr>
                <td>Serviços ({linhas.length} linha(s))</td>
                <td style={{ textAlign: 'right' }}>{kz(t.servicos)}</td>
              </tr>
              {t.urgencia > 0 && (
                <tr>
                  <td>Urgência ({formatarKz(Number(def?.percentagem_urgencia ?? 0))}%)</td>
                  <td style={{ textAlign: 'right' }}>{kz(t.urgencia)}</td>
                </tr>
              )}
              {t.recolha > 0 && (
                <tr>
                  <td>Recolha</td>
                  <td style={{ textAlign: 'right' }}>{kz(t.recolha)}</td>
                </tr>
              )}
              {t.entrega > 0 && (
                <tr>
                  <td>Entrega</td>
                  <td style={{ textAlign: 'right' }}>{kz(t.entrega)}</td>
                </tr>
              )}
              <tr style={{ fontWeight: 800, borderTop: '2px solid #0f172a' }}>
                <td style={{ paddingTop: 4 }}>Total</td>
                <td style={{ textAlign: 'right', paddingTop: 4 }} data-testid="total-ordem">
                  {kz(t.total)}
                </td>
              </tr>
            </tbody>
          </table>
          <div style={{ fontSize: 12, color: '#475569', marginTop: 10, lineHeight: 1.7 }}>
            <div>
              📅 Pronta a partir de <strong>{t.prometida.format('DD/MM/YYYY')}</strong>
            </div>
            <div>🏷️ {t.etiquetas} etiqueta(s)</div>
            <div>🧾 Facturação {modo === 'RECEPCAO' ? 'agora, na recepção' : 'na entrega'}</div>
            {cf ? (
              <div style={{ color: '#b45309' }}>
                ⚠ Consumidor Final: adiantamento mínimo de {formatarKz(pctAdiantamento)}% ({kz(minimo)})
              </div>
            ) : (
              <div style={{ color: '#059669' }}>✔ Cliente em conta corrente: adiantamento opcional</div>
            )}
            {semEstado > 0 && <div style={{ color: '#b91c1c' }}>⚠ {semEstado} peça(s) sem estado à entrada</div>}
          </div>
          <Button type="primary" block icon={<CheckOutlined />} style={{ marginTop: 12, fontWeight: 800 }} disabled={!linhas.length} onClick={abrirConfirmacao}>
            Registar ordem
          </Button>
          <Button
            block
            icon={<ClearOutlined />}
            style={{ marginTop: 6 }}
            onClick={() => Modal.confirm({ title: 'Limpar a ordem em preparação?', okText: 'Limpar', cancelText: 'Cancelar', onOk: limpar })}
          >
            Limpar
          </Button>
        </Card>
      </div>

      <Modal
        open={confirmar}
        title="Registar ordem de serviço"
        width={600}
        onCancel={() => setConfirmar(false)}
        footer={[
          <Button key="voltar" onClick={() => setConfirmar(false)}>
            Voltar
          </Button>,
          <Button key="gravar" type="primary" icon={<SaveOutlined />} loading={registar.isPending} onClick={gravar} style={{ fontWeight: 800 }}>
            Registar e imprimir
          </Button>,
        ]}
      >
        <div style={{ fontSize: 13, lineHeight: 1.7 }}>
          <div>
            <strong>{cliente?.nome}</strong> · {linhas.length} linha(s) · {t.etiquetas} etiqueta(s)
          </div>
          <div>
            Total: <strong>{kz(t.total)}</strong> · pronta a partir de <strong>{t.prometida.format('DD/MM/YYYY')}</strong> · facturação{' '}
            {modo === 'RECEPCAO' ? <strong>na recepção</strong> : 'na entrega'}
          </div>
          {cf && (
            <div style={{ color: '#b45309' }}>
              Consumidor Final: adiantamento obrigatório de pelo menos <strong>{kz(minimo)}</strong> ({formatarKz(pctAdiantamento)}%).
            </div>
          )}
          <div style={{ color: '#475569', fontSize: 12 }}>
            Pagamento total na recepção com facturação → <strong>Factura-Recibo</strong> (venda directa). Pagamento parcial ou sem pagamento → <strong>Factura</strong> em conta
            corrente do cliente e recibo.
          </div>
        </div>
        <Campo rotulo="Valor a receber agora" style={{ margin: '12px 0' }}>
          <InputNumber<number>
            min={0}
            precision={2}
            decimalSeparator=","
            suffix="Kz"
            style={{ width: '100%', fontWeight: 800, fontSize: 17 }}
            value={valorAgora / 100}
            onChange={(v) => setValorAgora(Math.round((v ?? 0) * 100))}
          />
        </Campo>
        {valorAgora > 0 && terminal && <PainelPagamentos meios={meiosActivos(terminal.meios_pagamento)} total={valorAgora} pagamentos={pagamentos} onChange={setPagamentos} />}
      </Modal>

      <NovoClienteModal
        aberto={novoCliente}
        aoFechar={() => setNovoCliente(false)}
        aoCriar={(id) => {
          setNovoCliente(false);
          void obter<FichaTerceiro>(`/terceiros/${id}`)
            .then((f) => setCliente(f))
            .catch(() => setCliente({ id, nome: `Cliente #${id}`, nif: null } as FichaTerceiro));
        }}
      />
    </>
  );
}
