<?php
// Builds a second source/runtime under a separate bpit_68_ prefix.
$repo = dirname(__DIR__);
$destination = $repo . '/.runtime/compat-6.8';
if (!is_dir($destination)) mkdir($destination,0777,true);
$curl=curl_init('https://wordpress.org/wordpress-6.8.zip');
curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>90,CURLOPT_FAILONERROR=>true]);
$data=curl_exec($curl);
if($data===false || strlen($data)<1000) throw new RuntimeException('Official WordPress 6.8 download failed: '.curl_error($curl));
file_put_contents($destination.'/wordpress.zip',$data);
$zip=new ZipArchive();
if($zip->open($destination.'/wordpress.zip')!==true) throw new RuntimeException('Invalid archive.');
$zip->extractTo($destination);$zip->close();
$target=$destination.'/wordpress';
function compatibilityCopy(string $source,string $target): void {
    if(!is_dir($target)) mkdir($target,0777,true);
    foreach(new DirectoryIterator($source) as $file) {
        if($file->isDot()) continue;
        $path=$target.'/'.$file->getFilename();
        if($file->isDir()) compatibilityCopy($file->getPathname(),$path);else copy($file->getPathname(),$path);
    }
}
compatibilityCopy($repo.'/.runtime/buddypress',$target.'/wp-content/plugins/buddypress');
$config=file_get_contents($repo.'/.runtime/wordpress/wp-config.php');
$config=str_replace("'bpit_'","'bpit_68_'",$config);
file_put_contents($target.'/wp-config.php',$config);
if(!is_dir($target.'/wp-content/mu-plugins')) mkdir($target.'/wp-content/mu-plugins',0777,true);
file_put_contents($target.'/wp-content/mu-plugins/bpi-test.php',"<?php\nrequire_once ".var_export($repo.'/buddypress-intelligence.php',true).";\nadd_filter('pre_wp_mail','__return_true');\n");
define('WP_INSTALLING',true);
require $target.'/wp-load.php';
require_once ABSPATH.'wp-admin/includes/upgrade.php';
if(!is_blog_installed()) wp_install('BPI compatibility tests','bpi_admin','compat@example.invalid',true,'','bpi-test-password');
update_option('active_plugins',['buddypress/bp-loader.php']);
update_option('bp-active-components',array_fill_keys(['activity','groups','friends','xprofile','notifications','settings','members'],1));
echo "WordPress 6.8 test runtime: $target\n";
