# mxent/pwax

Progressive Web App built with Laravel & Vue

A Laravel package that seamlessly integrates Vue 3, Vue Router, and Pinia to build Progressive Web Applications with dynamic component loading and SPA-like experiences.

## Features

- 🚀 **Vue 3 Integration** - Modern reactive framework with Composition API support
- 🛣️ **Vue Router** - Client-side routing with hash or history mode
- 🗄️ **Pinia State Management** - Official Vue state management library
- 📦 **Dynamic Component Loading** - Load Vue components on-demand via AJAX
- ⚡ **Code Minification** - Automatic JS/CSS minification for optimal performance
- 🎨 **Customizable** - Extensive configuration options for plugins, directives, and middleware
- 🔄 **Hot Module Injection** - Dynamically inject CSS, JavaScript, and templates from Blade views

## Requirements

- PHP >= 8.2
- Laravel >= 12.0
- Composer

## Installation

Install the package via Composer:

```bash
composer require mxent/pwax
```

The service provider is auto-discovered by Laravel, so no additional setup is required.

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag=pwax-config
```

This creates `config/pwax.php` with the following options:

```php
return [
    // Use hash-based routing (#/) or history mode
    'hash_route' => false,
    
    // Route name for the home page
    'home' => 'index',
    
    // Prefix for internal pwax routes
    'route_prefix' => '__pwax__',
    
    // Override default Blade templates
    'blade' => [
        'content' => null,
        'head' => null,
        'foot' => null,
        'error' => null,
        'loader' => null,
    ],
    
    // Customize loading spinner appearance
    'customization' => [
        'init_spinner_color' => '#0c83ff',
        'init_spinner_bg' => '#f3f3f3',
    ],
    
    // Additional stylesheets to include
    'styles' => [],
    
    // CDN URLs for Vue, Vue Router, and Pinia
    'scripts' => [
        'https://unpkg.com/vue@3.5.18/dist/vue.global.prod.js',
        'https://unpkg.com/vue-router@4.5.1/dist/vue-router.global.prod.js',
        'https://unpkg.com/pinia@3.0.3/dist/pinia.iife.prod.js',
    ],
    
    // Custom Vue plugins
    'plugins' => [],
    
    // Custom Vue directives
    'directives' => [],
    
    // Route middleware to execute before component load
    'middleware' => [],
];
```

## Publishing Views

Publish the view files to customize the layout:

```bash
php artisan vendor:publish --tag=pwax-views
```

This publishes views to `resources/views/vendor/pwax/`.

## Usage

### Creating a Vue Component

Create a Blade view that contains `<template>`, `<script>`, and `<style>` sections:

```blade
{{-- resources/views/components/hello.blade.php --}}
<template>
    <div class="hello">
        <h1>{{ message }}</h1>
        <button @click="increment">Count: {{ count }}</button>
    </div>
</template>

<script>
export default {
    data() {
        return {
            message: 'Hello from PWax!',
            count: 0
        }
    },
    methods: {
        increment() {
            this.count++
        }
    }
}
</script>

<style>
.hello {
    padding: 20px;
    text-align: center;
}
</style>
```

### Rendering a Component

In your controller, use the `vue()` helper function:

```php
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index()
    {
        return vue('components.hello');
    }
    
    public function profile()
    {
        return vue('components.profile', [
            'user' => auth()->user()
        ]);
    }
}
```

### Routing

Define your routes as usual in `routes/web.php`:

```php
Route::get('/', [HomeController::class, 'index'])->name('index');
Route::get('/profile', [HomeController::class, 'profile'])->name('profile');
Route::get('/about', [HomeController::class, 'about'])->name('about');
```

Use the `router()` helper to generate Vue Router compatible paths:

```blade
<template>
    <nav>
        <a :href="router('index')">Home</a>
        <a :href="router('profile')">Profile</a>
        <a :href="router('about')">About</a>
    </nav>
</template>

<script>
export default {
    methods: {
        router(name, params = {}) {
            return window.router(name, params)
        }
    }
}
</script>
```

### Dynamic Imports

Use the `import()` helper to dynamically import components:

```blade
<script>
export default {
    async mounted() {
        // Import a component
        const { default: MyComponent } = await @import('components.my-component')
        
        // Import with variable assignment
        const MyModal = await @import('MyModal from components.modal')
    }
}
</script>
```

## Advanced Configuration

### Custom Plugins

Register custom Vue plugins in `config/pwax.php`:

```php
'plugins' => [
    [
        'name' => 'myPlugin',
        'init' => 'app.use(MyCustomPlugin, { option: "value" })'
    ]
],
```

### Custom Directives

Add custom Vue directives:

```php
'directives' => [
    [
        'name' => 'focus',
        'init' => 'app.directive("focus", { mounted(el) { el.focus() } })'
    ]
],
```

### Middleware

Execute JavaScript before component loads:

```php
'middleware' => [
    [
        'name' => 'auth',
        'init' => 'if (!user.isAuthenticated) { window.location = "/login" }'
    ]
],
```

## Security Best Practices

- ⚠️ **View Names**: Only use trusted view names. The package validates view names to prevent path traversal attacks.
- 🔒 **Config Values**: Avoid adding user-supplied data directly to config arrays like plugins or directives.
- 🛡️ **CSRF Protection**: Ensure CSRF tokens are included in AJAX requests if modifying data.
- 📝 **Input Validation**: Always validate and sanitize user input in your components.

## Testing

Run tests (if implemented):

```bash
composer test
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history and updates.

## Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).

## Credits

- [Mxent Open Source Team](mailto:opensource@mxent.com)
- [All Contributors](../../contributors)

## Support

For issues, questions, or feature requests, please [open an issue](../../issues) on GitHub.

