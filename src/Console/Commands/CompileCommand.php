<?php

namespace Mxent\Pwax\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Mxent\Pwax\Pwa\ComponentRegistry;
use Mxent\Pwax\Pwax;
use Mxent\Pwax\Support\RenderFunctionStore;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Compile every template to a Vue render function, ahead of time.
 *
 * Entirely optional, and the default path does not use it. Pwax's premise is that there is
 * no build step: templates go to the browser as strings and Vue compiles them there. This
 * command is for teams that already run CI and would rather spend a build minute than the
 * bytes and the CSP allowance.
 *
 * What it buys, measured on the builds this package vendors:
 *
 *   - `vue.runtime.global.prod.js` instead of `vue.global.prod.js`: 40.7 kB gzipped
 *     against 60.8 kB, so about 20 kB off every cold load.
 *   - No `Function` constructor at runtime, so `script-src 'unsafe-eval'` can go — the one
 *     requirement the README says the package cannot be configured out of.
 *   - No template compilation per navigation.
 *
 * Run it after every deploy that changes a component, and never add it to `pwax:install`.
 * An application that forgets is not broken *by default*: `Shell` serves the full Vue build
 * whenever the store is empty. It is only the half-updated store — compiled once, then a
 * component edited — that runtime-only Vue cannot render, which is what `pwax:doctor`
 * checks for and why this command records the views it covered.
 */
class CompileCommand extends Command
{
    protected $signature = 'pwax:compile
                            {--clear : Delete the compiled render functions and stop}
                            {--json : Output the result as JSON}';

    protected $description = 'Precompile Pwax templates to Vue render functions (optional; requires Node)';

    public function handle(
        Config $config,
        Pwax $pwax,
        ComponentRegistry $registry,
        RenderFunctionStore $store,
    ): int {
        if ($this->option('clear')) {
            $store->flush();
            $this->components->info('Compiled render functions removed. Templates compile in the browser again.');

            return self::SUCCESS;
        }

        // The same discovery `pwax:precache` uses, so `service_worker.components`,
        // `exclude` and `namespaces` all apply and there is no second list to keep in step.
        $registry->forget();
        $views = array_column($registry->precachable(), 'view');

        if ($views === []) {
            $this->components->warn('No components found to compile.');

            return self::SUCCESS;
        }

        $templates = [];
        $covered = [];
        $failures = [];

        foreach ($views as $view) {
            try {
                // No data. A page rendered with controller data cannot be precompiled —
                // its template is particular to a request — which is the same boundary the
                // compile cache draws, for the same reason. Such a view usually raises here
                // on the undefined variable, and is reported below rather than swallowed.
                $template = $pwax->compile($view)->template;

                if (trim($template) === '') {
                    continue;
                }

                $key = RenderFunctionStore::key($template);

                $templates[$key] = $template;
                $covered[$view] = $key;
            } catch (Throwable $e) {
                $failures[$view] = $e->getMessage();
            }
        }

        if ($templates === []) {
            $this->components->warn('Nothing to compile: no component produced a template.');

            foreach ($failures as $view => $message) {
                $this->components->warn(sprintf('%s could not be rendered: %s', $view, $message));
            }

            return $failures === [] ? self::SUCCESS : self::FAILURE;
        }

        $result = $this->run_($config, $templates);

        if ($result === null) {
            return self::FAILURE;
        }

        if (($result['ok'] ?? false) !== true) {
            $this->components->error((string) ($result['message'] ?? 'The template compiler failed.'));

            return self::FAILURE;
        }

        /** @var array<string, string> $functions */
        $functions = array_filter((array) ($result['functions'] ?? []), 'is_string');
        /** @var array<string, string> $errors */
        $errors = array_filter((array) ($result['errors'] ?? []), 'is_string');

        $store->write($functions, [
            'vue' => (string) ($result['version'] ?? ''),
            'compiled_at' => date(DATE_ATOM),
            // View name to store key, so `pwax:doctor` can name what a stale store is
            // missing rather than reporting a count that means nothing on its own.
            'views' => array_intersect($covered, array_keys($functions)),
        ]);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'compiled' => count($functions),
                'errors' => $errors,
                'unreadable' => $failures,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $errors === [] && $failures === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->components->info(sprintf('Compiled %d template(s) to render functions.', count($functions)));
        $this->components->twoColumnDetail('Store', $store->path());
        $this->components->twoColumnDetail('Vue', (string) ($result['version'] ?: 'unknown'));

        foreach ($failures as $view => $message) {
            $this->components->warn(sprintf('%s could not be rendered: %s', $view, $message));
        }

        foreach ($errors as $hash => $message) {
            $this->components->error(sprintf('A template (%s) would not compile: %s', $hash, $message));
        }

        if ($errors !== [] || $failures !== []) {
            // Loudly, and non-zero. A partial store is the one state that is worse than
            // none: the app ships runtime-only Vue and one page renders nothing.
            $this->components->error(
                'Some templates were not compiled. Fix them, or set assets.vue_build to "full".'
            );

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info("Set 'assets' => ['vue_build' => 'runtime'] to serve the smaller Vue build.");

        return self::SUCCESS;
    }

    /**
     * Hand the whole batch to Node in one go.
     *
     * Not named `run()`: `Illuminate\Console\Command::run()` is public and part of the
     * Symfony contract, so a private one here is a fatal error at class-load time.
     *
     * @param  array<string, string>  $templates
     * @return array<string, mixed>|null
     */
    private function run_(Config $config, array $templates): ?array
    {
        $script = dirname(__DIR__, 3) . '/bin/compile-templates.mjs';
        $node = (string) ($config->get('pwax.assets.node') ?: 'node');

        $process = new Process(
            [$node, $script],
            base_path(),
            null,
            (string) json_encode([
                'version' => $config->get('pwax.assets.versions.vue'),
                'templates' => $templates,
            ], JSON_THROW_ON_ERROR),
            120,
        );

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $this->components->error('The template compiler timed out.');

            return null;
        } catch (Throwable $e) {
            $this->components->error(sprintf('Could not start Node (%s): %s', $node, $e->getMessage()));

            return null;
        }

        if (! $process->isSuccessful() && $process->getOutput() === '') {
            $this->components->error(sprintf(
                "Node exited with %s.\n%s",
                (string) $process->getExitCode(),
                trim($process->getErrorOutput()) ?: 'No output.'
            ));

            return null;
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

            return $decoded;
        } catch (Throwable) {
            $this->components->error('The template compiler did not return JSON: ' . trim($process->getOutput()));

            return null;
        }
    }
}
