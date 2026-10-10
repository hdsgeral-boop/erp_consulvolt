import { Button, Card, Checkbox, Col, DatePicker, Flex, Input, Modal, Select, Space, Tag, Tooltip, Typography, message, theme } from 'antd';
import dayjs, { type Dayjs } from 'dayjs';
import { lazy, Suspense, useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { TabelaApi } from '@/componentes/TabelaApi';
import { IconeFa } from '@/componentes/fluxos';
import type { AccaoLinha } from '@/componentes/vistas';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData } from '@/utilitarios/formatacao';
import type { DocumentoLancamento } from '../api';
import { useDiarios, porId, useTabelaAux, useUnidadesNegocio } from '../comum/dados';
import { ModalImportar } from '../comum/ficheiros';
import { CampoFiltro, GrupoFiltros, PainelFiltros } from '../comum/PainelFiltros';
import { SeletorDiario, SeletorTerceiro } from '../comum/Seletores';
import { colunasLancamentos } from './colunas';
import {
  SECCOES,
  alternarSeccoes,
  descarregarTemplate,
  gravarSeccoesOcultas,
  lerSeccoesOcultas,
  regrasAccoes,
  type LinhaLista,
  type Seccao,
} from './ferramentas';
import { ModalSaldosHistoricos } from './ModalSaldosHistoricos';
import { ModalClassificacao, ModalTransferir, pedidoClassificacao, type CamposClassificacao } from './ModaisLancamento';
import { ModalColar, ModalDescontabilizar, ModalEstornar, ModalFluxoLancamento, abrirOrigem, obterOrigem, type AlvoMassa } from './ModaisParidade';
import type { EstadoCopia } from './NovoLancamento';
import { camposMassa, filtrosLancamentos, intervaloPeriodo, REMOVER, SEM_ROTULOS, semCriterios, type FiltrosLista, type SemCampo } from './filtros';

// «Agente IA (Ler Documento)» do legado → Assistente IA (M-03, ecrã do grupo Geral), carregado só quando se abre
const AssistenteIA = lazy(() => import('@/modulos/geral/integracoes/AssistenteIA').then((m) => ({ default: m.AssistenteIA })));

const ANOS = [3, 2, 1, 0].map((d) => dayjs().year() - d);

/** Campo de texto com o botão «Colar múltiplos» (fa-paste) à direita, como o «campo-com-botao» do legado. */
function CampoColar({ id, valor, placeholder, rotuloColar, aoMudar, aoColar, aoConfirmar }: { id: string; valor?: string; placeholder: string; rotuloColar: string; aoMudar: (v: string) => void; aoColar: () => void; aoConfirmar: () => void }) {
  return (
    <Space.Compact style={{ width: '100%' }}>
      <Input id={id} placeholder={placeholder} allowClear value={valor} onChange={(e) => aoMudar(e.target.value)} onPressEnter={aoConfirmar} />
      <Button icon={<IconeFa nome="paste" />} onClick={aoColar} title={rotuloColar} aria-label={rotuloColar} />
    </Space.Compact>
  );
}

/** Data simples (DD/MM/AAAA) ligada a um campo AAAA-MM-DD do rascunho. */
function CampoData({ id, valor, rotulo, aoMudar }: { id: string; valor?: string; rotulo: string; aoMudar: (v?: string) => void }) {
  return <DatePicker id={id} format="DD/MM/YYYY" style={{ width: '100%' }} aria-label={rotulo} value={valor ? dayjs(valor) : null} onChange={(v) => aoMudar(dataApi(v as Dayjs | null))} />;
}

/** Ecrã cheio (Fullscreen API) da área de filtros + lista (legado: toggleFullscreen('lancamentos-view-full')). */
function useEcraCheio() {
  const ref = useRef<HTMLDivElement>(null);
  const [cheio, setCheio] = useState(false);
  useEffect(() => {
    const mudar = () => setCheio(!!document.fullscreenElement);
    document.addEventListener('fullscreenchange', mudar);
    return () => document.removeEventListener('fullscreenchange', mudar);
  }, []);
  const alternar = useCallback(() => {
    if (document.fullscreenElement) void document.exitFullscreen?.().catch(() => undefined);
    else void ref.current?.requestFullscreen?.().catch(() => undefined);
  }, []);
  return { ref, cheio, alternar, suportado: typeof document !== 'undefined' && !!document.fullscreenEnabled };
}

