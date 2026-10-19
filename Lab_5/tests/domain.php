<?php
// Run only against a dedicated database: fleet_stock_test.
require '/var/www/html/app/bootstrap.php';
require '/var/www/html/app/inventory.php';
if (getenv('DB_NAME') !== 'fleet_stock_test') throw new RuntimeException('Use fleet_stock_test');
$checks = [];
function check(string $label, callable $test): void {
    global $checks; $test(); $checks[]=$label; echo "PASS $label\n";
}
function equal(mixed $actual, mixed $expected): void {
    if ($actual !== $expected) throw new RuntimeException('Expected '.var_export($expected,true).' got '.var_export($actual,true));
}
function rejects(callable $action): void {
    try { $action(); } catch (DomainException|PDOException $e) { return; }
    throw new RuntimeException('Expected rejection');
}
function balance(int $part, int $location): int {return (int)run('SELECT quantity FROM stock WHERE part_id=? AND location_id=?',[$part,$location])->fetchColumn();}
function move(string $type,int $qty,int $from=1,int $to=3): void {
    $_POST=['type'=>$type,'part_id'=>'1','quantity'=>(string)$qty,'from_location_id'=>(string)$from,'to_location_id'=>(string)$to,'note'=>'Перевірка обліку']; move_stock();
}
check('Початкові дані: 6 деталей, 3 місця, 75 одиниць',function(){equal(count(run('SELECT id FROM parts')->fetchAll()),6);equal((int)run('SELECT SUM(quantity) FROM stock')->fetchColumn(),75);equal(count(run('SELECT id FROM locations')->fetchAll()),3);});
check('CRUD комплектуючої та захист унікальності артикула',function(){
 $_POST=['id'=>'','sku'=>'TEST-01','name'=>'Тестова комплектуюча','category'=>'Фільтри','min_qty'=>'3'];save_part();
 $id=(int)db()->lastInsertId();rejects(fn()=>save_part());
 $_POST['id']=(string)$id;$_POST['name']='Оновлена комплектуюча';save_part();
 equal(run('SELECT name FROM parts WHERE id=?',[$id])->fetchColumn(),'Оновлена комплектуюча');
 $_POST=['id'=>(string)$id];delete_part();equal(run('SELECT id FROM parts WHERE id=?',[$id])->fetchColumn(),false);
});
check('CRUD місця зберігання',function(){
 $_POST=['id'=>'','code'=>'T-01','name'=>'Тестовий склад','description'=>'Полиця 1'];save_location();$id=(int)db()->lastInsertId();
 $_POST['id']=(string)$id;$_POST['description']='Полиця 2';save_location();equal(run('SELECT description FROM locations WHERE id=?',[$id])->fetchColumn(),'Полиця 2');
 delete_location();equal(run('SELECT id FROM locations WHERE id=?',[$id])->fetchColumn(),false);
});
check('Надходження збільшує залишок та створює запис журналу',function(){move('receipt',10,1,1);equal(balance(1,1),34);equal((int)run('SELECT COUNT(*) FROM movements')->fetchColumn(),7);});
check('Списання враховує обране місце',function(){move('issue',4);equal(balance(1,1),30);equal(balance(1,3),8);});
check('Переміщення зберігає сумарний залишок',function(){move('transfer',5);equal(balance(1,1),25);equal(balance(1,3),13);equal((int)run('SELECT SUM(quantity) FROM stock WHERE part_id=1')->fetchColumn(),38);});
check('Недостатній залишок не змінює журнал або залишки',function(){ $before=(int)run('SELECT COUNT(*) FROM movements')->fetchColumn();rejects(fn()=>move('issue',26));equal(balance(1,1),25);equal((int)run('SELECT COUNT(*) FROM movements')->fetchColumn(),$before);});
check('Помилка після віднімання відкочує всю транзакцію',function(){run('UPDATE stock SET quantity=1000000000 WHERE part_id=1 AND location_id=3');$before=(int)run('SELECT COUNT(*) FROM movements')->fetchColumn();rejects(fn()=>move('transfer',1));equal(balance(1,1),25);equal(balance(1,3),1000000000);equal((int)run('SELECT COUNT(*) FROM movements')->fetchColumn(),$before);run('UPDATE stock SET quantity=13 WHERE part_id=1 AND location_id=3');});
check('Неприпустимі кількості та однакові місця відхиляються',function(){foreach(['0','-1','1.5','1000001','1e3'] as $qty){$_POST=['type'=>'receipt','part_id'=>'1','quantity'=>$qty,'to_location_id'=>'1','note'=>'Тест'];rejects(fn()=>move_stock());}rejects(fn()=>move('transfer',1,1,1));});
check('Невідомі комплектуючі та місця відхиляються',function(){rejects(fn()=>move('receipt',1,1,999));$_POST=['type'=>'receipt','part_id'=>'999','quantity'=>'1','to_location_id'=>'1','note'=>'Тест'];rejects(fn()=>move_stock());});
check('Історія руху забороняє видалення використаних записів',function(){$_POST=['id'=>'1'];rejects(fn()=>delete_part());rejects(fn()=>delete_location());});
check('Сервер перевіряє довжину та формат реквізитів',function(){$_POST=['id'=>'','sku'=>'BAD CODE','name'=>'Деталь','category'=>'Тест','min_qty'=>'0'];rejects(fn()=>save_part());$_POST['sku']='VALID';$_POST['name']='А';rejects(fn()=>save_part());});
check('Граничні статуси запасу',function(){equal(quantity_status(0,0)[0],'empty');equal(quantity_status(5,5)[0],'low');equal(quantity_status(6,5)[0],'ok');});
check('Баланс узгоджується з журналом руху для кожного місця',function(){foreach(run('SELECT * FROM stock')->fetchAll() as $s){$in=(int)run('SELECT COALESCE(SUM(quantity),0) FROM movements WHERE part_id=? AND to_location_id=?',[$s['part_id'],$s['location_id']])->fetchColumn();$out=(int)run('SELECT COALESCE(SUM(quantity),0) FROM movements WHERE part_id=? AND from_location_id=?',[$s['part_id'],$s['location_id']])->fetchColumn();equal((int)$s['quantity'],$in-$out);}});
echo json_encode(['passed'=>count($checks),'checks'=>$checks],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
