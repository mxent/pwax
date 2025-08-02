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
        'https://unpkg.com/vue@3.5.18/dist/vue.global.prod.js',
        'https://unpkg.com/vue-router@4.5.1/dist/vue-router.global.prod.js',
        'https://unpkg.com/pinia@3.0.3/dist/pinia.iife.prod.js',
        'https://unpkg.com/jquery@3.7.1/dist/jquery.js',
    ],
];
