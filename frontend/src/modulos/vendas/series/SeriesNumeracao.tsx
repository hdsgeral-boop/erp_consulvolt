import { Alert, Button, Card, Checkbox, Col, Flex, Form, Input, InputNumber, Modal, Row, Select, Skeleton, Space, Table, Tag, Tooltip, Typography, theme } from 'antd';
import { DeleteOutlined, EditOutlined, LockOutlined, PlusOutlined, UnlockOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useEffect, useMemo, useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { ModalFormulario, type CampoFormulario } from '@/componentes/formularios';
import { useAccao } from '@/componentes/Accoes';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarData } from '@/utilitarios/formatacao';
import {
  TIPOS_SERIE, codigoSugerido, erroCodigo, erroNumeroInicial, outraActiva, podeAlterarNumeracao, podeEliminar, proximoDocumento, textoCartao,
  type DadosSeries, type SerieNumeracao,
} from './regrasSeries';

const CHAVE = ['vendas', 'series-numeracao'];

interface FormSerie {
  tipo: string;
  ano: number;
  origem: string;
  codigo: string;
  proximo_numero: number;
  estabelecimento: string | null;
  estado: 'ATIVA' | 'FECHADA';
  contingencia: boolean;
}

/**
 * Vendas › Séries de numeração (js/vendas_series.js:63 renderVendasSeriesTab do legado novo): cartões FT / FR / NC com a
 * série actual, «+ Nova série» e a tabela das séries. O número inicial só se atribui enquanto a série não emitiu nada;
 * uma série com documentos não se apaga, fecha-se. Gerir exige vendas_series_gerir (ou vendas_fe_config, de quem configura a AGT).
 */
function CartaoTipoSerie({ tipo, nome, numero, nota, atribuida }: { tipo: string; nome: string; numero: string; nota: string; atribuida: boolean }) {
  const { token } = theme.useToken();
  return (
    <div style={{ border: `1px solid ${atribuida ? token.colorSuccessBorder : token.colorWarningBorder}`, background: atribuida ? token.colorSuccessBg : token.colorWarningBg, borderRadius: 4, padding: '0.8rem 0.9rem' }}>
      <Typography.Text type="secondary" style={{ fontSize: '0.78rem', textTransform: 'uppercase', letterSpacing: '0.04em', fontWeight: 700 }}>{tipo} · {nome}</Typography.Text>
      <div style={{ fontSize: '1.05rem', fontWeight: 700, fontFamily: 'ui-monospace, monospace', margin: '0.35rem 0 0.15rem' }}>{numero}</div>
      <Typography.Text style={{ fontSize: '0.8rem', color: atribuida ? token.colorSuccessText : token.colorWarningText }}>{nota}</Typography.Text>
    </div>
  );
}

