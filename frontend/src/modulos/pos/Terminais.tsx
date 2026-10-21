import { Alert, Button, Checkbox, Flex, Form, Input, InputNumber, Modal, Radio, Select, Space, Table, Tag, TimePicker, Tooltip, Typography, theme } from 'antd';
import { CopyOutlined, DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { ModalFormulario, SeccaoFormulario } from '@/componentes/formularios';
import { pares, tabelaHtml } from '@/componentes/impressao';
import { larguraModal, scrollTabela, useEcraPequeno } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import { useAccao } from '@/componentes/Accoes';
import { NomeArmazem } from '@/modulos/compras/comum/referencias';
import { SeletorArmazem, SeletorConta, SeletorTerceiro } from '@/modulos/compras/comum/Seletores';
import { SeletorAux, SeletorUnidade } from '@/modulos/contab/comum/Seletores';
import { TIPOS_MEIO, TIPOS_TERMINAL, useTerminais } from './comum/dados';
import { classeLiquidacao, meiosPadrao, meiosParaApi, validarMeios } from './comum/meios';
import { accoesTerminal } from './comum/regras';
import { SO_GRADE } from './comum/formularios';
import type { MeioPagamento, Terminal, TipoTerminal } from './comum/tipos';

import { TabelaComModos } from '@/componentes/vistas';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { ModalDefinicoesCaixa } from './comum/DefinicoesCaixa';
import { iconeMeio, useSeriesTerminais } from './comum/frente';
import './comum/pos.css';
import { AvisoSessoesAntigas } from './frente/AvisoSessoesAntigas';
import { ModalServicosTerminal } from './lavandaria/ServicosTerminal';

/** Subtítulo do legado (renderTerminais, pos_gestao.js). */
export const SUBTITULO_TERMINAIS = 'Série documental, armazém, cliente por omissão e meios de pagamento com a conta transitória de cada meio.';

/** Estado da caixa do terminal: «● Aberta» (sessão, operador, desde) ou «● Fechada» (3.3.1). */
export function EstadoCaixa({ sessao }: { sessao: Terminal['sessao_aberta'] }) {
  if (!sessao)
    return (
      <span style={{ color: '#b91c1c', fontWeight: 700, whiteSpace: 'nowrap' }}>
        <span aria-hidden>●</span> Fechada
      </span>
    );
  return (
    <span style={{ fontSize: '0.8rem' }}>
      <span style={{ color: '#059669', fontWeight: 700, whiteSpace: 'nowrap' }}>
        <span aria-hidden>●</span> Aberta
      </span>{' '}
      <b>{sessao.codigo_sessao}</b>
      <div style={{ color: '#64748b' }}>
        {sessao.nome_operador ?? '—'} · desde {formatarDataHora(sessao.aberto_em)}
      </div>
    </span>
  );
}

/**
 * POS › Terminais (ecrã pos_terminais): terminais com a série, o estado da caixa e os meios de pagamento (com ícone), o
 * aviso das sessões antigas e «Definições de caixa»; meios com as contas transitória/liquidação/comissão, cópia de meios de
 * outro terminal (mesma ou outra empresa), activar/desactivar e eliminar (ADR-047).
 */
export default function Terminais() {
  const { pode } = useSessao();
  const terminais = useTerminais();
  const series = useSeriesTerminais();
  const [editar, setEditar] = useState<Terminal | 'novo' | null>(null);
  const [copiar, setCopiar] = useState<Terminal | null>(null);
  const [definicoes, setDefinicoes] = useState(false);
  const [servicosLav, setServicosLav] = useState<{ terminal: Terminal; obrigatorio: boolean } | null>(null);
  const accao = useAccao({ invalidar: [['pos']] });
  const pequeno = useEcraPequeno();
  const serie = (t: Terminal) => series.data?.[String(t.id)]?.serie ?? null;

  return (
    <>
      <CabecalhoPagina
        titulo="Terminais POS"
        subtitulo={SUBTITULO_TERMINAIS}
        impressaoDesactivada={!terminais.data?.length}
        impressao={() => ({ titulo: 'Lista de terminais POS', conteudo: documentoTerminais(terminais.data ?? [], series.data) })}
        accoes={
          <>
            {pode('pos_terminais_view', 'pos_terminais_gerir') && (
              <Button icon={<IconeFa nome="sliders-h" />} onClick={() => setDefinicoes(true)}>
                Definições de caixa
              </Button>
            )}
            {pode('pos_terminais_gerir') && (
              <Button type="primary" icon={<PlusOutlined />} onClick={() => setEditar('novo')}>
                Novo terminal
              </Button>
            )}
          </>
        }
      />
      <AvisoSessoesAntigas />
      <TabelaComModos<Terminal>
        rowKey="id"
        size={pequeno ? 'small' : 'middle'}
        loading={terminais.isFetching}
        dataSource={terminais.data}
        pagination={false}
        scroll={scrollTabela()}
        expandable={{ expandedRowRender: (t) => <TabelaMeiosTerminal meios={t.meios_pagamento ?? []} /> }}
        onRow={(t) => ({ style: t.ativo ? undefined : { opacity: 0.55 } })}
        columns={[
          // colunas do legado (pos_gestao.js:744): Código, Terminal, Meios de pagamento, Caixa; as restantes ficam no menu «Colunas»
          { title: 'Código', dataIndex: 'codigo', render: (v) => <b>{v}</b> },
          {
            title: 'Terminal',
            dataIndex: 'nome',
            render: (v, t) => (
              <>
                <b>{v}</b>
                <div>
                  <small style={{ color: '#64748b' }}>
                    {TIPOS_TERMINAL[t.tipo] ?? t.tipo}
                    {serie(t) && ` · Série ${serie(t)}`}
                  </small>
                </div>
              </>
            ),
          },
          { title: 'Tipo', dataIndex: 'tipo', render: (v: string) => TIPOS_TERMINAL[v] ?? v, responsive: ['xl'], ocultaPorOmissao: true },
          { title: 'Armazém', dataIndex: 'armazem_id', render: (v) => (v ? <NomeArmazem id={v} /> : 'Predefinido'), responsive: ['lg'], ocultaPorOmissao: true },
          { title: 'Fundo padrão', dataIndex: 'fundo_maneio_padrao', align: 'right', render: (v) => formatarKz(v), responsive: ['md'], ocultaPorOmissao: true },
          {
            title: 'Meios de pagamento',
            responsive: ['md'],
            render: (_, t) =>
              (t.meios_pagamento ?? [])
                .filter((m) => m.ativo)
                .map((m) => (
                  <Tag key={m.id} icon={<IconeFa nome={iconeMeio(m.tipo)} style={{ marginRight: 4 }} />}>
                    {m.nome}
                  </Tag>
                )),
          },
          { title: 'Caixa', dataIndex: 'sessao_aberta', render: (s: Terminal['sessao_aberta'], t) => (t.ativo ? <EstadoCaixa sessao={s} /> : <span style={{ color: '#64748b' }}>Inactivo</span>) },
          { title: 'Estado', dataIndex: 'ativo', render: (v) => (v ? <Tag color="green">Activo</Tag> : <Tag>Inactivo</Tag>), ocultaPorOmissao: true },
          {
            title: '',
            key: 'accoes',
            fixed: 'right',
            render: (_, t) => {
              const a = accoesTerminal(pode, t);
              return (
                <Space size={4} wrap>
                  {a.editar && <Button size="small" icon={<IconeFa nome="cog" />} onClick={() => setEditar(t)} aria-label="Configurar" title="Configurar" />}
                  {a.editar && t.tipo === 'LAVANDARIA' && (
                    <Tooltip title={t.lavandaria_servicos_ids?.length ? 'Configurar serviços' : 'Configurar serviços — ainda sem nenhum escolhido'}>
                      <Button
                        size="small"
                        icon={<IconeFa nome="tshirt" />}
                        danger={!t.lavandaria_servicos_ids?.length}
                        onClick={() => setServicosLav({ terminal: t, obrigatorio: false })}
                        aria-label="Configurar serviços"
                      />
                    </Tooltip>
                  )}
                  {a.copiarMeios && (
                    <Tooltip title="Copiar meios de pagamento de outro terminal">
                      <Button size="small" icon={<CopyOutlined />} onClick={() => setCopiar(t)} aria-label="Copiar meios" />
                    </Tooltip>
                  )}
                  {a.activar && (
                    <Button size="small" icon={<IconeFa nome="toggle-off" />} aria-label="Activar" title="Activar" onClick={() => accao.mutate({ url: `/pos/terminais/${t.id}/ativo`, dados: { ativo: true } })} />
                  )}
                  {a.desactivar.visivel && (
                    <Tooltip title={a.desactivar.bloqueio ?? 'Desactivar'}>
                      <Button size="small" icon={<IconeFa nome="toggle-on" />} aria-label="Desactivar" disabled={!!a.desactivar.bloqueio} onClick={() => accao.mutate({ url: `/pos/terminais/${t.id}/ativo`, dados: { ativo: false } })} />
                    </Tooltip>
                  )}
                  {a.eliminar && (
                    <Button
                      size="small"
                      danger
                      icon={<DeleteOutlined />}
                      aria-label="Eliminar"
                      onClick={() =>
                        Modal.confirm({
                          title: `Eliminar o terminal ${t.codigo}?`,
                          content: 'Só é possível se o terminal nunca teve sessões nem vendas; caso contrário, desactive-o.',
                          okText: 'Eliminar',
                          okButtonProps: { danger: true },
                          cancelText: 'Cancelar',
                          onOk: () => accao.mutateAsync({ metodo: 'delete', url: `/pos/terminais/${t.id}` }).catch(() => undefined),
                        })
                      }
                    />
                  )}
                </Space>
              );
            },
          },
        ]}
      />
      <EditorTerminal
        alvo={editar}
        terminais={terminais.data ?? []}
        aoFechar={() => setEditar(null)}
        aoCriarLavandaria={(t) => setServicosLav({ terminal: t, obrigatorio: true })}
      />
      <ModalCopiarMeios destino={copiar} terminais={terminais.data ?? []} aoFechar={() => setCopiar(null)} />
      <ModalDefinicoesCaixa aberto={definicoes} aoFechar={() => setDefinicoes(false)} />
      <ModalServicosTerminal terminal={servicosLav?.terminal ?? null} obrigatorio={servicosLav?.obrigatorio} aoFechar={() => setServicosLav(null)} />
    </>
  );
}

function TabelaMeiosTerminal({ meios }: { meios: MeioPagamento[] }) {
  return (
    <Table<MeioPagamento>
      size="small"
      scroll={scrollTabela()}
      pagination={false}
      rowKey={(m) => m.id ?? m.nome}
      dataSource={meios}
      columns={[
        { title: 'Meio', dataIndex: 'nome' },
        { title: 'Tipo', dataIndex: 'tipo', render: (v: string) => TIPOS_MEIO[v] ?? v },
        { title: 'Transitória', dataIndex: 'conta_transitoria', render: (v) => v ?? '—' },
        { title: 'Liquidação', dataIndex: 'conta_liquidacao', render: (v) => v ?? '—' },
        { title: 'TPA', render: (_, m) => (m.tipo === 'TPA' ? `${m.codigo_tpa ?? '—'} · comissão ${m.comissao_pct ?? 0}%${m.conta_comissao ? ` (${m.conta_comissao})` : ''}${m.comissao_deduzida === false ? ' · não deduzida' : ''}` : '—') },
        { title: 'Copiado de', dataIndex: 'copiado_de', render: (v) => v ?? '—' },
        { title: 'Estado', dataIndex: 'ativo', render: (v) => (v ? <Tag color="green">Activo</Tag> : <Tag>Inactivo</Tag>) },
      ]}
    />
  );
}

interface FormTerminal {
  codigo: string;
  nome: string;
  tipo: TipoTerminal;
  armazem_id?: number | null;
  cliente_padrao_id?: number | null;
  fundo_maneio_padrao?: number | null;
  unidade_negocio_id?: number | null;
  centro_custo_id?: number | null;
  meios_pagamento: MeioPagamento[];
  hotel_hora_entrada?: Dayjs | null;
  hotel_hora_saida?: Dayjs | null;
  hotel_tolerancia_atraso_min?: number | null;
  hotel_bloco_horas?: boolean;
  hotel_bloco_horas_de?: Dayjs | null;
  hotel_bloco_horas_ate?: Dayjs | null;
}

/** Tipos de negócio com os rótulos do editor do legado (pos_gestao.js:786). */
const TIPOS_NEGOCIO: Record<string, string> = { LOJA: 'Loja / Supermercado', RESTAURANTE: 'Restaurante', HOTELARIA: 'Hotelaria', LAVANDARIA: 'Lavandaria / Alfaiataria' };

const hora = (v: string | null | undefined) => (v ? dayjs(v, 'HH:mm') : null);
const textoHora = (v: Dayjs | null | undefined) => (v ? v.format('HH:mm') : null);

function EditorTerminal({ alvo, terminais, aoFechar, aoCriarLavandaria }: { alvo: Terminal | 'novo' | null; terminais: Terminal[]; aoFechar: () => void; aoCriarLavandaria: (t: Terminal) => void }) {
  const [form] = Form.useForm<FormTerminal>();
  const [erros, setErros] = useState<string[]>([]);
  const novo = alvo === 'novo';
  const t = alvo && alvo !== 'novo' ? alvo : null;
  const tipo = Form.useWatch('tipo', form);
  const { token } = theme.useToken();
  // novo terminal de Lavandaria: a seguir a criar, obriga a escolher os serviços que este POS vai usar
  const gravar = useAccao<Terminal>({
    invalidar: [['pos']],
    aoSucesso: (res) => {
      aoFechar();
      if (novo && res.tipo === 'LAVANDARIA') aoCriarLavandaria(res);
    },
    tituloErro: 'Não foi possível gravar o terminal',
  });

  useEffect(() => {
    if (!alvo) return;
    setErros([]);
    form.resetFields();
    form.setFieldsValue(
      t
        ? {
            codigo: t.codigo,
            nome: t.nome,
            tipo: t.tipo,
            armazem_id: t.armazem_id,
            cliente_padrao_id: t.cliente_padrao_id,
            fundo_maneio_padrao: Number(t.fundo_maneio_padrao ?? 0),
            unidade_negocio_id: t.unidade_negocio_id,
            centro_custo_id: t.centro_custo_id,
            meios_pagamento: t.meios_pagamento?.length ? t.meios_pagamento.map((m) => (m.tipo === 'TPA' ? { ...m, comissao_deduzida: m.comissao_deduzida ?? true } : m)) : meiosPadrao(),
            hotel_hora_entrada: hora(t.hotel_hora_entrada ?? '14:00'),
            hotel_hora_saida: hora(t.hotel_hora_saida ?? '12:00'),
            hotel_tolerancia_atraso_min: t.hotel_tolerancia_atraso_min ?? 60,
            hotel_bloco_horas: t.hotel_bloco_horas !== false,
            hotel_bloco_horas_de: hora(t.hotel_bloco_horas_de ?? '21:00'),
            hotel_bloco_horas_ate: hora(t.hotel_bloco_horas_ate ?? '08:00'),
          }
        : { tipo: 'LOJA', fundo_maneio_padrao: 0, meios_pagamento: meiosPadrao(), hotel_hora_entrada: hora('14:00'), hotel_hora_saida: hora('12:00'), hotel_tolerancia_atraso_min: 60, hotel_bloco_horas: true, hotel_bloco_horas_de: hora('21:00'), hotel_bloco_horas_ate: hora('08:00') },
    );
  }, [alvo, t, form]);

  const enviar = (v: FormTerminal) => {
    const meios = v.meios_pagamento ?? [];
    const e = validarMeios(meios);
    setErros(e);
    if (e.length) return;
    const dados = {
      codigo: v.codigo?.trim().toUpperCase(),
      nome: v.nome?.trim(),
      tipo: v.tipo,
      armazem_id: v.armazem_id ?? null,
      cliente_padrao_id: v.cliente_padrao_id ?? null,
      fundo_maneio_padrao: v.fundo_maneio_padrao ?? 0,
      unidade_negocio_id: v.unidade_negocio_id ?? null,
      centro_custo_id: v.centro_custo_id ?? null,
      meios_pagamento: meiosParaApi(meios),
      ...(v.tipo === 'HOTELARIA'
        ? {
            hotel_hora_entrada: textoHora(v.hotel_hora_entrada),
            hotel_hora_saida: textoHora(v.hotel_hora_saida),
            hotel_tolerancia_atraso_min: v.hotel_tolerancia_atraso_min ?? 60,
            hotel_bloco_horas: !!v.hotel_bloco_horas,
            hotel_bloco_horas_de: textoHora(v.hotel_bloco_horas_de),
            hotel_bloco_horas_ate: textoHora(v.hotel_bloco_horas_ate),
          }
        : {}),
    };
    gravar.mutate(t ? { metodo: 'put', url: `/pos/terminais/${t.id}`, dados } : { url: '/pos/terminais', dados });
  };

  // «Copiar de outro terminal» dentro do editor, como o legado (posEditorCopiarMeios): substitui os meios do formulário pelos
  // do terminal escolhido (fica no editor até gravar) e reaproveita os identificadores dos meios actuais do mesmo tipo.
  const [aCopiar, setACopiar] = useState(false);
  const [origem, setOrigem] = useState<number>();
  const copiarParaEditor = () => {
    const fonte = terminais.find((x) => x.id === origem);
    if (!fonte) return;
    const livres = [...(form.getFieldValue('meios_pagamento') as MeioPagamento[] | undefined ?? [])];
    const meios = (fonte.meios_pagamento ?? []).map((m) => {
      const i = livres.findIndex((l) => l.tipo === m.tipo && l.id);
      const id = i >= 0 ? livres.splice(i, 1)[0].id : undefined;
      return { ...m, id };
    });
    form.setFieldValue('meios_pagamento', meios);
    setACopiar(false);
    setOrigem(undefined);
  };

  return (
    // Só grade (também em «Novo terminal»): cada terminal tem série, meios de pagamento e contas próprias, que não cabem
    // numa linha; os campos ficam em secções recolhíveis como os blocos do editor do legado (pos_gestao.js:783-851).
    <ModalFormulario
      chave="pos.terminais"
      {...SO_GRADE}
      aberto={!!alvo}
      aoFechar={aoFechar}
      width={larguraModal(980)}
      className="pos-editor-terminal"
      titulo={
        <span>
          <IconeFa nome="desktop" style={{ color: token.colorPrimary }} /> {novo ? 'Novo terminal' : 'Configurar terminal'}
        </span>
      }
      maskClosable={false}
      footer={
        <Flex justify="end" gap={8} style={{ borderTop: `1px solid ${token.colorSplit}`, paddingTop: '0.9rem' }}>
          <Button onClick={aoFechar}>Cancelar</Button>
          <Button type="primary" icon={<IconeFa nome="save" />} loading={gravar.isPending} onClick={() => form.submit()}>
            Gravar terminal
          </Button>
        </Flex>
      }
    >
      <Form form={form} layout="vertical" onFinish={enviar} requiredMark={false} className="pos-editor-form">
        <SeccaoFormulario titulo="Identificação e operação">
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '0.7rem' }}>
          <Form.Item
            name="codigo"
            label="Código (série FR-POS/código/ano)"
            rules={[{ required: true, message: 'Indique o código.' }, { pattern: /^[A-Za-z0-9_-]{1,10}$/, message: 'Até 10 letras, algarismos, «-» ou «_».' }]}
            tooltip={t ? 'Bloqueado depois da primeira sessão (entra na numeração).' : undefined}
          >
            <Input maxLength={10} style={{ textTransform: 'uppercase' }} />
          </Form.Item>
          <Form.Item name="nome" label="Nome do terminal" rules={[{ required: true, message: 'Indique o nome.' }]}>
            <Input maxLength={255} placeholder="Ex.: Caixa 1 — Loja" />
          </Form.Item>
          <Form.Item name="tipo" label="Tipo de negócio">
            <Select options={Object.entries(TIPOS_NEGOCIO).map(([value, label]) => ({ value, label }))} />
          </Form.Item>
          <Form.Item name="fundo_maneio_padrao" label="Fundo de maneio habitual (Kz)">
            <InputNumber<number> min={0} precision={2} decimalSeparator="," style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="armazem_id" label="Armazém de saída de stock">
            <SeletorArmazem allowClear placeholder="— armazém por omissão —" />
          </Form.Item>
          <Form.Item name="cliente_padrao_id" label="Cliente por omissão">
            <SeletorTerceiro papel="CLIENTE" placeholder="— Consumidor Final —" />
          </Form.Item>
        </div>
        </SeccaoFormulario>
        <SeccaoFormulario titulo="Contabilidade analítica" recolhida={!t?.unidade_negocio_id && !t?.centro_custo_id}>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '0.7rem' }}>
          <Form.Item name="unidade_negocio_id" label="Unidade de negócio">
            <SeletorUnidade style={{ width: '100%' }} placeholder="— nenhuma —" />
          </Form.Item>
          <Form.Item name="centro_custo_id" label="Centro de custo">
            <SeletorAux tabela="centros-custo" placeholder="— nenhum —" style={{ width: '100%' }} />
          </Form.Item>
        </div>
        </SeccaoFormulario>

        {tipo === 'HOTELARIA' && (
          <SeccaoFormulario
            titulo={
              <span>
                <IconeFa nome="hotel" /> Regras da hotelaria
              </span>
            }
          >
          <div style={{ border: `1px solid ${token.colorWarningBorder}`, background: token.colorWarningBg, borderRadius: 4, padding: '0.8rem' }}>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(170px, 1fr))', gap: '0.7rem' }}>
              <Form.Item name="hotel_hora_entrada" label="Hora de entrada (check-in)">
                <TimePicker format="HH:mm" minuteStep={5} style={{ width: '100%' }} />
              </Form.Item>
              <Form.Item name="hotel_hora_saida" label="Hora de saída (check-out)">
                <TimePicker format="HH:mm" minuteStep={5} style={{ width: '100%' }} />
              </Form.Item>
              <Form.Item name="hotel_tolerancia_atraso_min" label="Tolerância na saída (minutos)">
                <InputNumber<number> min={0} step={5} precision={0} style={{ width: '100%' }} />
              </Form.Item>
              <Form.Item name="hotel_bloco_horas" label="Venda à hora" valuePropName="checked">
                <Checkbox>Bloquear no horário abaixo</Checkbox>
              </Form.Item>
              <Form.Item name="hotel_bloco_horas_de" label="Bloqueio desde">
                <TimePicker format="HH:mm" minuteStep={5} style={{ width: '100%' }} />
              </Form.Item>
              <Form.Item name="hotel_bloco_horas_ate" label="Bloqueio até">
                <TimePicker format="HH:mm" minuteStep={5} style={{ width: '100%' }} />
              </Form.Item>
            </div>
            <p style={{ margin: '0.5rem 0 0', fontSize: '0.74rem', color: token.colorWarningText }}>
              Diária: a saída prevista é às {textoHora(form.getFieldValue('hotel_hora_saida')) ?? '12:00'} do dia seguinte a cada diária. Depois da tolerância o check-out propõe o recálculo e o operador confirma.
            </p>
          </div>
          </SeccaoFormulario>
        )}

        <Form.List name="meios_pagamento">
          {(campos, { add, remove }) => (
            <SeccaoFormulario
              titulo={
                <span>
                  <IconeFa nome="credit-card" /> Meios de pagamento aceites
                </span>
              }
              extra={
                <div style={{ display: 'flex', gap: '0.35rem', flexWrap: 'wrap' }}>
                  <Button size="small" icon={<IconeFa nome="copy" />} onClick={() => setACopiar(true)} disabled={!terminais.some((x) => x.id !== t?.id)}>
                    Copiar de outro terminal
                  </Button>
                  {(['NUMERARIO', 'TPA', 'TRANSFERENCIA'] as const).map((tp) => (
                    <Button
                      key={tp}
                      size="small"
                      icon={<IconeFa nome={iconeMeio(tp)} />}
                      onClick={() => add({ tipo: tp, nome: TIPOS_MEIO[tp], ativo: true, conta_transitoria: null, conta_liquidacao: null, ...(tp === 'TPA' ? { codigo_tpa: null, comissao_pct: 0, conta_comissao: null, comissao_deduzida: true } : {}) })}
                    >
                      + {TIPOS_MEIO[tp]}
                    </Button>
                  ))}
                </div>
              }
            >
              <p style={{ margin: '0 0 0.5rem', fontSize: '0.75rem', color: token.colorTextSecondary }}>
                A venda debita a <strong>conta transitória</strong> do meio. A prestação de contas transfere-a para a <strong>conta de liquidação</strong>: numerário para a Folha de Caixa, TPA para o banco (com comissão) e transferências comprovativo a comprovativo.
              </p>
              {campos.length === 0 && <div style={{ padding: '1rem', color: token.colorError }}>Adicione pelo menos um meio de pagamento.</div>}
              {campos.map((campo) => (
                <EditorMeio
                  key={campo.key}
                  nome={campo.name}
                  aoRemover={() =>
                    Modal.confirm({
                      title: 'Remover este meio de pagamento?',
                      content: 'Os documentos já emitidos mantêm as suas contas.',
                      okText: 'Remover',
                      okButtonProps: { danger: true },
                      cancelText: 'Cancelar',
                      onOk: () => remove(campo.name),
                    })
                  }
                />
              ))}
            </SeccaoFormulario>
          )}
        </Form.List>
        {erros.length > 0 && (
          <Alert
            style={{ marginTop: 12 }}
            type="error"
            showIcon
            message="Corrija os meios de pagamento"
            description={
              <ul style={{ margin: 0, paddingLeft: 18 }}>
                {erros.map((e) => (
                  <li key={e}>{e}</li>
                ))}
              </ul>
            }
          />
        )}
      </Form>
      <Modal open={aCopiar} title="Copiar meios de pagamento de outro terminal" okText="Copiar" cancelText="Cancelar" okButtonProps={{ disabled: !origem }} onOk={copiarParaEditor} onCancel={() => setACopiar(false)} width={larguraModal(520)} destroyOnHidden>
        <Select
          style={{ width: '100%' }}
          placeholder="Terminal de origem"
          value={origem}
          onChange={setOrigem}
          options={terminais.filter((x) => x.id !== t?.id).map((x) => ({ value: x.id, label: `${x.codigo} — ${x.nome} (${(x.meios_pagamento ?? []).length} meio(s))` }))}
        />
        <Typography.Paragraph type="secondary" style={{ marginTop: 8, marginBottom: 0 }}>
          Os meios actuais são substituídos no editor; só ficam gravados com «Gravar terminal». Para copiar de outra empresa use o botão de cópia na lista.
        </Typography.Paragraph>
      </Modal>
    </ModalFormulario>
  );
}

