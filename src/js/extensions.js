/**
 * Resolution of configured plugins, directives and client middleware.
 *
 * The server describes each entry as data — either a component module to import or a
 * dotted path to look up on `window` — and this resolves it. Nothing is evaluated.
 *
 * Deliberately nothing. Interpolating these config values straight into the page with
 * `{!! !!}` and executing them would make any path by which a config value can be
 * influenced, in effect, remote code execution — and even without that, a typo in
 * `config/pwax.php` would become a syntax error in a `<script>` block that takes the whole
 * application down with no useful message.
 */

/**
 * Look up a dotted path on a root object without evaluating anything.
 *
 * @param {string} path e.g. "MyLibrary.plugin"
 * @param {object} root
 */
export function resolveGlobal(path, root = window) {
    return String(path)
        .split('.')
        .reduce((carry, segment) => (carry == null ? undefined : carry[segment]), root);
}

/**
 * Resolve one configured extension entry to its value.
 *
 * @param {{type: string, url?: string, export?: string, path?: string}} entry
 * @param {{load: (url: string, exportName?: string) => Promise<any>}} loader
 */
export async function resolveExtension(entry, loader, name = '') {
    if (!entry || typeof entry !== 'object') {
        return undefined;
    }

    if (entry.type === 'module' && entry.url) {
        return loader.load(entry.url, entry.export || '');
    }

    if (entry.type === 'global' && entry.path) {
        const value = resolveGlobal(entry.path);

        if (value === undefined) {
            // Named, because the path alone does not say which entry to go and look at.
            // An application with several globals configured gets one message that could
            // have come from any of them.
            console.warn(
                `pwax: global "${entry.path}"${name ? ` (configured as "${name}")` : ''} is not defined. ` +
                    'Is its script tag present, and does it come before pwax.js?'
            );
        }

        return value;
    }

    return undefined;
}

/**
 * Resolve a map of extension entries, keeping the keys.
 *
 * @param {Record<string, any>} entries
 * @param {{load: (url: string, exportName?: string) => Promise<any>}} loader
 */
export async function resolveExtensions(entries, loader) {
    const names = Object.keys(entries || {});

    const values = await Promise.all(
        names.map(async (name) => {
            try {
                return await resolveExtension(entries[name], loader, name);
            } catch (error) {
                console.error(`pwax: failed to resolve "${name}"`, error);
                return undefined;
            }
        })
    );

    return names.reduce((carry, name, index) => {
        if (values[index] !== undefined) {
            carry[name] = values[index];
        }

        return carry;
    }, {});
}
