-- Base aparte para las pruebas automáticas (se corre solo cuando el volumen de MariaDB se crea de cero).
-- Las pruebas borran las tablas de la base que usan; por eso nunca deben apuntar a "tiempo".
CREATE DATABASE IF NOT EXISTS tiempo_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON tiempo_test.* TO 'tiempo'@'%';
FLUSH PRIVILEGES;