/** Cor e ícone de cada tipo de meio (TIPOS_MEIO do legado, pos_gestao.js:26-30). */
const COR_MEIO: Record<string, string> = { NUMERARIO: '#059669', TPA: '#2563eb', TRANSFERENCIA: '#7c3aed' };

function EditorMeio({ nome, aoRemover }: { nome: number; aoRemover: () => void }) {
  const form = Form.useFormInstance<FormTerminal>();
  const tipo = Form.useWatch(['meios_pagamento', nome, 'tipo'], form) as MeioPagamento['tipo'] | undefined;
  const pct = Form.useWatch(['meios_pagamento', nome, 'comissao_pct'], form) as number | undefined;
  const ativo = Form.useWatch(['meios_pagamento', nome, 'ativo'], form) as boolean | undefined;
  const deduzida = Form.useWatch(['meios_pagamento', nome, 'comissao_deduzida'], form) as boolean | undefined;
  const tpa = tipo === 'TPA';
  const { token } = theme.useToken();
  // linha do legado (posEditorDesenharMeios, pos_gestao.js:832-851)
  return (
    <div
      className="pos-editor-meio"
      style={{
        border: `1px solid ${ativo === false ? token.colorBorderSecondary : token.colorBorder}`,
        borderLeft: `4px solid ${tipo ? COR_MEIO[tipo] ?? '#334155' : '#334155'}`,
        borderRadius: 4,
        padding: '0.6rem 0.75rem',
        marginBottom: '0.5rem',
        opacity: ativo === false ? 0.6 : 1,
      }}
    >
      <Form.Item name={[nome, 'id']} hidden>
        <Input />
      </Form.Item>
      <Form.Item name={[nome, 'tipo']} hidden>
        <Input />
      </Form.Item>
      <div style={{ display: 'grid', gridTemplateColumns: `1.2fr 1.4fr 1.4fr ${tpa ? '0.8fr ' : ''}auto`, gap: '0.5rem', alignItems: 'end' }}>
        <Form.Item
          name={[nome, 'nome']}
          label={
            <span>
              <IconeFa nome={iconeMeio(tipo)} /> {tipo ? TIPOS_MEIO[tipo] : 'Meio'}
            </span>
          }
          rules={[{ required: true, message: 'Indique o nome.' }]}
        >
          <Input maxLength={100} placeholder="Designação" />
        </Form.Item>
        <Form.Item name={[nome, 'conta_transitoria']} label="Conta transitória *">
          <SeletorConta />
        </Form.Item>
        <Form.Item name={[nome, 'conta_liquidacao']} label={`Conta de liquidação (${tipo === 'NUMERARIO' ? 'caixa 45' : 'banco 43'}) *`}>
          <SeletorConta prefixo={tipo ? classeLiquidacao(tipo) : undefined} />
        </Form.Item>
        {tpa && (
          <Form.Item name={[nome, 'codigo_tpa']} label="N.º do TPA">
            <Input maxLength={30} />
          </Form.Item>
        )}
        <div style={{ display: 'flex', gap: '0.3rem', alignItems: 'center', paddingBottom: '0.3rem' }}>
          <Form.Item name={[nome, 'ativo']} valuePropName="checked" noStyle>
            <Checkbox style={{ fontSize: '0.72rem' }}>Activo</Checkbox>
          </Form.Item>
          <Button size="small" title="Remover" aria-label="Retirar meio" icon={<IconeFa nome="trash-alt" style={{ color: '#b91c1c' }} />} onClick={aoRemover} />
        </div>
      </div>
      {tpa && (
        <div style={{ display: 'grid', gridTemplateColumns: '1.4fr 0.6fr 1.4fr 1.4fr', gap: '0.5rem', marginTop: '0.4rem' }}>
          <Form.Item name={[nome, 'conta_comissao']} label="Conta de comissões bancárias" rules={[{ required: Number(pct ?? 0) > 0, message: 'Com comissão, indique a conta.' }]}>
            <SeletorConta prefixo="7" />
          </Form.Item>
          <Form.Item name={[nome, 'comissao_pct']} label="Comissão (%)">
            <InputNumber<number> min={0} max={99.99} precision={2} decimalSeparator="," style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name={[nome, 'comissao_deduzida']} label="Tratamento da comissão">
            <Select
              options={[
                { value: true, label: 'Deduzida no valor creditado' },
                { value: false, label: 'Não deduzida (cobrada à parte)' },
              ]}
            />
          </Form.Item>
          <div style={{ fontSize: '0.72rem', color: token.colorTextSecondary, alignSelf: 'end', paddingBottom: '0.4rem' }}>
            {deduzida === false ? 'O banco credita o valor total; a comissão fica como pagamento separado na Tesouraria.' : 'O banco credita o valor líquido (total menos a comissão).'} A comissão é sugerida na liquidação e pode ser acertada pelo extracto.
          </div>
        </div>
      )}
    </div>
  );
}

