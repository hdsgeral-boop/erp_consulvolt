import { notification, Typography } from 'antd';
import { categoriaDoErro, detalhesDoErro, gravidadeDoErro, mensagemParaUtilizador, referenciaDoErro } from '@/utilitarios/mensagensErro';

export { categoriaDoErro, detalhesDoErro, gravidadeDoErro, mensagemParaUtilizador, referenciaDoErro } from '@/utilitarios/mensagensErro';

/**
 * Ponto único de apresentação de erros ao utilizador (docs/qualidade/MENSAGENS_ERRO.md). A mensagem vem sempre de
 * `mensagemParaUtilizador` (nunca o `message` cru de uma excepção): aviso para validação/regras/permissões, erro para falhas
 * do sistema; os 5xx mostram a referência (com «copiar») que o suporte cruza com o log; o mesmo erro repetido não empilha
 * (a chave substitui o alerta anterior); `role="alert"` anuncia-o aos leitores de ecrã.
 */
export function notificarErro(e: unknown, titulo = 'Não foi possível concluir a operação'): void {
  const categoria = categoriaDoErro(e);
  if (categoria === 'cancelado') return; // o utilizador (ou o ecrã) cancelou o pedido: não é uma falha
  const mensagem = mensagemParaUtilizador(e);
  const detalhes = detalhesDoErro(e);
  const referencia = referenciaDoErro(e);
  const abrir = gravidadeDoErro(e) === 'error' ? notification.error : notification.warning;
  abrir({
    key: `erro|${categoria}|${titulo}|${mensagem}`,
    title: titulo,
    role: 'alert',
    description: (
      <>
        <div>{mensagem}</div>
        {detalhes.length > 0 && (
          <ul style={{ margin: '8px 0 0', paddingLeft: 18 }}>
            {detalhes.slice(0, 8).map((d, i) => (
              <li key={i}>{d}</li>
            ))}
          </ul>
        )}
        {referencia && (
          <div style={{ marginTop: 6, fontSize: 12, opacity: 0.75 }}>
            Referência:{' '}
            <Typography.Text code copyable={{ text: referencia, tooltips: ['Copiar referência', 'Copiada'] }}>
              {referencia}
            </Typography.Text>
          </div>
        )}
      </>
    ),
    duration: gravidadeDoErro(e) === 'error' ? 10 : 8,
  });
}
