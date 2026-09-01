/**
 * Stands in for a component library an application already loads.
 *
 * The JSON catalog's third reference form is a dotted path looked up on `window`, for
 * exactly this: something that is already on the page and is not a Pwax component. It
 * has no `emits` the runtime can read, so `workbench/config/pwax.php` names its event
 * with `events` — the one case where configuration has to.
 *
 * Registered through `pwax.scripts`, so it evaluates before the runtime boots.
 */
window.DemoLib = {
    Stamp: {
        props: { label: { type: String, default: '' } },
        emits: ['stamped'],
        template:
            '<button type="button" class="stamp" @click="$emit(\'stamped\')">{{ label }}</button>',
    },
};