function ModalCopiarMeios({ destino, terminais, aoFechar }: { destino: Terminal | null; terminais: Terminal[]; aoFechar: () => void }) {
  const { empresa, empresas } = useSessao();
  const [form] = Form.useForm<{ empresa_origem_id: number; terminal_origem_id: number; modo: 'SUBSTITUIR' | 'ACRESCENTAR' }>();
  const empresaOrigem = Form.useWatch('empresa_origem_id', form);
  const outraEmpresa = !!empresaOrigem && empresaOrigem !== empresa?.id;
  const deOutra = useQuery({
    queryKey: ['pos', 'terminais', 'empresa', empresaOrigem],
    queryFn: () => obter<Terminal[]>('/pos/terminais', undefined, { headers: { 'X-Empresa-Id': String(empresaOrigem) } }),
    enabled: !!destino && outraEmpresa,
    retry: false,
  });
  const copiar = useAccao({ invalidar: [['pos']], aoSucesso: aoFechar, tituloErro: 'Não foi possível copiar os meios' });
  useEffect(() => {
    if (destino) form.setFieldsValue({ empresa_origem_id: empresa?.id, terminal_origem_id: undefined, modo: 'SUBSTITUIR' });
  }, [destino, empresa, form]);
  const origens = (outraEmpresa ? deOutra.data ?? [] : terminais.filter((t) => t.id !== destino?.id)).map((t) => ({
    value: t.id,
    label: `${t.codigo} — ${t.nome} (${(t.meios_pagamento ?? []).length} meio(s))`,
  }));
  return (
    <Modal open={!!destino} title={`Copiar meios de pagamento para ${destino?.codigo ?? ''}`} okText="Copiar" cancelText="Cancelar" confirmLoading={copiar.isPending} onCancel={aoFechar} onOk={() => form.submit()} width={larguraModal(560)} destroyOnHidden>
      <Form
        form={form}
        layout="vertical"
        onFinish={(v) =>
          destino &&
          copiar.mutate({
            url: `/pos/terminais/${destino.id}/copiar-meios`,
            dados: { terminal_origem_id: v.terminal_origem_id, modo: v.modo, ...(outraEmpresa ? { empresa_origem_id: v.empresa_origem_id } : {}) },
          })
        }
      >
        {empresas.length > 1 && (
          <Form.Item name="empresa_origem_id" label="Empresa de origem">
            <Select options={empresas.map((e) => ({ value: e.id, label: e.nome }))} onChange={() => form.setFieldValue('terminal_origem_id', undefined)} />
          </Form.Item>
        )}
        <Form.Item name="terminal_origem_id" label="Terminal de origem" rules={[{ required: true, message: 'Escolha o terminal de origem.' }]}>
          <Select loading={deOutra.isFetching} options={origens} placeholder="Terminal" />
        </Form.Item>
        <Form.Item name="modo" label="Modo">
          <Radio.Group>
            <Space direction="vertical">
              <Radio value="SUBSTITUIR">Substituir os meios actuais (reaproveita os identificadores por tipo)</Radio>
              <Radio value="ACRESCENTAR">Acrescentar aos meios actuais</Radio>
            </Space>
          </Radio.Group>
        </Form.Item>
        <Alert type="info" showIcon message="As contas são validadas no destino: têm de existir no plano desta empresa." />
      </Form>
    </Modal>
  );
}

