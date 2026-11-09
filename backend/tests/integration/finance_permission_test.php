<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\Request;
use App\Middleware\PermissionMiddleware;
use App\Services\FinancialDataService;
use App\Services\PaymentDocumentService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
if (getenv('MIRANDA_FINANCE_TEST') !== '1' || !preg_match('/^miranda_finance_data_test_/', (string)getenv('DB_NAME'))) { fwrite(STDERR, "Refusing unsafe database.\n"); exit(2); }
function permissionExpect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

$pdo = Database::connect(); $suffix = (string)getmypid();
$school = $pdo->prepare('INSERT INTO schools(name,code,slug,email_domain,status)VALUES(?,?,?,? ,"ACTIVE")');
$school->execute(['Permission A','FPA'.$suffix,'permission-a-'.$suffix,'permission-a-'.$suffix.'.test']); $a=(int)$pdo->lastInsertId();
$school->execute(['Permission B','FPB'.$suffix,'permission-b-'.$suffix,'permission-b-'.$suffix.'.test']); $b=(int)$pdo->lastInsertId();
$user = $pdo->prepare('INSERT INTO users(school_id,first_name,last_name,email,password,role,status) VALUES(?,?,?,?,? ,?,"ACTIVE")');
$user->execute([$a,'No','Permission','none-'.$suffix.'@test','test','user']); $none=(int)$pdo->lastInsertId();
$user->execute([$a,'View','Only','view-'.$suffix.'@test','test','user']); $view=(int)$pdo->lastInsertId();
$user->execute([$a,'Manage','Only','manage-'.$suffix.'@test','test','user']); $manage=(int)$pdo->lastInsertId();
$permissions=$pdo->query('SELECT code,id FROM permissions WHERE code IN ("finance.view","finance.manage")')->fetchAll(PDO::FETCH_KEY_PAIR);
$grant=$pdo->prepare('INSERT INTO user_permissions(user_id,permission_id) VALUES(?,?)'); $grant->execute([$view,$permissions['finance.view']]); $grant->execute([$manage,$permissions['finance.manage']]);
$student=$pdo->prepare('INSERT INTO students(school_id,first_name,last_name,class_level,monthly_amount,status)VALUES(?,?,?,?,?,"REGISTERED")');
$student->execute([$a,'Finance','A','A',0]);$studentA=(int)$pdo->lastInsertId();$student->execute([$b,'Finance','B','B',0]);$studentB=(int)$pdo->lastInsertId();
$item=$pdo->prepare('INSERT INTO student_financial_items(school_id,student_id,item_code,label,unit_amount,total_amount,discount_amount,paid_amount,status)VALUES(?,?,?,?,?,?,0,0,"UNPAID")');
$detail=$pdo->prepare('INSERT INTO student_financial_item_details(student_financial_item_id,school_id,original_amount,final_amount)VALUES(?,?,?,?)');
foreach ([[$a,$studentA,'FPA','School A'],[$b,$studentB,'FPB','School B']] as [$schoolId,$studentId,$code,$label]) {$item->execute([$schoolId,$studentId,$code.$suffix,$label,10,10]);$id=(int)$pdo->lastInsertId();$detail->execute([$id,$schoolId,10,10]);}

Request::set('auth_user',['id'=>$none,'role'=>'user','school_id'=>$a]); permissionExpect(!PermissionMiddleware::allows('finance.view')&&!PermissionMiddleware::allows('finance.manage'),'User without finance permission was granted access');
Request::set('auth_user',['id'=>$view,'role'=>'user','school_id'=>$a]); permissionExpect(PermissionMiddleware::allows('finance.view')&&!PermissionMiddleware::allows('finance.manage'),'finance.view permissions are incorrect');
Request::set('auth_user',['id'=>$manage,'role'=>'user','school_id'=>$a]); permissionExpect(PermissionMiddleware::allows('finance.view')&&PermissionMiddleware::allows('finance.manage'),'finance.manage does not include server-side finance.view');
Request::set('auth_user',['id'=>1,'role'=>'admin','school_id'=>$a]); permissionExpect(PermissionMiddleware::allows('finance.view')&&PermissionMiddleware::allows('finance.manage'),'Admin finance permissions are incorrect');
$financial=new FinancialDataService(); try {$financial->getStudentFinancialSummary($a,$studentB);throw new RuntimeException('Cross-school student finance was allowed');}catch(DomainException){}
$_GET=['school_id'=>$b]; $adminPdf=(new PaymentDocumentService())->unpaid([]); permissionExpect(!isset($adminPdf['error'])&&str_starts_with((string)$adminPdf['content'],'%PDF'),'School-bound admin PDF failed');
Request::set('auth_user',['id'=>0,'role'=>'super_admin','school_id'=>0]); permissionExpect(PermissionMiddleware::allows('finance.view')&&PermissionMiddleware::allows('finance.manage'),'Super admin finance permissions are incorrect'); $_GET=['school_id'=>$b]; $globalPdf=(new PaymentDocumentService())->unpaid([]); permissionExpect(!isset($globalPdf['error'])&&str_starts_with((string)$globalPdf['content'],'%PDF'),'Super admin scoped PDF failed');
echo "finance permission integration: OK\n";
