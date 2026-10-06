<?php
require __DIR__.'/extended-bootstrap.php';
$state=json_decode(file_get_contents($runtime.'/extended-state.json'),true,512,JSON_THROW_ON_ERROR);
$action=$argv[1]??'';
qaActor($state['alice']);
// A filesystem barrier ensures workers are all bootstrapped before competing.
if (isset($argv[3])) {
    file_put_contents($runtime.'/ready-'.$argv[3],'1');
    $deadline=microtime(true)+30;
    while (!is_file($runtime.'/go') && microtime(true)<$deadline) usleep(10000);
    if (!is_file($runtime.'/go')) throw new RuntimeException('Worker barrier timeout.');
}
$result=['status'=>'ok'];
switch ($action) {
    case 'accept':
        try { $knowledge->accept($state['alice'],$state['question'],(int)$argv[2]); }
        catch (InvalidArgumentException $e) { $result['status']='conflict'; }
        break;
    case 'automation': $automation->process(['event_id'=>$state['event']]); break;
    case 'vote': $knowledge->vote($state['carol'],$state['answer_a']); break;
    case 'rule':
        qaActor(1);
        try { $automation->save(['name'=>'Competing rule '.$argv[2],'trigger'=>'member_registered','action'=>'notify','enabled'=>false]); }
        catch (InvalidArgumentException $e) { $result['status']='capacity'; }
        break;
    case 'work':
        $table=$GLOBALS['wpdb']->prefix.'qa_executions';
        $jobs->handlers(['rollup'=>static function(array $payload) use($db,$table): void {
            $db->query('INSERT INTO '.$table.' (job_id) VALUES (%d)',[(int)$payload['job_id']]);
            usleep(20000);
        }]);
        for ($round=0;$round<30;$round++) {
            $jobs->work();
            if (!$db->one('jobs',['status'=>'pending'])) break;
            usleep(20000);
        }
        break;
    case 'rate':
        $result['allowed']=0;
        for ($i=0;$i<25;$i++) if ($jobs->rateLimit($state['alice'],$state['rate_action'],40,DAY_IN_SECONDS)) $result['allowed']++;
        break;
    case 'cached':
        if (!wp_using_ext_object_cache() || wp_cache_get('process-marker','bpi_qa')!==$state['marker']) throw new RuntimeException('Real cross-process cache persistence failed.');
        $result['ids']=array_column($discovery->retrieve($state['alice'],['kind'=>'feed','mode'=>'latest','per_page'=>50,'cursor'=>$state['cursor']])['items'],'id');
        break;
    case 'block': $graph->change($state['alice'],'block','member',$state['bob']); break;
    case 'erase': $privacy->erase(get_userdata($state['bob'])->user_email); break;
    case 'expired':
        try { $discovery->retrieve($state['alice'],['kind'=>'feed','mode'=>'latest','per_page'=>50,'cursor'=>$state['cursor']]); throw new RuntimeException('Erased cursor remained usable.'); }
        catch (InvalidArgumentException $e) { $result['status']='expired'; }
        break;
    default: throw new RuntimeException('Unknown verification worker.');
}
echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
