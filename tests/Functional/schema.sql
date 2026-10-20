CREATE TABLE eventos (
    id INT NOT NULL PRIMARY KEY,
    categoria_id INT NOT NULL,
    dia_id INT NOT NULL,
    hora_id INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO eventos (id, categoria_id, dia_id, hora_id) VALUES (1, 1, 1, 1);
