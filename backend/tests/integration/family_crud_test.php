<?php
declare(strict_types=1);
use App\Core\Database; use App\Core\Request; use App\Services\FamilyService;
require dirname(__DIR__,2).'/vendor/autoload.php';
if(getenv('MIRANDA_FINANCE_TEST')!=='1'||!preg_match('/^miranda_finance_data_test_/',(string)getenv('DB_NAME'))){fwrite(STDERR,"Refusing unsafe database.\n");exit(2);} function ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
$pdo=Database::connect();$x=(string)getmypid();
$pdo->prepare('INSERT INTO schools(name,code,slug,email_domain,status)VALUES(?,?,?,?,"ACTIVE")')->execute(['Family A','FA'.$x,'family-a-'.$x,'family-a-'.$x.'.test']);$a=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO schools(name,code,slug,email_domain,status)VALUES(?,?,?,?,"ACTIVE")')->execute(['Family B','FB'.$x,'family-b-'.$x,'family-b-'.$x.'.test']);$b=(int)$pdo->lastInsertId();
$add=$pdo->prepare('INSERT INTO students(school_id,first_name,last_name,class_level,parent_name,phone,monthly_amount,status)VALUES(?,?,?,?,?,?,0,"REGISTERED")');$add->execute([$a,'One','Child','Test','Parent','0600']);$s1=(int)$pdo->lastInsertId();$add->execute([$a,'Two','Child','Test','Parent','0601']);$s2=(int)$pdo->lastInsertId();$add->execute([$b,'Other','Child','Test','Parent','0602']);$other=(int)$pdo->lastInsertId();
Request::set('auth_user',['id'=>1,'role'=>'admin','school_id'=>$a]);$service=new FamilyService();$family=$service->create(['family_name'=>'Fixture household','family_reference'=>'FAM-'.$x]);ok(!isset($family['error']),'Family creation failed');$fid=(int)$family['id'];
ok(!isset($service->attach($fid,$s1)['error'])&&!isset($service->attach($fid,$s2)['error']),'Could not attach both children');
$g1=$service->addGuardian($s1,['full_name'=>'Guardian One','relationship_type'=>'Parent','phone_primary'=>'0611','is_financial_responsible'=>true,'is_emergency_contact'=>true]);$g2=$service->addGuardian($s1,['full_name'=>'Guardian Two','relationship_type'=>'Parent','phone_primary'=>'0612','is_financial_responsible'=>false,'is_emergency_contact'=>false]);ok(!isset($g1['error'])&&!isset($g2['error']),'Guardian creation failed');
$shown=$service->show($fid);ok($shown!==false&&count($shown['members'])===2,'Family consultation did not return both children');ok(count($service->guardians($s1))===2,'Multiple guardians were not persisted');
Request::set('auth_user',['id'=>2,'role'=>'admin','school_id'=>$b]);ok($service->show($fid)===false,'Cross-school family read was allowed');ok(isset($service->attach($fid,$other)['error']),'Cross-school family attach was allowed');
echo "family CRUD integration: OK\n";
