<?php
final class DiscoveryTest extends CommunityTestCase {
    public function testPersonalizedFeedIncludesFollowedContentOutsideLatestWindow(): void {
        $this->settings->save(['candidate_limit'=>30]);
        $old=bp_activity_add(['user_id'=>$this->bob,'component'=>'activity','type'=>'activity_update','content'=>'Older followed content','date_recorded'=>gmdate('Y-m-d H:i:s',time()-2*DAY_IN_SECONDS)]);
        for($i=0;$i<35;++$i) bp_activity_add(['user_id'=>$this->carol,'component'=>'activity','type'=>'activity_update','content'=>'Recent '.$i]);
        $this->graph->change($this->alice,'follow','member',$this->bob);
        $latest=$this->data($this->request('feed','GET',['mode'=>'latest']));
        $personal=$this->data($this->request('feed','GET',['mode'=>'for_you']));
        self::assertNotContains($old,array_column($latest['items'],'id'));
        self::assertContains($old,array_column($personal['items'],'id'));
        self::assertNotSame(array_column($latest['items'],'id'),array_column($personal['items'],'id'));
    }
    public function testUnansweredMeansNoVisibleAnswerAndPhraseSearchWorks(): void {
        $q=$this->data($this->request('questions','POST',['title'=>'Exact phrase search','body'=>'Details']))['id'];
        $data=$this->data($this->request('search','GET',['type'=>'question','q'=>'"Exact phrase"','unanswered'=>true]));
        self::assertContains($q,array_column($data['items'],'id'));
        $this->data($this->request('answers','POST',['parent_id'=>$q,'body'=>'Not yet accepted'],$this->bob));
        $data=$this->data($this->request('search','GET',['type'=>'question','q'=>'"Exact phrase"','unanswered'=>true]));
        self::assertNotContains($q,array_column($data['items'],'id'));
    }
    public function testAllRetrievalChannelsExcludePrivateAndBlockedContent(): void {
        foreach (['feed','recommendations','search'] as $path) {
            $data=$this->data($this->request($path,'GET',['type'=>'activity','q'=>'PHP']));
            self::assertNotContains($this->privateActivity,array_column($data['items'],'id'));
        }
        $this->graph->change($this->alice,'block','member',$this->bob);
        foreach (['feed','recommendations','search'] as $path) {
            $data=$this->data($this->request($path,'GET',['type'=>'activity','q'=>'PHP']));
            self::assertNotContains($this->publicActivity,array_column($data['items'],'id'));
        }
    }
    public function testCachedSnapshotRechecksPrivacyAndMemberIdentity(): void {
        $first=$this->data($this->request('feed','GET',['mode'=>'latest','per_page'=>1]));
        self::assertNotEmpty($first['cursor']);
        $this->denied($this->request('feed','GET',['mode'=>'latest','per_page'=>1,'cursor'=>$first['cursor']],$this->carol),400);
        $this->graph->change($this->alice,'block','member',$this->bob);
        $again=$this->data($this->request('feed','GET',['mode'=>'latest','per_page'=>1,'cursor'=>$first['cursor']]));
        self::assertNotContains($this->publicActivity,array_column($again['items'],'id'));
    }
    public function testCachedFeedHonorsNewNegativeFeedbackImmediately(): void {
        $first=$this->data($this->request('feed','GET',['mode'=>'latest','per_page'=>50]));
        $this->data($this->request('feedback','POST',['type'=>'activity','id'=>$this->publicActivity,'value'=>'not_interested']));
        $again=$this->data($this->request('feed','GET',['mode'=>'latest','per_page'=>50,'cursor'=>$first['cursor']]));
        self::assertNotContains($this->publicActivity,array_column($again['items'],'id'));
        $search=$this->data($this->request('search','GET',['type'=>'activity','q'=>'Public PHP alpha']));
        self::assertContains($this->publicActivity,array_column($search['items'],'id'));
    }
    public function testColdStartOnboardingFeedbackAndFollowing(): void {
        $cold=$this->data($this->request('recommendations','GET',['type'=>'topic']));
        self::assertContains($this->topic,array_column($cold['items'],'id'));
        $this->data($this->request('preferences','POST',['interests'=>[$this->topic],'skills'=>[$this->topic],'onboarded'=>true]));
        wp_set_current_user($this->bob);
        $this->topics->assign($this->bob,'activity',$this->publicActivity,[$this->topic]);
        $feed=$this->data($this->request('feed','GET',['mode'=>'following']));
        self::assertContains($this->publicActivity,array_column($feed['items'],'id'));
        $this->data($this->request('feedback','POST',['type'=>'activity','id'=>$this->publicActivity,'value'=>'not_interested']));
        $feed=$this->data($this->request('feed','GET',['mode'=>'for_you']));
        self::assertNotContains($this->publicActivity,array_column($feed['items'],'id'));
    }
    public function testPostStatusPasswordsSqlInjectionAndPagination(): void {
        $public=wp_insert_post(['post_title'=>'PHP visible','post_content'=>'safe','post_author'=>$this->bob,'post_status'=>'publish']);
        $private=wp_insert_post(['post_title'=>'PHP private','post_content'=>'secret','post_author'=>$this->bob,'post_status'=>'private']);
        $password=wp_insert_post(['post_title'=>'PHP password','post_password'=>'secret','post_author'=>$this->bob,'post_status'=>'publish']);
        $data=$this->data($this->request('search','GET',['type'=>'post','q'=>'PHP','per_page'=>1]));
        self::assertContains($public,array_column($data['items'],'id'));
        self::assertFalse($this->policy->view($this->alice,'post',$private));
        self::assertFalse($this->policy->view($this->alice,'post',$password));
        $safe=$this->data($this->request('search','GET',['q'=>"%' OR 1=1 --",'type'=>'topic']));
        self::assertSame([],$safe['items']);
        $page=$this->data($this->request('search','GET',['type'=>'post','q'=>'PHP','per_page'=>1,'page'=>2,'cursor'=>$data['cursor']]));
        self::assertNotContains($public,array_column($page['items'],'id'));
    }
    public function testDisabledProvidersAndComponentsGracefullyDegrade(): void {
        $this->settings->save(['modules'=>['feed'=>false,'ai'=>false]]);
        $this->denied($this->request('feed'),503);
        $post=wp_insert_post(['post_title'=>'Public','post_content'=>'No AI','post_status'=>'publish','post_author'=>$this->alice]);
        $result=$this->data($this->request('intelligence','POST',['type'=>'post','id'=>$post,'capability'=>'summarize']));
        self::assertFalse($result['available']);
        $components=buddypress()->active_components;
        unset(buddypress()->active_components['groups']);
        try { self::assertFalse($this->policy->view($this->alice,'group',$this->privateGroup)); }
        finally { buddypress()->active_components=$components; }
    }
    public function testErasureInvalidatesPersistentCacheCursors(): void {
        $original=wp_using_ext_object_cache();
        wp_using_ext_object_cache(true);
        try {
            $first=$this->data($this->request('feed','GET',['mode'=>'latest','per_page'=>5]));
            $privacy=new BuddyPressIntelligence\Privacy($this->db);
            self::assertTrue($privacy->erase(get_userdata($this->carol)->user_email)['done']);
            $this->denied($this->request('feed','GET',['mode'=>'latest','per_page'=>5,'cursor'=>$first['cursor']]),400);
            self::assertNotEmpty($this->data($this->request('feed','GET',['mode'=>'latest','per_page'=>5]))['cursor']);
        } finally { wp_using_ext_object_cache($original); }
    }
    public function testExpertTopicFilterIncludesDeclaredSkillWithoutRequiringInterestMapping(): void {
        $this->topics->preferences($this->bob,['skills'=>[$this->topic]]);
        $data=$this->data($this->request('recommendations','GET',['type'=>'expert','topic_id'=>$this->topic]));
        self::assertContains($this->bob,array_column($data['items'],'id'));
        $this->graph->change($this->alice,'block','member',$this->bob);
        $data=$this->data($this->request('recommendations','GET',['type'=>'expert','topic_id'=>$this->topic]));
        self::assertNotContains($this->bob,array_column($data['items'],'id'));
    }
}
