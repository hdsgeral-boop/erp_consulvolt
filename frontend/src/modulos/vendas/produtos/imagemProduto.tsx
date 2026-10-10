import { Button, Space, Typography, Upload, message } from 'antd';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useMemo, useState, type CSSProperties } from 'react';
import { enviar, http } from '@/api/cliente';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { notificarErro } from '@/utilitarios/erros';

/**
 * Imagem do produto (paridade 4.1.5 — «Imagem do Produto» do legado, também usada nos cartões do POS).
 * O servidor guarda o ficheiro no disco privado e serve-o em GET /api/vendas/produtos/{id}/imagem com autenticação:
 * por isso a imagem lê-se com o cliente HTTP (token) e mostra-se como URL de objecto (blob:), nunca com <img src> directo.
 */

/** Tipos e tamanho aceites (validados de novo no servidor, pelo conteúdo). */
export const TIPOS_IMAGEM = ['image/jpeg', 'image/png', 'image/webp'];
export const TAMANHO_MAXIMO_IMAGEM = 2 * 1024 * 1024;

/** Validação local antes de enviar (mensagem imediata; o servidor decide). */
export function erroImagem(f: { type: string; size: number }): string | null {
  if (!TIPOS_IMAGEM.includes(f.type)) return 'Só são aceites imagens JPEG, PNG ou WebP.';
  if (f.size > TAMANHO_MAXIMO_IMAGEM) return 'A imagem não pode ter mais de 2 MB.';
  return null;
}

/**
 * URL de objecto da imagem autenticada (`imagem_url` do ProdutoResource, relativo à API). Liberta o URL ao desmontar.
 * Exportado para o POS: `const src = useImagemProduto(produto.imagem_url)`.
 */
export function useImagemProduto(url: string | null | undefined): string | null {
  const consulta = useQuery({
    queryKey: ['vendas', 'imagem-produto', url],
    queryFn: async () => (await http.get<Blob>(url as string, { responseType: 'blob' })).data,
    enabled: !!url,
    staleTime: 600_000,
    retry: false,
  });
  const src = useMemo(() => (consulta.data ? URL.createObjectURL(consulta.data) : null), [consulta.data]);
  useEffect(() => () => { if (src) URL.revokeObjectURL(src); }, [src]);
  return src;
}

/** Miniatura 30 × 30 da coluna CÓDIGO (como no legado: ícone de caixa quando não há imagem). */
export function MiniaturaProduto({ url, tamanho = 30, style }: { url?: string | null; tamanho?: number; style?: CSSProperties }) {
  const src = useImagemProduto(url);
  return (
    <span
      style={{ width: tamanho, height: tamanho, borderRadius: 6, background: '#f1f5f9', border: '1px solid #e2e8f0', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', overflow: 'hidden', flexShrink: 0, ...style }}
      aria-hidden
    >
      {src ? <img src={src} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover' }} /> : <IconeFa nome="box" style={{ color: '#94a3b8', fontSize: tamanho * 0.45 }} />}
    </span>
  );
}

/** Campo «Imagem do Produto» do formulário: pré-visualização, escolher ficheiro e remover. Só com o produto gravado. */
export function CampoImagemProduto({ produtoId, url, podeGerir }: { produtoId?: number; url?: string | null; podeGerir: boolean }) {
  const consulta = useQueryClient();
  const [actual, setActual] = useState<string | null | undefined>(url);
  const [aEnviar, setAEnviar] = useState(false);
  useEffect(() => setActual(url), [url]);

  const actualizar = (novo: string | null) => {
    setActual(novo);
    void consulta.invalidateQueries({ queryKey: ['logistica'] });
  };
  const enviarFicheiro = async (f: File) => {
    const erro = erroImagem(f);
    if (erro) return void message.error(erro);
    if (!produtoId) return;
    setAEnviar(true);
    try {
      const dados = new FormData();
      dados.append('imagem', f);
      const r = await enviar<{ imagem_url: string | null }>('post', `/vendas/produtos/${produtoId}/imagem`, dados);
      message.success(r.mensagem);
      actualizar(r.dados.imagem_url);
    } catch (e) {
      notificarErro(e, 'Não foi possível guardar a imagem');
    } finally {
      setAEnviar(false);
    }
  };
  const remover = async () => {
    if (!produtoId) return;
    try {
      const r = await enviar<{ imagem_url: null }>('delete', `/vendas/produtos/${produtoId}/imagem`);
      message.success(r.mensagem);
      actualizar(null);
    } catch (e) {
      notificarErro(e, 'Não foi possível remover a imagem');
    }
  };

  return (
    <Space align="start" size="middle">
      <MiniaturaProduto url={actual} tamanho={72} />
      <Space direction="vertical" size={4}>
        {!produtoId ? (
          <Typography.Text type="secondary">Grave o produto para lhe juntar uma imagem.</Typography.Text>
        ) : (
          podeGerir && (
            <Space wrap>
              <Upload accept={TIPOS_IMAGEM.join(',')} showUploadList={false} beforeUpload={(f) => { void enviarFicheiro(f); return false; }}>
                <Button size="small" icon={<IconeFa nome="upload" />} loading={aEnviar}>Escolher imagem</Button>
              </Upload>
              {actual && <Button size="small" danger icon={<IconeFa nome="trash" />} onClick={() => void remover()}>Remover</Button>}
            </Space>
          )
        )}
        <Typography.Text type="secondary" style={{ fontSize: 12 }}>JPEG, PNG ou WebP até 2 MB. Mostrada nos cartões do POS.</Typography.Text>
      </Space>
    </Space>
  );
}
