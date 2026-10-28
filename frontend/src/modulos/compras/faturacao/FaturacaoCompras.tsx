import { Button, Card, Checkbox, DatePicker, Form, Input, Modal, Select, Tag } from 'antd';
import { SettingOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import { ModalMotivo, useAccao } from '@/componentes/Accoes';
import { larguraModal } from '@/componentes/responsivo';
import { PainelPesquisaCompras } from '../comum/PainelPesquisaCompras';
import { BotaoAccao, type AccaoLinha } from '@/componentes/vistas';
import { useImprimirCompra } from '../comum/accoesLista';
import { colunasMoeda, colunaUnCc } from '../comum/colunasLegado';
import { ModalFluxoDocumental, type TipoDocumentoCompra } from '../comum/FluxoDocumental';
import { accoesFatura } from '../comum/regras';
import { numeroOuId } from '../comum/tipos';
import { useEcraPequeno } from '@/componentes/responsivo';
import { somar } from '@/utilitarios/decimal';
import type { Dayjs } from 'dayjs';
import { useEffect, useState } from 'react';
import { Route, Routes, useNavigate, useSearchParams } from 'react-router-dom';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { EstadoTag, opcoesEstado, rotuloEstado } from '../comum/estados';
import { ModalContas } from '../comum/ModalContas';
import { NomeTerceiro } from '../comum/referencias';
import { SeletorTerceiro } from '../comum/Seletores';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
import type { FaturaCompra } from '../comum/tipos';
import { DetalheFatura } from './DetalheFatura';
import { NovaFatura } from './NovaFatura';
import { PopoverPreContabilizacao } from './PopoverPreContabilizacao';

/** Compras › Facturas de fornecedores (ecrã compras_faturacao). */
export default function FaturacaoCompras() {
  return (
    <Routes>
      <Route index element={<ListaFaturas />} />
      <Route path="novo" element={<NovaFatura />} />
      <Route path=":id" element={<DetalheFatura />} />
    </Routes>
  );
}

function ListaFaturas() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [estado, setEstado] = useState<string>();
  const [fornecedor, setFornecedor] = useState<number>();
  const [porContabilizar, setPorContabilizar] = useState(false);
  const [periodo, setPeriodo] = useState<[Dayjs | null, Dayjs | null] | null>(null);
  const [contas, setContas] = useState(false);
  const [pesquisa, setPesquisa] = useState('');
  // ?encomenda=ID — facturas de uma encomenda (ligação a partir do detalhe da encomenda)
  const [params, setParams] = useSearchParams();
  const encomenda = Number(params.get('encomenda')) || undefined;
  const [nomeFornecedor, setNomeFornecedor] = useState<string>();
  const pequeno = useEcraPequeno();

  const [fluxo, setFluxo] = useState<{ tipo: TipoDocumentoCompra; id: number } | null>(null);
  const [modal, setModal] = useState<{ tipo: 'anular' | 'descontabilizar' | 'editar'; f: FaturaCompra } | null>(null);
  const imprimir = useImprimirCompra();
  const accao = useAccao<FaturaCompra>({ invalidar: [['compras']], aoSucesso: () => setModal(null) });

  const colunas: ColunaApi<FaturaCompra>[] = [
    { title: 'Fatura N', dataIndex: 'numero_fatura', fixed: 'left', filtroServidor: 'pesquisa', render: (v: string) => <strong>{v}</strong> },
    { title: 'Data', dataIndex: 'data', render: formatarData },
    { title: 'Encomenda Ref.', key: 'encomenda', valorImpressao: (r) => (r.encomenda_compra_id ? numeroOuId(r.numero_encomenda, r.encomenda_compra_id) : 'Directa'), render: (_, r) => (r.encomenda_compra_id ? numeroOuId(r.numero_encomenda, r.encomenda_compra_id) : 'Directa') },
    { title: 'Fornecedor', key: 'fornecedor', valorImpressao: (r) => r.fornecedor?.nome?.trim() ?? `#${r.fornecedor_id}`, render: (_, r) => <NomeTerceiro id={r.fornecedor_id} terceiro={r.fornecedor} /> },
    {
      title: 'Valor Total (Kz)', dataIndex: 'montante_total', align: 'right', render: (v: string | null) => formatarKz(v),
      total: (ls) => formatarKz(somar(ls.map((l) => (l.estado === 'ANULADA' ? 0 : l.montante_total)))),
      totalImpressao: (ls) => formatarKz(somar(ls.map((l) => (l.estado === 'ANULADA' ? 0 : l.montante_total)))),
    },
    ...colunasMoeda<FaturaCompra>(),
    colunaUnCc<FaturaCompra>(),
    {
      title: 'Status', key: 'status', valorImpressao: (r) => (r.estado === 'ANULADA' ? 'ANULADA' : r.contabilizado ? 'CONTABILIZADO' : 'PENDENTE'),
      render: (_, r) => (r.estado === 'ANULADA' ? <EstadoTag estado="ANULADA" /> : <Tag color={r.contabilizado ? 'green' : 'orange'}>{r.contabilizado ? 'CONTABILIZADO' : 'PENDENTE'}</Tag>),
    },
    { title: 'Vencimento', dataIndex: 'data_vencimento', ocultaPorOmissao: true, render: formatarData },
    { title: 'Pagamento', dataIndex: 'estado', ocultaPorOmissao: true, render: (e: string | null) => <EstadoTag estado={e} /> },
  ];

  /** Acções por linha de «Faturas de Fornecedores» do legado (js/ui_compras_v2.js:621-636). */
  const accoes = (f: FaturaCompra): AccaoLinha[] => {
    const a = accoesFatura(f, pode);
    const anulada = f.estado === 'ANULADA';
    return [
      { icone: 'eye', titulo: 'Ver Detalhes', onClick: () => navegar(String(f.id)) },
      { icone: 'print', titulo: 'Imprimir Fatura', onClick: () => imprimir.fatura(f.id) },
      { icone: 'edit', titulo: 'Editar Fatura', cor: '#d97706', oculto: !!f.contabilizado || anulada || !pode('compras_fact_registar'), onClick: () => setModal({ tipo: 'editar', f }) },
      { icone: 'project-diagram', titulo: 'Ver Fluxo', variante: 'info', onClick: () => setFluxo({ tipo: 'fatura', id: f.id }) },
      f.contabilizado
        ? { icone: 'undo', titulo: 'Descontabilizar', cor: '#dc2626', desactivado: !a.descontabilizar && (pode('compras_descontab') ? 'Factura paga: não se descontabiliza' : 'Sem permissão para descontabilizar'), onClick: () => setModal({ tipo: 'descontabilizar', f }) }
        : { chave: 'contabilizar', icone: 'book', titulo: 'Contabilizar', variante: 'primario', oculto: anulada, desactivado: !a.contabilizar && 'Sem permissão para contabilizar', confirmar: `Contabilizar a factura ${f.numero_fatura}?`, onClick: () => accao.mutateAsync({ url: `/compras/faturas/${f.id}/contabilizar` }) },
      { icone: 'trash', titulo: 'Apagar Fatura', variante: 'perigo', cheio: true, onClick: () => setModal({ tipo: 'anular', f }),
        desactivado: anulada ? 'A factura já está anulada' : f.contabilizado ? 'Eliminação bloqueada: factura contabilizada (descontabilize primeiro)' : !a.anular ? 'Sem permissão para anular facturas' : false },
    ];
  };

  /**
   * Coluna «Ações» própria (em vez da prop `accoes` da tabela) só para envolver «Contabilizar» na simulação
   * contabilística ao passar o rato (legado :640); o resto é o mesmo grupo de botões de AccoesLinha.
   */
  const colunaAccoes: ColunaApi<FaturaCompra> = {
    key: '__accoes', title: 'Ações', accoes: true, exportar: false, filtravel: false, ocultavel: false, align: 'left',
    render: (_, f) => (
      <span className="erp-accoes-linha" role="group" aria-label="Acções">
        {accoes(f).filter((a) => !a.oculto).map((a, i) =>
          a.chave === 'contabilizar' && !a.desactivado ? (
            <PopoverPreContabilizacao key="contabilizar" fatura={f}><BotaoAccao accao={a} /></PopoverPreContabilizacao>
          ) : (
            <BotaoAccao key={a.chave ?? `${a.icone}:${a.titulo}:${i}`} accao={a} />
          ),
        )}
      </span>
    ),
  };

  return (
    <>
      <ModalFluxoDocumental documento={fluxo} aoFechar={() => setFluxo(null)} />
      <ModalEditarFatura fatura={modal?.tipo === 'editar' ? modal.f : null} aoFechar={() => setModal(null)} />
      <ModalMotivo
        aberto={modal?.tipo === 'anular' || modal?.tipo === 'descontabilizar'}
        titulo={modal ? `${modal.tipo === 'anular' ? 'Apagar (anular)' : 'Descontabilizar'} a factura ${modal.f.numero_fatura}` : ''}
        textoOk={modal?.tipo === 'anular' ? 'Anular' : 'Descontabilizar'}
        aviso={modal?.tipo === 'descontabilizar' ? 'O lançamento é estornado (fica o rasto no Diário). Não é possível em facturas pagas.' : undefined}
        carregando={accao.isPending}
        aoFechar={() => setModal(null)}
        aoConfirmar={(motivo) => modal && accao.mutate({ url: `/compras/faturas/${modal.f.id}/${modal.tipo === 'anular' ? 'anular' : 'descontabilizar'}`, dados: { motivo } })}
      />
      {/* um só cartão com o painel de pesquisa, o título e a lista, como o legado (js/ui_compras_v2.js:165-233) */}
      <Card className="compras-cartao-lista">
      <PainelPesquisaCompras placeholder="N.º da factura" aoPesquisar={setPesquisa} periodo={periodo} aoMudarPeriodo={setPeriodo}>
        <Select placeholder="Estado" allowClear style={{ width: 180 }} value={estado} onChange={setEstado} options={opcoesEstado(['PENDENTE', 'PARCIAL', 'PAGO', 'ANULADA'])} />
        <SeletorTerceiro papel="FORNECEDOR" style={{ width: 320, maxWidth: '100%' }} value={fornecedor} onChange={(v, o) => { setFornecedor(v); setNomeFornecedor(o && !Array.isArray(o) && o.label ? String(o.label) : undefined); }} />
        {encomenda && (
        <Tag closable onClose={() => setParams({})}>
        Encomenda #{encomenda}
        </Tag>
        )}
        <Checkbox checked={porContabilizar} onChange={(e) => setPorContabilizar(e.target.checked)}>
        Só por contabilizar
        </Checkbox>
      </PainelPesquisaCompras>
      <CabecalhoPagina
        titulo="Faturas de Fornecedores"
        accoes={
          pode('compras_faturacao_view', 'compras_fact_contabilizar') && (
            <Button icon={<SettingOutlined />} onClick={() => setContas(true)}>
              Contas de compras
            </Button>
          )
        }
      />
        <TabelaApi<FaturaCompra>
          url="/compras/faturas"
          chaveConsulta={['compras', 'faturas']}
          filtros={{ estado, fornecedor_id: fornecedor, encomenda_compra_id: encomenda, pesquisa: pesquisa || undefined, por_contabilizar: porContabilizar ? 1 : undefined, data_inicio: dataApi(periodo?.[0]), data_fim: dataApi(periodo?.[1]) }}
          columns={[...colunas, colunaAccoes]}
          rotuloTotal="TOTAL (FILTRADO):"
          size={pequeno ? 'small' : 'middle'}
          impressao={{
            titulo: 'Faturas de Fornecedores',
            periodo: periodo?.[0] && periodo?.[1] ? `${periodo[0].format('DD/MM/YYYY')} a ${periodo[1].format('DD/MM/YYYY')}` : undefined,
            filtros: [
              estado && `Estado: ${rotuloEstado(estado)}`,
              !!fornecedor && `Fornecedor: ${nomeFornecedor ?? `#${fornecedor}`}`,
              !!encomenda && `Encomenda: #${encomenda}`,
              pesquisa && `Pesquisa: ${pesquisa}`,
              porContabilizar && 'Só por contabilizar',
            ],
            rotuloTotal: 'Total (sem anuladas)',
          }}
          onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })}
        />
      </Card>
      <ModalContas
        url="/compras/configuracao/contas"
        titulo="Contas de compras"
        chaveConsulta={['compras', 'contas']}
        aberto={contas}
        aoFechar={() => setContas(false)}
        podeEditar={pode('compras_fact_contabilizar')}
      />
    </>
  );
}

