{{--
    A component held in `data()` rather than in `components:`.

    This is the shape `<component :is="…">` needs, and the one that broke: an async
    component is a plain object whose meaning is in its functions, so a state island built
    with `JSON.stringify` carried a lookalike with the functions gone — and the client used
    that lookalike in place of the real component.
--}}
<template>
    <div class="dynamic">
        <h1>@{{ label }}</h1>
        <component :is="badge" />
    </div>
</template>

<script>
    const badge = @pwaxImport('components.badge');

    export default {
        data() {
            return {
                badge,
                label: 'Dynamic',
                // Not JSON either: a Date round-trips to a string, and the client would
                // then call a Date method on it.
                published: new Date('2020-01-01T00:00:00Z'),
            };
        },
    };
</script>
