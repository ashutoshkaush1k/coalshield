<?php

use yii\db\Migration;

/**
 * postgis, pg_trgm, pgcrypto (brief Phase 1).
 *
 * postgis is not a "trusted" extension, so on a fresh database a superuser must create it once
 * (docs/SETUP_WINDOWS.md; the Docker image does it itself). IF NOT EXISTS makes this a no-op then.
 * safeDown leaves the extensions in place: other databases objects (and PostGIS's own tables)
 * depend on them and dropping them needs a superuser.
 */
class m260927_000001_enable_extensions extends Migration
{
    public function safeUp()
    {
        $this->execute('CREATE EXTENSION IF NOT EXISTS postgis');
        $this->execute('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $this->execute('CREATE EXTENSION IF NOT EXISTS pgcrypto');
    }

    public function safeDown()
    {
        echo "    > extensions postgis, pg_trgm, pgcrypto are kept (drop them as superuser if needed)\n";
    }
}
