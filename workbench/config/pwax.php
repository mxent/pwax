<?php

/*
|--------------------------------------------------------------------------
| Configuration for the workbench demo application
|--------------------------------------------------------------------------
|
| Served by `php vendor/bin/testbench serve`. It is the package's own config with a
| handful of overrides, read from the real file rather than restated here so it cannot
| drift — and so this file shows only what the demo changes.
|
| Built from the whole array rather than the overrides alone because `mergeConfigFrom()`
| merges at the top level only: a file returning `['service_worker' => ['enabled' => true]]`
| would replace the entire `service_worker` block, defaults included, and leave the demo
| running on a configuration no application would ever have.
|
*/

$config = require __DIR__ . '/../../config/pwax.php';

// The demo exists to be looked at in a browser, and the worker's failure modes are only
// visible in one.
$config['service_worker']['enabled'] = true;

$config['head']['title_template'] = ':title · Pwax';
$config['head']['image'] = '/img/og.png';

$config['manifest']['name'] = 'Pwax Demo';
$config['manifest']['short_name'] = 'Pwax';
$config['manifest']['description'] = 'A demo application for working on the package.';

// A stable identity, so the demo is not reinstalled as a second app whenever `start_url`
// is edited — which is the whole reason `pwax:doctor` asks for one.
$config['manifest']['id'] = '/';

// Solid squares from `workbench/public/img`, generated rather than designed. They are here
// so the demo is actually installable: without a 192 and a 512 no browser offers to install
// it, and the one claim hardest to check by reading code is the one about being installable.
$config['manifest']['icons'] = [
    ['src' => '/img/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
    ['src' => '/img/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
    ['src' => '/img/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
];

return $config;
