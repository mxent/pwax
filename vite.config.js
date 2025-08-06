import { resolve } from 'path';

export default {
  build: {
    lib: {
      entry: resolve(__dirname, 'resources/js/pwax.js'),
      name: 'Pwax',
      fileName: () => 'pwax.js',
      formats: ['iife'],
    },
    outDir: 'dist',
    minify: true,
    rollupOptions: {
      output: {
        globals: {
          vue: 'Vue',
          pinia: 'Pinia',
          'vue-router': 'VueRouter',
          dexie: 'Dexie',
        }
      }
    }
  },
  define: {
    'process.env.NODE_ENV': '"production"',
    'process.env': '{}', // fallback
    'process': '{}',     // fallback
  }
};