type Colar = { campo: 'contas' | 'numeroLan' | 'numeroDoc' | 'referencia'; titulo: string } | null;

/**
 * Contabilidade › Lançamentos Contabilísticos (legado: renderLancamentos, js/ui_lancamentos.js:66-950; 8.1.1–8.1.3).
 * Barra de botões pela ordem do legado (Ecrã Cheio, Agente IA (Ler Documento), Baixar Template, Importar Lançamentos,
 * Descontabilizar Selecionados, Importar Histórico, Novo Lançamento); painel de filtros por secções que se mostram/ocultam
 * («Secções», preferência do browser), com «Colar múltiplos» nas contas e na identificação, «Mostrar linhas sem» e
 * «Alterar em massa» (selecção ou todas as filtradas, com pré-visualização do n.º de linhas); lista com todas as colunas
 * do legado («Colunas ▾»), totais D/C e as 6 acções por linha. Todas as regras (equilíbrio, exercício, origem do
 * documento, permissões) são verificadas no servidor.
 */
export function ListaLancamentos() {
  const navegar = useNavigate();
  const { token } = theme.useToken();
  const { pode } = useSessao();
  const cliente = useQueryClient();
  const ecra = useEcraCheio();
  const diarios = useDiarios();
  const codigoDiario = porId(diarios.data, (d) => d.codigo);
  const nomeDiario = porId(diarios.data, (d) => d.descricao ?? d.nome ?? '');
  const demo = useTabelaAux('notas-demonstracao');
  const fluxo = useTabelaAux('notas-fluxo-caixa');
  const centros = useTabelaAux('centros-custo');
  const unidades = useUnidadesNegocio();
  const codigo = (lista: { id: number; codigo: string | null }[] | undefined) => new Map((lista ?? []).map((x) => [x.id, x.codigo ?? String(x.id)]));

  // rascunho dos filtros (só se aplicam com «Filtrar», como no legado) e filtros aplicados
  const [rascunho, setRascunho] = useState<FiltrosLista>({});
  const [aplicados, setAplicados] = useState<FiltrosLista>({});
  const [sem, setSem] = useState<SemCampo[]>([]);
  const [selec, setSelec] = useState<number[]>([]);
  const [massa, setMassa] = useState<Record<string, number | undefined>>({});
  const [ocultas, setOcultas] = useState<Seccao[]>(lerSeccoesOcultas);
  const [colar, setColar] = useState<Colar>(null);
  const [importar, setImportar] = useState(false);
  const [saldos, setSaldos] = useState(false);
  const [aceitarAvisos, setAceitarAvisos] = useState(false);
  const [editar, setEditar] = useState<{ linha: LinhaLista; titulo: string } | null>(null);
  const [assistente, setAssistente] = useState(false);
  const [descontabilizar, setDescontabilizar] = useState<AlvoMassa | null>(null);
  const [fluxoDe, setFluxoDe] = useState<number | null>(null);
  const [estornar, setEstornar] = useState<LinhaLista | null>(null);
  const [transferir, setTransferir] = useState<LinhaLista | null>(null);
  const muda = (p: Partial<FiltrosLista>) => setRascunho((r) => ({ ...r, ...p }));
  const filtrar = () => setAplicados(rascunho);
  const filtros = useMemo(() => filtrosLancamentos(aplicados, sem), [aplicados, sem]);
  const podeMassa = pode('lancamentos_bulk_notes', 'lancamentos_editar');
  const podeLancar = pode('lancamentos_post');
  const visivel = (s: Seccao) => !ocultas.includes(s);
  const seccoesDisponiveis = SECCOES.filter((s) => s.chave !== 'massa' || podeMassa);
  const mudarSeccoes = (accao: Seccao | 'todas' | 'nenhuma') =>
    setOcultas((o) => {
      const n = alternarSeccoes(o, accao, seccoesDisponiveis.map((s) => s.chave));
      gravarSeccoesOcultas(n);
      return n;
    });
  /** Secção oculta mas com valores preenchidos (o botão fica marcado, como o ponto do legado). */
  const comValores: Record<Seccao, boolean> = {
    periodo: !!(rascunho.ano || rascunho.inicio || rascunho.fim),
    datas: !!(rascunho.docInicio || rascunho.docFim || rascunho.criadoInicio || rascunho.criadoFim),
    conta: !!(rascunho.contas || rascunho.diario),
    ident: !!(rascunho.numeroLan || rascunho.numeroDoc || rascunho.referencia),
    terceiro: !!(rascunho.terceiro || rascunho.pesquisa || rascunho.classe9),
    sem: sem.length > 0,
    massa: false,
  };
  const alvoActual = (): AlvoMassa | null => {
    if (selec.length) return { ids: selec };
    if (semCriterios(aplicados, sem)) return null;
    return { filtros };
  };

  const aplicarMassa = useMutation({
    mutationFn: (alvo: AlvoMassa) => pedidoClassificacao(alvo, camposMassa(massa) as CamposClassificacao),
    onSuccess: ({ mensagem }) => {
      message.success(mensagem);
      setSelec([]);
      setMassa({});
      void cliente.invalidateQueries({ queryKey: ['contab'] });
    },
    onError: (e) => notificarErro(e, 'Não foi possível aplicar a alteração em massa'),
  });
  const confirmarMassa = async () => {
    if (!Object.keys(camposMassa(massa)).length) {
      void message.warning('Escolha pelo menos um campo para alterar.');
      return;
    }
    const alvo = alvoActual();
    if (!alvo) {
      void message.warning('Nenhum lançamento seleccionado ou filtrado: aplique pelo menos um filtro (data, conta, diário ou documento).');
      return;
    }
    let n = selec.length;
    if (!('ids' in alvo)) {
      try {
        const r = (await enviar<{ linhas: number; excede: boolean; maximo: number }>('post', '/contabilidade/lancamentos/classificacao/previsao', alvo)).dados;
        if (r.excede) {
          void message.warning(`A filtragem tem mais de ${r.maximo.toLocaleString('pt-PT')} linhas: restrinja os filtros.`);
          return;
        }
        n = r.linhas;
      } catch (e) {
        notificarErro(e, 'Não foi possível contar as linhas filtradas');
        return;
      }
    }
    if (!n) {
      void message.info('Nenhuma linha corresponde aos filtros.');
      return;
    }
    Modal.confirm({
      title: 'Alterar em massa',
      content: `Aplicar a alteração às ${n.toLocaleString('pt-PT')} linha(s) ${'ids' in alvo ? 'seleccionada(s)' : 'filtrada(s) na tabela'}? A conta, o valor e a data não mudam.`,
      okText: 'Aplicar',
      cancelText: 'Cancelar',
      onOk: () => aplicarMassa.mutateAsync(alvo),
    });
  };
  const pedirDescontabilizacao = () => {
    const alvo = alvoActual();
    if (!alvo) {
      void message.warning('Nenhum Lançamento selecionado ou filtrado.');
      return;
    }
    setDescontabilizar(alvo);
  };

  const documento = (id: number) => obter<DocumentoLancamento>(`/contabilidade/lancamentos/${id}`);
  const comErro = async (accao: () => Promise<void>, erro: string) => {
    try {
      await accao();
    } catch (e) {
      notificarErro(e, erro);
    }
  };

  // 8.1.3 — as 6 acções do legado, pela mesma ordem e com os mesmos ícones (fa-edit, fa-project-diagram, fa-exchange-alt,
  // fa-copy, fa-truck-moving, fa-external-link-alt); o servidor valida permissões, exercício, origem e equilíbrio
  const accoes = (r: LinhaLista): (AccaoLinha | false)[] => {
    const a = regrasAccoes(r, pode);
    return [
      a.edicao !== null && {
        chave: 'editar',
        icone: 'fa-edit',
        cor: '#000',
        titulo: a.tituloEdicao,
        onClick:
          a.edicao === 'completa'
            ? () => comErro(async () => navegar('novo', { state: { copia: await documento(r.id), corrigir: r.id } satisfies EstadoCopia }), 'Não foi possível abrir o lançamento')
            : () => setEditar({ linha: r, titulo: a.tituloEdicao }),
      },
      { chave: 'fluxo', icone: 'fa-project-diagram', cor: '#000', titulo: 'Ver Fluxo Completo', onClick: () => setFluxoDe(r.id) },
      a.estornar !== false && {
        chave: 'estornar',
        icone: 'fa-exchange-alt',
        cor: '#000',
        titulo: 'Estornar (Criar Lançamento contrário)',
        desactivado: typeof a.estornar === 'string' ? a.estornar : false,
        onClick: () => setEstornar(r),
      },
      a.copiar && {
        chave: 'copiar',
        icone: 'fa-copy',
        cor: '#000',
        titulo: 'Copiar Lançamento',
        onClick: () => comErro(async () => navegar('novo', { state: { copia: await documento(r.id) } satisfies EstadoCopia }), 'Não foi possível copiar o lançamento'),
      },
      a.transferir !== false && {
        chave: 'transferir',
        icone: 'fa-truck-moving',
        cor: '#000',
        titulo: 'Transferir Lançamento para Outra Empresa',
        desactivado: typeof a.transferir === 'string' ? a.transferir : false,
        onClick: () => setTransferir(r),
      },
      {
        chave: 'origem',
        icone: 'fa-external-link-alt',
        cor: '#000',
        titulo: 'Ver Documento de Origem',
        onClick: () => comErro(async () => abrirOrigem(await obterOrigem(r.id), navegar), 'Não foi possível localizar o documento de origem'),
      },
    ];
  };

  const colunas = colunasLancamentos({
    codigoDiario,
    nomeDiario,
    demo: codigo(demo.data),
    fluxo: codigo(fluxo.data),
    unidade: codigo(unidades.data),
    centro: codigo(centros.data),
  });

  const opcoesMassa = (lista: { id: number; codigo: string | null; descricao?: string | null; nome?: string }[] | undefined, remover: string) => [
    { value: REMOVER, label: <Typography.Text type="danger">{remover}</Typography.Text>, texto: remover },
    ...(lista ?? []).map((x) => ({ value: x.id, label: `${x.codigo ?? ''} - ${x.descricao ?? x.nome ?? ''}`, texto: `${x.codigo ?? ''} ${x.descricao ?? x.nome ?? ''}` })),
  ];
  const selectMassa = (campo: string, placeholder: string, rotulo: string, lista: Parameters<typeof opcoesMassa>[0], remover: string) => (
    <Select
      size="small"
      allowClear
      placeholder={placeholder}
      aria-label={`${rotulo} (vazio = manter)`}
      title={`${rotulo} (vazio = manter)`}
      style={{ width: 120 }}
      popupMatchSelectWidth={false}
      showSearch
      optionFilterProp="texto"
      value={massa[campo]}
      onChange={(v?: number) => setMassa((m) => ({ ...m, [campo]: v }))}
      options={opcoesMassa(lista, remover)}
    />
  );
  const limpar = () => {
    setSem([]);
    setRascunho({});
    setAplicados({});
    setSelec([]);
  };

  // «Secções» (legado: filtros-vistas): mostrar/ocultar cada secção ou todas
  const barraSeccoes: ReactNode = (
    <Col span={24}>
      <Flex wrap gap={6} align="center" role="toolbar" aria-label="Mostrar ou ocultar secções dos filtros" style={{ fontSize: 12 }}>
        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
          <IconeFa nome="sliders-h" /> Secções
        </Typography.Text>
        {seccoesDisponiveis.map((s) => {
          const on = visivel(s.chave);
          return (
            <Button
              key={s.chave}
              size="small"
              type={on ? 'primary' : 'default'}
              ghost={on}
              aria-pressed={on}
              title={`${on ? 'Ocultar' : 'Mostrar'} ${s.rotulo}`}
              icon={<IconeFa nome={on ? 'eye' : 'eye-slash'} />}
              onClick={() => mudarSeccoes(s.chave)}
            >
              {s.rotulo}
              {!on && comValores[s.chave] && <span title="Tem filtros preenchidos" style={{ display: 'inline-block', width: 6, height: 6, borderRadius: 3, background: token.colorWarning, marginLeft: 4 }} />}
            </Button>
          );
        })}
        <span style={{ width: 1, height: 16, background: token.colorBorder, margin: '0 4px' }} />
        <Button size="small" type="link" disabled={ocultas.length === 0} onClick={() => mudarSeccoes('todas')}>
          Mostrar todas
        </Button>
        <Button size="small" type="link" disabled={ocultas.length >= seccoesDisponiveis.length} onClick={() => mudarSeccoes('nenhuma')}>
          Ocultar todas
        </Button>
      </Flex>
    </Col>
  );

  return (
    <>
      <CabecalhoPagina
        titulo="Lançamentos Contabilísticos"
        subtitulo={!podeLancar ? <Tag>Modo Somente Leitura</Tag> : undefined}
        accoes={
          <>
            {ecra.suportado && (
              <Button icon={<IconeFa nome="expand" />} onClick={ecra.alternar}>
                {ecra.cheio ? 'Sair do Ecrã Cheio' : 'Ecrã Cheio'}
              </Button>
            )}
            {podeLancar && (
              <Button icon={<IconeFa nome="robot" />} onClick={() => setAssistente(true)} style={{ background: 'linear-gradient(135deg,#6d28d9,#7c3aed)', color: '#fff', border: 'none' }}>
                Agente IA (Ler Documento)
              </Button>
            )}
            {pode('lancamentos_import') && (
              <Button icon={<IconeFa nome="file-download" />} onClick={() => void message.success(`Template descarregado: ${descarregarTemplate()}`)}>
                Baixar Template
              </Button>
            )}
            {pode('lancamentos_import') && (
              <Button icon={<IconeFa nome="file-import" />} onClick={() => setImportar(true)}>
                Importar Lançamentos
              </Button>
            )}
            {pode('contab_lanc_del') && (
              <Button icon={<IconeFa nome="undo" />} onClick={pedirDescontabilizacao} style={{ background: '#f59e0b', borderColor: '#f59e0b', color: '#fff' }}>
                Descontabilizar Selecionados
              </Button>
            )}
            {pode('lancamentos_saldos') && (
              <Button icon={<IconeFa nome="history" />} onClick={() => setSaldos(true)} style={{ borderColor: token.colorPrimary, color: token.colorPrimary }}>
                Importar Histórico
              </Button>
            )}
            {podeLancar && (
              <Button type="primary" icon={<IconeFa nome="plus" />} onClick={() => navegar('novo')}>
                Novo Lançamento
              </Button>
            )}
          </>
        }
      />
      <div ref={ecra.ref} style={{ background: token.colorBgContainer, overflow: ecra.cheio ? 'auto' : undefined, padding: ecra.cheio ? 16 : undefined }}>
        <PainelFiltros
          aria="Filtros dos lançamentos"
          rodape={
            <>
              <Flex wrap gap={8} align="center" style={{ flex: '1 1 auto', minWidth: 0 }}>
                {visivel('sem') && (
                  <Flex wrap gap={8} align="center" role="group" aria-label="Linhas sem">
                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                      Mostrar linhas sem:
                    </Typography.Text>
                    {SEM_ROTULOS.map((s) => (
                      <Tooltip key={s.chave} title={s.titulo}>
                        <Tag.CheckableTag
                          checked={sem.includes(s.chave)}
                          onChange={(on) => setSem((v) => (on ? [...v, s.chave] : v.filter((x) => x !== s.chave)))}
                          style={{ border: `1px solid ${token.colorBorder}`, padding: '1px 8px' }}
                        >
                          {s.rotulo}
                        </Tag.CheckableTag>
                      </Tooltip>
                    ))}
                    <Button size="small" danger type="text" icon={<IconeFa nome="times" />} onClick={limpar} title="Limpar todos os filtros aplicados">
                      Limpar filtros
                    </Button>
                  </Flex>
                )}
                {podeMassa && visivel('massa') && (
                  <Flex wrap gap={6} align="center" role="group" aria-label="Alterar em massa">
                    <Typography.Text strong style={{ fontSize: 12 }} title="Aplica às linhas seleccionadas ou, sem selecção, a todas as filtradas na tabela">
                      Alterar em massa ({selec.length || 'filtradas'}):
                    </Typography.Text>
                    {selectMassa('nota_demonstracao_id', 'Nota Dem.', 'Nota demonstrativa', demo.data, '[REMOVER NOTA]')}
                    {selectMassa('nota_fluxo_caixa_id', 'Nota Fluxo', 'Nota de fluxo', fluxo.data, '[REMOVER NOTA]')}
                    {selectMassa('unidade_negocio_id', 'UN', 'Unidade de negócio', unidades.data, '[REMOVER UN]')}
                    {selectMassa('centro_custo_id', 'CC', 'Centro de custo', centros.data, '[REMOVER CC]')}
                    <Button size="small" icon={<IconeFa nome="check-double" />} loading={aplicarMassa.isPending} onClick={() => void confirmarMassa()} title="Aplicar às linhas filtradas">
                      Aplicar
                    </Button>
                  </Flex>
                )}
              </Flex>
              <Button type="primary" icon={<IconeFa nome="search" />} onClick={filtrar}>
                Filtrar
              </Button>
            </>
          }
        >
          {barraSeccoes}
          {visivel('periodo') && (
            <GrupoFiltros titulo="Período fiscal" icone={<IconeFa nome="calendar-alt" />} largura={12}>
              <CampoFiltro rotulo="Ano" htmlFor="filt-quick-year">
                <Select
                  id="filt-quick-year"
                  allowClear
                  placeholder="-"
                  style={{ width: '100%' }}
                  value={rascunho.ano}
                  options={ANOS.map((a) => ({ value: a, label: String(a) }))}
                  onChange={(ano?: number) => muda({ ano, mes: ano ? rascunho.mes : undefined, ...intervaloPeriodo(ano, ano ? rascunho.mes : undefined) })}
                />
              </CampoFiltro>
              <CampoFiltro rotulo="Período" htmlFor="filt-quick-month">
                <Select
                  id="filt-quick-month"
                  allowClear
                  placeholder="-"
                  style={{ width: '100%' }}
                  value={rascunho.mes}
                  disabled={!rascunho.ano}
                  options={Array.from({ length: 12 }, (_, i) => ({ value: i + 1, label: String(i + 1) }))}
                  onChange={(mes?: number) => muda({ mes, ...intervaloPeriodo(rascunho.ano, mes) })}
                />
              </CampoFiltro>
              <CampoFiltro rotulo="Data fiscal (início)" htmlFor="filt-date-start">
                <CampoData id="filt-date-start" rotulo="Data fiscal (início)" valor={rascunho.inicio} aoMudar={(inicio) => muda({ inicio, ano: undefined, mes: undefined })} />
              </CampoFiltro>
              <CampoFiltro rotulo="Data fiscal (fim)" htmlFor="filt-date-end">
                <CampoData id="filt-date-end" rotulo="Data fiscal (fim)" valor={rascunho.fim} aoMudar={(fim) => muda({ fim, ano: undefined, mes: undefined })} />
              </CampoFiltro>
            </GrupoFiltros>
          )}
          {visivel('datas') && (
            <GrupoFiltros titulo="Datas do documento e do lançamento" icone={<IconeFa nome="clock" />} largura={12}>
              <CampoFiltro rotulo="Data doc. (início)" htmlFor="filt-doc-date-start">
                <CampoData id="filt-doc-date-start" rotulo="Data doc. (início)" valor={rascunho.docInicio} aoMudar={(docInicio) => muda({ docInicio })} />
              </CampoFiltro>
              <CampoFiltro rotulo="Data doc. (fim)" htmlFor="filt-doc-date-end">
                <CampoData id="filt-doc-date-end" rotulo="Data doc. (fim)" valor={rascunho.docFim} aoMudar={(docFim) => muda({ docFim })} />
              </CampoFiltro>
              <CampoFiltro rotulo="Data lanç. (início)" htmlFor="filt-created-date-start">
                <CampoData id="filt-created-date-start" rotulo="Data lanç. (início)" valor={rascunho.criadoInicio} aoMudar={(criadoInicio) => muda({ criadoInicio })} />
              </CampoFiltro>
              <CampoFiltro rotulo="Data lanç. (fim)" htmlFor="filt-created-date-end">
                <CampoData id="filt-created-date-end" rotulo="Data lanç. (fim)" valor={rascunho.criadoFim} aoMudar={(criadoFim) => muda({ criadoFim })} />
              </CampoFiltro>
            </GrupoFiltros>
          )}
          {visivel('conta') && (
            <GrupoFiltros titulo="Conta e diário" icone={<IconeFa nome="sitemap" />}>
              <CampoFiltro rotulo="Conta(s)" htmlFor="filt-account" largo>
                <CampoColar
                  id="filt-account"
                  placeholder="Ex: 11, 21-25, 31*"
                  rotuloColar="Colar múltiplas contas"
                  valor={rascunho.contas}
                  aoMudar={(contas) => muda({ contas })}
                  aoColar={() => setColar({ campo: 'contas', titulo: 'Colar múltiplas Contas' })}
                  aoConfirmar={filtrar}
                />
              </CampoFiltro>
              <CampoFiltro rotulo="Diário" htmlFor="filt-journal" largo>
                <SeletorDiario id="filt-journal" placeholder="[Todos os diários]" allowClear style={{ width: '100%' }} value={rascunho.diario} onChange={(diario) => muda({ diario })} />
              </CampoFiltro>
            </GrupoFiltros>
          )}
          {visivel('ident') && (
            <GrupoFiltros titulo="Identificação" icone={<IconeFa nome="hashtag" />}>
              <CampoFiltro rotulo="Nº lançamento" htmlFor="filt-lan-number" largo>
                <CampoColar
                  id="filt-lan-number"
                  placeholder="Ex: SAL2026..."
                  rotuloColar="Colar vários números de lançamento"
                  valor={rascunho.numeroLan}
                  aoMudar={(numeroLan) => muda({ numeroLan })}
                  aoColar={() => setColar({ campo: 'numeroLan', titulo: 'Colar N.ºs Lançamento' })}
                  aoConfirmar={filtrar}
                />
              </CampoFiltro>
              <CampoFiltro rotulo="Documento" htmlFor="filt-doc-number">
                <CampoColar
                  id="filt-doc-number"
                  placeholder="Nº doc..."
                  rotuloColar="Colar vários documentos"
                  valor={rascunho.numeroDoc}
                  aoMudar={(numeroDoc) => muda({ numeroDoc })}
                  aoColar={() => setColar({ campo: 'numeroDoc', titulo: 'Colar Documentos' })}
                  aoConfirmar={filtrar}
                />
              </CampoFiltro>
              <CampoFiltro rotulo="Referência" htmlFor="filt-reference">
                <CampoColar
                  id="filt-reference"
                  placeholder="Referência..."
                  rotuloColar="Colar várias referências"
                  valor={rascunho.referencia}
                  aoMudar={(referencia) => muda({ referencia })}
                  aoColar={() => setColar({ campo: 'referencia', titulo: 'Colar Referências' })}
                  aoConfirmar={filtrar}
                />
              </CampoFiltro>
            </GrupoFiltros>
          )}
          {visivel('terceiro') && (
            <GrupoFiltros titulo="Terceiro e descrição" icone={<IconeFa nome="user" />}>
              <CampoFiltro rotulo="Terceiro" htmlFor="filt-terceiro" largo>
                <SeletorTerceiro id="filt-terceiro" style={{ width: '100%' }} value={rascunho.terceiro} onChange={(terceiro) => muda({ terceiro })} />
              </CampoFiltro>
              <CampoFiltro rotulo="Descrição contém" htmlFor="filt-desc">
                <Input id="filt-desc" placeholder="Texto…" allowClear value={rascunho.pesquisa} onChange={(e) => muda({ pesquisa: e.target.value })} onPressEnter={filtrar} />
              </CampoFiltro>
              <CampoFiltro rotulo="Contas de ordem">
                <Checkbox checked={!!rascunho.classe9} onChange={(e) => muda({ classe9: e.target.checked })}>
                  Incluir classe 9
                </Checkbox>
              </CampoFiltro>
            </GrupoFiltros>
          )}
        </PainelFiltros>
        <Card size="small" styles={{ body: { padding: 8 } }}>
          <TabelaApi<LinhaLista>
            url="/contabilidade/lancamentos"
            chaveConsulta={['contab', 'lancamentos']}
            idVista="contabilidade/lancamentos/paridade"
            porPagina={50}
            filtros={filtros}
            columns={colunas}
            size="small"
            accoes={accoes}
            tituloAccoes="Ações"
            seleccao={{
              chaves: selec.map(String),
              aoMudar: (k) => setSelec(k.map(Number)),
              ajuda: 'Marque as linhas para «Descontabilizar Selecionados» ou «Alterar em massa»; sem selecção, aplicam-se a todas as filtradas.',
            }}
            impressao={{
              titulo: 'Lançamentos contabilísticos',
              orientacao: 'paisagem',
              rotuloTotal: 'TOTAL:',
              periodo: aplicados.inicio && aplicados.fim ? `${formatarData(aplicados.inicio)} a ${formatarData(aplicados.fim)}` : undefined,
              filtros: [
                !!aplicados.diario && `Diário: ${codigoDiario.get(aplicados.diario) ?? aplicados.diario}`,
                aplicados.contas && `Contas: ${aplicados.contas}`,
                !!aplicados.terceiro && `Terceiro: n.º ${aplicados.terceiro}`,
                aplicados.numeroLan && `N.º lançamento: ${aplicados.numeroLan}`,
                aplicados.numeroDoc && `N.º documento: ${aplicados.numeroDoc}`,
                aplicados.referencia && `Referência: ${aplicados.referencia}`,
                (aplicados.docInicio || aplicados.docFim) && `Data doc.: ${formatarData(aplicados.docInicio ?? null) || '…'} a ${formatarData(aplicados.docFim ?? null) || '…'}`,
                (aplicados.criadoInicio || aplicados.criadoFim) && `Data lanç.: ${formatarData(aplicados.criadoInicio ?? null) || '…'} a ${formatarData(aplicados.criadoFim ?? null) || '…'}`,
                aplicados.pesquisa && `Descrição: ${aplicados.pesquisa}`,
                aplicados.classe9 && 'Inclui classe 9',
                sem.length > 0 && `Sem: ${SEM_ROTULOS.filter((s) => sem.includes(s.chave)).map((s) => s.rotulo).join(', ')}`,
              ],
            }}
            onRow={(r) => ({
              // duplo clique abre o lançamento (o clique simples fica para a selecção e as acções, como no legado)
              onDoubleClick: () => navegar(String(r.id)),
            })}
          />
        </Card>
      </div>
      <ModalColar
        aberto={!!colar}
        titulo={colar?.titulo ?? ''}
        contas={colar?.campo === 'contas'}
        valor={colar ? (rascunho[colar.campo] as string | undefined) : undefined}
        aoAplicar={(texto) => colar && muda({ [colar.campo]: texto })}
        aoFechar={() => setColar(null)}
      />
      <ModalImportar
        aberto={importar}
        titulo="Importar Lançamentos"
        url="/contabilidade/lancamentos/importar"
        comSimulacao
        campos={{ aceitar_avisos: aceitarAvisos }}
        ajuda="Use o template de importação de lançamentos («Baixar Template»; XLSX, XLS ou CSV). Valide primeiro com a simulação: nada é gravado se houver erros."
        extra={
          <Space direction="vertical">
            <Button size="small" icon={<IconeFa nome="file-download" />} onClick={() => descarregarTemplate()}>
              Baixar Template
            </Button>
            <Checkbox checked={aceitarAvisos} onChange={(e) => setAceitarAvisos(e.target.checked)}>
              Aceitar avisos (códigos inexistentes ficam em branco)
            </Checkbox>
          </Space>
        }
        aoFechar={() => setImportar(false)}
        aoConcluir={() => void cliente.invalidateQueries({ queryKey: ['contab'] })}
      />
      {saldos && <ModalSaldosHistoricos aberto={saldos} aoFechar={() => setSaldos(false)} />}
      <ModalClassificacao linha={editar?.linha ?? null} titulo={editar?.titulo} aoFechar={() => setEditar(null)} />
      <ModalDescontabilizar alvo={descontabilizar} aoFechar={() => setDescontabilizar(null)} aoConcluir={() => setSelec([])} />
      <ModalFluxoLancamento
        linhaId={fluxoDe}
        aoFechar={() => setFluxoDe(null)}
        aoDescontabilizar={(ids) => {
          setFluxoDe(null);
          setDescontabilizar({ ids });
        }}
      />
      <ModalEstornar linha={estornar} aoFechar={() => setEstornar(null)} />
      {transferir && <ModalTransferir aberto linhaId={transferir.id} numeroLan={transferir.numero_lan} aoFechar={() => setTransferir(null)} />}
      {assistente && (
        <Suspense fallback={null}>
          <AssistenteIA aberto aoFechar={() => setAssistente(false)} />
        </Suspense>
      )}
    </>
  );
}
