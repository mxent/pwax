{{--
    The one action on `/vocabulary` that needs a handler.

    A handler receives the resolved `params` and nothing else — no state setter. Writing
    a result back is `onSuccess`'s job, and the document does exactly that. Returning a
    rejected promise instead would run its `onError`.
--}}
<script>
    export default async function (params) {
        // Stands in for the request a real application would make.
        await new Promise((resolve) => setTimeout(resolve, 150));

        console.info('pwax workbench: saved', params);
    };
</script>
