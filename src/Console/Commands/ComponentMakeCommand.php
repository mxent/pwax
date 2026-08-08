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
        {--plain : Omit the <style> block}';

    protected $description = 'Scaffold a Pwax component Blade view';

    public function handle(Filesystem $files): int
    {
        $argument = $this->argument('name');
        $name = is_string($argument) ? $argument : '';

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
        $files->put($path, $this->stub($name));

        $this->components->info(sprintf('Component created: %s', $path));
        $this->line('  Serve it with:');
        $this->line(sprintf(
            "  <fg=gray>Route::get('/%s', fn () => pwaxRender('%s'))->name('%s');</>",
            str_replace('.', '/', $name),
            $name,
            $name
        ));

        return self::SUCCESS;
    }

    private function stub(string $name): string
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
}
