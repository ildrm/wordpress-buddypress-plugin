<?php
require __DIR__.'/extended-bootstrap.php';
if (!wp_using_ext_object_cache() || !method_exists($GLOBALS['wp_object_cache'],'redis_status') || !$GLOBALS['wp_object_cache']->redis_status()) throw new RuntimeException('A connected real Redis cache is required.');
$checks=[];
function qaCheck(bool $condition,string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks[]=$message;
    echo 'PASS: '.$message."\n";
}
function qaWorkers(string $action,array $arguments): array {
    global $runtime;
    $workers=[];
    if (is_file($runtime.'/go')) unlink($runtime.'/go');
    foreach ($arguments as $index=>$argument) {
        $token=bin2hex(random_bytes(8));
        $pipes=[];
        $process=proc_open([PHP_BINARY,__DIR__.'/extended-worker.php',$action,(string)$argument,$token],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not start verification worker.');
        fclose($pipes[0]);
        $workers[]=[$process,$pipes,$token];
    }
    $deadline=microtime(true)+30;
    do {
        $ready=true;
        foreach ($workers as $worker) if (!is_file($runtime.'/ready-'.$worker[2])) $ready=false;
        if ($ready) break;
        usleep(10000);
    } while (microtime(true)<$deadline);
    file_put_contents($runtime.'/go','1');
    $results=[];
    foreach ($workers as [$process,$pipes,$token]) {
        $output=stream_get_contents($pipes[1]);
        $error=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code=proc_close($process);
        if (is_file($runtime.'/ready-'.$token)) unlink($runtime.'/ready-'.$token);
        if ($code || trim($error)!=='') throw new RuntimeException('Verification worker failed: '.$output.$error);
        $results[]=json_decode($output,true,512,JSON_THROW_ON_ERROR);
    }
    unlink($runtime.'/go');
    if (!$ready) throw new RuntimeException('Workers failed to reach the concurrency barrier.');
    return $results;
}
// The database and prefix guards above precede every destructive fixture operation.
foreach (array_keys(BuddyPressIntelligence\Database::COLUMNS) as $table) $db->query('DELETE FROM '.$db->table($table));
BuddyPressIntelligence\Core::option('bpi_settings',BuddyPressIntelligence\Settings::defaults(),true);
wp_cache_flush(); // This Redis server exists exclusively for these disposable tests.
qaActor(1);
$topic=$topics->save(['name'=>'Concurrency QA']);
$state=[];
foreach (['alice','bob','carol'] as $name) {
    $login='bpi_concurrent_'.$name;
    $user=get_user_by('login',$login);
    $id=$user?$user->ID:wp_insert_user(['user_login'=>$login,'user_pass'=>'bpi-test-password','user_email'=>$login.'@example.invalid','user_registered'=>gmdate('Y-m-d H:i:s',time()-30*DAY_IN_SECONDS),'role'=>'subscriber']);
    foreach (['_bpi_preferences','_bpi_hidden','_bpi_restricted_until'] as $key) delete_user_meta($id,$key);
    $state[$name]=(int)$id;
}
qaActor($state['alice']);
$state['question']=$knowledge->create($state['alice'],['kind'=>'question','title'=>'Concurrent acceptance','body'=>'One winner','topics'=>[$topic]]);
$state['answer_a']=$knowledge->create($state['bob'],['kind'=>'answer','parent_id'=>$state['question'],'body'=>'Answer A']);
$state['answer_b']=$knowledge->create($state['carol'],['kind'=>'answer','parent_id'=>$state['question'],'body'=>'Answer B']);
$state['rate_action']='concurrent:'.bin2hex(random_bytes(8));
qaActor(1);
$state['rule']=$automation->save(['name'=>'Concurrent notification','trigger'=>'member_profile_updated','action'=>'notify','enabled'=>true,'dry_run'=>false]);
$state['event']=$events->record('member_profile_updated',$state['alice'],'member',$state['alice']);
file_put_contents($runtime.'/extended-state.json',json_encode($state,JSON_THROW_ON_ERROR));
$started=microtime(true);
$answers=array_merge(array_fill(0,6,$state['answer_a']),array_fill(0,6,$state['answer_b']));
$results=qaWorkers('accept',$answers);
$question=$db->one('entries',['id'=>$state['question']]);
qaCheck(in_array((int)$question['accepted_id'],[$state['answer_a'],$state['answer_b']],true),'12 simultaneous acceptance requests select one valid answer');
qaCheck((int)$question['revision']===1,'Acceptance revision advances exactly once');
qaCheck(count($db->rows('ledger',['kind'=>'accepted_answer'],50))===1,'Competing acceptance awards exactly one reputation credit');
qaCheck(count($db->rows('events',['type'=>'answer_accepted'],50))===1,'Competing acceptance emits exactly one event');
qaCheck(count(array_filter($results,static fn($r)=>$r['status']==='ok'))===6,'Winning acceptance retries are idempotent and competing answers are rejected');
qaWorkers('vote',range(1,12));
qaCheck(count($db->rows('votes',['actor'=>$state['carol'],'object_id'=>$state['answer_a']],50))===1,'12 simultaneous helpful votes persist once');
qaWorkers('automation',range(1,12));
$runs=$db->rows('runs',['rule_id'=>$state['rule'],'event_id'=>$state['event']],50);
qaCheck(count($runs)===1 && $runs[0]['status']==='done' && (int)$runs[0]['attempts']===1,'12 simultaneous automation deliveries commit one successful run');
$notifications=$db->select('SELECT COUNT(*) AS total FROM '.buddypress()->notifications->table_name.' WHERE component_name=%s AND item_id=%d AND secondary_item_id=%d',['bpi',$state['rule'],$state['event']]);
qaCheck((int)$notifications[0]['total']===1,'Concurrent automation sends exactly one native notification');
qaActor(1);
for ($i=0;$i<98;$i++) $automation->save(['name'=>'Capacity '.$i,'trigger'=>'member_registered','action'=>'notify','enabled'=>false]);
$results=qaWorkers('rule',range(1,8));
qaCheck(count($db->rows('rules',[],101))===100 && count(array_filter($results,static fn($r)=>$r['status']==='ok'))===1,'8 competing rule creations preserve the 100-rule capacity');
$db->query('DELETE FROM '.$db->table('jobs'));
$executionTable=$GLOBALS['wpdb']->prefix.'qa_executions';
$db->query('CREATE TABLE IF NOT EXISTS '.$executionTable.' (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,job_id INT NOT NULL,PRIMARY KEY (id)) ENGINE=InnoDB');
$db->query('DELETE FROM '.$executionTable);
for ($i=1;$i<=120;$i++) $jobs->enqueue('rollup',['job_id'=>$i],'concurrent-job:'.$i);
qaWorkers('work',range(1,8));
$executions=$db->select('SELECT COUNT(*) AS total,COUNT(DISTINCT job_id) AS distinct_jobs FROM '.$executionTable,[])[0];
qaCheck((int)$executions['total']===120 && (int)$executions['distinct_jobs']===120,'8 queue workers execute all 120 handlers exactly once');
qaCheck(count($db->rows('jobs',['status'=>'done'],150))===120 && !$db->one('jobs',['status'=>'running']) && !$db->one('jobs',['status'=>'pending']),'Concurrent queue drains without stranded or failed leases');
$results=qaWorkers('rate',range(1,12));
$allowed=array_sum(array_column($results,'allowed'));
qaCheck($allowed>0 && $allowed<=40,'300 competing rate-limit requests never exceed the 40-request allowance');
// Prove actual persistence across fresh PHP processes, then recheck changing policy and erasure.
qaActor($state['alice']);
$state['activity']=bp_activity_add(['user_id'=>$state['bob'],'component'=>'activity','type'=>'activity_update','content'=>'Cross-process cached activity']);
$policy->clear();
$feed=$discovery->retrieve($state['alice'],['kind'=>'feed','mode'=>'latest','per_page'=>50]);
$state['cursor']=$feed['cursor'];
$state['marker']=bin2hex(random_bytes(16));
wp_cache_set('process-marker',$state['marker'],'bpi_qa',300);
file_put_contents($runtime.'/extended-state.json',json_encode($state,JSON_THROW_ON_ERROR));
$results=qaWorkers('cached',[1]);
qaCheck(in_array($state['activity'],$results[0]['ids'],true),'Fresh PHP process reads the existing Redis feed snapshot');
qaWorkers('block',[1]);
$results=qaWorkers('cached',[1]);
qaCheck(!in_array($state['activity'],$results[0]['ids'],true),'Fresh process excludes a newly blocked author from the cached snapshot');
qaWorkers('erase',[1]);
$results=qaWorkers('expired',[1]);
qaCheck($results[0]['status']==='expired','Erasure invalidates existing Redis cursors across PHP processes');
$report=['php'=>PHP_VERSION,'wordpress'=>get_bloginfo('version'),'buddypress'=>bp_get_version(),'real_redis'=>true,'checks'=>count($checks),'passed'=>$checks,'elapsed_seconds'=>round(microtime(true)-$started,3),'rate_allowed'=>$allowed];
file_put_contents($runtime.'/extended-results.json',json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
