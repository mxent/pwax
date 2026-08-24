<?php

namespace Mxent\Pwax\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Mxent\Pwax\Exceptions\InvalidComponentId;
use Mxent\Pwax\Support\ComponentId;

class ComponentMakeCommand extends Command
{
    protected $signature = 'pwax:component
        {name : Dot-delimited view name, e.g. pages.home}
        {--force : Overwrite the file if it already exists}
        {--plain : Omit the <style> block}
        {--plugin : Scaffold a Vue plugin}
        {--directive : Scaffold a Vue directive}
        {--middleware : Scaffold a client middleware}';

    protected $description = 'Scaffold a Pwax component Blade view';

    public function handle(Filesystem $files): int
    {
        $argument = $this->argument('name');
        $name = is_string($argument) ? $argument : '';

        $scaffold = collect(['plugin', 'directive', 'middleware'])
            ->filter(fn ($flag) => (bool) $this->option($flag))
            ->values();

        if ($scaffold->count() > 1) {
            $this->components->error('Pass at most one of --plugin, --directive, --middleware.');

            return self::FAILURE;
        }

        try {
            ComponentId::validate($name);
        } catch (InvalidComponentId $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if (str_contains($name, '::')) {
            $this->components->error('Namespaced views belong to their own package and cannot be generated here.');

            return self::FAILURE;
        }

        $path = resource_path('views/' . str_replace('.', '/', $name) . '.blade.php');

        if ($files->exists($path) && ! $this->option('force')) {
            $this->components->error(sprintf('%s already exists. Pass --force to overwrite.', $path));

            return self::FAILURE;
        }

        $files->ensureDirectoryExists(dirname($path));

        $kind = $scaffold->first();

        $stub = match ($kind) {
            'plugin' => $this->pluginStub($name),
            'directive' => $this->directiveStub($name),
            'middleware' => $this->middlewareStub($name),
            default => $this->componentStub($name),
        };

        $files->put($path, $stub);

        $this->components->info(sprintf('%s created: %s', $kind === null ? 'Component' : ucfirst($kind), $path));

        if ($kind === null) {
            $this->line('  Serve it with:');
            $this->line(sprintf(
                "  <fg=gray>Route::get('/%s', fn () => pwaxRender('%s'))->name('%s');</>",
                str_replace('.', '/', $name),
                $name,
                $name
            ));
        } else {
            $this->line(sprintf('  Register it in config/pwax.php under the matching key (e.g. %s).', $this->configKeyFor($kind)));
        }

        return self::SUCCESS;
    }

    /**
     * The dot-suffix under which a Vue plugin / directive / middleware is registered
     * inside the `pwax.vue.*` config group.
     */
    private function configKeyFor(string $kind): string
    {
        return match ($kind) {
            'plugin' => 'plugins',
            'directive' => 'directives',
            'middleware' => 'middleware',
            default => throw new \InvalidArgumentException('Unknown scaffold kind: ' . $kind),
        };
    }

    private function componentStub(string $name): string
    {
        $title = json_encode(Str::headline(str_replace('.', ' ', $name)), JSON_THROW_ON_ERROR);
        $class = Str::kebab(Str::afterLast($name, '.'));

        // `@{{ }}` keeps Blade from consuming Vue's own interpolation.
        $style = $this->option('plain') ? '' : <<<BLADE


            <style scoped>
                .{$class} {
                    padding: 2rem;
                }
            </style>
            BLADE;

        return <<<BLADE
            <template>
                <div class="{$class}">
                    <h1>@{{ title }}</h1>
                    <button type="button" @click="count++">
                        Clicked @{{ count }} times
                    </button>
                </div>
            </template>

            <script>
                export default {
                    data() {
                        return {
                            title: {$title},
                            count: 0,
                        };
                    },
                };
            </script>{$style}

            BLADE;
    }

    /**
     * A Vue plugin — registered under `plugins` in the config.
     *
     * The default export is the plugin object itself. The runtime calls
     * `app.use(plugin, ...options)` for each entry.
     */
    private function pluginStub(string $name): string
    {
        return <<<'BLADE'
            <script>
            /**
             * A Vue plugin. Registered under `plugins` in config/pwax.php.
             *
             * The runtime installs each entry with `app.use(plugin, options)`, so
             * this default export is the same shape you would hand to `app.use()`:
             *
             *   {
             *     install(app, options) { ... },
             *   }
             *
             * The function form is also accepted:
             *
             *   export default function (app, options) { ... }
             */
            export default {
                install(app, options = {}) {
                    // Register global properties, components, directives — anything
                    // you would normally do inside `app.use(...)`.
                },
            };
            </script>

            BLADE;
    }

    /**
     * A Vue directive — registered under `directives` in the config.
     */
    private function directiveStub(string $name): string
    {
        $directiveName = Str::kebab(Str::afterLast($name, '.'));

        return <<<BLADE
            <script>
            /**
             * A Vue directive. Registered under `directives` in config/pwax.php.
             *
             * The default export is the directive definition. The `name` is the
             * shorthand you can use in templates — `v-{$directiveName}` — and the
             * lifecycle hooks are the same ones Vue exposes to directives
             * registered via `app.directive()`.
             *
             *   {
             *     name: '{$directiveName}',
             *     bind(el, binding, vnode) { ... },
             *     inserted(el, binding, vnode) { ... },
             *     update(el, binding, vnode, oldVnode) { ... },
             *     unbind(el, binding, vnode) { ... },
             *   }
             */
            export default {
                name: '{$directiveName}',
                bind(el) {
                    el.focus();
                },
            };
            </script>

            BLADE;
    }

    /**
     * A client middleware — registered under `vue.middleware` in the config.
     */
    private function middlewareStub(string $name): string
    {
        $middleName = Str::kebab(Str::afterLast($name, '.'));

        return <<<BLADE
            <script>
            /**
             * A client middleware. Registered under `vue.middleware` in
             * config/pwax.php, and referenced from a page's `meta.middleware`
             * array by the value of `name` below.
             *
             * The default export is the middleware definition. The runtime
             * calls `before(next)` and `await after(next)`; either may redirect
             * or short-circuit by calling `next()` with no further middleware
             * to run.
             *
             *   {
             *     name: '{$middleName}',
             *     before(next) { ... next(); },
             *     async after(next) { ... await next(); },
             *   }
             *
             * A function default export is also accepted:
             *
             *   export default async function ({ component, meta, redirect }) { ... }
             */
            export default {
                name: '{$middleName}',
                before(next) {
                    next();
                },
            };
            </script>

            BLADE;
    }
}
