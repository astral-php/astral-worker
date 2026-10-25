<?php

declare(strict_types=1);

use Astral\Worker\Database\Schema;
use Database\Migration\Migration;

class CreateFailedJobsTable extends Migration
{
    public function up(\PDO $pdo): void
    {
        Schema::createFailedJobsTable($pdo);
    }

    public function down(\PDO $pdo): void
    {
        Schema::dropTable($pdo, 'failed_jobs');
    }
}
