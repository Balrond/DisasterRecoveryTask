<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260129192219 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_rates_pair_validfrom');
        $this->addSql('CREATE UNIQUE INDEX uniq_rates_pair_validfrom ON rates (source_currency, target_currency, valid_from)');
        $this->addSql('ALTER TABLE transactions DROP CONSTRAINT fk_eaa81a4c19eb6921');
        $this->addSql('ALTER TABLE transactions ADD client_external_id VARCHAR(10) NOT NULL');
        $this->addSql('ALTER TABLE transactions ALTER client_id DROP NOT NULL');
        $this->addSql('ALTER TABLE transactions ADD CONSTRAINT FK_EAA81A4C19EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX idx_tx_clientext_createdat ON transactions (client_external_id, created_at)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX uniq_rates_pair_validfrom');
        $this->addSql('CREATE INDEX idx_rates_pair_validfrom ON rates (source_currency, target_currency, valid_from)');
        $this->addSql('ALTER TABLE transactions DROP CONSTRAINT FK_EAA81A4C19EB6921');
        $this->addSql('DROP INDEX idx_tx_clientext_createdat');
        $this->addSql('ALTER TABLE transactions DROP client_external_id');
        $this->addSql('ALTER TABLE transactions ALTER client_id SET NOT NULL');
        $this->addSql('ALTER TABLE transactions ADD CONSTRAINT fk_eaa81a4c19eb6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
