<?php
$connection=new mysqli('127.0.0.1','root','bpi_test_local','bpi_tests',33307);
$original=$connection->query("SELECT option_value FROM bpit_options WHERE option_name='bp-active-components'")->fetch_assoc()['option_value'];
$components=unserialize($original,['allowed_classes'=>false]);$components['messages']=1;
$statement=$connection->prepare("UPDATE bpit_options SET option_value=? WHERE option_name='bp-active-components'");
$modified=serialize($components);$statement->bind_param('s',$modified);$statement->execute();
try {
    $_SERVER['HTTP_HOST']='127.0.0.1:8917';$_SERVER['REQUEST_URI']='/';
    require dirname(__DIR__).'/.runtime/wordpress/wp-load.php';
    if(!bp_is_active('messages')) throw new RuntimeException('Messages component must load.');
    require_once ABSPATH.'wp-admin/includes/upgrade.php';
    require_once buddypress()->plugin_dir.'bp-core/admin/bp-core-admin-schema.php';
    bp_core_install(['messages'=>1]);
    $alice=(int)get_user_by('login','bpi_alice')->ID;$bob=(int)get_user_by('login','bpi_bob')->ID;
    delete_user_meta($alice,'_bpi_restricted_until');delete_user_meta($bob,'_bpi_restricted_until');
    delete_user_meta($alice,'_bpi_preferences');delete_user_meta($bob,'_bpi_preferences');
    $db=new BuddyPressIntelligence\Database();$settings=new BuddyPressIntelligence\Settings();
    $policy=new BuddyPressIntelligence\Policy($db,new BuddyPressIntelligence\NativeObjects($db,$settings));
    $events=new BuddyPressIntelligence\Events($db,new BuddyPressIntelligence\Jobs($db,$settings),$settings);
    $graph=new BuddyPressIntelligence\Graph($db,$policy,$events);
    wp_set_current_user($alice);$graph->change($alice,'block','member',$bob);
    $before=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.buddypress()->messages->table_name_messages);
    $result=messages_new_message(['sender_id'=>$alice,'recipients'=>[$bob],'subject'=>'Isolated test','content'=>'Must not persist','error_type'=>'wp_error']);
    if(!is_wp_error($result)) throw new RuntimeException('Blocked message was accepted.');
    $after=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.buddypress()->messages->table_name_messages);
    if($before!==$after) throw new RuntimeException('Blocked message reached persistence.');
    $graph->change($alice,'block','member',$bob,true);
    echo "PASS: BuddyPress native messaging rejects a blocked recipient before persistence.\n";
} finally { $statement->bind_param('s',$original);$statement->execute(); }
