<?php
use BuddyPressIntelligence\{Integration,UI};
final class NativeIntegrationTest extends CommunityTestCase {
    private array $originalHooks=[];
    protected function setUp(): void {
        parent::setUp();
        foreach($GLOBALS['wp_filter'] as $name=>$hook) $this->originalHooks[$name]=clone $hook;
    }
    protected function tearDown(): void {
        $GLOBALS['wp_filter']=$this->originalHooks;
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
}
