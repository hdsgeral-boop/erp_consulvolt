import {
  CheckCircleTwoTone,
  CloseCircleTwoTone,
  DeleteOutlined,
  DownloadOutlined,
  EyeOutlined,
  FileAddOutlined,
  InboxOutlined,
  PlusOutlined,
  QuestionCircleTwoTone,
  RobotOutlined,
} from '@ant-design/icons';
import { Alert, Button, Card, Collapse, DatePicker, Descriptions, Drawer, Empty, Flex, Input, InputNumber, Modal, Popconfirm, Select, Space, Switch, Table, Tabs, Tag, Typography, Upload, message } from 'antd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { enviar, http, obter } from '@/api/cliente';
import { ErroApi, type Envelope } from '@/api/tipos';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { larguraGaveta, scrollTabela } from '@/componentes/responsivo';
import { propostaParaFormulario } from '@/modulos/geral/integracoes/AssistenteIA';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { formatarKz } from '@/utilitarios/formatacao';
import {
  CHAVE_REGRAS,
  MAX_BYTES_REGRA,
  ROTULO_MOTOR,
  URL_REGRAS,
  descarregarJson,
  novoDia,
  pedidoCalculo,
  usaMotor,
  validacoesFalhadas,
  type DetalheRegra,
  type DiaFormulario,
  type EstadoIA,
  type FicheiroRegra,
  type LinhaCalculada,
  type Motor,
  type ResultadoCalculo,
  type ResultadoDia,
} from './ia/regrasFicheiro';

/**
 * Contabilidade › Assistente IA (lançamento assistido) — ecrã `ia_assistente` da Versão Actual (js/ia_regras_ficheiro.js).
 *
 * Cada regra é um ficheiro JSON com as contas, as fórmulas e as validações. Os dois motores usam o mesmo ficheiro e o mesmo
 * cálculo, feito no SERVIDOR: o motor interno pede os valores num formulário gerado pela regra; a IA externa lê-os do
 * documento (a chave do fornecedor fica só no servidor). Nada é gravado: cada dia abre no formulário normal de lançamento,
 * onde o utilizador revê e grava. Permissões: ver (ia_assistente_view), gerir regras (ia_regras), calcular/ler (ia_lancar).
 */
export default function IAAssistente() {
  const { pode } = useSessao();
  const [separador, setSeparador] = useState('regras');
  const [regraEscolhida, setRegraEscolhida] = useState<number | null>(null);
  const lancar = pode('ia_lancar');
  const usar = (id: number, motor: 'interno' | 'externo') => {
    setRegraEscolhida(id);
    setSeparador(motor);
  };
  return (
    <>
      <CabecalhoPagina
        titulo={<span><RobotOutlined /> Assistente IA — Regras em ficheiro</span>}
        subtitulo="Os dois motores usam o mesmo ficheiro: o motor interno pede-lhe os valores, a IA externa lê-os do documento. O cálculo e as validações são sempre feitos pelo ERP e nada é gravado sem revisão."
        excel={false}
      />
      <Tabs
        activeKey={separador}
        onChange={setSeparador}
        items={[
          { key: 'regras', label: 'Regras em ficheiro', children: <ListaRegras aoUsar={usar} /> },
          ...(lancar
            ? [
                { key: 'interno', label: 'Motor interno', children: <MotorInterno regraId={regraEscolhida} aoEscolher={setRegraEscolhida} /> },
                { key: 'externo', label: 'IA externa', children: <IAExterna regraId={regraEscolhida} aoEscolher={setRegraEscolhida} /> },
              ]
            : []),
        ]}
      />
    </>
  );
}

// ---------------------------------------------------------------- lista de regras