interface ValoresCabecalho {
  numero_fatura: string;
  data: dayjs.Dayjs | null;
  data_vencimento: dayjs.Dayjs | null;
}

/**
 * «Editar Fatura» (só por contabilizar, como no legado): corrige o n.º da factura do fornecedor, a data e o vencimento
 * (PUT /compras/faturas/{id}). As linhas corrigem-se anulando e registando de novo.
 */
export function ModalEditarFatura({ fatura, aoFechar }: { fatura: FaturaCompra | null; aoFechar: () => void }) {
  const [form] = Form.useForm<ValoresCabecalho>();
  const gravar = useAccao<FaturaCompra>({ invalidar: [['compras']], aoSucesso: aoFechar, tituloErro: 'Não foi possível gravar a factura' });
  useEffect(() => {
    if (fatura)
      form.setFieldsValue({ numero_fatura: fatura.numero_fatura, data: fatura.data ? dayjs(fatura.data) : null, data_vencimento: fatura.data_vencimento ? dayjs(fatura.data_vencimento) : null });
  }, [fatura, form]);
  return (
    <Modal
      open={!!fatura}
      title={fatura ? `Editar fatura ${fatura.numero_fatura}` : ''}
      width={larguraModal(520)}
      okText="Gravar"
      cancelText="Cancelar"
      confirmLoading={gravar.isPending}
      onCancel={aoFechar}
      onOk={() => form.submit()}
      destroyOnHidden
    >
      <Form<ValoresCabecalho>
        form={form}
        layout="vertical"
        onFinish={(v) =>
          fatura &&
          gravar.mutate({ metodo: 'put', url: `/compras/faturas/${fatura.id}`, dados: { numero_fatura: v.numero_fatura.trim(), data: dataApi(v.data), data_vencimento: dataApi(v.data_vencimento) ?? null } })
        }
      >
        <Form.Item name="numero_fatura" label="N.º da factura do fornecedor" rules={[{ required: true, message: 'Indique o n.º da factura.' }, { max: 50 }]}>
          <Input />
        </Form.Item>
        <Form.Item name="data" label="Data" rules={[{ required: true, message: 'Indique a data.' }]}>
          <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
        </Form.Item>
        <Form.Item name="data_vencimento" label="Vencimento">
          <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
        </Form.Item>
        <div style={{ color: 'rgba(0,0,0,0.55)' }}>As linhas não se alteram aqui: para as corrigir, anule a factura e registe-a de novo.</div>
      </Form>
    </Modal>
  );
}
