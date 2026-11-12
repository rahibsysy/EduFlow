<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\Request;
use App\Services\MonthlyFeeService;
use App\Services\ChargeCategoryService;
use App\Services\StudentService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (getenv('MIRANDA_FINANCE_TEST') !== '1' || !preg_match('/^miranda_finance_data_test_/', (string) getenv('DB_NAME'))) {
    fwrite(STDERR, "Refusing unsafe database.\n"); exit(2);
}
function tuitionExpect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

$pdo = Database::connect(); $suffix = (string) getmypid();
$pdo->prepare('INSERT INTO schools(name,code,slug,email_domain) VALUES(?,?,?,?)')->execute(['Tuition grid '.$suffix,'TG'.$suffix,'tuition-grid-'.$suffix,'tuition-'.$suffix.'.test']);
$school = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO academic_years(school_id,label,name,status,is_current) VALUES(?,?,?,"ACTIVE",1)')->execute([$school,'2026/2027','2026/2027']);
$pdo->prepare('INSERT INTO class_levels(school_id,name,level_name,group_name,school_year,status) VALUES(?,?,?,?,?,"ACTIVE")')->execute([$school,'1AC 1','1AC','1','2026/2027']);
$class = (int) $pdo->lastInsertId();
Request::set('auth_user', ['role' => 'admin', 'school_id' => $school]);
$students = new StudentService();
(new ChargeCategoryService())->all(true);

$missing = $students->createModern(['first_name'=>'No','last_name'=>'Tariff','class_level_id'=>$class,'parent_phone'=>'060'.$suffix,'tuition_enabled'=>true,'charges'=>[]]);
tuitionExpect(isset($missing['error']) && str_contains($missing['error'], 'Tarif non configuré'), 'Missing tuition tariff was silently accepted');

$pdo->prepare('INSERT INTO tuition_fee_schedules(school_id,school_year,level_name,stream,monthly_amount,updated_by) VALUES(?,?,?,?,?,NULL)')->execute([$school,'2026/2027','1AC','',1300]);
$created = $students->createModern([
    'first_name'=>'Tariff','last_name'=>'Student','class_level_id'=>$class,'parent_phone'=>'061'.$suffix,
    'tuition_enabled'=>true,'monthly_amount'=>1,'monthly_discount_type'=>'FIXED','monthly_discount_value'=>100,
    'monthly_discount_reason'=>'Bourse test','charges'=>[]
]);
tuitionExpect(!isset($created['error']), 'Configured tariff student creation failed: '.($created['error'] ?? 'unknown'));
$student = (int) $created['id'];
$q=$pdo->prepare('SELECT monthly_amount,monthly_discount_type,monthly_discount_value,monthly_discount_reason FROM students WHERE id=?'); $q->execute([$student]); $row=$q->fetch();
tuitionExpect((float)$row['monthly_amount'] === 1300.0, 'Client supplied monthly amount overrode configured tariff');
tuitionExpect($row['monthly_discount_type']==='FIXED' && (float)$row['monthly_discount_value']===100.0 && $row['monthly_discount_reason']==='Bourse test', 'Monthly discount audit fields were not persisted');
$fee=(new MonthlyFeeService())->resolveOrCreateFee($school,$student,null,'01',2027);
tuitionExpect(!isset($fee['error']) && (float)$fee['fee']['total_amount']===1200.0, 'Fixed monthly discount was not applied to future monthly fee');

$percentage = $students->createModern(['first_name'=>'Percent','last_name'=>'Student','class_level_id'=>$class,'parent_phone'=>'062'.$suffix,'tuition_enabled'=>true,'monthly_discount_type'=>'PERCENTAGE','monthly_discount_value'=>10,'monthly_discount_reason'=>'Bourse pourcentage','charges'=>[]]);
tuitionExpect(!isset($percentage['error']), 'Percentage tuition discount creation failed');
$percentageFee=(new MonthlyFeeService())->resolveOrCreateFee($school,(int)$percentage['id'],null,'02',2027);
tuitionExpect((float)$percentageFee['fee']['total_amount']===1170.0, 'Percentage monthly discount was not applied');

$categories=$pdo->prepare('SELECT id,code,default_amount FROM charge_categories WHERE school_id=? AND code IN ("TRANSPORT","DAYCARE","SMOCK","PAPER","REGISTRATION_INSURANCE")');$categories->execute([$school]);$rows=$categories->fetchAll(); tuitionExpect(count($rows)===5, 'Expected registration categories are missing');
$charges=[]; foreach($rows as $category){$charges[]=['charge_category_id'=>(int)$category['id'],'label'=>$category['code'],'original_amount'=>(float)$category['default_amount'],'discount_type'=>'FIXED','discount_value'=>10,'discount_reason'=>'Aide test'];}
$withCharges=$students->createModern(['first_name'=>'Charges','last_name'=>'Student','class_level_id'=>$class,'parent_phone'=>'063'.$suffix,'tuition_enabled'=>false,'guardians'=>[['full_name'=>'Guardian Charges','phone_primary'=>'063'.$suffix]],'charges'=>$charges]);
tuitionExpect(!isset($withCharges['error']), 'Student charges creation failed: '.($withCharges['error']??''));
$count=$pdo->prepare('SELECT COUNT(*),MIN(d.discount_value),MAX(d.discount_value),MIN(d.notes) FROM student_financial_items fi INNER JOIN student_financial_item_details d ON d.student_financial_item_id=fi.id WHERE fi.student_id=?');$count->execute([(int)$withCharges['id']]);$check=$count->fetch(PDO::FETCH_NUM); tuitionExpect((int)$check[0]===5 && (float)$check[1]===10.0 && (float)$check[2]===10.0 && $check[3]==='Aide test','Individual charge discounts or reasons were not persisted');
$duplicate=$students->updateModern((int)$withCharges['id'],['class_level_id'=>$class,'charges'=>$charges]); tuitionExpect(!isset($duplicate['error']), 'Existing student update failed');
$after=$pdo->prepare('SELECT COUNT(*) FROM student_financial_items WHERE student_id=?');$after->execute([(int)$withCharges['id']]); tuitionExpect((int)$after->fetchColumn()===5,'Updating a dossier duplicated financial charges');
echo "tuition grid integration: OK\n";
