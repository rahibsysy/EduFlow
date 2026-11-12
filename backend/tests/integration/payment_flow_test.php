<?php
declare(strict_types=1);

foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $key) {
    if (($value = getenv($key)) !== false) $_ENV[$key] = $value;
}

use App\Core\Database;
use App\Core\Request;
use App\Services\PaymentService;
use App\Services\ReceiptService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
if (getenv('MIRANDA_FINANCE_TEST') !== '1' || !preg_match('/^miranda_finance_data_test_/', (string) getenv('DB_NAME'))) exit(2);
function paymentExpect(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }

$pdo = Database::connect(); $suffix = (string) getmypid();
$pdo->prepare('INSERT INTO schools(name,code,slug,email_domain) VALUES(?,?,?,?)')->execute(['Payment flow '.$suffix,'PF'.$suffix,'payment-flow-'.$suffix,'payment-flow-'.$suffix.'.test']);
$school = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO schools(name,code,slug,email_domain) VALUES(?,?,?,?)')->execute(['Other payment '.$suffix,'PO'.$suffix,'other-payment-'.$suffix,'other-payment-'.$suffix.'.test']);
$otherSchool = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO users(school_id,first_name,last_name,email,password,role,status) VALUES(?,?,?,?,?,"admin","ACTIVE")')->execute([$school,'Payment','Tester','payment-'.$suffix.'@test.local','test']);
$user = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO students(school_id,first_name,last_name,class_level,monthly_amount,status) VALUES(?,?,?,?,?,"REGISTERED")')->execute([$school,'Multi','Payer','1AC',1000]);
$student = (int)$pdo->lastInsertId();
$method = (int)$pdo->query('SELECT id FROM payment_methods WHERE status="ACTIVE" ORDER BY id LIMIT 1')->fetchColumn();
paymentExpect($method > 0, 'No active payment method');
Request::set('auth_user', ['id'=>$user,'role'=>'admin','school_id'=>$school]);

$pdo->prepare('INSERT INTO monthly_fees(school_id,student_id,month_label,year_value,total_amount,amount_paid,remaining_amount,status) VALUES(?,?,"01",2027,1000,0,1000,"UNPAID")')->execute([$school,$student]);
$monthly = (int)$pdo->lastInsertId();
$service = new PaymentService();
$firstMonthly = $service->create(['student_id'=>$student,'monthly_fee_id'=>$monthly,'amount_paid'=>400,'payment_date'=>'2027-01-10','payment_method_id'=>$method,'idempotency_key'=>'payment-monthly-'.$suffix]);
paymentExpect(!isset($firstMonthly['error']), 'Monthly payment failed');
$retryMonthly = $service->create(['student_id'=>$student,'monthly_fee_id'=>$monthly,'amount_paid'=>400,'payment_date'=>'2027-01-10','payment_method_id'=>$method,'idempotency_key'=>'payment-monthly-'.$suffix]);
paymentExpect(($retryMonthly['idempotent_replay'] ?? false) === true && (int)$retryMonthly['id'] === (int)$firstMonthly['id'], 'Monthly retry created a duplicate payment');
$count = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE school_id=? AND monthly_fee_id=?'); $count->execute([$school,$monthly]);
paymentExpect((int)$count->fetchColumn() === 1, 'Monthly retry created another database row');

$item = $pdo->prepare('INSERT INTO student_financial_items(school_id,student_id,item_code,idempotency_key,label,unit_amount,total_amount,paid_amount,status) VALUES(?,?,?,?,?,?,?,0,"UNPAID")');
$detail = $pdo->prepare('INSERT INTO student_financial_item_details(student_financial_item_id,school_id,original_amount,final_amount) VALUES(?,?,?,?)');
foreach ([['TRANSPORT',250],['DAYCARE',120]] as [$code,$amount]) { $item->execute([$school,$student,$code.'-'.$suffix,$code.'-item-'.$suffix,$code,$amount,$amount]); $detail->execute([(int)$pdo->lastInsertId(),$school,$amount,$amount]); }
$items = $pdo->prepare('SELECT id FROM student_financial_items WHERE school_id=? AND student_id=? AND item_code IN (?,?) ORDER BY id'); $items->execute([$school,$student,'TRANSPORT-'.$suffix,'DAYCARE-'.$suffix]); $ids = array_map('intval', array_column($items->fetchAll(),'id'));
$flex = $service->create(['student_id'=>$student,'amount_paid'=>170,'payment_date'=>'2027-01-10','payment_method_id'=>$method,'idempotency_key'=>'payment-flex-'.$suffix,'allocations'=>[['student_financial_item_id'=>$ids[0],'amount'=>100],['student_financial_item_id'=>$ids[1],'amount'=>70]]]);
paymentExpect(!isset($flex['error']) && count($flex['allocations'] ?? []) === 2, 'Multi-fee flexible payment failed');
$retryFlex = $service->create(['student_id'=>$student,'amount_paid'=>170,'payment_date'=>'2027-01-10','payment_method_id'=>$method,'idempotency_key'=>'payment-flex-'.$suffix,'allocations'=>[['student_financial_item_id'=>$ids[0],'amount'=>100],['student_financial_item_id'=>$ids[1],'amount'=>70]]]);
paymentExpect(($retryFlex['idempotent_replay'] ?? false) === true && (int)$retryFlex['id'] === (int)$flex['id'], 'Flexible retry created a duplicate payment');
$allocationCount = $pdo->prepare('SELECT COUNT(*) FROM payment_allocations WHERE payment_id=?'); $allocationCount->execute([(int)$flex['id']]);
paymentExpect((int)$allocationCount->fetchColumn() === 2, 'Flexible payment allocations are wrong');
paymentExpect(str_starts_with((string)(new ReceiptService())->generatePdf((int)$flex['id'])['content'], '%PDF'), 'Flexible receipt PDF failed');

Request::set('auth_user', ['id'=>$user,'role'=>'admin','school_id'=>$otherSchool]);
$forbidden = $service->create(['student_id'=>$student,'amount_paid'=>1,'payment_date'=>'2027-01-10','payment_method_id'=>$method,'allocations'=>[['student_financial_item_id'=>$ids[0],'amount'=>1]]]);
paymentExpect(isset($forbidden['error']), 'Cross-school payment was allowed');
echo "payment flow integration: OK\n";
