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
| merges at the top level only: a file returning `['ssr' => ['enabled' => true]]` would
| replace the entire `ssr` block, defaults included, and leave the demo running on a
| configuration no application would ever have.
|
*/

$config = require __DIR__ . '/../../config/pwax.php';

// The demo exists to be looked at in a browser, so the two features whose failure modes
// are only visible in one are on.
$config['ssr']['enabled'] = true;
$config['ssr']['timeout'] = 15;
$config['service_worker']['enabled'] = true;

$config['head']['title_template'] = ':title · Pwax';
$config['head']['image'] = '/img/og.png';

$config['manifest']['name'] = 'Pwax Demo';
$config['manifest']['short_name'] = 'Pwax';
$config['manifest']['description'] = 'A demo application for working on the package.';

return $config;
