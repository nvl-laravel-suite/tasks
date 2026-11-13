<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Nvl\Support\Config\PackageStorage;

/** Preserve the calendar storage migration record. SQLite and PostgreSQL already support the accepted date range. */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return PackageStorage::connection('tasks');
    }

    public function up(): void {}

    public function down(): void {}
};
