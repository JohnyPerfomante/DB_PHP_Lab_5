SET NAMES utf8mb4;
CREATE TABLE parts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(40) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    category VARCHAR(80) NOT NULL,
    min_qty INT NOT NULL DEFAULT 0,
    CHECK (min_qty BETWEEN 0 AND 1000000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE locations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(24) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(200) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE stock (
    part_id INT UNSIGNED NOT NULL,
    location_id INT UNSIGNED NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    PRIMARY KEY (part_id, location_id),
    FOREIGN KEY (part_id) REFERENCES parts(id) ON DELETE RESTRICT,
    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE RESTRICT,
    CHECK (quantity BETWEEN 0 AND 1000000000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE movements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    part_id INT UNSIGNED NOT NULL,
    type ENUM('receipt', 'issue', 'transfer') NOT NULL,
    from_location_id INT UNSIGNED NULL,
    to_location_id INT UNSIGNED NULL,
    quantity INT NOT NULL,
    note VARCHAR(200) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (part_id) REFERENCES parts(id) ON DELETE RESTRICT,
    FOREIGN KEY (from_location_id) REFERENCES locations(id) ON DELETE RESTRICT,
    FOREIGN KEY (to_location_id) REFERENCES locations(id) ON DELETE RESTRICT,
    INDEX ix_movements_part_date (part_id, created_at),
    CHECK (quantity BETWEEN 1 AND 1000000),
    CHECK ((type = 'receipt' AND from_location_id IS NULL AND to_location_id IS NOT NULL)
        OR (type = 'issue' AND from_location_id IS NOT NULL AND to_location_id IS NULL)
        OR (type = 'transfer' AND from_location_id IS NOT NULL AND to_location_id IS NOT NULL
            AND from_location_id <> to_location_id))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO parts (id, sku, name, category, min_qty) VALUES
(1, 'FLT-OIL-001', 'Фільтр оливний MAN', 'Фільтри', 12),
(2, 'BRK-PAD-002', 'Колодки гальмівні передні', 'Гальмівна система', 8),
(3, 'BLT-DRV-003', 'Ремінь приводу генератора', 'Привід', 5),
(4, 'FLT-AIR-004', 'Фільтр повітряний DAF', 'Фільтри', 10),
(5, 'LMP-H7-005', 'Лампа H7 24V', 'Електрообладнання', 10),
(6, 'BRG-HUB-006', 'Підшипник маточини', 'Ходова частина', 4);
INSERT INTO locations (id, code, name, description) VALUES
(1, 'A-01', 'Основний склад', 'Стелаж A, полиця 01'),
(2, 'A-02', 'Основний склад', 'Стелаж A, полиця 02'),
(3, 'B-01', 'Зона технічного обслуговування', 'Шафа B, полиця 01');
INSERT INTO movements (part_id,type,to_location_id,quantity,note) VALUES
(1,'receipt',1,24,'Початкове надходження за накладною 001'),
(1,'receipt',3,8,'Початкове надходження за накладною 002'),
(2,'receipt',2,6,'Початкове надходження за накладною 003'),
(3,'receipt',1,14,'Початкове надходження за накладною 004'),
(4,'receipt',2,18,'Початкове надходження за накладною 005'),
(5,'receipt',3,5,'Початкове надходження за накладною 006');
INSERT INTO stock (part_id,location_id,quantity) VALUES
(1,1,24),(1,3,8),(2,2,6),(3,1,14),(4,2,18),(5,3,5);
