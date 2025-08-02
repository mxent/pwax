<?php

return [
    /**
     * Main Config
     */
    'hash_route' => false,
    'home' => 'index', // route name for the home page
    'route_prefix' => '__pwax__',

    /**
     * Blade files
     */
    'blade' => [
        'content' => null,
        'head' => null,
        'foot' => null,
        'error' => null,
        'loader' => null,
    ],

    /**
     * Customization
     */
    'customization' => [
        'init_spinner_color' => '#0c83ff',
        'init_spinner_bg' => '#f3f3f3',
    ],

    /**
     * Styles
     */
    'styles' => [

    ],

    /**
     * Scripts
     */
    'scripts' => [
        'https://unpkg.com/vue@3.4.29/dist/vue.global.prod.js',
        'https://unpkg.com/vue-router@4.3.3/dist/vue-router.global.prod.js',
        'https://unpkg.com/@vueuse/shared@10.11.0/index.iife.min.js',
        'https://unpkg.com/@vueuse/core@10.11.0/index.iife.min.js',
        'https://unpkg.com/pinia@2.1.7/dist/pinia.iife.js',
        'https://unpkg.com/jquery@3.7.1/dist/jquery.min.js',
        'https://unpkg.com/mitt@3.0.1/dist/mitt.umd.js',
    ],
];
