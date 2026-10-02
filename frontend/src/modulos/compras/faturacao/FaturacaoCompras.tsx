import { Button, Card, Checkbox, DatePicker, Input, Select, Tag } from 'antd';
import { CheckCircleTwoTone, PlusOutlined, SettingOutlined } from '@ant-design/icons';
import { BarraFiltros, useEcraPequeno } from '@/componentes/responsivo';
import { somar } from '@/utilitarios/decimal';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
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
import { ValorMoeda } from '../comum/Valores';
import { DetalheFatura } from './DetalheFatura';
import { NovaFatura } from './NovaFatura';

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

  const colunas: ColunaApi<FaturaCompra>[] = [
    { title: 'Factura', dataIndex: 'numero_fatura', fixed: 'left', render: (v: string) => <strong>{v}</strong> },
    { title: 'Data', dataIndex: 'data', responsive: ['sm'], render: formatarData },
    { title: 'Fornecedor', key: 'fornecedor', valorImpressao: (r) => r.fornecedor?.nome?.trim() ?? `#${r.fornecedor_id}`, render: (_, r) => <NomeTerceiro id={r.fornecedor_id} terceiro={r.fornecedor} /> },
    { title: 'Encomenda', dataIndex: 'encomenda_compra_id', responsive: ['lg'], render: (v: number | null) => (v ? `#${v}` : 'Directa') },
    {
      title: 'Total (Kz)',
      key: 'total',
      align: 'right',
      valorImpressao: (r) => formatarKz(r.montante_total),
      totalImpressao: (ls) => formatarKz(somar(ls.map((l) => (l.estado === 'ANULADA' ? 0 : l.montante_total)))),
      render: (_, r) => <ValorMoeda kz={r.montante_total} moeda={r.codigo_moeda} valorMoeda={r.montante_total_moeda} />,
    },
    { title: 'Vencimento', dataIndex: 'data_vencimento', responsive: ['md'], render: formatarData },
    { title: 'Estado', dataIndex: 'estado', responsive: ['sm'], render: (e: string | null) => <EstadoTag estado={e} /> },
    { title: 'Contab.', dataIndex: 'contabilizado', align: 'center', responsive: ['lg'], valorImpressao: (r) => (r.contabilizado ? 'Sim' : 'Não'), render: (c: boolean | null) => (c ? <CheckCircleTwoTone twoToneColor="#52c41a" /> : null) },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo="Facturas de fornecedores"
        subtitulo="Facturas de encomendas e facturas directas; contabilização no diário de fornecedores"
        accoes={
          <>
            {pode('compras_faturacao_view', 'compras_fact_contabilizar') && (
              <Button icon={<SettingOutlined />} onClick={() => setContas(true)}>
                Contas de compras
              </Button>
            )}
            {pode('compras_fact_registar') && (
              <Button type="primary" icon={<PlusOutlined />} onClick={() => navegar('novo')}>
                Factura directa
              </Button>
            )}
          </>
        }
      />
      <Card>
        <BarraFiltros>
          <Select placeholder="Estado" allowClear style={{ width: 180 }} value={estado} onChange={setEstado} options={opcoesEstado(['PENDENTE', 'PARCIAL', 'PAGO', 'ANULADA'])} />
          <SeletorTerceiro papel="FORNECEDOR" style={{ width: 320, maxWidth: '100%' }} value={fornecedor} onChange={(v, o) => { setFornecedor(v); setNomeFornecedor(o && !Array.isArray(o) && o.label ? String(o.label) : undefined); }} />
          <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} />
          <Input.Search placeholder="N.º da factura" allowClear style={{ width: 200, maxWidth: '100%' }} onSearch={(v) => setPesquisa(v.trim())} />
          {encomenda && (
            <Tag closable onClose={() => setParams({})}>
              Encomenda #{encomenda}
            </Tag>
          )}
          <Checkbox checked={porContabilizar} onChange={(e) => setPorContabilizar(e.target.checked)}>
            Só por contabilizar
          </Checkbox>
        </BarraFiltros>
        <TabelaApi<FaturaCompra>
          url="/compras/faturas"
          chaveConsulta={['compras', 'faturas']}
          filtros={{ estado, fornecedor_id: fornecedor, encomenda_compra_id: encomenda, pesquisa: pesquisa || undefined, por_contabilizar: porContabilizar ? 1 : undefined, data_inicio: dataApi(periodo?.[0]), data_fim: dataApi(periodo?.[1]) }}
          columns={colunas}
          size={pequeno ? 'small' : 'middle'}
          impressao={{
            titulo: 'Lista de facturas de fornecedores',
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
