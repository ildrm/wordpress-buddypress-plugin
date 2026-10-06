<?php
final class KnowledgeModerationTest extends CommunityTestCase {
    public function testReversalIsAuditableIdempotentAndReacceptanceAwardsOnce(): void {
        $q=$this->data($this->request('questions','POST',['title'=>'Reversal','body'=>'Details','topics'=>[$this->topic]]))['id'];
        $a=$this->data($this->request('answers','POST',['parent_id'=>$q,'body'=>'Evidence'],$this->bob))['id'];
        $this->data($this->request('accept','POST',['question_id'=>$q,'answer_id'=>$a]));
        $this->denied($this->request('accept/reverse','POST',['question_id'=>$q]),403);
        $this->data($this->request('accept/reverse','POST',['question_id'=>$q],1));
        $this->data($this->request('accept/reverse','POST',['question_id'=>$q],1));
        self::assertCount(2,$this->db->rows('ledger',['actor'=>$this->bob]));
        self::assertSame(0,array_sum(array_column($this->db->rows('ledger',['actor'=>$this->bob]),'points')));
        $this->data($this->request('accept','POST',['question_id'=>$q,'answer_id'=>$a]));
        self::assertCount(3,$this->db->rows('ledger',['actor'=>$this->bob]));
        self::assertSame(10,array_sum(array_column($this->db->rows('ledger',['actor'=>$this->bob]),'points')));
        $this->graph->change($this->alice,'block','member',$this->bob);
        self::assertSame(0,$this->data($this->request('entries/'.$q))['accepted_id']);
    }
    public function testQuestionAnswersAcceptanceReputationAndVoteAbuse(): void {
        $q=$this->data($this->request('questions','POST',['title'=>'How PHP works?','body'=>'Details','topics'=>[$this->topic]]))['id'];
        $a=$this->data($this->request('answers','POST',['parent_id'=>$q,'body'=>'A useful answer'],$this->bob))['id'];
        $this->denied($this->request('accept','POST',['question_id'=>$q,'answer_id'=>$a],$this->carol));
        $this->data($this->request('accept','POST',['question_id'=>$q,'answer_id'=>$a]));
        $this->data($this->request('accept','POST',['question_id'=>$q,'answer_id'=>$a]));
        self::assertCount(1,$this->db->rows('ledger',['actor'=>$this->bob]));
        self::assertGreaterThan(0,$this->data($this->request('reputation/'.$this->bob))[0]['score']);
        $this->denied($this->request('votes','POST',['answer_id'=>$a],$this->bob));
        $this->data($this->request('votes','POST',['answer_id'=>$a]));
        $this->data($this->request('votes','POST',['answer_id'=>$a]));
        self::assertCount(1,$this->db->rows('votes',['object_id'=>$a]));
        $knowledge=$this->data($this->request('knowledge','POST',['parent_id'=>$q,'title'=>'Reviewed PHP','body'=>'reviewed'],1))['id'];
        self::assertTrue($this->policy->view($this->alice,'knowledge',$knowledge));
    }
    public function testPrivateGroupInheritanceAndIdor(): void {
        $q=$this->data($this->request('questions','POST',['title'=>'Private PHP','body'=>'confidential','group_id'=>$this->privateGroup],$this->bob))['id'];
        $this->denied($this->request('entries/'.$q));
        $this->denied($this->request('answers','POST',['parent_id'=>$q,'body'=>'unauthorized']));
        $data=$this->data($this->request('search','GET',['type'=>'question','q'=>'Private']));
        self::assertNotContains($q,array_column($data['items'],'id'));
    }
    public function testStoredXssRemovedAndInternalNotesProtected(): void {
        $q=$this->data($this->request('questions','POST',['title'=>'<script>alert(1)</script>title','body'=>'<img src=x onerror=alert(1)><script>alert(2)</script>text']))['id'];
        $detail=$this->data($this->request('entries/'.$q));
        self::assertStringNotContainsString('<script',$detail['title']);
        self::assertStringNotContainsString('onerror',$detail['body']);
        $case=$this->data($this->request('reports','POST',['type'=>'activity','id'=>$this->publicActivity,'category'=>'spam','description'=>'Suspicious']))['case_id'];
        $same=$this->data($this->request('reports','POST',['type'=>'activity','id'=>$this->publicActivity,'category'=>'spam','description'=>'Again']))['case_id'];
        self::assertSame($case,$same);
        self::assertCount(1,$this->db->rows('reports',['case_id'=>$case]));
        $this->denied($this->request('cases/'.$case),403);
        $this->data($this->request('cases/'.$case,'POST',['action'=>'hide','note'=>'Internal secret'],1));
        self::assertFalse($this->policy->view($this->alice,'activity',$this->publicActivity));
        $this->data($this->request('cases/'.$case,'POST',['action'=>'restore','note'=>'Reversed'],1));
        self::assertTrue($this->policy->view($this->alice,'activity',$this->publicActivity));
        $detail=$this->data($this->request('cases/'.$case,'GET',[],1));
        self::assertCount(2,$detail['audit']);
    }
    public function testRestrictionCaseLifecycleAndAppeal(): void {
        $case=$this->data($this->request('reports','POST',['type'=>'activity','id'=>$this->publicActivity,'category'=>'harassment']))['case_id'];
        $this->data($this->request('cases/'.$case,'POST',['action'=>'transition','status'=>'triaged'],1));
        $this->data($this->request('cases/'.$case,'POST',['action'=>'restrict','days'=>1,'note'=>'Policy breach'],1));
        $this->denied($this->request('questions','POST',['title'=>'Restricted','body'=>'Cannot create'],$this->bob));
        $this->denied($this->request('appeals','POST',['case_id'=>$case,'reason'=>'No standing'],$this->carol));
        $this->data($this->request('appeals','POST',['case_id'=>$case,'reason'=>'Please review'],$this->bob));
        $appeal=$this->db->one('appeals',['case_id'=>$case]);
        $this->data($this->request('appeals/'.$appeal['id'],'POST',['status'=>'upheld','decision'=>'Restored'],1));
        self::assertSame('under_review',$this->db->one('cases',['id'=>$case])['status']);
        $this->denied($this->request('appeals/'.$appeal['id'],'POST',['status'=>'rejected','decision'=>'Duplicate'],1));
        $this->data($this->request('cases/'.$case,'POST',['action'=>'unrestrict'],1));
        self::assertTrue($this->policy->interact($this->bob,'activity',$this->publicActivity));
    }
    public function testLongAnswerThreadsExposePaginationWithoutDuplicates(): void {
        $q=$this->knowledge->create($this->alice,['title'=>'Long thread','body'=>'Question details']);
        wp_set_current_user($this->bob);
        for($i=0;$i<51;++$i) $this->knowledge->create($this->bob,['kind'=>'answer','parent_id'=>$q,'body'=>'Answer '.$i]);
        $first=$this->data($this->request('entries/'.$q,'GET',['kind'=>'question','page'=>1]));
        $second=$this->data($this->request('entries/'.$q,'GET',['kind'=>'question','page'=>2]));
        self::assertCount(50,$first['answers']);
        self::assertCount(1,$second['answers']);
        self::assertTrue($first['has_more_answers']);
        self::assertFalse($second['has_more_answers']);
        self::assertSame([],array_intersect(array_column($first['answers'],'id'),array_column($second['answers'],'id')));
    }
}
