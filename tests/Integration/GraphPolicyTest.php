<?php
final class GraphPolicyTest extends CommunityTestCase {
    public function testFollowUniquenessRemoveMuteBlockAndUnblock(): void {
        $args=['kind'=>'follow','type'=>'member','id'=>$this->bob];
        $this->data($this->request('graph','POST',$args));
        $this->data($this->request('graph','POST',$args));
        self::assertCount(1,$this->db->rows('edges',['actor'=>$this->alice]));
        self::assertTrue($this->policy->relation($this->alice,'follow','member',$this->bob));
        $this->data($this->request('graph','POST',['kind'=>'mute','type'=>'member','id'=>$this->bob]));
        self::assertFalse($this->policy->view($this->alice,'activity',$this->publicActivity,true));
        self::assertTrue($this->policy->view($this->alice,'activity',$this->publicActivity));
        $block=['kind'=>'block','type'=>'member','id'=>$this->bob];
        $this->data($this->request('graph','POST',$block));
        self::assertFalse($this->policy->view($this->alice,'member',$this->bob));
        self::assertFalse($this->policy->view($this->bob,'member',$this->alice));
        $this->data($this->request('graph','DELETE',$block));
        self::assertTrue($this->policy->view($this->alice,'member',$this->bob));
        $this->data($this->request('graph','DELETE',$args));
        self::assertFalse($this->policy->relation($this->alice,'follow','member',$this->bob));
    }
    public function testNativeGroupPolicyAndVisibilityScopes(): void {
        self::assertFalse($this->policy->view($this->alice,'activity',$this->privateActivity));
        self::assertFalse($this->policy->view($this->alice,'group',$this->hiddenGroup));
        self::assertTrue($this->policy->view($this->alice,'group',$this->privateGroup));
        self::assertTrue($this->policy->view($this->bob,'activity',$this->privateActivity));
        $this->topics->preferences($this->bob,['visibility'=>'only_me']);
        self::assertFalse($this->policy->view($this->alice,'member',$this->bob));
        self::assertTrue($this->policy->view($this->bob,'member',$this->bob));
        $this->topics->preferences($this->bob,['visibility'=>'followers']);
        self::assertFalse($this->policy->view($this->alice,'member',$this->bob));
        // Native root owner can create a follow before the target changes scope.
        $this->topics->preferences($this->bob,['visibility'=>'everyone']);
        $this->graph->change($this->alice,'follow','member',$this->bob);
        $this->topics->preferences($this->bob,['visibility'=>'followers']);
        self::assertTrue($this->policy->view($this->alice,'member',$this->bob));
    }
    public function testAnonymousMassAssignmentAndSpoofedActor(): void {
        $this->denied($this->request('graph','POST',['kind'=>'follow','type'=>'member','id'=>$this->bob],0),401);
        $this->denied($this->request('graph','POST',['kind'=>'follow','type'=>'member','id'=>$this->bob,'actor'=>$this->carol]),400);
        $this->denied($this->request('settings','POST',['accepted_points'=>100]),403);
        $this->denied($this->request('graph','POST',['kind'=>'block','type'=>'group','id'=>$this->privateGroup]),400);
    }
    public function testSnoozeExpirationAndTopicCycle(): void {
        $this->graph->change($this->alice,'snooze','member',$this->bob,false,1);
        self::assertFalse($this->policy->view($this->alice,'activity',$this->publicActivity,true));
        $this->db->update('edges',['expires'=>gmdate('Y-m-d H:i:s',time()-10)],['actor'=>$this->alice,'kind'=>'snooze']);
        $this->policy->clear(); // Model the next request after an external persistence mutation.
        self::assertTrue($this->policy->view($this->alice,'activity',$this->publicActivity,true));
        wp_set_current_user(1);
        $child=$this->topics->save(['name'=>'Child','parent_id'=>$this->topic]);
        $this->expectException(InvalidArgumentException::class);
        $this->topics->save(['name'=>'PHP','parent_id'=>$child],$this->topic);
    }
}
