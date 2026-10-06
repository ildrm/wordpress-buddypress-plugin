<?php
// Disposable release-verification services, never loaded by the production plugin.
use BuddyPressIntelligence\{Core,Database,Settings,NativeObjects,Policy,Jobs,Events,Topics,Knowledge,Automation,Discovery,Graph,Privacy};
$runtime=getenv('BPI_WP_PATH');
if (PHP_OS_FAMILY!=='Linux' || !$runtime || !str_starts_with($runtime,'/workspace/.runtime/container-qa/')) throw new RuntimeException('Isolated container runtime required.');
$_SERVER['HTTP_HOST']='127.0.0.1:8917';
$_SERVER['REQUEST_URI']='/';
require $runtime.'/wp-load.php';
if (DB_NAME!=='bpi_tests' || !preg_match('/^bpit_c\d+_\d+_$/',$GLOBALS['wpdb']->prefix)) throw new RuntimeException('Refusing to mutate a non-test database.');
add_filter('bp_email_use_wp_mail','__return_true');
add_filter('pre_wp_mail','__return_true');
$db=new Database();
$settings=new Settings();
$native=new NativeObjects($db,$settings);
$policy=new Policy($db,$native);
$jobs=new Jobs($db,$settings);
$events=new Events($db,$jobs,$settings);
$topics=new Topics($db,$policy,$events);
$knowledge=new Knowledge($db,$policy,$events,$settings,$topics);
$automation=new Automation($db,$settings);
$discovery=new Discovery($db,$native,$policy,$settings);
$graph=new Graph($db,$policy,$events);
$privacy=new Privacy($db);
function qaActor(int $id): void { wp_set_current_user($id); buddypress()->loggedin_user->id=$id; }
