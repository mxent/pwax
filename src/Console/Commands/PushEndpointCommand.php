<?php

namespace Mxent\Pwax\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * Scaffold the push-subscription endpoint.
 *
 * The README walks through writing the controller by hand, and every application reaches
 * for the same shape — validate the subscription that arrived, store it keyed on the
 * endpoint, on `DELETE` remove it. Putting the boilerplate on disk means a developer
 * can read it next to the README example, modify it, and not have to keep both in
 * their head.
 *
 * The command writes the file only when missing, so re-running on an already-scaffolded
 * application is a no-op. `--force` overwrites; the migration is not created here —
 * the schema is small enough to write inline, and one command means one decision rather
 * than two.
 */
class PushEndpointCommand extends Command
{
    protected $signature = 'pwax:push-endpoint
        {--force : Overwrite the controller if it already exists}';

    protected $description = 'Scaffold the push-subscription controller for Web Push';

    public function handle(Filesystem $files): int
    {
        $path = app_path('Http/Controllers/PushSubscriptionController.php');

        if ($files->exists($path) && ! $this->option('force')) {
            $this->components->error(sprintf(
                '%s already exists. Pass --force to overwrite, or edit the existing file.',
                $path
            ));

            return self::FAILURE;
        }

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $this->stub());

        $this->components->info(sprintf('Controller created: %s', $path));

        $this->newLine();
        $this->line('  Add these routes to <options=bold>routes/web.php</>:');
        $this->newLine();
        $this->line('  <fg=gray>Route::middleware(\'auth\')->group(function () {</>');
        $this->line('  <fg=gray>    Route::post(\'/push/subscriptions\', [\\App\\Http\\Controllers\\PushSubscriptionController::class, \'subscribe\']);</>');
        $this->line('  <fg=gray>    Route::delete(\'/push/subscriptions\', [\\App\\Http\\Controllers\\PushSubscriptionController::class, \'unsubscribe\']);</>');
        $this->line('  <fg=gray>});</>');
        $this->newLine();
        $this->line('  Then point <options=bold>pwax.push.endpoint</> at the URL you chose:');
        $this->newLine();
        $this->line('  <fg=gray>\'push\' => [\'endpoint\' => \'/push/subscriptions\', ...],</>');
        $this->newLine();
        $this->line('  The controller expects a `push_subscriptions` table. A starter migration:');
        $this->newLine();
        $this->line('  <fg=gray>Schema::create(\'push_subscriptions\', function ($t) {</>');
        $this->line('  <fg=gray>    $t->id();</>');
        $this->line('  <fg=gray>    $t->foreignId(\'user_id\')->constrained()->cascadeOnDelete();</>');
        $this->line('  <fg=gray>    $t->string(\'endpoint\');</>');
        $this->line('  <fg=gray>    $t->string(\'p256dh\', 256);</>');
        $this->line('  <fg=gray>    $t->string(\'auth\', 64);</>');
        $this->line('  <fg=gray>    $t->timestamps();</>');
        $this->line('  <fg=gray>    $t->unique([\'user_id\', \'endpoint\']);</>');
        $this->line('  <fg=gray>});</>');

        return self::SUCCESS;
    }

    private function stub(): string
    {
        return <<<'PHP'
            <?php

            namespace App\Http\Controllers;

            use Illuminate\Http\Request;
            use Illuminate\Routing\Controller;

            /**
             * Store and remove Web Push subscriptions on behalf of the authenticated user.
             *
             * The browser POSTs its `PushSubscription.toJSON()` shape; we deduplicate on the
             * endpoint so a user subscribing twice (different device, refreshed permissions,
             * subscription expiry and reissue) keeps one row per endpoint.
             */
            class PushSubscriptionController extends Controller
            {
                /**
                 * The subscription the runtime posted.
                 *
                 * @param  \Illuminate\Http\Request  $request
                 */
                public function subscribe(Request $request)
                {
                    $data = $request->validate([
                        'endpoint' => ['required', 'url'],
                        'keys.p256dh' => ['required', 'string'],
                        'keys.auth' => ['required', 'string'],
                    ]);

                    $request->user()->pushSubscriptions()->updateOrCreate(
                        ['endpoint' => $data['endpoint']],
                        ['p256dh' => $data['keys']['p256dh'], 'auth' => $data['keys']['auth']],
                    );

                    return response()->noContent();
                }

                /**
                 * Drop the subscription matching the endpoint the runtime unsubscribed.
                 *
                 * @param  \Illuminate\Http\Request  $request
                 */
                public function unsubscribe(Request $request)
                {
                    $request->user()->pushSubscriptions()
                        ->where('endpoint', $request->input('endpoint'))
                        ->delete();

                    return response()->noContent();
                }
            }

            PHP;
    }
}
