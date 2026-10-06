<?php
use BuddyPressIntelligence\{Database, Settings, NativeObjects, Policy, Jobs, Events, Graph, Topics, Discovery, Knowledge, Moderation, Automation, Analytics, Intelligence, REST, Core};
use PHPUnit\Framework\TestCase;

abstract class CommunityTestCase extends TestCase {
    protected Database $db;
    protected Settings $settings;
    protected Policy $policy;
    protected NativeObjects $native;
    protected Events $events;
    protected Jobs $jobs;
    protected Graph $graph;
    protected Topics $topics;
    protected Discovery $discovery;
    protected Knowledge $knowledge;
    protected Moderation $moderation;
    protected Automation $automation;
    protected Analytics $analytics;
    protected REST $api;
    protected int $alice;
    protected int $bob;
    protected int $carol;
    protected int $topic;
    protected int $privateGroup;
    protected int $hiddenGroup;
    protected int $privateActivity;
    protected int $publicActivity;
    protected function setUp(): void {
        if (!function_exists('buddypress')) $this->markTestSkipped('Real WordPress/BuddyPress test runtime is required.');
        if (DB_NAME !== 'bpi_tests' || !str_starts_with($GLOBALS['wpdb']->prefix, 'bpit_')) throw new RuntimeException('Refusing to clear data outside the isolated bpi_tests / bpit_ test database.');
        $this->db = new Database();
        foreach (array_keys(Database::COLUMNS) as $table) $this->db->query('DELETE FROM ' . $this->db->table($table));
        $defaults = Settings::defaults();
        $defaults['modules']['ai'] = false;
        Core::option('bpi_settings', $defaults, true);
        Core::option('bpi_provider_failure', '', true);
        $this->settings = new Settings();
        $this->native = new NativeObjects($this->db, $this->settings);
        $this->policy = new Policy($this->db, $this->native);
        $this->jobs = new Jobs($this->db, $this->settings);
        $this->events = new Events($this->db, $this->jobs, $this->settings);
        $this->graph = new Graph($this->db, $this->policy, $this->events);
        $this->topics = new Topics($this->db, $this->policy, $this->events);
        $this->discovery = new Discovery($this->db, $this->native, $this->policy, $this->settings);
        $this->knowledge = new Knowledge($this->db, $this->policy, $this->events, $this->settings, $this->topics);
        $this->moderation = new Moderation($this->db, $this->policy, $this->native, $this->events, $this->settings);
        $this->automation = new Automation($this->db, $this->settings);
        $this->analytics = new Analytics($this->db, $this->settings);
        $ai = new Intelligence($this->policy, $this->settings);
        $this->api = new REST($this->db, $this->settings, $this->graph, $this->topics, $this->discovery, $this->knowledge, $this->moderation, $this->automation, $this->analytics, $ai, $this->events, $this->policy);
        $this->jobs->handlers(['events'=>[$this->automation,'process'],'rollup'=>[$this->analytics,'rollup']]);
        $this->alice = $this->user('bpi_alice');
        $this->bob = $this->user('bpi_bob');
        $this->carol = $this->user('bpi_carol');
        wp_set_current_user(1);
        $this->topic = $this->topics->save(['name'=>'PHP', 'aliases'=>['Hypertext Preprocessor']]);
        $this->privateGroup = groups_create_group(['creator_id'=>$this->bob,'name'=>'Private '.wp_generate_uuid4(),'slug'=>'private-'.wp_generate_uuid4(),'status'=>'private']);
        $this->hiddenGroup = groups_create_group(['creator_id'=>$this->bob,'name'=>'Hidden '.wp_generate_uuid4(),'slug'=>'hidden-'.wp_generate_uuid4(),'status'=>'hidden']);
        $this->privateActivity = bp_activity_add(['user_id'=>$this->bob,'component'=>'groups','type'=>'activity_update','item_id'=>$this->privateGroup,'content'=>'Confidential PHP alpha','hide_sitewide'=>true]);
        $this->publicActivity = bp_activity_add(['user_id'=>$this->bob,'component'=>'activity','type'=>'activity_update','content'=>'Public PHP alpha']);
        $this->policy->clear();
        wp_set_current_user($this->alice);
    }
    protected function user(string $login): int {
        $user = get_user_by('login', $login);
        $id = $user ? $user->ID : wp_insert_user(['user_login'=>$login,'user_pass'=>'bpi-test-password','user_email'=>$login.'@example.invalid','display_name'=>$login,'user_registered'=>gmdate('Y-m-d H:i:s', time()-30*DAY_IN_SECONDS),'role'=>'subscriber']);
        foreach (['_bpi_preferences','_bpi_hidden','_bpi_restricted_until','_bpi_states'] as $key) delete_user_meta($id,$key);
        bp_update_user_last_activity($id);
        return (int)$id;
    }
    protected function request(string $path, string $method='GET', array $input=[], ?int $actor=null) {
        wp_set_current_user($actor ?? $this->alice);
        $this->policy->clear();
        $r = new WP_REST_Request($method, '/buddypress-intelligence/v1/'.$path);
        if ($method==='GET') $r->set_query_params($input);
        else { $r->set_header('content-type','application/json'); $r->set_body(wp_json_encode($input)); }
        return $this->api->handle($r);
    }
    protected function data($response): array {
        self::assertInstanceOf(WP_REST_Response::class,$response, is_wp_error($response) ? $response->get_error_message() : '');
        return $response->get_data();
    }
    protected function denied($response, int $status=404): void {
        self::assertInstanceOf(WP_Error::class, $response);
        self::assertSame($status,$response->get_error_data()['status']);
    }
}
