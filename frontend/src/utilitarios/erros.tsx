import { notification } from 'antd';
import { ErroApi } from '@/api/tipos';

/** Mostra o erro da API: a mensagem do servidor e, se houver, a lista de detalhes por campo (chave `erros`). */
export function notificarErro(e: unknown, titulo = 'Não foi possível concluir a operação'): void {
  const erro = e instanceof ErroApi ? e : null;
  const detalhes = erro?.erros ? listaDetalhes(erro.erros) : [];
  notification.error({
    message: titulo,
    description: (
      <>
        <div>{erro?.message ?? (e instanceof Error ? e.message : String(e))}</div>
        {detalhes.length > 0 && (
          <ul style={{ margin: '8px 0 0', paddingLeft: 18 }}>
            {detalhes.slice(0, 8).map((d, i) => (
              <li key={i}>{d}</li>
            ))}
          </ul>
        )}
        {erro?.codigo && <div style={{ marginTop: 6, fontSize: 12, opacity: 0.6 }}>Código: {erro.codigo}</div>}
      </>
    ),
    duration: 8,
  });
}

function listaDetalhes(erros: Record<string, unknown>): string[] {
  return Object.entries(erros).flatMap(([campo, v]) => {
    if (Array.isArray(v)) return v.map((x) => (typeof x === 'string' ? x : `${campo}: ${JSON.stringify(x)}`));
    if (typeof v === 'string') return [v];
    return [];
  });
}
