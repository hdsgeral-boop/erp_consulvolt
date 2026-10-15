/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** Origem da API sem /api (só no deploy Render; vazio = mesma origem). */
  readonly VITE_API_URL?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
