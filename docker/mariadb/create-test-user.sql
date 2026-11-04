-- The test account cannot read, migrate, or reset the development database.
CREATE DATABASE IF NOT EXISTS `testing`;
CREATE USER IF NOT EXISTS 'sail_test'@'%' IDENTIFIED BY 'password';
GRANT ALL PRIVILEGES ON `testing`.* TO 'sail_test'@'%';
