<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261008121421 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema: venue, sections, seats, event, platforms, tickets, runs, jobs, job attempts, ticket action ledger, webhook deliveries';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE events (id INT UNSIGNED AUTO_INCREMENT NOT NULL, status VARCHAR(12) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, name VARCHAR(160) NOT NULL, starts_at DATETIME NOT NULL, venue_id SMALLINT UNSIGNED NOT NULL, INDEX idx_events_venue_starts (venue_id, starts_at), INDEX idx_events_venue (venue_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE job_attempts (id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, attempt_no SMALLINT UNSIGNED NOT NULL, worker_id VARCHAR(64) NOT NULL, outcome VARCHAR(20) DEFAULT NULL, http_status SMALLINT UNSIGNED DEFAULT NULL, latency_ms INT UNSIGNED DEFAULT NULL, retry_after_ms INT UNSIGNED DEFAULT NULL, error VARCHAR(500) DEFAULT NULL, started_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, job_id BIGINT UNSIGNED NOT NULL, INDEX idx_job_attempts_outcome_started (outcome, started_at), INDEX idx_job_attempts_job (job_id), UNIQUE INDEX uq_job_attempts_job_no (job_id, attempt_no), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE jobs (id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, status VARCHAR(16) NOT NULL, attempts SMALLINT UNSIGNED NOT NULL, max_attempts SMALLINT UNSIGNED NOT NULL, next_attempt_at DATETIME DEFAULT NULL, last_outcome VARCHAR(20) DEFAULT NULL, last_error VARCHAR(500) DEFAULT NULL, worker_id VARCHAR(64) DEFAULT NULL, duration_ms INT UNSIGNED DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, run_id BINARY(16) NOT NULL, ticket_id BIGINT UNSIGNED NOT NULL, platform_id SMALLINT UNSIGNED DEFAULT NULL, INDEX idx_jobs_run_status (run_id, status), INDEX idx_jobs_ticket (ticket_id), INDEX idx_jobs_status_next (status, next_attempt_at), INDEX idx_jobs_worker_updated (worker_id, updated_at), INDEX idx_jobs_run (run_id), INDEX idx_jobs_platform (platform_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE platforms (id SMALLINT UNSIGNED AUTO_INCREMENT NOT NULL, color VARCHAR(7) NOT NULL, client_pace_ratio NUMERIC(3, 2) NOT NULL, failure_rate NUMERIC(4, 3) NOT NULL, buyer_rate NUMERIC(4, 3) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, code VARCHAR(32) NOT NULL, base_url VARCHAR(255) NOT NULL, rate_limit_per_min INT UNSIGNED NOT NULL, fee_bps SMALLINT UNSIGNED NOT NULL, min_price_cents INT UNSIGNED NOT NULL, webhook_secret VARCHAR(64) NOT NULL, UNIQUE INDEX uq_platforms_code (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE runs (id BINARY(16) NOT NULL, number INT UNSIGNED NOT NULL, status VARCHAR(16) NOT NULL, total_jobs INT UNSIGNED NOT NULL, completed_jobs INT UNSIGNED NOT NULL, failed_jobs INT UNSIGNED NOT NULL, skipped_jobs INT UNSIGNED NOT NULL, dead_lettered_jobs INT UNSIGNED NOT NULL, started_at DATETIME DEFAULT NULL, paused_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, type VARCHAR(16) NOT NULL, selection JSON NOT NULL, params JSON NOT NULL, replay_of_id BINARY(16) DEFAULT NULL, event_id INT UNSIGNED NOT NULL, target_platform_id SMALLINT UNSIGNED DEFAULT NULL, INDEX idx_runs_status_created (status, created_at), INDEX idx_runs_replay_of (replay_of_id), INDEX idx_runs_event (event_id), INDEX idx_runs_target_platform (target_platform_id), UNIQUE INDEX uq_runs_number (number), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE seats (id BIGINT UNSIGNED NOT NULL, row_label VARCHAR(4) NOT NULL, seat_number SMALLINT UNSIGNED NOT NULL, seat_type VARCHAR(10) NOT NULL, map_x SMALLINT NOT NULL, map_y SMALLINT NOT NULL, created_at DATETIME NOT NULL, section_id SMALLINT UNSIGNED NOT NULL, INDEX idx_seats_section (section_id), UNIQUE INDEX uq_seats_position (section_id, row_label, seat_number), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sections (id SMALLINT UNSIGNED AUTO_INCREMENT NOT NULL, status VARCHAR(8) NOT NULL, seat_count INT UNSIGNED NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, code VARCHAR(8) NOT NULL, tier VARCHAR(8) NOT NULL, map_geometry JSON NOT NULL, venue_id SMALLINT UNSIGNED NOT NULL, INDEX idx_sections_status (status), INDEX idx_sections_venue (venue_id), UNIQUE INDEX uq_sections_venue_code (venue_id, code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ticket_actions (id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, action VARCHAR(16) NOT NULL, state VARCHAR(8) NOT NULL, idempotency_key VARCHAR(36) NOT NULL, from_price_cents INT UNSIGNED DEFAULT NULL, to_price_cents INT UNSIGNED DEFAULT NULL, from_barcode VARCHAR(20) DEFAULT NULL, to_barcode VARCHAR(20) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, ticket_id BIGINT UNSIGNED NOT NULL, run_id BINARY(16) NOT NULL, job_id BIGINT UNSIGNED NOT NULL, from_platform_id SMALLINT UNSIGNED DEFAULT NULL, to_platform_id SMALLINT UNSIGNED DEFAULT NULL, INDEX idx_ticket_actions_run_state (run_id, state), INDEX idx_ticket_actions_ticket_created (ticket_id, created_at), INDEX idx_ticket_actions_ticket (ticket_id), INDEX idx_ticket_actions_run (run_id), INDEX idx_ticket_actions_job (job_id), INDEX idx_ticket_actions_from_platform (from_platform_id), INDEX idx_ticket_actions_to_platform (to_platform_id), UNIQUE INDEX uq_ticket_actions_ticket_run (ticket_id, run_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE tickets (id BIGINT UNSIGNED NOT NULL, status VARCHAR(12) NOT NULL, external_ref VARCHAR(64) DEFAULT NULL, face_value_cents INT UNSIGNED NOT NULL, price_cents INT UNSIGNED NOT NULL, currency VARCHAR(3) NOT NULL, barcode VARCHAR(20) NOT NULL, version INT UNSIGNED DEFAULT 1 NOT NULL, last_run_id BINARY(16) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, event_id INT UNSIGNED NOT NULL, seat_id BIGINT UNSIGNED NOT NULL, section_id SMALLINT UNSIGNED NOT NULL, platform_id SMALLINT UNSIGNED DEFAULT NULL, INDEX idx_tickets_event_section_status (event_id, section_id, status), INDEX idx_tickets_platform_status (platform_id, status), INDEX idx_tickets_event (event_id), INDEX idx_tickets_seat (seat_id), INDEX idx_tickets_section (section_id), INDEX idx_tickets_platform (platform_id), UNIQUE INDEX uq_tickets_event_seat (event_id, seat_id), UNIQUE INDEX uq_tickets_platform_ref (platform_id, external_ref), UNIQUE INDEX uq_tickets_barcode (barcode), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE venues (id SMALLINT UNSIGNED AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, code VARCHAR(32) NOT NULL, name VARCHAR(120) NOT NULL, UNIQUE INDEX uq_venues_code (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE webhook_deliveries (id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, received_at DATETIME NOT NULL, delivery_id VARCHAR(36) NOT NULL, event VARCHAR(40) NOT NULL, payload JSON NOT NULL, outcome VARCHAR(24) NOT NULL, platform_id SMALLINT UNSIGNED NOT NULL, ticket_id BIGINT UNSIGNED DEFAULT NULL, INDEX idx_webhook_deliveries_ticket (ticket_id), INDEX idx_webhook_deliveries_platform (platform_id), UNIQUE INDEX uq_webhook_deliveries_platform_delivery (platform_id, delivery_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE events ADD CONSTRAINT FK_5387574A40A73EBA FOREIGN KEY (venue_id) REFERENCES venues (id)');
        $this->addSql('ALTER TABLE job_attempts ADD CONSTRAINT FK_D880421FBE04EA9 FOREIGN KEY (job_id) REFERENCES jobs (id)');
        $this->addSql('ALTER TABLE jobs ADD CONSTRAINT FK_A8936DC584E3FEC4 FOREIGN KEY (run_id) REFERENCES runs (id)');
        $this->addSql('ALTER TABLE jobs ADD CONSTRAINT FK_A8936DC5700047D2 FOREIGN KEY (ticket_id) REFERENCES tickets (id)');
        $this->addSql('ALTER TABLE jobs ADD CONSTRAINT FK_A8936DC5FFE6496F FOREIGN KEY (platform_id) REFERENCES platforms (id)');
        $this->addSql('ALTER TABLE runs ADD CONSTRAINT FK_803A7B1FDA7FCFC8 FOREIGN KEY (replay_of_id) REFERENCES runs (id)');
        $this->addSql('ALTER TABLE runs ADD CONSTRAINT FK_803A7B1F71F7E88B FOREIGN KEY (event_id) REFERENCES events (id)');
        $this->addSql('ALTER TABLE runs ADD CONSTRAINT FK_803A7B1F78DC0665 FOREIGN KEY (target_platform_id) REFERENCES platforms (id)');
        $this->addSql('ALTER TABLE seats ADD CONSTRAINT FK_BFE25750D823E37A FOREIGN KEY (section_id) REFERENCES sections (id)');
        $this->addSql('ALTER TABLE sections ADD CONSTRAINT FK_2B96439840A73EBA FOREIGN KEY (venue_id) REFERENCES venues (id)');
        $this->addSql('ALTER TABLE ticket_actions ADD CONSTRAINT FK_984A8BD700047D2 FOREIGN KEY (ticket_id) REFERENCES tickets (id)');
        $this->addSql('ALTER TABLE ticket_actions ADD CONSTRAINT FK_984A8BD84E3FEC4 FOREIGN KEY (run_id) REFERENCES runs (id)');
        $this->addSql('ALTER TABLE ticket_actions ADD CONSTRAINT FK_984A8BDBE04EA9 FOREIGN KEY (job_id) REFERENCES jobs (id)');
        $this->addSql('ALTER TABLE ticket_actions ADD CONSTRAINT FK_984A8BD61A9780A FOREIGN KEY (from_platform_id) REFERENCES platforms (id)');
        $this->addSql('ALTER TABLE ticket_actions ADD CONSTRAINT FK_984A8BDD175770C FOREIGN KEY (to_platform_id) REFERENCES platforms (id)');
        $this->addSql('ALTER TABLE tickets ADD CONSTRAINT FK_54469DF471F7E88B FOREIGN KEY (event_id) REFERENCES events (id)');
        $this->addSql('ALTER TABLE tickets ADD CONSTRAINT FK_54469DF4C1DAFE35 FOREIGN KEY (seat_id) REFERENCES seats (id)');
        $this->addSql('ALTER TABLE tickets ADD CONSTRAINT FK_54469DF4D823E37A FOREIGN KEY (section_id) REFERENCES sections (id)');
        $this->addSql('ALTER TABLE tickets ADD CONSTRAINT FK_54469DF4FFE6496F FOREIGN KEY (platform_id) REFERENCES platforms (id)');
        $this->addSql('ALTER TABLE webhook_deliveries ADD CONSTRAINT FK_3681F32DFFE6496F FOREIGN KEY (platform_id) REFERENCES platforms (id)');
        $this->addSql('ALTER TABLE webhook_deliveries ADD CONSTRAINT FK_3681F32D700047D2 FOREIGN KEY (ticket_id) REFERENCES tickets (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE events DROP FOREIGN KEY FK_5387574A40A73EBA');
        $this->addSql('ALTER TABLE job_attempts DROP FOREIGN KEY FK_D880421FBE04EA9');
        $this->addSql('ALTER TABLE jobs DROP FOREIGN KEY FK_A8936DC584E3FEC4');
        $this->addSql('ALTER TABLE jobs DROP FOREIGN KEY FK_A8936DC5700047D2');
        $this->addSql('ALTER TABLE jobs DROP FOREIGN KEY FK_A8936DC5FFE6496F');
        $this->addSql('ALTER TABLE runs DROP FOREIGN KEY FK_803A7B1FDA7FCFC8');
        $this->addSql('ALTER TABLE runs DROP FOREIGN KEY FK_803A7B1F71F7E88B');
        $this->addSql('ALTER TABLE runs DROP FOREIGN KEY FK_803A7B1F78DC0665');
        $this->addSql('ALTER TABLE seats DROP FOREIGN KEY FK_BFE25750D823E37A');
        $this->addSql('ALTER TABLE sections DROP FOREIGN KEY FK_2B96439840A73EBA');
        $this->addSql('ALTER TABLE ticket_actions DROP FOREIGN KEY FK_984A8BD700047D2');
        $this->addSql('ALTER TABLE ticket_actions DROP FOREIGN KEY FK_984A8BD84E3FEC4');
        $this->addSql('ALTER TABLE ticket_actions DROP FOREIGN KEY FK_984A8BDBE04EA9');
        $this->addSql('ALTER TABLE ticket_actions DROP FOREIGN KEY FK_984A8BD61A9780A');
        $this->addSql('ALTER TABLE ticket_actions DROP FOREIGN KEY FK_984A8BDD175770C');
        $this->addSql('ALTER TABLE tickets DROP FOREIGN KEY FK_54469DF471F7E88B');
        $this->addSql('ALTER TABLE tickets DROP FOREIGN KEY FK_54469DF4C1DAFE35');
        $this->addSql('ALTER TABLE tickets DROP FOREIGN KEY FK_54469DF4D823E37A');
        $this->addSql('ALTER TABLE tickets DROP FOREIGN KEY FK_54469DF4FFE6496F');
        $this->addSql('ALTER TABLE webhook_deliveries DROP FOREIGN KEY FK_3681F32DFFE6496F');
        $this->addSql('ALTER TABLE webhook_deliveries DROP FOREIGN KEY FK_3681F32D700047D2');
        $this->addSql('DROP TABLE events');
        $this->addSql('DROP TABLE job_attempts');
        $this->addSql('DROP TABLE jobs');
        $this->addSql('DROP TABLE platforms');
        $this->addSql('DROP TABLE runs');
        $this->addSql('DROP TABLE seats');
        $this->addSql('DROP TABLE sections');
        $this->addSql('DROP TABLE ticket_actions');
        $this->addSql('DROP TABLE tickets');
        $this->addSql('DROP TABLE venues');
        $this->addSql('DROP TABLE webhook_deliveries');
    }
}
