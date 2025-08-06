export default {
  build: {
    lib: {
      entry: 'resources/js/pwax.js',
      name: 'Pwax',
      fileName: () => 'pwax.js',
      formats: ['iife'],
    },
    rollupOptions: {
      output: {
        globals: {
          vue: 'Vue',
          'vue-router': 'VueRouter',
          pinia: 'Pinia',
          dexie: 'Dexie',
        },
      },
    },
  },
  define: {
    'process.env.NODE_ENV': '"production"',
    'process.env': '{}',
    'process': '{}',
  }
};
