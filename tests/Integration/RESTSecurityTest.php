<?php
use BuddyPressIntelligence\{Intelligence, IntelligenceProvider, Privacy, UI};
final class RESTSecurityTest extends CommunityTestCase {
    public function testEveryRouteRejectsAnonymousAndEveryAdminRouteRejectsOrdinaryMembers(): void {
        $routes = ['graph','preferences','topics','topics/1','object-topics','feed','recommendations','search','autocomplete','feedback','questions','answers','knowledge','entries/1','accept','accept/reverse','votes','reputation/1','reports','cases','cases/1','appeals','appeals/1','rules','rules/1','runs','analytics','experiments','experiments/1','experiments/1/assignment','intelligence','events','settings','diagnostics','jobs/1/retry'];
        foreach($routes as $path) $this->denied($this->request($path,'GET',[],0),401);
        foreach(['settings','diagnostics','jobs/1/retry','cases','cases/1','rules','rules/1','runs','analytics','experiments'] as $path) $this->denied($this->request($path),403);
        $this->denied($this->request('preferences','DELETE',['visibility'=>'only_me']),405);
    }
    public function testCustomCapabilitiesAndObjectOwnersAreSeparate(): void {
        $moderator = get_userdata($this->carol);
        $moderator->add_cap('bpi_view_cases');
        try {
            $this->data($this->request('cases','GET',[],$this->carol));
            $this->denied($this->request('cases/1','POST',['action'=>'hide'],$this->carol),403);
            $this->denied($this->request('settings','GET',[],$this->carol),403);
        } finally { $moderator->remove_cap('bpi_view_cases'); }
        $this->denied($this->request('object-topics','POST',['type'=>'activity','id'=>$this->publicActivity,'topics'=>[$this->topic]]));
    }
    public function testProviderUrlSsrfPrivateDataAndFailureFallback(): void {
        wp_set_current_user(1);
        foreach(['http://example.com','https://127.0.0.1/test','https://169.254.169.254/latest/meta-data','file:///tmp/data'] as $url) {
            $this->denied($this->request('settings','POST',['provider_url'=>$url],1),400);
        }
        $this->settings->save(['modules'=>['ai'=>true],'external_consent'=>true]);
        $this->topics->preferences($this->alice,['external_ai'=>true]);
        $this->denied($this->request('intelligence','POST',['type'=>'activity','id'=>$this->privateActivity,'capability'=>'summarize']));
        $this->denied($this->request('intelligence','POST',['type'=>'activity','id'=>$this->publicActivity,'capability'=>'summarize']));
        $post=wp_insert_post(['post_title'=>'Public text','post_content'=>'Email test@example.invalid','post_author'=>$this->alice,'post_status'=>'publish']);
        $provider = static function() { return new class implements IntelligenceProvider { public function process(string $capability,string $publicText): array { throw new RuntimeException('secret token'); } }; };
        add_filter('bpi_intelligence_provider_v1',$provider);
        try {
            $data=$this->data($this->request('intelligence','POST',['type'=>'post','id'=>$post,'capability'=>'summarize']));
            self::assertFalse($data['available']);
            self::assertStringNotContainsString('secret',wp_json_encode($data));
        } finally { remove_filter('bpi_intelligence_provider_v1',$provider); }
    }
    public function testActualRestRegistrationNonceAndCookieAuthenticationBoundary(): void {
        add_action('rest_api_init',[$this->api,'register_routes']);
        if (did_action('rest_api_init')) do_action('rest_api_init',rest_get_server());
        $routes=rest_get_server()->get_routes();
        foreach($routes as $name=>$endpoints) if(str_starts_with($name,'/buddypress-intelligence/v1/')) foreach($endpoints as $endpoint) {
            if(isset($endpoint['methods'])) self::assertTrue(is_callable($endpoint['permission_callback']));
        }
        wp_set_current_user(0);
        $request=new WP_REST_Request('GET','/buddypress-intelligence/v1/graph');
        $response=rest_do_request($request);
        self::assertSame(401,$response->get_status());
        wp_set_current_user($this->alice);
        $response=rest_do_request(new WP_REST_Request('GET','/buddypress-intelligence/v1/preferences'));
        self::assertSame(200,$response->get_status());
        self::assertSame('private, no-store',$response->get_headers()['Cache-Control']);
    }
    public function testProviderConsentMinimizationAndCircuitBreaker(): void {
        wp_set_current_user(1);
        $this->settings->save(['modules'=>['ai'=>true],'external_consent'=>true]);
        $post=wp_insert_post(['post_title'=>'Editorial','post_content'=>'<b>Hello</b> person@example.invalid '.str_repeat('x',4500),'post_status'=>'publish','post_author'=>$this->alice]);
        $provider=new class implements IntelligenceProvider {
            public int $calls=0;
            public string $text='';
            public bool $fail=false;
            public function process(string $capability,string $publicText): array {
                ++$this->calls; $this->text=$publicText;
                if($this->fail) throw new RuntimeException('provider failure');
                return ['available'=>true,'data'=>['label'=>'<script>unsafe</script>','nested'=>['secret']]];
            }
        };
        $filter=static fn()=>$provider;
        add_filter('bpi_intelligence_provider_v1',$filter);
        try {
            $input=['type'=>'post','id'=>$post,'capability'=>'classify'];
            self::assertFalse($this->data($this->request('intelligence','POST',$input))['available']);
            self::assertSame(0,$provider->calls);
            $this->topics->preferences($this->alice,['external_ai'=>true]);
            $result=$this->data($this->request('intelligence','POST',$input));
            self::assertTrue($result['available']);
            self::assertLessThanOrEqual(4000,mb_strlen($provider->text));
            self::assertStringNotContainsString('person@example.invalid',$provider->text);
            self::assertStringNotContainsString('<b>',$provider->text);
            self::assertArrayNotHasKey('nested',$result['data']);
            self::assertStringNotContainsString('<script>',wp_json_encode($result));
            $provider->fail=true;
            self::assertFalse($this->data($this->request('intelligence','POST',$input))['available']);
            self::assertSame('provider_backoff',$this->data($this->request('intelligence','POST',$input))['reason']);
            self::assertSame(2,$provider->calls);
        } finally { remove_filter('bpi_intelligence_provider_v1',$filter); }
    }
    public function testPrivacyExportPaginationAndErasureHandlesEveryBatch(): void {
        for($i=0;$i<61;++$i) $this->events->record('member_profile_updated',$this->alice,'member',$this->alice,[],'profile:'.$i);
        $privacy=new Privacy($this->db);
        $email=get_userdata($this->alice)->user_email;
        self::assertFalse($privacy->export($email,1)['done']);
        self::assertTrue($privacy->export($email,2)['done']);
        $first=$privacy->erase($email,1);
        self::assertFalse($first['done']);
        self::assertTrue($privacy->erase($email,2)['done']);
        self::assertSame([],$this->db->rows('events',['actor'=>$this->alice]));
    }
    public function testMalformedFieldsAndNegativeIdentifiersDoNotBecomeValidIds(): void {
        foreach([-1,'-1','1 OR 1=1',[],true] as $id) $this->denied($this->request('graph','POST',['kind'=>'follow','type'=>'member','id'=>$id]),400);
        $this->denied($this->request('preferences','POST',['visibility'=>'administrators','actor'=>$this->bob]),400);
        $this->denied($this->request('rules','POST',['name'=>'Unsafe','trigger'=>'member_registered','action'=>'php','config'=>['code'=>'exec'] ],1),400);
        self::assertSame(['analytics'=>false], UI::payload([],['analytics'=>'0'],['analytics'=>'checkbox']));
        self::assertSame(['interests'=>[1,2]], UI::payload([],['interests'=>['1','2']],['interests'=>'topic_ids']));
    }
}