export default function SeriesNumeracao() {
  const { pode } = useSessao();
  const podeGerir = pode('vendas_series_gerir', 'vendas_fe_config');
  const consulta = useQuery({ queryKey: CHAVE, queryFn: () => obter<DadosSeries>('/vendas/series') });
  const [edicao, setEdicao] = useState<SerieNumeracao | 'nova' | null>(null);
  const accao = useAccao({ invalidar: [CHAVE, ['vendas', 'fe', 'series']], aoSucesso: () => setEdicao(null) });

  if (consulta.isLoading) return <Skeleton active />;
  const dados = consulta.data;
  if (!dados) return <Alert type="error" showIcon message="Não foi possível obter as séries." />;
  const rotuloOrigem = (o: string) => dados.origens.find((x) => x.valor === o)?.rotulo ?? o;

  const mudarEstado = (s: SerieNumeracao) => {
    const fechar = s.estado === 'ATIVA';
    Modal.confirm({
      title: fechar ? `Fechar a série ${s.tipo} ${s.codigo} (${s.ano})?` : `Reabrir a série ${s.tipo} ${s.codigo} (${s.ano})?`,
      content: fechar
        ? 'Deixa de dar números. Os documentos já emitidos não são afectados. Para voltar a numerar este tipo de documento tem de abrir outra série ou reabrir esta.'
        : `Volta a dar números a partir do ${s.proximo_numero}. Só pode haver uma série activa por tipo, ano e origem.`,
      okText: fechar ? 'Fechar série' : 'Reabrir série',
      cancelText: 'Cancelar',
      onOk: () => accao.mutateAsync({
        metodo: 'put', url: `/vendas/series/${s.id}`,
        dados: { tipo: s.tipo, ano: s.ano, origem: s.origem, codigo: s.codigo, estabelecimento: s.estabelecimento, contingencia: s.contingencia, estado: fechar ? 'FECHADA' : 'ATIVA' },
      }),
    });
  };

  return (
    <>
      <CabecalhoPagina
        titulo="Séries de numeração"
        subtitulo="Facturas, facturas-recibo e notas de crédito de cliente: código da série e número de arranque"
        accoes={podeGerir && <Button type="primary" icon={<PlusOutlined />} onClick={() => setEdicao('nova')}>Nova série</Button>}
      />
      <Flex vertical gap={16}>
        <Card>
          <Typography.Paragraph type="secondary" style={{ marginTop: 0 }}>
            A série dá o número ao documento, no formato <code>TIPO SÉRIE/N</code> (ex.: <code>FT A{dados.ano}/1</code>). Pode atribuir o código e o número
            de arranque — útil para continuar a numeração que já vem de outro sistema ou de um livro.
          </Typography.Paragraph>
          {/* cartões do antigo (vendas_series.js:121-130): grelha auto-fit de 240 px, verde com série, âmbar sem série */}
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(240px, 100%), 1fr))', gap: '0.9rem' }}>
            {dados.resumo.map((r) => (
              <CartaoTipoSerie key={r.tipo} tipo={r.tipo} nome={r.nome} {...textoCartao(r, dados.regime)} />
            ))}
          </div>
          <Alert
            style={{ marginTop: 12 }}
            type={dados.regime.ativo ? 'info' : undefined}
            showIcon
            message={dados.regime.ativo
              ? `O regime de facturação electrónica está activo desde ${formatarData(dados.regime.data_inicio)}: as facturas com data a partir daí são seladas e usam estas séries.`
              : 'O regime de facturação electrónica não está activo. As séries que criar aqui já numeram os documentos e continuam a valer quando o regime arrancar — sem saltos na numeração.'}
          />
        </Card>

        <Card title={<><IconeFa nome="hashtag" style={{ marginRight: 6 }} />Séries registadas</>}>
          <Table<SerieNumeracao>
            rowKey="id"
            size="small"
            dataSource={dados.series}
            pagination={false}
            scroll={scrollTabela()}
            locale={{ emptyText: `Ainda não há séries. Sem série, o sistema numera os documentos sozinho (série A${dados.ano}).` }}
            rowClassName={(s) => (s.estado === 'FECHADA' ? 'erp-linha-inactiva' : '')}
            columns={[
              { title: 'Tipo', dataIndex: 'tipo', render: (t: string, s) => <Space size={4}><Tag>{t}</Tag><Typography.Text type="secondary" style={{ fontSize: 12 }}>{s.tipo_nome}</Typography.Text></Space> },
              { title: 'Ano', dataIndex: 'ano', align: 'center' },
              { title: 'Origem', dataIndex: 'origem', responsive: ['md'], render: (o: string, s) => s.origem_rotulo ?? rotuloOrigem(o) },
              { title: 'Código', dataIndex: 'codigo', render: (v: string) => <code style={{ fontWeight: 700 }}>{v}</code> },
              { title: <Tooltip title="Estabelecimento (n.º atribuído pela AGT)">Estab.</Tooltip>, dataIndex: 'estabelecimento', align: 'center', responsive: ['md'], render: (v: string | null) => v || '—' },
              { title: <Tooltip title="Número que o próximo documento vai levar (número de arranque enquanto a série não emitiu nada)">Próximo n.º</Tooltip>, dataIndex: 'proximo_numero', align: 'right' },
              { title: 'Próximo documento', dataIndex: 'proximo_documento', render: (v: string) => <code>{v}</code> },
              { title: 'Emitidos', dataIndex: 'emitidos', align: 'right', render: (n: number) => n || '—' },
              { title: 'Último doc.', key: 'ultimo', responsive: ['lg'], render: (_, s) => (s.ultimo_documento ? <span>{s.ultimo_documento}<br /><Typography.Text type="secondary" style={{ fontSize: 12 }}>{formatarData(s.ultima_data)}</Typography.Text></span> : '—') },
              {
                title: 'Estado', dataIndex: 'estado', align: 'center',
                render: (e: string, s) => (
                  <Space size={4} wrap>
                    {e === 'ATIVA' ? <Tag color="green">Activa</Tag> : <Tag>Fechada</Tag>}
                    {s.contingencia && <Tooltip title="Série de contingência"><Tag color="orange">Cont.</Tag></Tooltip>}
                  </Space>
                ),
              },
              { title: <Tooltip title="Código da série atribuído pela AGT">AGT</Tooltip>, dataIndex: 'agt_codigo', align: 'center', responsive: ['md'], render: (v: string | null) => (v ? <Tag color="blue">{v}</Tag> : '—') },
              {
                title: 'Acções', key: 'accoes', align: 'right',
                render: (_, s) => podeGerir && (
                  <Space wrap={false}>
                    <Tooltip title="Alterar a série"><Button size="small" icon={<EditOutlined />} aria-label={`Alterar a série ${s.tipo} ${s.codigo}`} onClick={() => setEdicao(s)} /></Tooltip>
                    <Tooltip title={s.estado === 'ATIVA' ? 'Fechar a série (deixa de dar números)' : 'Reabrir a série'}>
                      <Button size="small" icon={s.estado === 'ATIVA' ? <LockOutlined /> : <UnlockOutlined />} aria-label={s.estado === 'ATIVA' ? 'Fechar' : 'Reabrir'} onClick={() => mudarEstado(s)} />
                    </Tooltip>
                    {podeEliminar(s) && (
                      <Tooltip title="Eliminar (só sem documentos emitidos)">
                        <Button
                          size="small"
                          danger
                          icon={<DeleteOutlined />}
                          aria-label={`Eliminar a série ${s.tipo} ${s.codigo}`}
                          onClick={() => Modal.confirm({
                            title: `Eliminar a série ${s.tipo} ${s.codigo} (${s.ano})?`,
                            content: 'Só é possível porque ainda não emitiu nenhum documento.',
                            okText: 'Eliminar', okButtonProps: { danger: true }, cancelText: 'Cancelar',
                            onOk: () => accao.mutateAsync({ metodo: 'delete', url: `/vendas/series/${s.id}` }),
                          })}
                        />
                      </Tooltip>
                    )}
                  </Space>
                ),
              },
            ]}
          />
        </Card>
      </Flex>
      <FormularioSerie
        edicao={edicao}
        dados={dados}
        aGravar={accao.isPending}
        aoFechar={() => setEdicao(null)}
        aoGravar={(v) => {
          const corpo = dadosSerie(v as unknown as Record<string, unknown>);
          accao.mutate(edicao && edicao !== 'nova' ? { metodo: 'put', url: `/vendas/series/${edicao.id}`, dados: corpo } : { url: '/vendas/series', dados: corpo });
        }}
      />
    </>
  );
}

