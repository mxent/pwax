/**
 * The runtime's view of who is signed in, kept in step with the server's.
 *
 * `config.identity` is read once from the shell's JSON island. That is fine for an
 * application whose sign-in reloads the document and wrong for this one, because Pwax
 * translates `return redirect('/dashboard')` into a client-side navigation on purpose —
 * so an authenticated visitor goes on sending the guest label, and the service worker
 * goes on filing their pages where every guest can read them.
 *
 * Every page payload now reports the identity it was rendered for. These tests cover the
 * runtime half of that correction: the part that decides whether to believe it.
 */
import { describe, expect, it, vi } from 'vitest';
import { createPageComponent } from '../../src/js/page.js';

const ALICE = 'a1b2c3d4e5f60718';

/**
 * `adoptIdentity` is a plain method on the returned options object, so it can be called
 * directly — no Vue, no router, no mounting.
 */
function subject(identity = null) {
    const config = { identity };
    const page = createPageComponent({
        http: { json: async () => ({}) },
        styles: {},
        config,
        initial: null,
    });

    return { config, adopt: (payload) => page.methods.adoptIdentity(payload) };
}

describe('adopting the server identity', () => {
    it('takes up the identity a payload reports', () => {
        const { config, adopt } = subject(null);

        adopt({ identity: ALICE });

        expect(config.identity).toBe(ALICE);
    });

    it('drops the identity on sign-out', () => {
        const { config, adopt } = subject(ALICE);

        // A guest rendering reports null, and that is a real answer, not a missing one.
        adopt({ identity: null });

        expect(config.identity).toBe(null);
    });

    it('leaves the identity alone when a payload does not report one', () => {
        const { config, adopt } = subject(ALICE);

        // An older server, or a response that is not a page payload at all. Absence is
        // not evidence of a sign-out, and treating it as one would drop a live user into
        // the guest bucket on the next request.
        adopt({ template: '<p>hi</p>' });

        expect(config.identity).toBe(ALICE);
    });

    it('announces a change so an application can clear what the old identity left', () => {
        const { adopt } = subject(null);
        const heard = vi.fn();

        document.addEventListener('pwax:identity', heard);
        adopt({ identity: ALICE });
        document.removeEventListener('pwax:identity', heard);

        expect(heard).toHaveBeenCalledTimes(1);
        expect(heard.mock.calls[0][0].detail).toEqual({ identity: ALICE });
    });

    it('says nothing when the identity has not changed', () => {
        const { adopt } = subject(ALICE);
        const heard = vi.fn();

        document.addEventListener('pwax:identity', heard);
        adopt({ identity: ALICE });
        document.removeEventListener('pwax:identity', heard);

        // Every navigation carries an identity. Announcing each one would make the event
        // useless as a sign-out signal.
        expect(heard).not.toHaveBeenCalled();
    });
});
