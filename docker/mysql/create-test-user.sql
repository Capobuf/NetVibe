-- The test account cannot read, migrate, or reset the development database.
CREATE USER IF NOT EXISTS 'sail_test'@'%' IDENTIFIED BY 'password';
GRANT ALL PRIVILEGES ON `testing`.* TO 'sail_test'@'%';