function ListaRegras({ aoUsar }: { aoUsar: (id: number, motor: 'interno' | 'externo') => void }) {
  const { pode } = useSessao();
  const gerir = pode('ia_regras');
  const lancar = pode('ia_lancar');
  const cliente = useQueryClient();
  const lista = useQuery({ queryKey: CHAVE_REGRAS, queryFn: () => obter<FicheiroRegra[]>(URL_REGRAS) });
  const [ver, setVer] = useState<number | null>(null);
  const actualizar = () => void cliente.invalidateQueries({ queryKey: CHAVE_REGRAS });
  const [substituir, setSubstituir] = useState<{ ficheiro: File; mensagem: string } | null>(null);

  const importar = useMutation({
    mutationFn: async ({ ficheiro, substituir }: { ficheiro: File; substituir: boolean }) => {
      const dados = new FormData();
      dados.append('ficheiro', ficheiro);
      if (substituir) dados.append('substituir', '1');
      const r = await http.post<Envelope<FicheiroRegra>>(URL_REGRAS, dados);
      return r.data;
    },
    onSuccess: (r) => {
      message.success(r.mensagem);
      if (r.dados.avisos?.length) message.warning(r.dados.avisos.join(' '));
      actualizar();
    },
    onError: (e, v) => {
      if (e instanceof ErroApi && e.codigo === 'IA_REGRA_EXISTE') {
        setSubstituir({ ficheiro: v.ficheiro, mensagem: e.message });
        return;
      }
      notificarErro(e, 'Não foi possível importar o ficheiro');
    },
  });
  const alterar = useMutation({
    mutationFn: ({ id, ...d }: { id: number; ativo?: boolean; motor?: Motor }) => http.patch<Envelope<FicheiroRegra>>(`${URL_REGRAS}/${id}`, d),
    onSuccess: actualizar,
    onError: (e) => notificarErro(e),
  });
  const eliminar = useMutation({
    mutationFn: (id: number) => enviar('delete', `${URL_REGRAS}/${id}`),
    onSuccess: ({ mensagem }) => {
      message.success(mensagem);
      actualizar();
    },
    onError: (e) => notificarErro(e),
  });
  const exportar = (id: number) =>
    obter<{ ficheiro: string; conteudo: string }>(`${URL_REGRAS}/${id}/exportar`)
      .then((f) => descarregarJson(f.ficheiro, f.conteudo))
      .catch((e) => notificarErro(e));
  const modelo = () =>
    obter<{ ficheiro: string; conteudo: string }>(`${URL_REGRAS}/modelo`)
      .then((f) => descarregarJson(f.ficheiro, f.conteudo))
      .catch((e) => notificarErro(e));

  return (
    <>
      <Flex gap={8} wrap style={{ marginBottom: 12 }}>
        {gerir && (
          <Upload
            accept=".json,application/json"
            showUploadList={false}
            beforeUpload={(f) => {
              if (f.size > MAX_BYTES_REGRA) message.error('O ficheiro é demasiado grande (máximo 256 KB).');
              else importar.mutate({ ficheiro: f, substituir: false });
              return false;
            }}
          >
            <Button type="primary" icon={<FileAddOutlined />} loading={importar.isPending}>
              Importar ficheiro de regras
            </Button>
          </Upload>
        )}
        <Button icon={<DownloadOutlined />} onClick={modelo}>
          Descarregar modelo
        </Button>
      </Flex>
      <Table<FicheiroRegra>
        size="small"
        rowKey="id"
        loading={lista.isLoading}
        dataSource={lista.data}
        pagination={false}
        scroll={scrollTabela()}
        locale={{ emptyText: <Empty description="Ainda não há regras importadas. Importe um ficheiro JSON ou descarregue o modelo para começar." /> }}
        columns={[
          {
            title: 'Regra',
            key: 'nome',
            render: (_: unknown, r) => (
              <div>
                <Space size={4} wrap>
                  <strong>{r.nome}</strong>
                  <Tag>v{r.versao}</Tag>
                  {!r.ativo && <Tag>inactiva</Tag>}
                  {r.problema && <Tag color="red">inválida</Tag>}
                </Space>
                <div><Typography.Text type="secondary">{r.problema ?? r.descricao}</Typography.Text></div>
                {!!r.contas_em_falta?.length && <Typography.Text type="danger">Contas que não existem no plano: {r.contas_em_falta.join(', ')}</Typography.Text>}
                {!!r.contas_totalizadoras?.length && <div><Typography.Text type="warning">Contas totalizadoras: {r.contas_totalizadoras.join(', ')}</Typography.Text></div>}
              </div>
            ),
          },
          { title: 'Ficheiro', key: 'f', responsive: ['md'], render: (_: unknown, r) => <span>{r.ficheiro}<br /><Typography.Text type="secondary">{[r.linhas ? `${r.linhas} linha(s)` : null, r.unidade, r.diario ? `diário ${r.diario}` : null].filter(Boolean).join(' · ')}</Typography.Text></span> },
          {
            title: 'Motor',
            key: 'motor',
            render: (_: unknown, r) => (
              <Select<Motor>
                size="small"
                value={r.motor}
                disabled={!gerir}
                style={{ minWidth: 160 }}
                aria-label={`Motores onde a regra ${r.nome} aparece`}
                options={(Object.keys(ROTULO_MOTOR) as Motor[]).map((m) => ({ value: m, label: ROTULO_MOTOR[m] }))}
                onChange={(motor) => alterar.mutate({ id: r.id, motor })}
              />
            ),
          },
          {
            title: 'Activa',
            key: 'ativo',
            render: (_: unknown, r) => <Switch size="small" checked={r.ativo} disabled={!gerir} aria-label={`Regra ${r.nome} activa`} onChange={(ativo) => alterar.mutate({ id: r.id, ativo })} />,
          },
          {
            title: '',
            key: 'accoes',
            render: (_: unknown, r) => (
              <Space size={2} wrap>
                <Button size="small" type="text" icon={<EyeOutlined />} aria-label="Ver a regra" title="Ver a regra" onClick={() => setVer(r.id)} />
                <Button size="small" type="text" icon={<DownloadOutlined />} aria-label="Exportar" title="Exportar o ficheiro" onClick={() => exportar(r.id)} />
                {lancar && !r.problema && usaMotor(r, 'INTERNA') && <Button size="small" onClick={() => aoUsar(r.id, 'interno')}>Motor interno</Button>}
                {lancar && !r.problema && usaMotor(r, 'EXTERNA') && <Button size="small" onClick={() => aoUsar(r.id, 'externo')}>IA externa</Button>}
                {gerir && (
                  <Popconfirm title={`Eliminar a regra «${r.nome}»?`} description="O ficheiro pode voltar a ser importado depois." okText="Eliminar" cancelText="Cancelar" onConfirm={() => eliminar.mutateAsync(r.id)}>
                    <Button size="small" type="text" danger icon={<DeleteOutlined />} aria-label="Eliminar" title="Eliminar" />
                  </Popconfirm>
                )}
              </Space>
            ),
          },
        ]}
      />
      <VerRegra id={ver} aoFechar={() => setVer(null)} />
      <Modal
        title="Regra já existente"
        open={!!substituir}
        okText="Substituir"
        cancelText="Cancelar"
        confirmLoading={importar.isPending}
        onCancel={() => setSubstituir(null)}
        onOk={() => substituir && importar.mutateAsync({ ficheiro: substituir.ficheiro, substituir: true }).catch(() => undefined).finally(() => setSubstituir(null))}
      >
        {substituir?.mensagem}
      </Modal>
    </>
  );
}

