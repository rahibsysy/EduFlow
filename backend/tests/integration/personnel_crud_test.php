<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\Request;
use App\Middleware\PermissionMiddleware;
use App\Services\PersonnelService;
use App\Services\SubjectService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
if (getenv('MIRANDA_FINANCE_TEST') !== '1' || !preg_match('/^miranda_finance_data_test_/', (string)getenv('DB_NAME'))) { fwrite(STDERR, "Refusing unsafe database.\n"); exit(2); }
function personnelExpect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

$pdo=Database::connect();$suffix=(string)getmypid();
$school=$pdo->prepare('INSERT INTO schools(name,code,slug,email_domain,status) VALUES(?,?,?,?,"ACTIVE")');
$school->execute(['Personnel A','PA'.$suffix,'personnel-a-'.$suffix,'personnel-a-'.$suffix.'.test']);$a=(int)$pdo->lastInsertId();
$school->execute(['Personnel B','PB'.$suffix,'personnel-b-'.$suffix,'personnel-b-'.$suffix.'.test']);$b=(int)$pdo->lastInsertId();
$class=$pdo->prepare('INSERT INTO class_levels(school_id,name,level_name,group_name,school_year,status) VALUES(?,?,?,?,?,"ACTIVE")');$class->execute([$a,'1AC 1','1AC','1','2026/2027']);$classA=(int)$pdo->lastInsertId();
(new SubjectService())->ensureSubjectsForClassLevel($classA);
$subject=(int)$pdo->query('SELECT subject_id FROM subject_class_levels WHERE class_level_id='.(int)$classA.' LIMIT 1')->fetchColumn();personnelExpect($subject>0,'No subject available for professor assignment fixture');
$user=$pdo->prepare('INSERT INTO users(school_id,first_name,last_name,email,password,role,status) VALUES(?,?,?,?,?,"user","ACTIVE")');$user->execute([$a,'Personnel','View','personnel-view-'.$suffix.'@test.local','test']);$viewer=(int)$pdo->lastInsertId();
$user->execute([$a,'Personnel','Manage','personnel-manage-'.$suffix.'@test.local','test']);$manager=(int)$pdo->lastInsertId();
$permissions=$pdo->query('SELECT code,id FROM permissions WHERE code IN ("personnel.view","personnel.manage")')->fetchAll(PDO::FETCH_KEY_PAIR);$grant=$pdo->prepare('INSERT INTO user_permissions(user_id,permission_id) VALUES(?,?)');$grant->execute([$viewer,$permissions['personnel.view']]);$grant->execute([$manager,$permissions['personnel.manage']]);
Request::set('auth_user',['id'=>$viewer,'role'=>'user','school_id'=>$a]);personnelExpect(PermissionMiddleware::allows('personnel.view'),'personnel.view permission was rejected');personnelExpect(!PermissionMiddleware::allows('personnel.manage'),'personnel.manage was granted unexpectedly');
Request::set('auth_user',['id'=>$manager,'role'=>'user','school_id'=>$a]);personnelExpect(PermissionMiddleware::allows('personnel.manage'),'personnel.manage permission was rejected');
Request::set('auth_user',['id'=>1,'role'=>'admin','school_id'=>$a]);$service=new PersonnelService();
$admin=$service->create(['personnel_type'=>'ADMINISTRATIVE_STAFF','first_name'=>'Amal','last_name'=>'Admin','phone'=>'0600000001','email'=>'amal-'.$suffix.'@test.local','job_title'=>'Secrétaire','department'=>'Administration','cin'=>'PA-'.$suffix]);personnelExpect(!isset($admin['error']),'Administrative personnel creation failed: '.($admin['error']??''));$adminId=(int)$admin['id'];personnelExpect(str_starts_with((string)$admin['personnel_number'],'PERS-'.$a.'-'),'Automatic personnel number is invalid');
$row=$pdo->prepare('SELECT first_name,email,personnel_type,status FROM personnel_profiles WHERE id=? AND school_id=?');$row->execute([$adminId,$a]);$row=$row->fetch();personnelExpect($row['first_name']==='Amal'&&$row['email']==='amal-'.$suffix.'@test.local'&&$row['personnel_type']==='ADMINISTRATIVE_STAFF'&&$row['status']==='ACTIF','Administrative personnel was not persisted');
$updated=$service->update($adminId,['first_name'=>'Amal Updated','phone'=>'0600000002','job_title'=>'Responsable administrative']);personnelExpect(!isset($updated['error']),'Personnel update failed');$row=$pdo->prepare('SELECT first_name,phone,job_title FROM personnel_profiles WHERE id=?');$row->execute([$adminId]);$row=$row->fetch();personnelExpect($row['first_name']==='Amal Updated'&&$row['phone']==='0600000002','Personnel update did not persist');
personnelExpect(($service->status($adminId,'ARCHIVE')['status']??'')==='ARCHIVE','Personnel deactivation failed');personnelExpect(($service->status($adminId,'ACTIF')['status']??'')==='ACTIF','Personnel reactivation failed');
$professor=$service->create(['personnel_type'=>'PROFESSOR','first_name'=>'Paul','last_name'=>'NoAssignment','phone'=>'0600000003','job_title'=>'Professeur']);personnelExpect(!isset($professor['error']),'Professor without assignments was rejected: '.($professor['error']??''));$shown=$service->one((int)$professor['id']);personnelExpect($shown!==false&&$shown['subject_ids']===[]&&$shown['class_level_ids']===[],'Unassigned professor relations are not empty');
$assigned=$service->create(['personnel_type'=>'PROFESSOR','first_name'=>'Sara','last_name'=>'Assigned','phone'=>'0600000004','job_title'=>'Professeur','subject_ids'=>[$subject],'class_level_ids'=>[$classA]]);personnelExpect(!isset($assigned['error']),'Professor with valid assignments failed: '.($assigned['error']??''));$shown=$service->one((int)$assigned['id']);personnelExpect($shown!==false&&$shown['subject_ids']===[$subject]&&$shown['class_level_ids']===[$classA],'Professor assignments were not persisted');
$duplicate=$service->create(['personnel_type'=>'ADMINISTRATIVE_STAFF','first_name'=>'Duplicate','last_name'=>'Cin','phone'=>'0600000005','job_title'=>'Agent','cin'=>'PA-'.$suffix]);personnelExpect(isset($duplicate['error']),'Duplicate CIN was accepted');
Request::set('auth_user',['id'=>2,'role'=>'admin','school_id'=>$b]);personnelExpect($service->one($adminId)===false,'Cross-school personnel consultation was allowed');$forbidden=$service->update($adminId,['first_name'=>'Intruder']);personnelExpect(($forbidden['error']??'')==='Personnel introuvable','Cross-school personnel update was allowed');
echo "personnel CRUD integration: OK\n";
