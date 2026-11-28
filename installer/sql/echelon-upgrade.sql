-- ==========================================================================
-- Echelon 3 - database upgrade for the ECHELON database (the one with the ech_* tables)
--
--  * Safe to run more than once: every step first checks whether it is needed.
--  * Works on any database name: it acts on the database you have selected
--    (mysql -u USER -p DBNAME < echelon-upgrade.sql, or pick the database in phpMyAdmin first).
--  * Brings Echelon 2.x databases (any 2.x release) up to the Echelon 3 layout.
--  * Nothing is dropped and no rows are deleted.
--  * It does NOT touch your B3 game databases.
-- ==========================================================================

-- 1. ech_servers: map cycle file / URL columns (added in Echelon 2.3.6 / 2.3.7)
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ech_servers' AND COLUMN_NAME = 'mapcyclefile') = 0,
             'ALTER TABLE ech_servers ADD COLUMN mapcyclefile MEDIUMTEXT NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ech_servers' AND COLUMN_NAME = 'mapcycleurl') = 0,
             'ALTER TABLE ech_servers ADD COLUMN mapcycleurl MEDIUMTEXT NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 2. ech_users: room for longer e-mail addresses (2.3.3). Only widened, never shortened.
SET @s := IF((SELECT IFNULL(MAX(CHARACTER_MAXIMUM_LENGTH), 0) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ech_users' AND COLUMN_NAME = 'email') < 75,
             'ALTER TABLE ech_users MODIFY email VARCHAR(75) NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 3. Permissions that were added after Echelon 2.0 (INSERT IGNORE: name is a unique key)
INSERT IGNORE INTO ech_permissions (name, description) VALUES ('mapconfig',     'Allows user to configure map settings');
INSERT IGNORE INTO ech_permissions (name, description) VALUES ('listmgmt',      'Allows user to add/remove banlist/whitelist entries');
INSERT IGNORE INTO ech_permissions (name, description) VALUES ('reports',       'Allows user to view reports');
INSERT IGNORE INTO ech_permissions (name, description) VALUES ('permmute',      'Allows user to update Permanent Mute');
INSERT IGNORE INTO ech_permissions (name, description) VALUES ('server_action', 'Allows user to perform Server Actions');

-- 4. First upgrade to Echelon 3 only (no 'schema_version' row yet): give the built-in "Site Admin"
--    group the permissions above so the new pages are visible to them. Groups you edited later are
--    never changed again, because this step is skipped once schema_version exists.
SET @first := ((SELECT COUNT(*) FROM ech_config WHERE name = 'schema_version') = 0);

UPDATE ech_groups SET premissions = CONCAT(premissions, ',', (SELECT id FROM ech_permissions WHERE name = 'mapconfig'))
 WHERE @first AND name = 'siteadmin' AND NOT FIND_IN_SET((SELECT id FROM ech_permissions WHERE name = 'mapconfig'), premissions);
UPDATE ech_groups SET premissions = CONCAT(premissions, ',', (SELECT id FROM ech_permissions WHERE name = 'listmgmt'))
 WHERE @first AND name = 'siteadmin' AND NOT FIND_IN_SET((SELECT id FROM ech_permissions WHERE name = 'listmgmt'), premissions);
UPDATE ech_groups SET premissions = CONCAT(premissions, ',', (SELECT id FROM ech_permissions WHERE name = 'reports'))
 WHERE @first AND name = 'siteadmin' AND NOT FIND_IN_SET((SELECT id FROM ech_permissions WHERE name = 'reports'), premissions);
UPDATE ech_groups SET premissions = CONCAT(premissions, ',', (SELECT id FROM ech_permissions WHERE name = 'permmute'))
 WHERE @first AND name = 'siteadmin' AND NOT FIND_IN_SET((SELECT id FROM ech_permissions WHERE name = 'permmute'), premissions);
UPDATE ech_groups SET premissions = CONCAT(premissions, ',', (SELECT id FROM ech_permissions WHERE name = 'server_action'))
 WHERE @first AND name = 'siteadmin' AND NOT FIND_IN_SET((SELECT id FROM ech_permissions WHERE name = 'server_action'), premissions);

-- 5. Record the schema version (also used by the installer to see what it is dealing with)
INSERT INTO ech_config (name, value)
SELECT 'schema_version', '3.0' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM ech_config WHERE name = 'schema_version');
UPDATE ech_config SET value = '3.0' WHERE name = 'schema_version';

SELECT 'Echelon 3 database upgrade finished' AS status;
