<?php
$repo=dirname(__DIR__);
$content=$repo.'/.runtime/release-test/wp-content';
function releaseCopy(string $source,string $target): void {
    if(!is_dir($target)) mkdir($target,0777,true);
    foreach(new DirectoryIterator($source) as $file) {
        if($file->isDot()) continue;
        if($file->isLink()) throw new RuntimeException('No junctions in release source.');
        $path=$target.'/'.$file->getFilename();
        if($file->isDir()) releaseCopy($file->getPathname(),$path); else copy($file->getPathname(),$path);
    }
}
releaseCopy($repo.'/.runtime/buddypress',$content.'/plugins/buddypress');
$source=getenv('BPI_WP_PATH') ?: $repo.'/.runtime/wordpress';
releaseCopy($source.'/wp-content/themes',$content.'/themes');
$zip=new ZipArchive();
if($zip->open($repo.'/dist/buddypress-intelligence-1.0.0.zip')!==true) throw new RuntimeException('Build release archive first.');
$zip->extractTo($content.'/plugins');$zip->close();
define('WP_INSTALLING',true);
require __DIR__.'/release-bootstrap.php';
require_once ABSPATH.'wp-admin/includes/upgrade.php';
if(!is_blog_installed()) wp_install('BPI release verification','bpi_admin','release@example.invalid',true,'','bpi-test-password');
else wp_upgrade();
update_option('active_plugins',['buddypress/bp-loader.php']);
update_option('bp-active-components',array_fill_keys(['activity','groups','friends','xprofile','notifications','settings','members'],1));
echo "Isolated release prefix and extracted package ready.\n";
