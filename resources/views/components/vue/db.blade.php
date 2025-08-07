<script>
    export default {
        install(app, options) {
            let db = {};
            @foreach (config('pwax.db', []) as $dbKey => $dbConfig)
                const d = new Dexie("{{ $dbKey }}");
                d.version({{ $dbConfig['version'] }}).stores({
                    @foreach( $dbConfig['tables'] as $tableName => $tableSchema)
                        {{ $tableName }}: `{{ implode(',', $tableSchema) }}`,
                    @endforeach
                });
                db['{{ $dbKey }}'] = d;
            @endforeach

            app.config.globalProperties.$db = db;
            app.db = db;
        }
    };
</script>