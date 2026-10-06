<?php
use BuddyPressIntelligence\{Integration,UI};
final class NativeIntegrationTest extends CommunityTestCase {
    private array $originalHooks=[];
    private int $originalLoggedIn=0;
    protected function setUp(): void {
        parent::setUp();
        $this->originalLoggedIn=bp_loggedin_user_id();
        buddypress()->loggedin_user->id=$this->alice;
        foreach($GLOBALS['wp_filter'] as $name=>$hook) $this->originalHooks[$name]=clone $hook;
        // Replace request-scoped integration instances after resetting the database fixture.
        foreach ($GLOBALS['wp_filter'] as $name=>$hook) foreach ($hook->callbacks as $priority=>$callbacks) foreach ($callbacks as $callback) {
            if ($callback['function'] instanceof Closure && (new ReflectionFunction($callback['function']))->getClosureThis() instanceof Integration) remove_filter($name,$callback['function'],$priority);
        }
    }
    protected function tearDown(): void {
        $GLOBALS['wp_filter']=$this->originalHooks;
        buddypress()->loggedin_user->id=$this->originalLoggedIn;
        parent::tearDown();
    }
    public function testNestedNativeCommentsAndDirectRestHonorBilateralBlocks(): void {
        $comment=bp_activity_new_comment(['user_id'=>$this->carol,'activity_id'=>$this->publicActivity,'content'=>'Blocked nested comment']);
        self::assertGreaterThan(0,$comment);
        $this->graph->change($this->alice,'block','member',$this->carol);
        (new Integration($this->events,$this->policy,$this->settings,new UI($this->api,$this->settings,$this->db)))->register();
        $data=bp_activity_get_specific(['activity_ids'=>[$this->publicActivity],'display_comments'=>'threaded']);
        self::assertCount(1,$data['activities']);
        self::assertNotContains($comment,array_keys($data['activities'][0]->children??[]));
        $request=new WP_REST_Request('GET','/buddypress/v1/members/'.$this->carol);
        $result=apply_filters('rest_pre_dispatch',null,rest_get_server(),$request);
        self::assertInstanceOf(WP_Error::class,$result);
        self::assertSame(404,$result->get_error_data()['status']);
    }
    public function testRestrictionStopsNativeActivityBeforePersistence(): void {
        (new Integration($this->events,$this->policy,$this->settings,new UI($this->api,$this->settings,$this->db)))->register();
        update_user_meta($this->alice,'_bpi_restricted_until',time()+3600);
        $result=bp_activity_add(['user_id'=>$this->alice,'component'=>'activity','type'=>'activity_update','content'=>'Restricted native write','error_type'=>'wp_error']);
        self::assertInstanceOf(WP_Error::class,$result);
        self::assertSame('bpi_unavailable',$result->get_error_code());
    }
    public function testNativeFriendshipRestBlocksBothDirectionsAndRetainsDeletion(): void {
        if (BP_Friends_Friendship::get_friendship_id($this->alice,$this->carol)) friends_remove_friend($this->alice,$this->carol);
        (new Integration($this->events,$this->policy,$this->settings,new UI($this->api,$this->settings,$this->db)))->register();
        $this->graph->change($this->alice,'block','member',$this->carol);
        foreach ([$this->alice=>$this->carol,$this->carol=>$this->alice] as $actor=>$target) {
            wp_set_current_user($actor);
            buddypress()->loggedin_user->id=$actor;
            $r=new WP_REST_Request('POST','/buddypress/v1/friends');
            $r->set_body_params(['initiator_id'=>$actor,'friend_id'=>$target]);
            $response=rest_do_request($r);
            self::assertSame(403,$response->get_status());
            self::assertSame('bpi_unavailable',$response->get_data()['code']);
            self::assertFalse(friends_check_friendship($actor,$target));
        }
        wp_set_current_user($this->alice);
        buddypress()->loggedin_user->id=$this->alice;
        $button=bp_get_add_friend_button_args($this->carol);
        self::assertSame([],$button);
        // A pending request created by a cooperating external writer still cannot be accepted.
        self::assertTrue(friends_add_friend($this->carol,$this->alice));
        $read=new WP_REST_Request('GET','/buddypress/v1/friends');
        $read->set_query_params(['user_id'=>$this->alice,'is_confirmed'=>0]);
        $response=rest_do_request($read);
        $response=apply_filters('rest_post_dispatch',$response,rest_get_server(),$read);
        self::assertSame([],$response->get_data());
        $id=BP_Friends_Friendship::get_friendship_id($this->carol,$this->alice);
        $read=new WP_REST_Request('GET','/buddypress/v1/friends/'.$id);
        $response=apply_filters('rest_post_dispatch',rest_do_request($read),rest_get_server(),$read);
        self::assertSame(404,$response->get_status());
        $r=new WP_REST_Request('PUT','/buddypress/v1/friends/'.$this->carol);
        $response=rest_do_request($r);
        self::assertSame(403,$response->get_status());
        self::assertFalse(friends_check_friendship($this->alice,$this->carol));
        // Deleting the pending request remains an available native action.
        $r=new WP_REST_Request('DELETE','/buddypress/v1/friends/'.$this->carol);
        $response=rest_do_request($r);
        self::assertSame(200,$response->get_status());
    }
    public function testNativeFriendshipAllowsEligibleRequestsAndRejectsRestrictedParticipant(): void {
        if (BP_Friends_Friendship::get_friendship_id($this->alice,$this->carol)) friends_remove_friend($this->alice,$this->carol);
        (new Integration($this->events,$this->policy,$this->settings,new UI($this->api,$this->settings,$this->db)))->register();
        $r=new WP_REST_Request('POST','/buddypress/v1/friends');
        $r->set_body_params(['initiator_id'=>$this->alice,'friend_id'=>$this->carol]);
        $response=rest_do_request($r);
        self::assertSame(200,$response->get_status(),wp_json_encode($response->get_data()));
        if (BP_Friends_Friendship::get_friendship_id($this->alice,$this->carol)) friends_remove_friend($this->alice,$this->carol);
        update_user_meta($this->carol,'_bpi_restricted_until',time()+3600);
        self::assertSame(403,rest_do_request($r)->get_status());
        self::assertSame('not_friends',friends_check_friendship_status($this->alice,$this->carol));
        delete_user_meta($this->carol,'_bpi_restricted_until');
    }
    public function testBothNativeAjaxTemplateGuardsRejectBeforeWritingAndAllowCleanup(): void {
        if (BP_Friends_Friendship::get_friendship_id($this->alice,$this->carol)) friends_remove_friend($this->alice,$this->carol);
        (new Integration($this->events,$this->policy,$this->settings,new UI($this->api,$this->settings,$this->db)))->register();
        $this->graph->change($this->alice,'block','member',$this->carol);
        add_filter('wp_doing_ajax','__return_true');
        add_filter('wp_die_ajax_handler',static fn()=>static function(): void { throw new RuntimeException('native_preflight_denied'); });
        $original=$_POST;
        try {
            foreach (['friends_add_friend'=>['item_id'=>$this->carol],'addremove_friend'=>['fid'=>$this->carol]] as $action=>$input) {
                $_POST=$input;
                ob_start();
                try { do_action('wp_ajax_'.$action); self::fail('Native AJAX guard must stop execution.'); }
                catch (RuntimeException $e) { self::assertSame('native_preflight_denied',$e->getMessage()); }
                $json=json_decode(ob_get_clean(),true);
                self::assertFalse($json['success']);
                self::assertSame('not_friends',friends_check_friendship_status($this->alice,$this->carol));
            }
            self::assertTrue(friends_add_friend($this->carol,$this->alice));
            $id=BP_Friends_Friendship::get_friendship_id($this->carol,$this->alice);
            foreach (['friends_accept_friendship'=>['item_id'=>$id],'accept_friendship'=>['id'=>$id]] as $action=>$input) {
                $_POST=$input;
                ob_start();
                try { do_action('wp_ajax_'.$action); self::fail('Native acceptance guard must stop execution.'); }
                catch (RuntimeException $e) { self::assertSame('native_preflight_denied',$e->getMessage()); }
                self::assertFalse(json_decode(ob_get_clean(),true)['success']);
                self::assertFalse(friends_check_friendship($this->alice,$this->carol));
            }
            // Invoke our preflight directly so the real native cleanup handler retains control.
            $_POST=['fid'=>$this->carol];
            foreach ($GLOBALS['wp_filter']['wp_ajax_addremove_friend']->callbacks[0] as $callback) ($callback['function'])();
            self::assertTrue(friends_remove_friend($this->alice,$this->carol));
        } finally { $_POST=$original; }
    }
    public function testNativeFriendshipScreensStopBeforeTheUpstreamWriters(): void {
        if (BP_Friends_Friendship::get_friendship_id($this->alice,$this->carol)) friends_remove_friend($this->alice,$this->carol);
        (new Integration($this->events,$this->policy,$this->settings,new UI($this->api,$this->settings,$this->db)))->register();
        $this->graph->change($this->alice,'block','member',$this->carol);
        $bp=buddypress();
        $saved=[$bp->current_component,$bp->current_action,$bp->action_variables];
        add_filter('wp_die_handler',static fn()=>static function($message,$title,$args): void {
            if (($args['response']??0)!==403) throw new RuntimeException('Unexpected screen error.');
            throw new RuntimeException('screen_preflight_denied');
        });
        try {
            $bp->current_component='friends';
            $bp->current_action='add-friend';
            $bp->action_variables=[$this->carol];
            try { do_action('bp_actions'); self::fail('Blocked native screen must stop.'); }
            catch (RuntimeException $e) { self::assertSame('screen_preflight_denied',$e->getMessage()); }
            self::assertSame('not_friends',friends_check_friendship_status($this->alice,$this->carol));
            self::assertTrue(friends_add_friend($this->carol,$this->alice));
            $id=BP_Friends_Friendship::get_friendship_id($this->carol,$this->alice);
            $bp->current_action='requests';
            $bp->action_variables=['accept',$id];
            try { do_action('bp_screens'); self::fail('Blocked native acceptance must stop.'); }
            catch (RuntimeException $e) { self::assertSame('screen_preflight_denied',$e->getMessage()); }
            self::assertFalse(friends_check_friendship($this->alice,$this->carol));
            self::assertTrue(friends_remove_friend($this->alice,$this->carol));
        } finally { [$bp->current_component,$bp->current_action,$bp->action_variables]=$saved; }
    }
    public function testFriendshipRemovalEventsRequireConfirmedDeletion(): void {
        if (BP_Friends_Friendship::get_friendship_id($this->alice,$this->carol)) friends_remove_friend($this->alice,$this->carol);
        (new Integration($this->events,$this->policy,$this->settings,new UI($this->api,$this->settings,$this->db)))->register();
        do_action('friends_friendship_deleted',2147483647,$this->alice,$this->carol);
        self::assertSame([],$this->db->rows('events',['type'=>'friendship_removed'],50));
        self::assertTrue(friends_add_friend($this->alice,$this->carol));
        self::assertTrue(friends_remove_friend($this->alice,$this->carol));
        $events=$this->db->rows('events',['type'=>'friendship_removed'],50);
        self::assertCount(1,$events);
        self::assertSame($this->alice,(int)$events[0]['actor']);
        self::assertSame($this->carol,(int)$events[0]['object_id']);
    }
}
