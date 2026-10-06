<?php
use BuddyPressIntelligence\{Core, Database, Privacy};
final class OperationsTest extends CommunityTestCase {
    public function testSchemaIdempotencyAndNestedTransactionRollback(): void {
        $this->db->migrate(); $this->db->migrate();
        self::assertSame(1,(int)Core::option('bpi_schema'));
        try {
            $this->db->transaction(function(): void {
                $this->events->record('member_followed',$this->alice,'member',$this->bob);
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException $e) { self::assertSame('rollback',$e->getMessage()); }
        self::assertSame([], $this->db->rows('events',['actor'=>$this->alice]));
    }
    public function testEventSanitizationIdempotencyAndOnceOnlyAutomation(): void {
        $this->data($this->request('rules','POST',['name'=>'Welcome','trigger'=>'member_followed','conditions'=>[],'action'=>'state','config'=>['state'=>'welcomed','value'=>'yes'],'enabled'=>true,'dry_run'=>false],1));
        $event=$this->events->record('member_followed',$this->alice,'member',$this->bob,['password'=>'secret','token'=>'secret','value'=>'follow'],'stable');
        $same=$this->events->record('member_followed',$this->alice,'member',$this->bob,[],'stable');
        self::assertSame($event,$same);
        self::assertStringNotContainsString('secret',$this->db->one('events',['id'=>$event])['metadata']);
        $this->automation->process(['event_id'=>$event]);
        $this->automation->process(['event_id'=>$event]);
        self::assertCount(1,$this->db->rows('runs',['event_id'=>$event]));
        self::assertSame('yes',get_user_meta($this->alice,'_bpi_states',true)['welcomed']);
        self::assertSame(1,(int)$this->db->one('runs',['event_id'=>$event])['attempts']);
        $generated=$this->events->record('member_followed',$this->alice,'member',$this->bob,[],'generated','automation');
        $this->automation->process(['event_id'=>$generated]);
        self::assertSame([],$this->db->rows('runs',['event_id'=>$generated]));
    }
    public function testQueueRetriesDryRunAndRateLimit(): void {
        $job=$this->jobs->enqueue('rollup',['day'=>'2026-10-01'],'fails-once');
        $this->jobs->handlers(['rollup'=>static function(): void { throw new RuntimeException('secret'); }]);
        $this->jobs->work();
        $row=$this->db->one('jobs',['id'=>$job]);
        self::assertSame('pending',$row['status']);
        self::assertSame(1,(int)$row['attempts']);
        self::assertSame('handler_failed',$row['error']);
        self::assertTrue($this->jobs->rateLimit($this->alice,'test',1));
        self::assertFalse($this->jobs->rateLimit($this->alice,'test',1));
        self::assertTrue($this->jobs->rateLimit($this->bob,'test',1));
        $this->data($this->request('rules','POST',['name'=>'Test','trigger'=>'member_followed','action'=>'state','config'=>['state'=>'dry','value'=>'no'],'enabled'=>true,'dry_run'=>true],1));
        $event=$this->events->record('member_followed',$this->alice,'member',$this->bob);
        $this->automation->process(['event_id'=>$event]);
        self::assertSame('dry_run',$this->db->one('runs',['event_id'=>$event])['status']);
        self::assertEmpty(get_user_meta($this->alice,'_bpi_states',true));
    }
    public function testPrivacyExportErasureAndRetention(): void {
        $this->graph->change($this->alice,'follow','member',$this->bob);
        $q=$this->knowledge->create($this->alice,['title'=>'Personal question','body'=>'My details']);
        $privacy=new Privacy($this->db);
        $email=get_userdata($this->alice)->user_email;
        $export=$privacy->export($email);
        self::assertNotEmpty($export['data']);
        $erase=$privacy->erase($email);
        self::assertTrue($erase['done']);
        self::assertSame([],$this->db->rows('edges',['actor'=>$this->alice]));
        self::assertSame(0,(int)$this->db->one('entries',['id'=>$q])['author']);
        self::assertSame('',$this->db->one('entries',['id'=>$q])['body']);
        $old=$this->events->record('member_followed',$this->bob,'member',$this->carol);
        $this->db->update('events',['created_at'=>'2020-01-01 00:00:00'],['id'=>$old]);
        $this->jobs->maintenance();
        self::assertNull($this->db->one('events',['id'=>$old]));
    }
    public function testConsentAggregationAndDeterministicAssignments(): void {
        $this->settings->save(['analytics_consent'=>true]);
        $this->topics->preferences($this->alice,['analytics'=>true]);
        $this->events->record('member_followed',$this->alice,'member',$this->bob);
        $this->analytics->rollup(['day'=>gmdate('Y-m-d')]);
        wp_set_current_user(1);
        $metrics=$this->analytics->overview()['metrics'];
        self::assertNotEmpty($metrics);
        foreach($metrics as $metric) self::assertNull($metric['value']);
        $id=$this->analytics->experiment(['name'=>'Safe UI','variants'=>['control','alternate'],'metric'=>'recommendation_clicked','status'=>'running','starts_at'=>gmdate('Y-m-d H:i:s',time()-60),'ends_at'=>gmdate('Y-m-d H:i:s',time()+3600)]);
        self::assertSame($this->analytics->assignment($this->alice,$id),$this->analytics->assignment($this->alice,$id));
        self::assertCount(1,$this->db->rows('assignments',['actor'=>$this->alice]));
        $results=$this->analytics->results($id);
        self::assertCount(2,$results['results']);
        self::assertNull($results['results'][0]['response_rate']);
        self::assertCount(3,$this->analytics->overview()['health']);
        $experiment=$this->db->one('experiments',['id'=>$id]);
        $input=array_intersect_key($experiment,array_flip(['name','metric','starts_at','ends_at']));
        $input['variants']=json_decode($experiment['variants'],true); $input['status']='ended';
        $this->analytics->experiment($input,$id);
        $this->denied($this->request('experiments/'.$id.'/assignment'));
        $this->denied($this->request('analytics'),403);
    }
    public function testDisabledTopicsAdminAndInvalidQueueRetryAreSafe(): void {
        wp_set_current_user(1);
        $this->settings->save(['modules'=>['topics'=>false]]);
        $saved=$_GET;
        $_GET['page']='bpi-topics';
        ob_start();
        try {
            (new BuddyPressIntelligence\UI($this->api,$this->settings,$this->db))->admin();
            $html=ob_get_contents();
            self::assertStringContainsString('bpi-alert',$html);
        } finally { ob_end_clean(); $_GET=$saved; }
        $this->denied($this->request('jobs/999999/retry','POST',[],1));
    }
    public function testRuleCapacityIsExplicitAndUpdatesRemainAvailable(): void {
        wp_set_current_user(1);
        $input=['name'=>'Bounded rule','trigger'=>'member_followed','action'=>'notify','enabled'=>false,'dry_run'=>true];
        $first=$this->automation->save($input);
        for($i=1;$i<100;++$i) $this->automation->save($input);
        $this->automation->save($input+['conditions'=>[]],$first);
        $this->denied($this->request('rules','POST',$input,1),400);
        self::assertCount(100,$this->db->rows('rules',[],100));
    }
    public function testExpiredWorkerCannotOverwriteAReplacementLease(): void {
        $job=$this->jobs->enqueue('rollup',['day'=>gmdate('Y-m-d')],'replacement-lease');
        $this->jobs->handlers(['rollup'=>function() use($job): void {
            $this->db->update('jobs',['status'=>'done','locked_at'=>gmdate('Y-m-d H:i:s',time()+1),'attempts'=>2],['id'=>$job]);
            throw new RuntimeException('old worker failed after lease replacement');
        }]);
        $this->jobs->work();
        $row=$this->db->one('jobs',['id'=>$job]);
        self::assertSame('done',$row['status']);
        self::assertSame(2,(int)$row['attempts']);
        self::assertSame('',$row['error']);
    }
    public function testSearchInstrumentationNeedsBothConsentsAndStoresNoQuery(): void {
        $this->data($this->request('search','GET',['q'=>'secret personal query','type'=>'topic']));
        self::assertSame([],$this->db->rows('events',['type'=>'search_performed']));
        wp_set_current_user(1);
        $this->settings->save(['analytics_consent'=>true]);
        $this->topics->preferences($this->alice,['analytics'=>true]);
        $first=$this->data($this->request('search','GET',['q'=>'PHP','type'=>'topic']));
        $this->data($this->request('search','GET',['q'=>'PHP','type'=>'topic','cursor'=>$first['cursor']]));
        $events=$this->db->rows('events',['type'=>'search_performed']);
        self::assertCount(1,$events);
        self::assertStringNotContainsString('PHP',$events[0]['metadata']);
        self::assertTrue(json_decode($events[0]['metadata'],true)['analytics']);
    }
    public function testHealthMetricsCountDistinctMembersWithoutEventMultiplication(): void {
        wp_set_current_user(1);
        $this->settings->save(['analytics_consent'=>true]);
        $members=[$this->alice,$this->bob,$this->carol,$this->user('bpi_metrics_four'),$this->user('bpi_metrics_five')];
        foreach($members as $actor) $this->topics->preferences($actor,['analytics'=>true]);
        foreach($members as $actor) {
            $earlier=$this->events->record('member_profile_updated',$actor,'member',$actor);
            $this->db->update('events',['created_at'=>gmdate('Y-m-d H:i:s',time()-10*DAY_IN_SECONDS)],['id'=>$earlier]);
            wp_set_current_user($actor);
            $q=$this->knowledge->create($actor,['title'=>'Answered health question','body'=>'Details']);
            $this->knowledge->create($actor,['title'=>'Unanswered health question','body'=>'Details']);
            wp_set_current_user($this->bob);
            $this->knowledge->create($this->bob,['kind'=>'answer','parent_id'=>$q,'body'=>'Response']);
        }
        wp_set_current_user(1);
        $health=$this->analytics->overview()['health'];
        self::assertSame(50.0,$health['unanswered_question_rate']['value']);
        self::assertSame(100.0,$health['weekly_cohort_return']['value']);
        self::assertSame(46.67,$health['contributor_concentration']['value']);
    }
}
