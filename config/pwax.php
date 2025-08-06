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
     * IndexedDB
     */
    'db_name' => 'pwax_db',
    'db_version' => 1, // Increment this version when you change the database schema
    'db_tables' => [
        // 'table_name' => ['column1', 'column2', ...],
        // Example:
        // 'users' => ['id++', 'name', 'email'],
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
        
    ],

    /**
     * Plugins
     */
    'plugins' => [
        
    ],

    /**
     * Directives
     */
    'directives' => [
        
    ],

    /**
     * Middleware
     */
    'middleware' => [
        
    ],
];
