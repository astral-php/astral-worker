<?php

declare(strict_types=1);

use Astral\Worker\Database\Schema;
use Database\Migration\Migration;

class CreateJobsTable extends Migration
{
    public function up(\PDO $pdo): void
    {
        Schema::createJobsTable($pdo);
    }

    public function down(\PDO $pdo): void
    {
        Schema::dropTable($pdo, 'jobs');
    }
}
