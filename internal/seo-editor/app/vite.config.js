import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath, URL } from 'node:url';

// Сборка кладётся в ../dist. index.php отдаёт dist/index.html из каталога
// internal/seo-editor/ и переписывает относительные пути ассетов
// (./assets/... → dist/assets/...), чтобы они резолвились от URL редактора.
export default defineConfig({
  base: './',
  plugins: [react()],
  build: {
    outDir: fileURLToPath(new URL('../dist', import.meta.url)),
    emptyOutDir: true,
    sourcemap: false,
  },
});
