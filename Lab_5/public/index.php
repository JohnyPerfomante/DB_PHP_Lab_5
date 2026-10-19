<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/inventory.php';
function query(string $key, string $default = ''): string {
    return isset($_GET[$key]) && is_string($_GET[$key]) ? trim($_GET[$key]) : $default;
}
$page = query('page', 'inventory');
$allowed = ['inventory','part-form','locations','location-form','movement','history'];
$error = '';
if (!in_array($page, $allowed, true)) { http_response_code(404); $page = 'not-found'; }
try {
    db();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['csrf'], input('csrf'))) {
            http_response_code(403); $error = 'Сесію форми не підтверджено. Оновіть сторінку та повторіть дію.';
        } else {
            try {
                $actions = [
                    'save_part' => ['save_part','inventory','Комплектуючу збережено.'],
                    'delete_part' => ['delete_part','inventory','Комплектуючу видалено.'],
                    'save_location' => ['save_location','locations','Місце зберігання збережено.'],
                    'delete_location' => ['delete_location','locations','Місце зберігання видалено.'],
                    'move_stock' => ['move_stock','history','Операцію проведено. Залишки оновлено.'],
                ];
                $action = input('action');
                if (!isset($actions[$action])) throw new DomainException('Невідома дія.');
                [$handler,$target,$message] = $actions[$action];
                $handler(); redirect($target, $message);
            } catch (DomainException $ex) { http_response_code(422); $error = $ex->getMessage(); }
            catch (PDOException $ex) {
                if ($ex->getCode() === '23000') { http_response_code(422); $error = 'Артикул або код уже існує, або запис використовується в обліку.'; }
                else { error_log((string)$ex); http_response_code(500); $error = 'Не вдалося зберегти дані. Повторіть операцію.'; }
            }
        }
    }
    $parts = run('SELECT p.*, COALESCE(s.total,0) AS total FROM parts p LEFT JOIN (SELECT part_id,SUM(quantity) AS total FROM stock GROUP BY part_id) s ON p.id=s.part_id ORDER BY p.id')->fetchAll();
    $locations = run('SELECT l.*, COALESCE(SUM(s.quantity),0) AS total, COUNT(CASE WHEN s.quantity>0 THEN 1 END) AS positions FROM locations l LEFT JOIN stock s ON s.location_id=l.id GROUP BY l.id ORDER BY l.code')->fetchAll();
    $stocks = run('SELECT s.*, l.code, l.name FROM stock s JOIN locations l ON l.id=s.location_id WHERE s.quantity>0 ORDER BY l.code')->fetchAll();
} catch (Throwable $ex) {
    error_log((string)$ex); http_response_code(503);
    echo '<!doctype html><html lang="uk"><meta charset="UTF-8"><title>Підключення до складу</title><h1>База даних недоступна</h1><p>Перевірте налаштування та запуск MySQL за інструкцією README.</p></html>'; exit;
}
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
$stockByPart = [];
foreach ($stocks as $s) $stockByPart[$s['part_id']][] = $s;
$low = count(array_filter($parts, fn($p) => (int)$p['total'] <= (int)$p['min_qty']));
$titles = ['inventory'=>'Залишки комплектуючих','part-form'=>'Картка комплектуючої','locations'=>'Місця зберігання','location-form'=>'Картка місця зберігання','movement'=>'Операція із запасами','history'=>'Журнал руху','not-found'=>'Сторінку не знайдено'];
function field_value(string $key, array $record, string $default = ''): string {
    return $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : (string)($record[$key] ?? $default);
}
function option_list(array $records, string $selected, string $kind): void {
    echo '<option value="">Оберіть ' . ($kind === 'part' ? 'комплектуючу' : 'місце') . '</option>';
    foreach ($records as $record) {
        $label = $kind === 'part' ? $record['sku'] . ' · ' . $record['name'] : $record['code'] . ' · ' . $record['name'];
        echo '<option value="'.(int)$record['id'].'"'.((string)$record['id']===$selected?' selected':'').'>'.e($label).'</option>';
    }
}
?>
<!doctype html>
<html lang="uk">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($titles[$page])?> · FleetStock</title><link rel="icon" href="/assets/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="/assets/style.css"><script src="/assets/app.js" defer></script></head>
<body>
<aside class="sidebar">
<a class="brand" href="/"><span class="brand-icon">F</span><span>FleetStock<small>СКЛАД АВТОПАРКУ</small></span></a>
<p class="nav-label">РОБОЧИЙ ПРОСТІР</p>
<nav aria-label="Основна навігація">
<?php foreach (['inventory'=>['01','Запаси'],'locations'=>['02','Місця зберігання'],'history'=>['03','Журнал руху']] as $link=>$item): ?>
<a href="/?page=<?=$link?>" class="nav-link <?= $page===$link?'active':''?>"><span><?=$item[0]?></span><?=$item[1]?></a>
<?php endforeach; ?>
</nav>
<div class="sidebar-bottom"><span class="status-dot"></span> Локальний склад<p>Облік комплектуючих для технічного обслуговування автопарку</p></div>
</aside>
<main>
<header class="topbar"><span>Складський облік <span class="divider">/</span> <?=e($titles[$page])?></span><span class="top-tag">ВАРІАНТ 06</span></header>
<div class="content">
<?php if ($flash): ?><div class="alert success" role="status"><?=e($flash)?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error" role="alert"><?=e($error)?></div><?php endif; ?>
<div class="page-heading"><div><p class="eyebrow">КОНТРОЛЬ ЗАПАСІВ</p><h1><?=e($titles[$page])?></h1><p class="subheading"><?php echo match($page) { 'inventory'=>'Актуальна кількість деталей та їх розташування на складі.', 'locations'=>'Стелажі, полиці та робочі зони вашого складу.', 'history'=>'Надходження, списання та переміщення комплектуючих.', 'movement'=>'Проведіть надходження, списання або переміщення деталей.', default=>'Внесіть дані та збережіть зміни.' }; ?></p></div>
<?php if (in_array($page,['inventory','history'])): ?><a class="button primary" href="/?page=movement">＋ Нова операція</a><?php elseif ($page==='locations'): ?><a class="button primary" href="/?page=location-form">＋ Додати місце</a><?php endif; ?></div>
<?php if ($page==='inventory'):
$q=query('q'); $locationFilter=query('location'); $statusFilter=query('status');
$filtered = array_filter($parts, function($part) use ($q,$locationFilter,$statusFilter,$stockByPart) {
    if ($q!=='' && !preg_match('/'.preg_quote($q,'/').'/iu', $part['sku'].' '.$part['name'].' '.$part['category'])) return false;
    if ($statusFilter!=='' && quantity_status((int)$part['total'],(int)$part['min_qty'])[0]!==$statusFilter) return false;
    if ($locationFilter!=='' && !in_array($locationFilter, array_map(fn($s)=>(string)$s['location_id'], $stockByPart[$part['id']]??[]),true)) return false;
    return true;
}); ?>
<section class="metrics" aria-label="Загальні показники">
<div class="metric"><span>Номенклатурних позицій</span><strong><?=count($parts)?></strong><small>у каталозі комплектуючих</small></div>
<div class="metric"><span>Загальний залишок</span><strong><?=array_sum(array_column($parts,'total'))?><em>шт.</em></strong><small>у всіх місцях зберігання</small></div>
<div class="metric warning"><span>Потребують поповнення</span><strong><?=$low?></strong><small>залишок не перевищує мінімум</small></div>
<div class="metric"><span>Місць зберігання</span><strong><?=count($locations)?></strong><small>складські та сервісні зони</small></div>
</section>
<section class="panel">
<div class="panel-heading"><div><h2>Каталог комплектуючих <span class="count"><?=count($filtered)?></span></h2><p>Залишки та статуси наведено сумарно для всіх місць.</p></div><a class="button secondary" href="/?page=part-form">＋ Додати деталь</a></div>
<form class="filters" method="get"><input type="hidden" name="page" value="inventory"><label class="search"><span class="sr-only">Пошук</span><input name="q" placeholder="Назва, артикул або категорія" value="<?=e($q)?>" maxlength="120"></label><label><span class="sr-only">Місце зберігання</span><select name="location"><option value="">Усі місця</option><?php foreach($locations as $l): ?><option value="<?=$l['id']?>" <?=$locationFilter===(string)$l['id']?'selected':''?>><?=e($l['code'])?></option><?php endforeach;?></select></label><label><span class="sr-only">Статус</span><select name="status"><option value="">Усі статуси</option><?php foreach(['ok'=>'Достатньо','low'=>'Низький запас','empty'=>'Відсутні'] as $value=>$label): ?><option value="<?=$value?>" <?=$statusFilter===$value?'selected':''?>><?=$label?></option><?php endforeach;?></select></label><button class="button secondary">Знайти</button><a class="reset" href="/">Скинути</a></form>
<div class="table-scroll"><table><thead><tr><th>Комплектуюча</th><th>Місце та кількість</th><th class="number">Залишок</th><th class="number">Мінімум</th><th>Статус</th><th>Дії</th></tr></thead><tbody>
<?php foreach($filtered as $p): [$status,$label]=quantity_status((int)$p['total'],(int)$p['min_qty']); ?>
<tr data-part-id="<?=$p['id']?>"><td><strong><?=e($p['name'])?></strong><span class="secondary-text"><?=e($p['sku'])?> <span class="dot">·</span> <?=e($p['category'])?></span></td><td><?php foreach($stockByPart[$p['id']]??[] as $s): ?><span class="location-chip"><?=e($s['code'])?> <b><?=(int)$s['quantity']?></b></span><?php endforeach; ?><?=empty($stockByPart[$p['id']])?'<span class="muted">Немає залишків</span>':''?></td><td class="number stock-number"><?=(int)$p['total']?> <small>шт.</small></td><td class="number"><?=(int)$p['min_qty']?></td><td><span class="badge <?=$status?>"><?=$label?></span></td><td><div class="row-actions"><a href="/?page=part-form&amp;id=<?=$p['id']?>" aria-label="Редагувати <?=e($p['sku'])?>">Змінити</a><form method="post" data-confirm="Видалити комплектуючу з каталогу?"><?=csrf()?><input type="hidden" name="action" value="delete_part"><input type="hidden" name="id" value="<?=$p['id']?>"><button class="text-danger" aria-label="Видалити <?=e($p['sku'])?>">Видалити</button></form></div></td></tr>
<?php endforeach; ?>
<?php if (!$filtered): ?><tr><td colspan="6" class="empty-state">За вибраними умовами комплектуючих не знайдено.</td></tr><?php endif;?></tbody></table></div>
<div class="panel-footer"><span>Показано <?=count($filtered)?> із <?=count($parts)?> позицій</span><span>Облік у штуках</span></div>
</section>
<?php elseif($page==='part-form' || $page==='location-form'):
$isPart=$page==='part-form'; $records=$isPart?$parts:$locations;
$id=query('id'); $record=[];
foreach($records as $candidate) if ((string)$candidate['id']===$id) $record=$candidate;
if($id!=='' && !$record): http_response_code(404); ?><div class="alert error">Запис не знайдено.</div>
<?php else: ?>
<form class="panel editor" method="post"><?=csrf()?><input type="hidden" name="action" value="<?=$isPart?'save_part':'save_location'?>"><input type="hidden" name="id" value="<?=e($id)?>"><div class="panel-heading"><h2><?=$id!==''?'Редагування':'Новий запис'?></h2><span class="muted">* Обов’язкові поля</span></div><div class="form-body">
<?php if($isPart): ?>
<div class="form-grid"><label>Артикул *<input id="sku" name="sku" value="<?=e(field_value('sku',$record))?>" minlength="2" maxlength="40" required placeholder="FLT-OIL-007" pattern="[A-Za-z0-9][A-Za-z0-9._-]+"><small>Унікальний код латинськими літерами та цифрами</small></label><label>Категорія *<input id="category" name="category" value="<?=e(field_value('category',$record))?>" minlength="2" maxlength="80" required placeholder="Фільтри"></label></div>
<label>Назва комплектуючої *<input id="name" name="name" value="<?=e(field_value('name',$record))?>" minlength="3" maxlength="120" required placeholder="Фільтр паливний"></label>
<label>Мінімальний запас, шт. *<input id="min_qty" class="short-input" type="number" name="min_qty" value="<?=e(field_value('min_qty',$record,'0'))?>" min="0" max="1000000" step="1" required><small>Сповіщення, коли сумарний залишок не перевищує цей рівень.</small></label>
<p class="form-note">Кількість змінюється через «Нову операцію». Нова деталь створюється з нульовим залишком.</p>
<?php else: ?>
<label>Код місця *<input id="code" name="code" value="<?=e(field_value('code',$record))?>" minlength="2" maxlength="24" required pattern="[A-Za-z0-9][A-Za-z0-9._-]+" placeholder="C-01"></label><label>Назва місця *<input id="name" name="name" value="<?=e(field_value('name',$record))?>" minlength="3" maxlength="100" required placeholder="Резервний склад"></label><label>Опис розташування<textarea id="description" name="description" maxlength="200" rows="3" placeholder="Стелаж, полиця або зона"><?=e(field_value('description',$record))?></textarea></label>
<?php endif; ?>
</div><div class="form-footer"><a class="button secondary" href="/?page=<?=$isPart?'inventory':'locations'?>">Скасувати</a><button class="button primary">Зберегти</button></div></form>
<?php endif; ?>
<?php elseif($page==='locations'): ?>
<div class="locations-grid"><?php foreach($locations as $l): ?><article class="panel location-card"><div class="location-card-top"><span class="location-code"><?=e($l['code'])?></span><span class="badge ok">Місце зберігання</span></div><h2><?=e($l['name'])?></h2><p><?=e($l['description'])?></p><div class="location-totals"><div><strong><?=(int)$l['positions']?></strong><span>позицій</span></div><div><strong><?=(int)$l['total']?></strong><span>одиниць</span></div></div><div class="card-actions"><a href="/?page=location-form&amp;id=<?=$l['id']?>">Редагувати</a><form method="post" data-confirm="Видалити місце зберігання?"><?=csrf()?><input type="hidden" name="action" value="delete_location"><input type="hidden" name="id" value="<?=$l['id']?>"><button class="text-danger">Видалити</button></form></div></article><?php endforeach; ?></div>
<?php if(!$locations): ?><p class="empty-state">Додайте перше місце зберігання.</p><?php endif; ?>
<?php elseif($page==='movement'):
$type=field_value('type',[],'receipt'); ?>
<form class="panel editor movement-form" method="post"><?=csrf()?><input type="hidden" name="action" value="move_stock"><div class="panel-heading"><h2>Рух комплектуючих</h2><span class="muted">* Обов’язкові поля</span></div><div class="form-body">
<label>Тип операції *<select id="type" name="type" required><?php foreach(['receipt'=>'Надходження','issue'=>'Списання на техобслуговування','transfer'=>'Переміщення між місцями'] as $key=>$value): ?><option value="<?=$key?>" <?=$type===$key?'selected':''?>><?=$value?></option><?php endforeach; ?></select></label>
<label>Комплектуюча *<select id="part_id" name="part_id" required><?php option_list($parts,field_value('part_id',[]),'part'); ?></select></label>
<div class="form-grid"><label data-source>Звідки *<select id="from_location_id" name="from_location_id"><?php option_list($locations,field_value('from_location_id',[]),'location'); ?></select></label><label data-destination>Куди *<select id="to_location_id" name="to_location_id"><?php option_list($locations,field_value('to_location_id',[]),'location'); ?></select></label></div>
<label>Кількість, шт. *<input id="quantity" type="number" name="quantity" value="<?=e(field_value('quantity',[],'1'))?>" min="1" max="1000000" step="1" required class="short-input"></label>
<label>Підстава або примітка *<textarea id="note" name="note" minlength="3" maxlength="200" rows="3" required placeholder="Наприклад, накладна № 017 або ТО автомобіля AA1234BB"><?=e(field_value('note',[]))?></textarea></label>
<p class="form-note">Списання обмежене залишком у вибраному місці. Переміщення зберігає загальну кількість деталей. Проведені операції зберігаються в журналі.</p>
</div><div class="form-footer"><a class="button secondary" href="/">Скасувати</a><button class="button primary">Провести операцію</button></div></form>
<?php elseif($page==='history'):
$partFilter=query('part'); $params=[]; $where='';
if($partFilter!=='' && ctype_digit($partFilter)) {$where=' WHERE m.part_id=?'; $params[]=$partFilter;}
$total=(int)run('SELECT COUNT(*) FROM movements m'.$where,$params)->fetchColumn();
$pages=max(1,(int)ceil($total/20)); $current=max(1,min($pages,(int)query('n','1'))); $offset=($current-1)*20;
$movements=run('SELECT m.*,p.sku,p.name,lf.code AS source,lt.code AS destination FROM movements m JOIN parts p ON p.id=m.part_id LEFT JOIN locations lf ON lf.id=m.from_location_id LEFT JOIN locations lt ON lt.id=m.to_location_id'.$where.' ORDER BY m.id DESC LIMIT 20 OFFSET '.$offset,$params)->fetchAll();
$types=['receipt'=>'Надходження','issue'=>'Списання','transfer'=>'Переміщення']; ?>
<section class="panel"><div class="panel-heading"><h2>Усі операції <span class="count"><?=$total?></span></h2><form class="history-filter" method="get"><input type="hidden" name="page" value="history"><label><span class="sr-only">Комплектуюча</span><select name="part"><option value="">Усі комплектуючі</option><?php foreach($parts as $p): ?><option value="<?=$p['id']?>" <?=$partFilter===(string)$p['id']?'selected':''?>><?=e($p['sku'])?></option><?php endforeach; ?></select></label><button class="button secondary">Фільтрувати</button></form></div>
<div class="table-scroll"><table class="history-table"><thead><tr><th>Дата та номер</th><th>Комплектуюча</th><th>Операція</th><th>Маршрут</th><th class="number">Кількість</th><th>Підстава</th></tr></thead><tbody><?php foreach($movements as $m): ?><tr><td><?=e(local_date($m['created_at']))?><span class="secondary-text">#<?=str_pad((string)$m['id'],4,'0',STR_PAD_LEFT)?></span></td><td><strong><?=e($m['name'])?></strong><span class="secondary-text"><?=e($m['sku'])?></span></td><td><span class="badge <?=$m['type']?>"><?=$types[$m['type']]?></span></td><td><?=e($m['source']??'Постачальник')?> → <?=e($m['destination']??'Техобслуговування')?></td><td class="number stock-number"><?=(int)$m['quantity']?></td><td><?=e($m['note'])?></td></tr><?php endforeach; ?><?php if(!$movements): ?><tr><td colspan="6" class="empty-state">Операцій ще немає.</td></tr><?php endif; ?></tbody></table></div><div class="panel-footer"><span>Сторінка <?=$current?> із <?=$pages?> · Час Києва</span><div><?php if($current>1): ?><a href="/?page=history&amp;part=<?=e($partFilter)?>&amp;n=<?=$current-1?>">← Назад</a><?php endif; ?> <?php if($current<$pages): ?><a href="/?page=history&amp;part=<?=e($partFilter)?>&amp;n=<?=$current+1?>">Далі →</a><?php endif; ?></div></div></section>
<?php else: ?><a class="button primary" href="/">До запасів</a><?php endif; ?>
<footer class="page-footer">FleetStock <span>Система контролю запасів комплектуючих автопарку</span></footer>
</div></main></body></html>