function VerRegra({ id, aoFechar }: { id: number | null; aoFechar: () => void }) {
  const detalhe = useQuery({ queryKey: [...CHAVE_REGRAS, id], queryFn: () => obter<DetalheRegra>(`${URL_REGRAS}/${id}`), enabled: id !== null });
  const d = detalhe.data;
  return (
    <Drawer title={d ? `${d.nome} (v${d.versao})` : 'Regra'} open={id !== null} onClose={aoFechar} width={larguraGaveta(760)} destroyOnHidden>
      {detalhe.isError && <Alert type="error" showIcon message="Não foi possível ler a regra" description={(detalhe.error as Error).message} />}
      {d && (
        <>
          <Typography.Paragraph type="secondary">{d.descricao}</Typography.Paragraph>
          <Descriptions size="small" column={1} bordered items={[
            { key: 'p', label: 'Valores pedidos', children: d.entradas.map((e) => e.nome).join(', ') || '—' },
            { key: 'c', label: 'Calculados pela regra', children: d.calculadas.join(', ') || '—' },
            { key: 'd', label: 'Diário', children: d.diario ?? '—' },
            { key: 'i', label: 'Importado', children: [d.importado_em ? dayjs(d.importado_em).format('DD/MM/YYYY HH:mm') : null, d.importado_por].filter(Boolean).join(' · ') || '—' },
          ]} />
          {!!d.avisos?.length && <Alert type="info" showIcon style={{ marginTop: 12 }} message={d.avisos.join(' ')} />}
          <pre className="erp-ia-json" style={{ marginTop: 12, maxHeight: '55vh', overflow: 'auto', background: '#0f172a', color: '#e2e8f0', padding: 12, borderRadius: 4, fontSize: 12 }}>
            {JSON.stringify(d.conteudo, null, 2)}
          </pre>
        </>
      )}
    </Drawer>
  );
}

