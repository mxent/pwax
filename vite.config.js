import { resolve } from 'path';

export default {
  build: {
    lib: {
      entry: resolve(__dirname, 'resources/js/pwax.js'),
      name: 'Pwax',
      formats: ['iife'],
      fileName: () => 'pwax.js',
    },
    outDir: 'dist',
    minify: true,
    rollupOptions: {
      treeshake: false,
      external: [],
    }
  },
  define: {
    'process.env.NODE_ENV': '"production"',
    'process.env': '{}',
    'process': '{}',
  }
};
