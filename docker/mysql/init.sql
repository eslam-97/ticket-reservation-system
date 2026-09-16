-- Runs once, on first boot of the mysql volume.
-- `tickets` is the application database; `tickets_test` is what Pest runs
-- against (docs/DESIGN_DECISIONS.md section 11: never SQLite).

CREATE DATABASE IF NOT EXISTS `tickets`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE DATABASE IF NOT EXISTS `tickets_test`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'tickets'@'%' IDENTIFIED BY 'secret';

GRANT ALL PRIVILEGES ON `tickets`.* TO 'tickets'@'%';
GRANT ALL PRIVILEGES ON `tickets_test`.* TO 'tickets'@'%';

FLUSH PRIVILEGES;