// ---------------------------------------------------------------- escolha da regra (motores)

function SeletorRegra({ motor, regraId, aoEscolher }: { motor: 'INTERNA' | 'EXTERNA'; regraId: number | null; aoEscolher: (id: number | null) => void }) {
  const lista = useQuery({ queryKey: CHAVE_REGRAS, queryFn: () => obter<FicheiroRegra[]>(URL_REGRAS) });
  const opcoes = (lista.data ?? []).filter((r) => !r.problema && usaMotor(r, motor));
  const valor = opcoes.some((r) => r.id === regraId) ? regraId : null;
  return (
    <Select<number>
      style={{ width: '100%', maxWidth: 520 }}
      placeholder="Escolha a regra"
      aria-label="Regra"
      loading={lista.isLoading}
      value={valor ?? undefined}
      onChange={(v) => aoEscolher(v)}
      options={opcoes.map((r) => ({ value: r.id, label: `${r.nome} (v${r.versao})${r.unidade ? ` — ${r.unidade}` : ''}` }))}
      notFoundContent={motor === 'INTERNA' ? 'Sem regras activas para o motor interno.' : 'Sem regras activas para a IA externa.'}
    />
  );
}

// ---------------------------------------------------------------- motor interno

function MotorInterno({ regraId, aoEscolher }: { regraId: number | null; aoEscolher: (id: number | null) => void }) {
  const detalhe = useQuery({ queryKey: [...CHAVE_REGRAS, regraId], queryFn: () => obter<DetalheRegra>(`${URL_REGRAS}/${regraId}`), enabled: regraId !== null });
  const [dias, setDias] = useState<DiaFormulario[]>([novoDia(dayjs().format('YYYY-MM-DD'))]);
  const calcular = useMutation({
    mutationFn: () => enviar<ResultadoCalculo>('post', `${URL_REGRAS}/${regraId}/calcular`, pedidoCalculo(dias, detalhe.data?.entradas ?? [])),
    onError: (e) => notificarErro(e, 'Não foi possível calcular'),
  });
  const mudar = (i: number, d: Partial<DiaFormulario>) => setDias((l) => l.map((x, k) => (k === i ? { ...x, ...d } : x)));
  const r = detalhe.data;
  return (
    <Space direction="vertical" size={12} style={{ width: '100%' }}>
      <SeletorRegra motor="INTERNA" regraId={regraId} aoEscolher={(id) => { aoEscolher(id); calcular.reset(); }} />
      {r && (
        <>
          <Typography.Text type="secondary">
            {r.descricao} Introduza os valores lidos no {r.documento ?? 'documento'}: o ERP calcula o resto, valida e propõe o lançamento.
          </Typography.Text>
          {dias.map((d, i) => (
            <Card key={i} size="small" title={
              <Flex align="center" gap={8} wrap>
                <span>Dia</span>
                <DatePicker size="small" format="DD/MM/YYYY" allowClear={false} value={dayjs(d.data)} aria-label={`Data do dia ${i + 1}`}
                  onChange={(v) => v && mudar(i, { data: v.format('YYYY-MM-DD') })} />
              </Flex>
            } extra={dias.length > 1 && <Button size="small" type="text" danger aria-label="Retirar o dia" onClick={() => setDias((l) => l.filter((_, k) => k !== i))}>Retirar</Button>}>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: 10 }}>
                {r.entradas.map((e) => (
                  <label key={e.nome} style={{ display: 'grid', gap: 2 }}>
                    <span style={{ fontSize: 12, fontWeight: 600 }}>{e.rotulo}</span>
                    <InputNumber<number> style={{ width: '100%' }} decimalSeparator="," step={0.01} placeholder="0,00" value={d.valores[e.nome] ?? null}
                      aria-label={e.rotulo} title={e.ajuda || e.nome} onChange={(v) => mudar(i, { valores: { ...d.valores, [e.nome]: typeof v === 'number' ? v : null } })} />
                    {e.ajuda && <Typography.Text type="secondary" style={{ fontSize: 11 }}>{e.ajuda}</Typography.Text>}
                  </label>
                ))}
              </div>
              <Collapse ghost size="small" style={{ marginTop: 8 }} items={[{
                key: 'p', label: 'Pontos a confirmar (métodos não mapeados, valores ilegíveis, sem IVA impresso)',
                children: (
                  <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: 10 }}>
                    <Input placeholder="Método não mapeado (ex.: cheque)" aria-label="Método não mapeado" value={d.outroMetodo} maxLength={100} onChange={(ev) => mudar(i, { outroMetodo: ev.target.value })} />
                    <InputNumber<number> style={{ width: '100%' }} min={0} placeholder="Valor" aria-label="Valor do método não mapeado" value={d.outroValor} onChange={(v) => mudar(i, { outroValor: v ?? null })} />
                    <Input placeholder="Valores ilegíveis" aria-label="Valores ilegíveis" value={d.ilegivel} maxLength={500} onChange={(ev) => mudar(i, { ilegivel: ev.target.value })} />
                    <InputNumber<number> style={{ width: '100%' }} placeholder="Sem IVA impresso no documento" aria-label="Sem IVA impresso no documento" value={d.netImpresso} onChange={(v) => mudar(i, { netImpresso: v ?? null })} />
                  </div>
                ),
              }]} />
            </Card>
          ))}
          <Flex gap={8} wrap>
            {r.por_dia && dias.length < 31 && (
              <Button icon={<PlusOutlined />} onClick={() => setDias((l) => [...l, novoDia(dayjs(l[l.length - 1]?.data).add(1, 'day').format('YYYY-MM-DD'))])}>Outro dia</Button>
            )}
            <Button type="primary" loading={calcular.isPending} onClick={() => calcular.mutate()}>Calcular e propor</Button>
          </Flex>
        </>
      )}
      {calcular.data && <ResultadosDias resultado={calcular.data.dados} colunas={r?.colunas} />}
    </Space>
  );
}

