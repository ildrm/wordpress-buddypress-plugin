<?php
// Controlled synthetic benchmark. Never runs against a production database.
$_SERVER['HTTP_HOST']='127.0.0.1:8917';
$_SERVER['REQUEST_URI']='/';
require dirname(__DIR__).'/.runtime/wordpress/wp-load.php';
if (DB_NAME!=='bpi_tests' || DB_HOST!=='127.0.0.1:33307') throw new RuntimeException('Isolated test database required.');
global $wpdb;
$db=new BuddyPressIntelligence\Database();
$settings=new BuddyPressIntelligence\Settings();
$native=new BuddyPressIntelligence\NativeObjects($db,$settings);
$policy=new BuddyPressIntelligence\Policy($db,$native);
$discovery=new BuddyPressIntelligence\Discovery($db,$native,$policy,$settings);
$alice=(int)get_user_by('login','bpi_alice')->ID;
$prefix='bpi_bench_';
$pattern=$wpdb->esc_like($prefix).'%';
$plans_only=in_array('--plans-only',$argv,true);
$report_path=dirname(__DIR__).'/.runtime/benchmark.json';
if($plans_only && !is_file($report_path)) throw new RuntimeException('Measure the feed before refreshing plans.');
if(!$plans_only) {
$db->query('DELETE e FROM '.$db->table('events')." e INNER JOIN {$wpdb->users} u ON u.ID=e.actor WHERE u.user_login LIKE %s",[$pattern]);
$db->query('DELETE a FROM '.buddypress()->activity->table_name." a INNER JOIN {$wpdb->users} u ON u.ID=a.user_id WHERE u.user_login LIKE %s",[$pattern]);
$db->query("DELETE m FROM {$wpdb->usermeta} m INNER JOIN {$wpdb->users} u ON u.ID=m.user_id WHERE u.user_login LIKE %s",[$pattern]);
$db->query("DELETE FROM {$wpdb->users} WHERE user_login LIKE %s",[$pattern]);
}
$done=0;
$results=$plans_only ? json_decode(file_get_contents($report_path),true)['results'] : [];
foreach($plans_only ? [] : [1000,10000,100000] as $population) {
    for($start=$done+1;$start<=$population;$start+=500) {
        $parts=[]; $args=[];
        for($i=$start;$i<=min($population,$start+499);++$i) {
            $parts[]='(%s,%s,%s,%s,%s,%s,%s,%d,%s)';
            array_push($args,$prefix.$i,'!',$prefix.$i,$prefix.$i.'@example.invalid','',gmdate('Y-m-d H:i:s'),'',0,'Benchmark '.$i);
        }
        $sql="INSERT IGNORE INTO {$wpdb->users} (user_login,user_pass,user_nicename,user_email,user_url,user_registered,user_activation_key,user_status,display_name) VALUES ".implode(',',$parts);
        $db->query($sql,$args);
    }
    $done=$population;
    $actual=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE %s",$pattern));
    if($actual!==$population) throw new RuntimeException('Synthetic population verification failed.');
    $ids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE user_login LIKE %s ORDER BY ID DESC LIMIT 150",$wpdb->esc_like($prefix).'%')));
    foreach($ids as $id) {
        bp_activity_add(['user_id'=>$id,'component'=>'activity','type'=>'activity_update','content'=>'Benchmark public content','date_recorded'=>gmdate('Y-m-d H:i:s')]);
    }
    wp_set_current_user($alice);
    $samples=[];
    foreach(range(1,3) as $sample) {
        wp_cache_flush(); $policy->clear();
        $queries=$wpdb->num_queries; $start=microtime(true);
        $data=$discovery->retrieve($alice,['kind'=>'feed','mode'=>'for_you','per_page'=>20]);
        $samples[]=['ms'=>round((microtime(true)-$start)*1000,2),'queries'=>$wpdb->num_queries-$queries,'items'=>count($data['items'])];
    }
    $results[]=['synthetic_members'=>$population,'samples'=>$samples];
    echo wp_json_encode(end($results))."\n";
}
$plans=[];
foreach([
    'privacy_edge'=>['SELECT * FROM '.$db->table('edges').' WHERE actor=%d AND kind=%s AND object_type=%s AND object_id=%d',[$alice,'block','member',1]],
    'event_window'=>['SELECT actor FROM '.$db->table('events').' WHERE type=%s AND created_at>%s',['activity_commented',gmdate('Y-m-d H:i:s',time()-86400)]],
    'job_claim'=>['SELECT id FROM '.$db->table('jobs').' WHERE status=%s AND available_at<=%s ORDER BY available_at,id LIMIT 25',['pending',gmdate('Y-m-d H:i:s')]],
] as $name=>[$sql,$args]) $plans[$name]=$db->select('EXPLAIN '.$sql,$args);
file_put_contents($report_path,wp_json_encode(['results'=>$results,'plans'=>$plans],JSON_PRETTY_PRINT));
if($plans_only) echo wp_json_encode($plans)."\n";
echo "Saved .runtime/benchmark.json\n";