/** Lista de terminais (com os meios de pagamento de cada um) para impressão em A4. */
export function documentoTerminais(terminais: Terminal[], series?: Record<string, { serie: string }>): string {
  const lista = tabelaHtml<Terminal>({
    linhas: terminais,
    colunas: [
      { titulo: 'Código', valor: (t) => t.codigo },
      { titulo: 'Nome', valor: (t) => t.nome },
      { titulo: 'Série', valor: (t) => series?.[String(t.id)]?.serie ?? '—' },
      { titulo: 'Tipo', valor: (t) => TIPOS_TERMINAL[t.tipo] ?? t.tipo },
      { titulo: 'Fundo padrão', valor: (t) => t.fundo_maneio_padrao, formato: 'moeda' },
      { titulo: 'Meios activos', valor: (t) => (t.meios_pagamento ?? []).filter((m) => m.ativo).map((m) => m.nome).join(', '), quebrar: true },
      { titulo: 'Caixa', valor: (t) => (t.sessao_aberta ? `Aberta · ${t.sessao_aberta.codigo_sessao} · ${t.sessao_aberta.nome_operador ?? '—'} · desde ${formatarDataHora(t.sessao_aberta.aberto_em)}` : 'Fechada') },
      { titulo: 'Estado', valor: (t) => (t.ativo ? 'Activo' : 'Inactivo') },
    ],
  });
  const meios = terminais
    .filter((t) => (t.meios_pagamento ?? []).length)
    .map((t) =>
      tabelaHtml<MeioPagamento>({
        legenda: `Meios de pagamento · ${t.codigo} — ${t.nome}`,
        linhas: t.meios_pagamento ?? [],
        colunas: [
          { titulo: 'Meio', valor: (m) => m.nome },
          { titulo: 'Tipo', valor: (m) => TIPOS_MEIO[m.tipo] ?? m.tipo },
          { titulo: 'Transitória', valor: (m) => m.conta_transitoria ?? '—' },
          { titulo: 'Liquidação', valor: (m) => m.conta_liquidacao ?? '—' },
          { titulo: 'TPA', valor: (m) => (m.tipo === 'TPA' ? `${m.codigo_tpa ?? '—'} · comissão ${m.comissao_pct ?? 0}%${m.conta_comissao ? ` (${m.conta_comissao})` : ''}` : '—') },
          { titulo: 'Estado', valor: (m) => (m.ativo ? 'Activo' : 'Inactivo') },
        ],
      }),
    )
    .join('');
  return `${pares([['Terminais', terminais.length], ['Activos', terminais.filter((t) => t.ativo).length]], 2)}${lista}${meios}`;
}