// ---------------------------------------------------------------- IA externa

function IAExterna({ regraId, aoEscolher }: { regraId: number | null; aoEscolher: (id: number | null) => void }) {
  const estado = useQuery({ queryKey: [...CHAVE_REGRAS, 'estado'], queryFn: () => obter<EstadoIA>(`${URL_REGRAS}/estado`) });
  const [ficheiro, setFicheiro] = useState<File | null>(null);
  const [texto, setTexto] = useState('');
  const ler = useMutation({
    mutationFn: async () => {
      const dados = new FormData();
      if (ficheiro) dados.append('ficheiro', ficheiro);
      if (texto.trim()) dados.append('texto', texto.trim());
      const r = await http.post<Envelope<ResultadoCalculo>>(`${URL_REGRAS}/${regraId}/extrair`, dados, { timeout: 180_000 });
      return r.data.dados;
    },
    onError: (e) => notificarErro(e, 'A IA não conseguiu ler o documento'),
  });
  const disponivel = !!estado.data?.ia_disponivel;
  return (
    <Space direction="vertical" size={12} style={{ width: '100%' }}>
      {estado.data && !disponivel && (
        <Alert type="info" showIcon message={estado.data.configurado ? 'A IA está desligada nesta empresa' : 'A IA não está configurada no servidor'}
          description="O motor interno continua disponível. Um administrador activa a IA em «Assistente IA › Configuração» (menu do utilizador); a chave do fornecedor fica sempre no servidor." />
      )}
      <Typography.Text type="secondary">
        A IA só LÊ os valores pedidos pela regra no documento anexado: não escolhe contas nem faz contas. O cálculo, as validações e a proposta são feitos pelo ERP
        com a regra, como no motor interno.
      </Typography.Text>
      <SeletorRegra motor="EXTERNA" regraId={regraId} aoEscolher={(id) => { aoEscolher(id); ler.reset(); }} />
      <Upload.Dragger
        accept=".pdf,.png,.jpg,.jpeg,.webp,.gif"
        maxCount={1}
        disabled={!disponivel}
        beforeUpload={(f) => {
          setFicheiro(f);
          return false;
        }}
        onRemove={() => setFicheiro(null)}
        fileList={ficheiro ? [{ uid: '1', name: ficheiro.name, status: 'done' }] : []}
      >
        <p className="ant-upload-drag-icon"><InboxOutlined /></p>
        <p>Arraste o documento (PDF ou imagem, até 10 MB) ou clique para escolher</p>
      </Upload.Dragger>
      <Input.TextArea rows={2} maxLength={4000} value={texto} onChange={(e) => setTexto(e.target.value)} disabled={!disponivel}
        placeholder="Indicação opcional (ex.: «Faz o lançamento do fecho de caixa dos dias 20 a 22»)" aria-label="Indicação para a IA" />
      <div>
        <Button type="primary" icon={<RobotOutlined />} disabled={!disponivel || !ficheiro || regraId === null} loading={ler.isPending} onClick={() => ler.mutate()}>
          Ler o documento e propor
        </Button>
      </div>
      {ler.data && <ResultadosDias resultado={ler.data} />}
    </Space>
  );
}

