<?php

namespace Mxent\Pwax\Console\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'pwax:install
        {--force : Overwrite any existing files}
        {--views : Also publish view files}';

    protected $description = 'Install the Pwax package (publish config and optionally views)';

    public function handle(): int
    {
        $this->components->info('Installing Pwax...');

        $this->call('vendor:publish', [
            '--tag' => 'pwax-config',
            '--force' => (bool) $this->option('force'),
        ]);

        if ($this->option('views')) {
            $this->call('vendor:publish', [
                '--tag' => 'pwax-views',
                '--force' => (bool) $this->option('force'),
            ]);
        }

        $this->components->info('Pwax installation complete.');
        $this->line('');
        $this->line('  Next steps:');
        $this->line('  - Review <info>config/pwax.php</info>');
        $this->line('  - Add <info><link rel="manifest" href="/manifest.webmanifest"></info> if not using the bundled head include');
        $this->line('  - To enable the service worker, set <info>service_worker.enabled = true</info> in config/pwax.php');
        $this->line('');

        return self::SUCCESS;
    }
}
