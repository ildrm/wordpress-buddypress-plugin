<?php
$component=$argv[1]??'';
if(!in_array($component,['activity','groups','friends','notifications'],true)) throw new RuntimeException('Choose a supported optional component.');
$connection=new mysqli('127.0.0.1','root','bpi_test_local','bpi_tests',33307);
$result=$connection->query("SELECT option_value FROM bpit_options WHERE option_name='bp-active-components'");
$original=$result->fetch_assoc()['option_value'];
$components=unserialize($original,['allowed_classes'=>false]);
unset($components[$component]);
$statement=$connection->prepare("UPDATE bpit_options SET option_value=? WHERE option_name='bp-active-components'");
$modified=serialize($components);$statement->bind_param('s',$modified);$statement->execute();
try {
    $_SERVER['HTTP_HOST']='127.0.0.1:8917';$_SERVER['REQUEST_URI']='/';
    require dirname(__DIR__).'/.runtime/wordpress/wp-load.php';
    if(bp_is_active($component)) throw new RuntimeException('Component remains active.');
    $db=new BuddyPressIntelligence\Database();$settings=new BuddyPressIntelligence\Settings();
    $native=new BuddyPressIntelligence\NativeObjects($db,$settings);$policy=new BuddyPressIntelligence\Policy($db,$native);
    $discovery=new BuddyPressIntelligence\Discovery($db,$native,$policy,$settings);
    $actor=(int)get_user_by('login','bpi_alice')->ID;
    wp_set_current_user($actor);
    $topics=$discovery->retrieve($actor,['kind'=>'recommendations','type'=>'topic']);
    if(!isset($topics['items'])) throw new RuntimeException('Unrelated topic discovery failed.');
    if($component==='activity' && $discovery->retrieve($actor,['kind'=>'feed'])['state']!=='unavailable') throw new RuntimeException('Missing activity must show unavailable state.');
    if($component==='groups' && $discovery->retrieve($actor,['kind'=>'recommendations','type'=>'group'])['state']!=='unavailable') throw new RuntimeException('Missing groups must show unavailable state.');
    if($component==='friends' && $native->friend($actor,1)) throw new RuntimeException('Disabled friendship must contribute no score.');
    echo "PASS: $component disabled in a fresh BuddyPress bootstrap; topic discovery remains available.\n";
} finally {
    $statement->bind_param('s',$original);$statement->execute();
}