// ---------------------------------------------------------------- resultados (comuns aos dois motores)

function ResultadosDias({ resultado, colunas }: { resultado: ResultadoCalculo; colunas?: string[] }) {
  const navegar = useNavigate();
  const { menu, pode } = useSessao();
  const podeAbrir = menu.some((m) => m.id === 'contab' && m.ecras.some((e) => e.id === 'lancamentos')) && pode('lancamentos_post');
  const titulos = useMemo(() => (colunas?.length === 6 ? colunas : ['Conta', 'D/C', 'Valor', 'Nota demo', 'Nota fluxo', 'Descrição']), [colunas]);
  const [confirmar, setConfirmar] = useState<ResultadoDia | null>(null);
  const seguir = (d: ResultadoDia) => d.proposta && navegar('/m/contab/lancamentos/novo', { state: propostaParaFormulario({ ...d.proposta, origem: 'REGRAS' }) });
  // validações da regra por cumprir: pede confirmação antes de abrir o formulário (como o legado)
  const abrir = (d: ResultadoDia) => (validacoesFalhadas(d) ? setConfirmar(d) : seguir(d));
  return (
    <div>
      <Modal
        title="Validações por cumprir"
        open={!!confirmar}
        okText="Continuar"
        cancelText="Cancelar"
        onCancel={() => setConfirmar(null)}
        onOk={() => {
          if (confirmar) seguir(confirmar);
          setConfirmar(null);
        }}
      >
        {confirmar && `${validacoesFalhadas(confirmar)} validação(ões) da regra não passaram. Quer mesmo rever este lançamento no formulário?`}
      </Modal>
      <Typography.Text type="secondary">
        {resultado.motor === 'INTERNA' ? 'Motor interno' : 'Valores lidos pela IA, cálculo do ERP'} · regra «{resultado.regra.nome}» v{resultado.regra.versao} · {resultado.propostas_validas} proposta(s). Nada foi gravado.
      </Typography.Text>
      {resultado.dias.map((d, i) => (
        <Card key={i} size="small" style={{ marginTop: 12 }}
          title={`Referência: ${d.referencia || '—'} — ${dayjs(d.data).format('DD/MM/YYYY')}`}
          extra={d.erros.length ? <Tag color="red">Com erros</Tag> : d.total_debito === d.total_credito ? <Tag color="green">Total D = Total C</Tag> : null}>
          <Table<LinhaCalculada>
            size="small"
            rowKey={(_, n) => String(n)}
            pagination={false}
            scroll={scrollTabela()}
            dataSource={d.linhas}
            columns={[
              { title: titulos[0], dataIndex: 'codigo_conta' },
              { title: titulos[1], dataIndex: 'tipo_dc', align: 'center' },
              { title: titulos[2], dataIndex: 'valor', align: 'right', render: (v: number) => formatarKz(v) },
              { title: titulos[3], dataIndex: 'nota_demonstracao', responsive: ['md'] },
              { title: titulos[4], dataIndex: 'nota_fluxo', responsive: ['md'] },
              { title: titulos[5], dataIndex: 'descricao', responsive: ['lg'] },
            ]}
            summary={() => (
              <Table.Summary.Row>
                <Table.Summary.Cell index={0} colSpan={2}><strong>Total D / Total C</strong></Table.Summary.Cell>
                <Table.Summary.Cell index={2} align="right">
                  <Typography.Text strong type={d.total_debito === d.total_credito ? 'success' : 'danger'}>{formatarKz(d.total_debito)} / {formatarKz(d.total_credito)}</Typography.Text>
                </Table.Summary.Cell>
              </Table.Summary.Row>
            )}
          />
          {d.validacoes.length > 0 && (
            <ul aria-label="Validações da regra" style={{ listStyle: 'none', paddingInlineStart: 0, margin: '8px 0 0', display: 'grid', gap: 2 }}>
              {d.validacoes.map((v, k) => (
                <li key={k}>
                  {v.ok === true ? <CheckCircleTwoTone twoToneColor="#16a34a" /> : v.ok === false ? <CloseCircleTwoTone twoToneColor="#dc2626" /> : <QuestionCircleTwoTone twoToneColor="#b45309" />}{' '}
                  <span>{v.texto}</span> <Typography.Text type="secondary">{v.detalhe}</Typography.Text>
                </li>
              ))}
            </ul>
          )}
          {d.pontos.length > 0 && <Alert style={{ marginTop: 8 }} type="warning" showIcon message="Pontos a confirmar" description={<ul style={{ margin: 0, paddingInlineStart: 18 }}>{d.pontos.map((p) => <li key={p}>{p}</li>)}</ul>} />}
          {d.erros.length > 0 && <Alert style={{ marginTop: 8 }} type="error" showIcon message="Corrija antes de propor" description={<ul style={{ margin: 0, paddingInlineStart: 18 }}>{d.erros.map((p) => <li key={p}>{p}</li>)}</ul>} />}
          {d.proposta && d.proposta.avisos.length > 0 && (
            <Alert style={{ marginTop: 8 }} type="info" showIcon message="Avisos do plano de contas" description={<ul style={{ margin: 0, paddingInlineStart: 18 }}>{d.proposta.avisos.map((p) => <li key={p}>{p}</li>)}</ul>} />
          )}
          <Flex justify="end" style={{ marginTop: 8 }}>
            <Button type="primary" disabled={!d.proposta || !podeAbrir} title={podeAbrir ? undefined : 'Sem permissão para gravar lançamentos'} onClick={() => abrir(d)}>
              Rever no formulário de lançamento
            </Button>
          </Flex>
        </Card>
      ))}
    </div>
  );
}
