import { Button, Card, Input, Table, Tag, Tooltip, Typography, theme } from 'antd';
import { UndoOutlined } from '@ant-design/icons';
import { Fragment } from 'react';
import { scrollTabela } from '@/componentes/responsivo';
import { SECCOES, tabelaSeccao, textoActual, textoParaHtml, type ConfigGestao, type DadosGestao, type TextoGravado } from './textosGestao';

/** Parágrafos e listas de um texto (sem HTML: «• » no início da linha = item de lista). */
export function TextoFormatado({ texto }: { texto: string }) {
  const linhas = texto.split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
  const blocos: { lista: boolean; itens: string[] }[] = [];
  for (const l of linhas) {
    const item = /^[•\-*]\s+/.test(l);
    const ultimo = blocos[blocos.length - 1];
    if (item && ultimo?.lista) ultimo.itens.push(l.replace(/^[•\-*]\s+/, ''));
    else blocos.push({ lista: item, itens: [item ? l.replace(/^[•\-*]\s+/, '') : l] });
  }
  return (
    <>
      {blocos.map((b, i) =>
        b.lista ? (
          <ul key={i} style={{ margin: '4px 0 8px 18px' }}>
            {b.itens.map((t, j) => (
              <li key={j}>{t}</li>
            ))}
          </ul>
        ) : (
          <Typography.Paragraph key={i} style={{ marginBottom: 8 }}>
            {b.itens[0]}
          </Typography.Paragraph>
        ),
      )}
    </>
  );
}

/**
 * Separador «Relatório de Gestão» (M-10; legado: aba «gestao», js/relatorio_contas.js:993-1001): secções com o texto
 * automático (gerado a partir dos números) ou o texto editado, «Repor automático» e as tabelas de indicadores.
 */
export function SeparadorGestao({
  dados,
  config,
  textos,
  editavel,
  aoMudar,
}: {
  dados: DadosGestao;
  config: ConfigGestao;
  textos: Record<string, TextoGravado>;
  editavel: boolean;
  aoMudar: (id: string, valor: TextoGravado | null) => void;
}) {
  const { token } = theme.useToken();
  let grupo: string | undefined;
  return (
    <>
      {SECCOES.map((s) => {
        const actual = textoActual(s.id, textos[s.id], dados, config);
        const linhas = tabelaSeccao(s.tabela, dados, config);
        const cabecalho = s.grupo && s.grupo !== grupo ? s.grupo : undefined;
        if (s.grupo) grupo = s.grupo;
        return (
          <Fragment key={s.id}>
            {cabecalho && (
              <Typography.Title level={5} style={{ margin: '20px 0 8px', textTransform: 'uppercase', color: token.colorPrimary }}>
                {cabecalho}
              </Typography.Title>
            )}
            <Card
              size="small"
              style={{ marginBottom: 12 }}
              title={s.titulo}
              extra={
                <span style={{ display: 'inline-flex', gap: 6, alignItems: 'center', flexWrap: 'wrap' }}>
                  {actual.auto ? (
                    <Tooltip title="Texto gerado a partir dos números; actualiza-se sozinho até ser editado.">
                      <Tag color="blue">{s.livre ? 'Modelo' : 'Automático'}</Tag>
                    </Tooltip>
                  ) : (
                    <Tag color="gold">{actual.copiado ? `Copiado de ${actual.copiado}` : 'Editado'}</Tag>
                  )}
                  {editavel && !actual.auto && (
                    <Button size="small" icon={<UndoOutlined />} onClick={() => aoMudar(s.id, null)} title="Repor o texto automático">
                      Repor automático
                    </Button>
                  )}
                </span>
              }
            >
              {editavel ? (
                <Input.TextArea
                  aria-label={`Texto da secção ${s.titulo}`}
                  autoSize={{ minRows: 3, maxRows: 24 }}
                  value={actual.texto}
                  maxLength={100000}
                  onChange={(e) => aoMudar(s.id, { texto: e.target.value, html: textoParaHtml(e.target.value), auto: false })}
                />
              ) : (
                <TextoFormatado texto={actual.texto} />
              )}
              {editavel && (
                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                  Um parágrafo por linha; comece a linha com «• » para uma lista.
                </Typography.Text>
              )}
              {linhas.length > 0 && (
                <Table
                  style={{ marginTop: 8 }}
                  size="small"
                  pagination={false}
                  scroll={scrollTabela()}
                  rowKey={(r) => r[0]}
                  dataSource={linhas}
                  columns={[
                    { title: s.tabela === 'aplicacao' ? 'Aplicação' : 'Indicador', render: (_, r) => r[0] },
                    { title: String(dados.ano), align: 'right', render: (_, r) => r[1] },
                    ...(s.tabela === 'aplicacao' ? [] : [{ title: String(dados.ano_anterior), align: 'right' as const, render: (_: unknown, r: [string, string, string]) => r[2] }]),
                  ]}
                />
              )}
            </Card>
          </Fragment>
        );
      })}
    </>
  );
}
