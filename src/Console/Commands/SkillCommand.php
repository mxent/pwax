<?php

namespace Mxent\Pwax\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * Publish the Pwax skill — the file an AI assistant reads before it touches a
 * Pwax-using project.
 *
 * The package ships a template that describes the contract: where pages live,
 * what a component looks like, which config keys cross the runtime boundary,
 * which Blade directives collide with Vue, what the doctor warnings mean. An
 * application developer who points their AI assistant at that file gets work
 * that needs fewer corrections; one who does not gets the AI reinventing the
 * package's conventions one guess at a time.
 *
 * The default target is `.ai/skills/pwax/` in the application root. That is
 * where the agent customisation tools (Copilot, Claude Code, Codex) expect a
 * project-local skill to live. `--path` overrides the target directory.
 * `--force` overwrites an existing file. The bundled template is the source
 * of truth: it is the file a maintainer edits, and the command copies it.
 */
class SkillCommand extends Command
{
    protected $signature = 'pwax:skill
        {--path= : Override the target directory (default: .ai/skills/pwax)}
        {--force : Overwrite an existing skill file}';

    protected $description = 'Publish the Pwax skill so an AI assistant knows the package conventions';

    public function handle(): int
    {
        $target = $this->resolveTarget();
        $absoluteTarget = base_path($target);
        $destination = $absoluteTarget . '/SKILL.md';

        if (is_file($destination) && ! $this->option('force')) {
            $this->components->error("A skill already exists at {$destination}. Re-run with --force to overwrite.");

            return self::FAILURE;
        }

        $template = $this->loadTemplate();

        if ($template === null) {
            $this->components->error('The bundled skill template could not be read. Check that resources/ai/pwax-skill.md is shipped.');

            return self::FAILURE;
        }

        $filesystem = new Filesystem;

        try {
            $filesystem->ensureDirectoryExists($absoluteTarget, 0o755);
        } catch (\Throwable) {
            $this->components->error("Could not create the target directory {$absoluteTarget}.");

            return self::FAILURE;
        }

        $filesystem->put($destination, $template);

        $this->components->info("Published the Pwax skill to {$destination}.");
        $this->newLine();
        $this->line('  <options=bold>Next steps</>');
        $this->line('  1. Point your AI assistant at the file:');
        $this->line('     <fg=gray>.ai/skills/pwax/SKILL.md</>');
        $this->line('  2. Or symlink it into the directory your tool watches:');
        $this->line('     <fg=gray>ln -s ../../.ai/skills/pwax/SKILL.md ~/.<assistant>/skills/pwax</>');
        $this->line('  3. Re-run after every package upgrade so the skill reflects the current conventions.');

        return self::SUCCESS;
    }

    /**
     * The target directory, relative to the project root.
     */
    private function resolveTarget(): string
    {
        $override = $this->option('path');

        if (is_string($override)) {
            $override = trim($override);

            if ($override !== '') {
                return rtrim($override, '/');
            }
        }

        return '.ai/skills/pwax';
    }

    private function loadTemplate(): ?string
    {
        $path = dirname(__DIR__, 3) . '/resources/ai/pwax-skill.md';

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        return (string) file_get_contents($path);
    }
}
