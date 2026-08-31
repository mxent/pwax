{{--
    An action that always rejects, so `/vocabulary` can demonstrate `onError`.

    The document maps the failure to `{"set": {"/status": "$error.message"}}`, and that
    literal is substituted with whatever was thrown.
--}}
<script>
    export default async function () {
        throw new Error('The server said no.');
    };
</script>
