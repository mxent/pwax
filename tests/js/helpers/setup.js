/**
 * Hand each worker process the bundle `globalSetup` built.
 *
 * `globalSetup` runs once in the main process; `provide`/`inject` is how a value crosses
 * into the test workers, and this puts it where the harness can reach it synchronously.
 */
import { inject } from 'vitest';
import { setWorkerBundle } from './serviceWorkerHarness.js';

setWorkerBundle(inject('pwaxWorkerBundle'));
