{{--
    A page that cannot be rendered from its view name alone.

    Requesting its module URL renders it with nothing bound, so it throws. That is not a
    bug — a page rendered with controller data is deliberately not addressable — but it
    does mean the page cannot be precached, and `pwax:precache --verify` exists to say so
    before a user finds out with no connection.
--}}
<template>
    <article class="post">
        <h1>{{ $post->title() }}</h1>
    </article>
</template>
