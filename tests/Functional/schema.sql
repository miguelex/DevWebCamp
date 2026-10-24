CREATE TABLE eventos (
    id INT NOT NULL PRIMARY KEY,
    categoria_id INT NOT NULL,
    dia_id INT NOT NULL,
    hora_id INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO eventos (id, categoria_id, dia_id, hora_id) VALUES (1, 1, 1, 1);

CREATE TABLE usuarios_devwebcamp (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    apellido VARCHAR(100) NOT NULL,
    email VARCHAR(191) NOT NULL,
    password VARCHAR(255) NOT NULL,
    confirmado TINYINT(1) NOT NULL DEFAULT 0,
    token VARCHAR(64) NOT NULL DEFAULT '',
    admin TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