/**
 * Corpo do pedido da série — o mesmo na grade e na vista Linhas. Nas linhas, o código em branco recebe o código sugerido
 * (A<ano> ou <terminal><ano>), como a grade o preenche enquanto não é escrito à mão.
 */
export function dadosSerie(v: Record<string, unknown>): FormSerie {
  const ano = Number(v.ano ?? dayjs().year());
  const origem = String(v.origem || 'GERAL');
  // grade: `codigo` (preenchido com a sugestão); linhas: `codigo_serie` (em branco = a sugestão da origem e do ano da linha)
  const codigo = String(v.codigo ?? v.codigo_serie ?? '').trim() || codigoSugerido(origem, ano);
  // grade: caixa de selecção (`contingencia`); linhas: Sim/Não (`contingencia_sn`)
  const contingencia = v.contingencia === true || v.contingencia_sn === 'S';
  return {
    tipo: String(v.tipo ?? 'FT'),
    ano,
    origem,
    codigo,
    proximo_numero: v.proximo_numero === null || v.proximo_numero === undefined || v.proximo_numero === '' ? 1 : Number(v.proximo_numero),
    estabelecimento: (v.estabelecimento as string | null | undefined) || null,
    estado: v.estado === 'FECHADA' ? 'FECHADA' : 'ATIVA',
    contingencia,
  };
}

