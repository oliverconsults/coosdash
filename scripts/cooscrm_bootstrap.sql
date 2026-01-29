-- Bootstrap: create DB + user (run as a privileged MySQL user)

-- EDIT DB NAME if you prefer something else:
CREATE DATABASE IF NOT EXISTS cooscrm
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

-- Create dedicated user (password below is generated on the server; replace if you want)
CREATE USER IF NOT EXISTS 'cooscrm'@'localhost' IDENTIFIED BY '8pRudK3oINs5S7wk8HVcdGkDkZmM';

GRANT ALL PRIVILEGES ON cooscrm.* TO 'cooscrm'@'localhost';
FLUSH PRIVILEGES;

-- After that:
--   mysql -u cooscrm -p cooscrm < scripts/cooscrm_schema.sql
