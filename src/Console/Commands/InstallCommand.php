<?php

namespace Mxent\Pwax\Console\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'pwax:install
        {--force : Overwrite any existing files}
        {--views : Also publish the Blade views}
        {--push : Also publish the worked push-endpoint Blade view (the controller is created by pwax:push-endpoint)}
        {--service-worker : Also publish the offline document the worker serves}
        {--no-assets : Skip publishing Vue, Vue Router and Pinia}';

    protected $description = 'Install Pwax: publish the config, the frontend assets and (optionally) the views';

    public function handle(): int
    {
        $this->components->info('Installing Pwax');

        $force = (bool) $this->option('force');

        $this->publish('pwax-config', $force);

        if (! $this->option('no-assets')) {
            $this->publish('pwax-assets', $force);
        }

        if ($this->option('views')) {
            $this->publish('pwax-views', $force);
        }

        if ($this->option('push')) {
            $this->publish('pwax-push', $force);
        }

        if ($this->option('service-worker')) {
            $this->publish('pwax-service-worker', $force);
        }

        $this->newLine();
        $this->components->info('Pwax installed.');

        $this->line('  <options=bold>Next steps</>');
        $this->line('  1. Review <info>config/pwax.php</info>.');
        $this->line('  2. Return a component from a route:');
        $this->line('     <fg=gray>Route::get(\'/\', fn () => pwaxRender(\'pages.home\'))->name(\'index\');</>');
        $this->line('  3. Create your first component:');
        $this->line('     <fg=gray>php artisan pwax:component pages.home</>');
        $this->line('  4. Check your setup at any time with <info>php artisan pwax:doctor</info>.');
        $this->newLine();

        if (! $this->option('no-assets')) {
            $this->components->warn(
                'Re-run `php artisan vendor:publish --tag=pwax-assets --force` after every '
                . 'package update, so the published Vue build matches the one Pwax expects.'
            );
        }

        return self::SUCCESS;
    }

    private function publish(string $tag, bool $force): void
    {
        $this->call('vendor:publish', array_filter([
            '--tag' => $tag,
            '--force' => $force,
        ]));
    }
}
