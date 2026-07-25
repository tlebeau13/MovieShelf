<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260725000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add throwaway raw.ingest_heartbeat so analytics has a RAW table to read (issue #3).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            CREATE TABLE raw.ingest_heartbeat (
                id          SERIAL PRIMARY KEY,
                source      VARCHAR(64) NOT NULL,
                observed_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        SQL);

        $this->addSql("INSERT INTO raw.ingest_heartbeat (source) VALUES ('scaffold')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE raw.ingest_heartbeat');
    }
}
