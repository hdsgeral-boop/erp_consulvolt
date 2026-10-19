import { Button, Card, Checkbox, DatePicker, Empty, Input, List, Modal, Table, Tabs, Tag, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { BotoesExportar } from '@/componentes/impressao';
import { BarraFiltros, larguraModal, scrollTabela } from '@/componentes/responsivo';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { tabelaDeColunas } from '@/modulos/contab/comum/impressao';
import { useSessao } from '@/sessao/SessaoContexto';
import { ValorKz } from '@/modulos/contab/comum/Componentes';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { EtiquetaAD, rotuloAD, useDefinicoes } from './comum/componentes';
import { candidatosVisiveis, contaBalancoPadrao, textoSemCandidatos, valoresDiferir, valorRegularizacao } from './comum/regras';
import type { Candidato, FonteRecolha, ItemAD } from './comum/tipos';
import { ModalRegularizar } from './DetalheItem';
import { ModalItem, type ValoresIniciais } from './ModalItem';
import { useSeparadorUrl } from '@/componentes/navegacao/useSeparadorUrl';

/** Separadores do legado (FONTES de ad_ui.js): rótulo e ícone Font Awesome. */
const FONTES: { value: FonteRecolha; label: string; icone: string }[] = [
  { value: 'COMPRA', label: 'Facturas de fornecedor', icone: 'file-invoice-dollar' },
  { value: 'VENDA', label: 'Facturas de clientes', icone: 'file-invoice' },
  { value: 'DIARIO', label: 'Lançamentos do Diário', icone: 'book-open' },
  { value: 'TESOURARIA', label: 'Pagamentos e recebimentos', icone: 'university' },
];

type Linha = Candidato['linhas'][number];

/**
 * Acréscimos e diferimentos › Recolher documentos (ecrã ad_recolher), como no legado (renderAdRecolher): «Diferir» cria
 * um diferimento a partir do documento; «Regularizar acréscimo» associa a factura real a um acréscimo em aberto. Os
 * documentos já usados ficam escondidos salvo «Mostrar os já usados».
 */
export default function Recolher() {
  const { pode } = useSessao();
  const navegar = useNavigate();
  const def = useDefinicoes();
  const [fonte, setFonte] = useSeparadorUrl(FONTES.map((f) => f.value));
  const [inicio, setInicio] = useState<dayjs.Dayjs | null>(dayjs().startOf('year'));
  const [fim, setFim] = useState<dayjs.Dayjs | null>(dayjs());
  const [texto, setTexto] = useState('');
  const [usados, setUsados] = useState(false);
  const [criar, setCriar] = useState<ValoresIniciais | null>(null);
  const [escolherLinha, setEscolherLinha] = useState<Candidato | null>(null);
  const [regularizarDe, setRegularizarDe] = useState<Candidato | null>(null);
  const de = dataApi(inicio);
  const ate = dataApi(fim);
  const q = useQuery({ queryKey: ['acrescimos', 'recolha', fonte, de, ate, texto], queryFn: () => obter<Candidato[]>('/acrescimos/recolha', { fonte, de, ate, texto }) });
  const editar = pode('ad_editar');
  const todos = q.data ?? [];
  const dados = candidatosVisiveis(todos, usados);

  const diferir = (c: Candidato, l: Linha) => {
    setEscolherLinha(null);
    setCriar(valoresDiferir(c, l, contaBalancoPadrao(def.data, 'DIFERIMENTO', c.natureza)));
  };
  const iniciarDiferir = (c: Candidato) => (c.linhas.length === 1 ? diferir(c, c.linhas[0]) : setEscolherLinha(c));

  const colunas: ColunaApi<Candidato>[] = [
    { title: 'Data', dataIndex: 'data', render: formatarData },
    {
      title: 'Documento', dataIndex: 'doc',
      render: (v, c) => (
        <>
          <strong>{v}</strong>
          {c.descricao && <Typography.Text type="secondary" style={{ display: 'block', fontSize: '0.74rem' }}>{c.descricao}</Typography.Text>}
          {!c.contabilizado && <div><Tag color="gold">por contabilizar</Tag></div>}
          {c.em_balanco && <div><Tag color="cyan">lançado na 37</Tag></div>}
          {c.ligado && <div><Tag color="red">já usado</Tag></div>}
        </>
      ),
    },
    { title: 'Terceiro', dataIndex: 'terceiro', ellipsis: true, width: 220, render: (v) => v?.trim() || '—' },
    { title: 'Natureza', dataIndex: 'natureza', render: (v) => <EtiquetaAD valor={v} /> },
    {
      title: 'Contas', key: 'c',
      render: (_, c) => c.linhas.map((l) => <div key={l.conta}><Tag>{l.conta}</Tag> {formatarKz(l.valor)}</div>),
    },
    { title: 'Valor (sem IVA)', dataIndex: 'total', align: 'right', render: (v) => <ValorKz valor={v} /> },
    {
      title: '', key: 'acc', align: 'right', exportar: false,
      render: (_, c) => editar && (
        <span style={{ whiteSpace: 'nowrap', display: 'inline-flex', gap: 6 }}>
          <Button size="small" type="primary" icon={<IconeFa nome="share-square" />} onClick={() => iniciarDiferir(c)}>Diferir</Button>
          {!c.em_balanco && <Button size="small" icon={<IconeFa nome="file-invoice" />} onClick={() => setRegularizarDe(c)}>Regularizar acréscimo</Button>}
        </span>
      ),
    },
  ];
  const nomeFonte = FONTES.find((x) => x.value === fonte)?.label ?? fonte;

  return (
    <>
      <CabecalhoPagina
        titulo="Recolher documentos — acréscimos e diferimentos"
        subtitulo={
          <>
            Parta dos documentos já inseridos: <strong>Diferir</strong> cria um diferimento com o valor, contas, terceiro e dimensões do documento;{' '}
            <strong>Regularizar acréscimo</strong> associa a factura real a um acréscimo em aberto.
          </>
        }
      />
      <Tabs
        activeKey={fonte}
        onChange={setFonte}
        items={FONTES.map((f) => ({ key: f.value, label: <span><IconeFa nome={f.icone} /> {f.label}</span> }))}
      />
      <Card>
        <BarraFiltros
          accoes={
            <BotoesExportar
              tamanho="small"
              desactivado={!dados.length}
              obterPedido={async () => ({
                titulo: 'Documentos a recolher para acréscimos e diferimentos',
                periodo: de && ate ? `${formatarData(de)} a ${formatarData(ate)}` : undefined,
                filtros: [`Fonte: ${nomeFonte}`, texto && `Pesquisa: ${texto}`, usados && 'Inclui os já usados'],
                conteudo: await tabelaDeColunas(colunas, dados),
              })}
            />
          }
        >
          <DatePicker format="DD/MM/YYYY" placeholder="De" aria-label="De" value={inicio} onChange={setInicio} />
          <DatePicker format="DD/MM/YYYY" placeholder="Até" aria-label="Até" value={fim} onChange={setFim} />
          <Input.Search placeholder="Documento, terceiro, conta…" aria-label="Procurar" allowClear onSearch={setTexto} style={{ width: 260 }} />
          <Checkbox checked={usados} onChange={(e) => setUsados(e.target.checked)}>Mostrar os já usados</Checkbox>
        </BarraFiltros>
        <Table<Candidato>
          rowKey={(c) => `${c.fonte}-${c.id}-${c.natureza}${c.em_balanco ? '-37' : ''}`}
          size="small"
          loading={q.isFetching}
          dataSource={dados}
          scroll={scrollTabela()}
          onRow={(c) => (c.ligado ? { style: { opacity: 0.55 } } : {})}
          pagination={dados.length > 50 ? { pageSize: 50, showSizeChanger: false, showTotal: (n) => `${n} documento(s)` } : false}
          locale={{ emptyText: <Empty description={q.isFetching ? 'A procurar documentos...' : textoSemCandidatos(fonte, todos.length > 0)} /> }}
          columns={colunas}
        />
      </Card>
      <EscolherLinha documento={escolherLinha} aoEscolher={(l) => escolherLinha && diferir(escolherLinha, l)} aoFechar={() => setEscolherLinha(null)} />
      <ModalItem aberto={!!criar} item={criar} aoFechar={() => setCriar(null)} aoGravar={(it) => navegar(`/m/acrescimos/ad_registos/${it.id}`)} />
      <EscolherAcrescimo documento={regularizarDe} aoFechar={() => setRegularizarDe(null)} />
    </>
  );
}

/** Documento com várias contas: escolher a linha a diferir (escolherLinha do legado). */
function EscolherLinha({ documento, aoEscolher, aoFechar }: { documento: Candidato | null; aoEscolher: (l: Linha) => void; aoFechar: () => void }) {
  return (
    <Modal title={`Diferir — ${documento?.doc ?? ''}`} open={!!documento} onCancel={aoFechar} footer={<Button onClick={aoFechar}>Cancelar</Button>} width={larguraModal(520)}>
      <Typography.Paragraph>O documento tem várias contas. Escolha a linha (pode repetir para as outras).</Typography.Paragraph>
      <List
        size="small"
        dataSource={documento?.linhas ?? []}
        renderItem={(l) => (
          <List.Item actions={[<Button key="e" size="small" type="primary" onClick={() => aoEscolher(l)}>Escolher</Button>]}>
            {l.conta} — {formatarKz(l.valor)}
          </List.Item>
        )}
      />
    </Modal>
  );
}

/** Lista os acréscimos em aberto que o documento pode regularizar (mesmo terceiro e mesma conta primeiro). */
function EscolherAcrescimo({ documento, aoFechar }: { documento: Candidato | null; aoFechar: () => void }) {
  const [escolhido, setEscolhido] = useState<ItemAD | null>(null);
  const q = useQuery({
    queryKey: ['acrescimos', 'abertos', documento?.fonte, documento?.id],
    queryFn: () => obter<{ item: ItemAD; afinidade: number }[]>('/acrescimos/recolha/acrescimos-abertos', { natureza: documento?.natureza, terceiro_id: documento?.terceiro_id, contas: documento?.linhas.map((l) => l.conta) }),
    enabled: !!documento,
  });
  return (
    <>
      <Modal title={`Regularizar com ${documento?.doc ?? ''}`} open={!!documento && !escolhido} onCancel={aoFechar} footer={<Button onClick={aoFechar}>Fechar</Button>} width={larguraModal(720)}>
        <Typography.Paragraph type="secondary">Escolha o acréscimo que este documento regulariza (primeiro os do mesmo terceiro e conta).</Typography.Paragraph>
        {q.data?.length ? (
          <List
            loading={q.isFetching}
            dataSource={q.data.slice(0, 12)}
            renderItem={({ item, afinidade }) => (
              <List.Item actions={[<Button key="r" type="primary" size="small" onClick={() => setEscolhido(item)}>Regularizar</Button>]}>
                <List.Item.Meta
                  title={<>{item.descricao} · {formatarKz(item.valor)}</>}
                  description={`${item.conta_resultado} · ${formatarData(item.data_inicio)}–${formatarData(item.data_fim)}${afinidade >= 2 ? ' · mesmo terceiro' : ''}${afinidade % 2 === 1 ? ' · mesma conta' : ''}`}
                />
              </List.Item>
            )}
          />
        ) : (
          <Empty
            description={
              q.isFetching
                ? 'A procurar…'
                : `Não há acréscimos de ${rotuloAD(documento?.natureza).toLowerCase()} em aberto para regularizar com este documento.`
            }
          />
        )}
      </Modal>
      <ModalRegularizar
        item={escolhido}
        documento={
          documento && escolhido
            ? { fonte: documento.fonte, id: documento.id, doc: documento.doc, data: documento.data, valor: valorRegularizacao(documento, escolhido.conta_resultado) }
            : undefined
        }
        aoFechar={() => { setEscolhido(null); aoFechar(); }}
      />
    </>
  );
}
