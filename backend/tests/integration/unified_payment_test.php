<?php
declare(strict_types=1);
foreach(['DB_HOST','DB_PORT','DB_NAME','DB_USER','DB_PASS'] as $k)if(($v=getenv($k))!==false)$_ENV[$k]=$v;
use App\Core\Database; use App\Core\Request; use App\Services\PaymentService; use App\Services\ReceiptService;
require dirname(__DIR__,2).'/vendor/autoload.php';
if(getenv('MIRANDA_FINANCE_TEST')!=='1'||!preg_match('/^miranda_finance_data_test_/',(string)getenv('DB_NAME')))exit(2);
function unifiedExpect(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$pdo=Database::connect();$x=(string)getmypid();
$pdo->prepare('INSERT INTO schools(name,code,slug,email_domain) VALUES(?,?,?,?)')->execute(['Unified '.$x,'UN'.$x,'unified-'.$x,'unified-'.$x.'.test']);$school=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO schools(name,code,slug,email_domain) VALUES(?,?,?,?)')->execute(['Other '.$x,'OU'.$x,'other-unified-'.$x,'other-'.$x.'.test']);$other=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO users(school_id,first_name,last_name,email,password,role,status) VALUES(?,?,?,?,?,"admin","ACTIVE")')->execute([$school,'Unified','Tester','unified-'.$x.'@test.local','test']);$user=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO students(school_id,first_name,last_name,class_level,monthly_amount,status) VALUES(?,?,?,?,?,"REGISTERED")')->execute([$school,'Unified','Student','1AC',1000]);$student=(int)$pdo->lastInsertId();
$method=(int)$pdo->query('SELECT id FROM payment_methods WHERE status="ACTIVE" ORDER BY id LIMIT 1')->fetchColumn();
$pdo->prepare('INSERT INTO monthly_fees(school_id,student_id,month_label,year_value,total_amount,amount_paid,remaining_amount,status) VALUES(?,?,"01",2027,1000,0,1000,"UNPAID"),(?,?,"02",2027,900,0,900,"UNPAID")')->execute([$school,$student,$school,$student]);
$fees=$pdo->prepare('SELECT id FROM monthly_fees WHERE school_id=? AND student_id=? ORDER BY id');$fees->execute([$school,$student]);$fees=array_map('intval',array_column($fees->fetchAll(),'id'));
$pdo->prepare('INSERT INTO student_financial_items(school_id,student_id,item_code,idempotency_key,label,unit_amount,total_amount,paid_amount,status) VALUES(?,?,?,?,?,?,?,0,"UNPAID")')->execute([$school,$student,'TRANSPORT-'.$x,'unified-transport-'.$x,'Transport janvier',250,250]);$transport=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO student_financial_item_details(student_financial_item_id,school_id,original_amount,final_amount) VALUES(?,?,?,?)')->execute([$transport,$school,250,250]);
Request::set('auth_user',['id'=>$user,'role'=>'admin','school_id'=>$school]);$service=new PaymentService();
$payload=['student_id'=>$student,'amount_paid'=>750,'payment_date'=>'2027-01-10','payment_method_id'=>$method,'idempotency_key'=>'unified-'.$x,'allocations'=>[['monthly_fee_id'=>$fees[0],'amount'=>400],['monthly_fee_id'=>$fees[1],'amount'=>200],['student_financial_item_id'=>$transport,'amount'=>150]]];
$created=$service->create($payload);unifiedExpect(!isset($created['error']),'Unified payment failed: '.($created['error']??''));$id=(int)$created['id'];
$replay=$service->create($payload);unifiedExpect(($replay['idempotent_replay']??false)===true&&(int)$replay['id']===$id,'Unified retry is not idempotent');
$alloc=$pdo->prepare('SELECT monthly_fee_id,student_financial_item_id,amount FROM payment_allocations WHERE payment_id=? ORDER BY id');$alloc->execute([$id]);$rows=$alloc->fetchAll();unifiedExpect(count($rows)===3&&(int)$rows[0]['monthly_fee_id']===$fees[0]&&(int)$rows[1]['monthly_fee_id']===$fees[1]&&(int)$rows[2]['student_financial_item_id']===$transport,'Unified allocations are incomplete');
$check=$pdo->prepare('SELECT amount_paid,remaining_amount FROM monthly_fees WHERE id=?');$check->execute([$fees[0]]);$first=$check->fetch();unifiedExpect((float)$first['amount_paid']===400.0&&(float)$first['remaining_amount']===600.0,'First monthly balance is wrong');
$item=$pdo->prepare('SELECT paid_amount,status FROM student_financial_items WHERE id=?');$item->execute([$transport]);$item=$item->fetch();unifiedExpect((float)$item['paid_amount']===150.0&&$item['status']==='PARTIAL','Flexible balance is wrong');
$receipt=new ReceiptService();$data=$receipt->generateData($id);unifiedExpect(count($data['allocations']??[])===3&&str_starts_with((string)$receipt->generatePdf($id)['content'],'%PDF'),'Unified receipt is not valid');
$cancel=$service->cancel($id,['reason'=>'Unified test']);unifiedExpect(($cancel['status']??'')==='CANCELLED','Unified cancellation failed');$check->execute([$fees[0]]);$first=$check->fetch();unifiedExpect((float)$first['amount_paid']===0.0,'Cancellation did not restore monthly balance');$item=$pdo->prepare('SELECT paid_amount FROM student_financial_items WHERE id=?');$item->execute([$transport]);unifiedExpect((float)$item->fetchColumn()===0.0,'Cancellation did not restore flexible balance');
Request::set('auth_user',['id'=>$user,'role'=>'admin','school_id'=>$other]);$forbidden=$service->create(['student_id'=>$student,'amount_paid'=>1,'payment_date'=>'2027-01-10','payment_method_id'=>$method,'allocations'=>[['monthly_fee_id'=>$fees[0],'amount'=>1]]]);unifiedExpect(isset($forbidden['error']),'Cross-school unified payment was allowed');
echo "unified payment integration: OK\n";
