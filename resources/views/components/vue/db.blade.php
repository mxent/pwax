<script>
    export default {
        install(app, options) {
            const db = new Dexie("{{ config('pwax.db_name', 'pwax_db') }}");
            db.version({{ config('pwax.db_version', 1) }}).stores({
                @foreach( config('pwax.db_tables', []) as $tableName => $tableSchema)
                    {{ $tableName }}: `{{ implode(',', $tableSchema) }}`,
                @endforeach
            });

            app.config.globalProperties.$db = db;
            app.db = db;
        }
    };
</script>