/** Colunas da vista Linhas das séries (as regras da grade: código, número inicial e uma só série activa por tipo, ano e origem). */
export function camposSerie(dados: DadosSeries): CampoFormulario[] {
  return [
    { nome: 'tipo', rotulo: 'Tipo de documento', tipo: 'select', obrigatorio: true, omissao: 'FT', largura: 170, aliases: ['Tipo'],
      opcoes: TIPOS_SERIE.map((t) => ({ value: t.tipo, label: `${t.tipo} — ${t.nome}` })) },
    { nome: 'ano', rotulo: 'Ano', tipo: 'numero', obrigatorio: true, min: 2000, max: 2100, precisao: 0, omissao: () => dayjs().year(), largura: 90 },
    { nome: 'origem', rotulo: 'Origem', tipo: 'select', obrigatorio: true, omissao: 'GERAL', largura: 170, opcoes: dados.origens.map((o) => ({ value: o.valor, label: o.rotulo })) },
    { nome: 'codigo_serie', rotulo: 'Código da série', maxLength: 30, largura: 130, aliases: ['Código', 'Série'], placeholder: 'Sugerido',
      validar: (c) => (String(c ?? '').trim() ? erroCodigo(String(c)) : null) },
    { nome: 'proximo_numero', rotulo: 'Número de arranque', tipo: 'numero', min: 1, max: 99_999_999, precisao: 0, omissao: 1, largura: 120, aliases: ['Número inicial', 'Próximo n.º'],
      validar: (n) => erroNumeroInicial(n as number | null) },
    dados.estabelecimentos.length
      ? { nome: 'estabelecimento', rotulo: 'Estabelecimento', tipo: 'select', largura: 150, omissao: dados.estabelecimentos[0]?.numero ?? null,
          opcoes: dados.estabelecimentos.map((e) => ({ value: e.numero, label: e.nome ? `${e.numero} — ${e.nome}` : e.numero })) }
      : { nome: 'estabelecimento', rotulo: 'Estabelecimento', maxLength: 20, largura: 120, emLinhas: 'opcional' },
    { nome: 'estado', rotulo: 'Estado', tipo: 'select', omissao: 'ATIVA', largura: 120,
      opcoes: [{ value: 'ATIVA', label: 'Activa' }, { value: 'FECHADA', label: 'Fechada' }],
      validar: (_, r) => {
        const d = dadosSerie(r);
        const outra = outraActiva(dados.series, { tipo: d.tipo, ano: d.ano, origem: d.origem, estado: d.estado });
        return outra ? `Já existe outra série activa para este tipo, ano e origem (${outra.codigo}).` : null;
      } },
    { nome: 'contingencia_sn', rotulo: 'Contingência', tipo: 'select', omissao: 'N', largura: 110, emLinhas: 'opcional', seccao: 'AGT',
      opcoes: [{ value: 'N', label: 'Não' }, { value: 'S', label: 'Sim' }] },
  ];
}

