<?php
if (!in_array('--install', $argv, true)) throw new RuntimeException('Explicit --install required for the isolated test network.');
require __DIR__ . '/network-bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
if (!is_blog_installed()) wp_install('BPI test network', 'network_admin', 'network@example.invalid', true, '', 'network-test-password');
foreach ($wpdb->tables('ms_global') as $name => $prefixed) $wpdb->$name = $prefixed;
install_network();
if (!$wpdb->get_var("SELECT id FROM {$wpdb->site} LIMIT 1")) {
    $result = populate_network(1, '127.0.0.1', 'network@example.invalid', 'BPI test network', '/', false);
    if (is_wp_error($result) && !in_array($result->get_error_code(), ['no_wildcard_dns'], true)) throw new RuntimeException($result->get_error_message());
}
update_option('active_plugins', ['buddypress/bp-loader.php']);
update_option('bp-active-components', array_fill_keys(['activity','groups','friends','xprofile','notifications','settings','members'],1));
echo "Isolated network installed under bpin_ prefix.\n";
