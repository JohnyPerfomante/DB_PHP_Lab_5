<?php
declare(strict_types=1);
function save_part(): void {
    $id = input('id') === '' ? null : integer('id');
    $sku = strtoupper(text_field('sku', 'Артикул', 2, 40));
    if (!preg_match('/^[A-Z0-9][A-Z0-9._-]+$/D', $sku)) throw new DomainException('Артикул: латинські літери, цифри, крапка, дефіс або підкреслення.');
    $values = [$sku, text_field('name', 'Назва', 3, 120), text_field('category', 'Категорія', 2, 80), integer('min_qty', 0)];
    if ($id) {
        if (!run('SELECT id FROM parts WHERE id = ?', [$id])->fetch()) throw new DomainException('Комплектуючу не знайдено.');
        run('UPDATE parts SET sku=?, name=?, category=?, min_qty=? WHERE id=?', [...$values, $id]);
    } else run('INSERT INTO parts (sku,name,category,min_qty) VALUES (?,?,?,?)', $values);
}
function delete_part(): void {
    $id = integer('id');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if (!run('SELECT id FROM parts WHERE id=? FOR UPDATE', [$id])->fetch()) throw new DomainException('Комплектуючу не знайдено.');
        if ((int)run('SELECT COUNT(*) FROM movements WHERE part_id=?', [$id])->fetchColumn() > 0) {
            throw new DomainException('Видалення неможливе: комплектуюча має історію руху.');
        }
        if ((int)run('SELECT COALESCE(SUM(quantity),0) FROM stock WHERE part_id=?', [$id])->fetchColumn() > 0) {
            throw new DomainException('Видалення неможливе: є залишок на складі.');
        }
        run('DELETE FROM stock WHERE part_id=?', [$id]);
        run('DELETE FROM parts WHERE id=?', [$id]);
        $pdo->commit();
    } catch (Throwable $error) { $pdo->rollBack(); throw $error; }
}
function save_location(): void {
    $id = input('id') === '' ? null : integer('id');
    $code = strtoupper(text_field('code', 'Код місця', 2, 24));
    if (!preg_match('/^[A-Z0-9][A-Z0-9._-]+$/D', $code)) throw new DomainException('Код місця: латинські літери, цифри, крапка, дефіс або підкреслення.');
    $values = [$code, text_field('name', 'Назва місця', 3, 100), text_field('description', 'Опис', 0, 200)];
    if ($id) {
        if (!run('SELECT id FROM locations WHERE id=?', [$id])->fetch()) throw new DomainException('Місце не знайдено.');
        run('UPDATE locations SET code=?, name=?, description=? WHERE id=?', [...$values, $id]);
    } else run('INSERT INTO locations (code,name,description) VALUES (?,?,?)', $values);
}
function delete_location(): void {
    $id = integer('id');
    $pdo = db(); $pdo->beginTransaction();
    try {
        if (!run('SELECT id FROM locations WHERE id=? FOR UPDATE', [$id])->fetch()) throw new DomainException('Місце не знайдено.');
        if ((int)run('SELECT COUNT(*) FROM movements WHERE from_location_id=? OR to_location_id=?', [$id,$id])->fetchColumn() > 0
            || (int)run('SELECT COALESCE(SUM(quantity),0) FROM stock WHERE location_id=?', [$id])->fetchColumn() > 0) {
            throw new DomainException('Місце використовується у залишках або історії руху.');
        }
        run('DELETE FROM stock WHERE location_id=?', [$id]);
        run('DELETE FROM locations WHERE id=?', [$id]);
        $pdo->commit();
    } catch (Throwable $error) { $pdo->rollBack(); throw $error; }
}
function move_stock(): void {
    $type = input('type');
    if (!in_array($type, ['receipt','issue','transfer'], true)) throw new DomainException('Невідомий тип операції.');
    $part = integer('part_id');
    $qty = integer('quantity');
    $from = $type === 'receipt' ? null : integer('from_location_id');
    $to = $type === 'issue' ? null : integer('to_location_id');
    $note = text_field('note', 'Підстава', 3, 200);
    if ($type === 'transfer' && $from === $to) throw new DomainException('Оберіть різні місця для переміщення.');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Serialize all movements of one part, including creation of a new stock row.
        if (!run('SELECT id FROM parts WHERE id=? FOR UPDATE', [$part])->fetch()) throw new DomainException('Комплектуючу не знайдено.');
        $ids = array_values(array_unique(array_filter([$from,$to]))); sort($ids);
        foreach ($ids as $location) {
            if (!run('SELECT id FROM locations WHERE id=? FOR SHARE', [$location])->fetch()) throw new DomainException('Місце зберігання не знайдено.');
        }
        if ($from) {
            $available = (int)run('SELECT quantity FROM stock WHERE part_id=? AND location_id=? FOR UPDATE', [$part,$from])->fetchColumn();
            if ($available < $qty) throw new DomainException("Недостатній залишок у місці зберігання: доступно $available шт.");
            run('UPDATE stock SET quantity=quantity-? WHERE part_id=? AND location_id=?', [$qty,$part,$from]);
        }
        if ($to) {
            $current = (int)run('SELECT quantity FROM stock WHERE part_id=? AND location_id=? FOR UPDATE', [$part,$to])->fetchColumn();
            if ($current + $qty > 1000000000) throw new DomainException('Перевищено максимально допустимий залишок.');
            run('INSERT INTO stock (part_id,location_id,quantity) VALUES (?,?,?) ON DUPLICATE KEY UPDATE quantity=quantity+?', [$part,$to,$qty,$qty]);
        }
        run('INSERT INTO movements (part_id,type,from_location_id,to_location_id,quantity,note) VALUES (?,?,?,?,?,?)', [$part,$type,$from,$to,$qty,$note]);
        $pdo->commit();
    } catch (Throwable $error) { $pdo->rollBack(); throw $error; }
}