function FormularioSerie({ edicao, dados, aGravar, aoFechar, aoGravar }: {
  edicao: SerieNumeracao | 'nova' | null;
  dados: DadosSeries;
  aGravar: boolean;
  aoFechar: () => void;
  aoGravar: (v: FormSerie) => void;
}) {
  const [form] = Form.useForm<FormSerie>();
  const [codigoManual, setCodigoManual] = useState(false);
  const serie = edicao && edicao !== 'nova' ? edicao : null;
  const livre = podeAlterarNumeracao(serie);
  const tipo = Form.useWatch('tipo', form);
  const codigo = Form.useWatch('codigo', form);
  const numero = Form.useWatch('proximo_numero', form);
  const ano = Form.useWatch('ano', form);
  const origem = Form.useWatch('origem', form);
  const estado = Form.useWatch('estado', form);
  const conflito = tipo && ano ? outraActiva(dados.series, { id: serie?.id, tipo, ano, origem: origem ?? 'GERAL', estado: estado ?? 'ATIVA' }) : null;

  useEffect(() => {
    setCodigoManual(false);
    if (edicao === 'nova') {
      const a = dayjs().year();
      form.setFieldsValue({ tipo: 'FT', ano: a, origem: 'GERAL', codigo: codigoSugerido('GERAL', a), proximo_numero: 1, estabelecimento: dados.estabelecimentos[0]?.numero ?? null, estado: 'ATIVA', contingencia: false });
    } else if (serie) {
      form.setFieldsValue({ tipo: serie.tipo, ano: serie.ano, origem: serie.origem, codigo: serie.codigo, proximo_numero: serie.proximo_numero, estabelecimento: serie.estabelecimento, estado: serie.estado === 'FECHADA' ? 'FECHADA' : 'ATIVA', contingencia: serie.contingencia });
    }
  }, [edicao, serie, form, dados.estabelecimentos]);

  /** O código por omissão acompanha o ano e a origem enquanto o utilizador não o escrever à mão (como o legado). */
  const sugerirCodigo = () => {
    if (serie || codigoManual) return;
    const v = form.getFieldsValue();
    form.setFieldValue('codigo', codigoSugerido(v.origem ?? 'GERAL', v.ano ?? dayjs().year()));
  };
  const previsao = proximoDocumento(tipo ?? 'FT', codigo, numero);
  const campos = useMemo(() => camposSerie(dados), [dados]);

  return (
    <ModalFormulario
      chave="vendas.series"
      width={larguraModal(640)}
      titulo={serie ? `Alterar série ${serie.tipo} ${serie.codigo}` : 'Nova série'}
      aberto={edicao !== null}
      aoFechar={aoFechar}
      criacao={!serie}
      form={form}
      campos={campos}
      nomeRegistos="séries"
      invalidar={[CHAVE, ['vendas', 'fe', 'series']]}
      gravarLinha={(r) => enviar('post', '/vendas/series', dadosSerie(r))}
      nota="Uma linha por série (FT, FR ou NC). Código em branco = código sugerido (A<ano>, ou o do terminal). Só pode haver uma série activa por tipo, ano e origem. Linhas deixadas em branco são ignoradas."
      okText="Guardar série"
      cancelText="Cancelar"
      confirmLoading={aGravar}
      okButtonProps={{ disabled: !!conflito }}
      onOk={() => form.submit()}
    >
      <Typography.Paragraph type="secondary">
        {serie && !livre
          ? <>Esta série já emitiu <strong>{serie.emitidos}</strong> documento(s): só pode mudar o estado e a contingência.</>
          : 'O número de arranque só se atribui enquanto a série não emitir nada.'}
      </Typography.Paragraph>
      <Form form={form} layout="vertical" onFinish={aoGravar}>
        <Row gutter={12}>
          <Col xs={24} sm={12}>
            <Form.Item name="tipo" label="Tipo de documento" rules={[{ required: true }]}>
              <Select disabled={!livre} options={TIPOS_SERIE.map((t) => ({ value: t.tipo, label: `${t.tipo} — ${t.nome}`, title: t.ajuda }))} />
            </Form.Item>
          </Col>
          <Col xs={24} sm={12}>
            <Form.Item name="ano" label="Ano" rules={[{ required: true, message: 'Indique o ano.' }]}>
              <InputNumber disabled={!livre} min={2000} max={2100} style={{ width: '100%' }} onChange={() => setTimeout(sugerirCodigo)} />
            </Form.Item>
          </Col>
          <Col xs={24} sm={12}>
            <Form.Item name="origem" label="Origem" extra="Cada terminal POS pode ter série própria. Sem série própria, o terminal usa a numeração automática do terminal." rules={[{ required: true }]}>
              <Select disabled={!livre} options={dados.origens.map((o) => ({ value: o.valor, label: o.rotulo }))} onChange={() => setTimeout(sugerirCodigo)} />
            </Form.Item>
          </Col>
          <Col xs={24} sm={12}>
            <Form.Item
              name="codigo"
              label="Código da série"
              extra="Só letras e números, sem espaços nem «/»."
              rules={[{ validator: (_, v) => { const e = erroCodigo(v); return e ? Promise.reject(new Error(e)) : Promise.resolve(); } }]}
            >
              <Input disabled={!livre} maxLength={30} onChange={() => setCodigoManual(true)} placeholder={`Ex.: A${dayjs().year()}`} style={{ fontFamily: 'ui-monospace, monospace', fontWeight: 700 }} />
            </Form.Item>
          </Col>
          <Col xs={24} sm={12}>
            <Form.Item
              name="proximo_numero"
              label="Número de arranque"
              extra="O próximo documento leva este número. Para continuar um livro que vai no 500, escreva 501."
              rules={[{ required: true, message: 'Indique o número.' }, { validator: (_, v) => { const e = erroNumeroInicial(v); return e ? Promise.reject(new Error(e)) : Promise.resolve(); } }]}
            >
              <InputNumber disabled={!livre} min={1} max={99_999_999} precision={0} style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          <Col xs={24} sm={12}>
            <Form.Item name="estabelecimento" label="Estabelecimento">
              {dados.estabelecimentos.length
                ? <Select allowClear options={dados.estabelecimentos.map((e) => ({ value: e.numero, label: e.nome ? `${e.numero} — ${e.nome}` : e.numero }))} />
                : <Input maxLength={20} />}
            </Form.Item>
          </Col>
          <Col xs={24} sm={12}>
            <Form.Item name="estado" label="Estado">
              <Select options={[{ value: 'ATIVA', label: 'Activa — é esta que dá números' }, { value: 'FECHADA', label: 'Fechada — já não dá números' }]} />
            </Form.Item>
          </Col>
          <Col xs={24} sm={12}>
            <Form.Item name="contingencia" valuePropName="checked" label=" ">
              <Checkbox>Série de contingência (serviço da AGT indisponível)</Checkbox>
            </Form.Item>
          </Col>
        </Row>
      </Form>
      {conflito && <Alert type="error" showIcon message={`Já existe outra série activa para este tipo, ano e origem (${conflito.codigo}). Feche-a antes ou grave esta como fechada.`} />}
      <Alert style={{ marginTop: 8 }} type="info" message={previsao ? <>O próximo documento será <code style={{ fontWeight: 700 }}>{previsao}</code>.</> : 'Indique o código da série para ver o número.'} />
    </ModalFormulario>
  );
}
