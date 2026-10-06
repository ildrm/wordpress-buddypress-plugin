<?php
use BuddyPressIntelligence\{Database,Jobs};

// Inject real MySQL error codes into only the job completion statement.
final class CompletionFaultDatabase extends wpdb {
    public int $remaining=0;
    public int $errorCode=1213;
    public int $completionCalls=0;
    public function update($table,$data,$where,$format=null,$where_format=null) {
        if (str_ends_with($table,'bpi_jobs') && ($data['status']??'')==='done') {
            $this->completionCalls++;
            if ($this->remaining>0) {
                $this->remaining--;
                return $this->query("SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO=".$this->errorCode.", MESSAGE_TEXT='Injected completion fault'");
            }
        }
        return parent::update($table,$data,$where,$format,$where_format);
    }
}

final class QueueRecoveryTest extends CommunityTestCase {
    private function completionScenario(int $failures,int $code,bool $throws): void {
        global $wpdb;
        $original=$wpdb;
        $fault=new CompletionFaultDatabase(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);
        $fault->set_prefix($original->prefix);
        $fault->remaining=$failures;
        $fault->errorCode=$code;
        $wpdb=$fault;
        try {
            $db=new Database();
            $db->query('DELETE FROM '.$db->table('jobs'));
            $jobs=new Jobs($db,$this->settings);
            $id=$jobs->enqueue('rollup',['day'=>gmdate('Y-m-d')],'completion-fault:'.$code.':'.$failures);
            $calls=0;
            $jobs->handlers(['rollup'=>static function() use(&$calls): void { $calls++; }]);
            $error=null;
            try { $jobs->work(); } catch (RuntimeException $e) { $error=$e; }
            self::assertSame(1,$calls,'A successful handler must never be replayed by completion retries.');
            self::assertSame($throws,$error instanceof RuntimeException);
            self::assertSame($code===1064?1:min(3,$failures+1),$fault->completionCalls);
            $row=$db->one('jobs',['id'=>$id]);
            self::assertSame($throws?'running':'done',$row['status']);
            self::assertSame(1,(int)$row['attempts']);
            self::assertSame('',$row['error'],'Completion failure must not be labeled as handler failure.');
            self::assertFalse($fault->suppress_errors,'Error suppression must be restored.');
        } finally { $wpdb=$original; $fault->close(); }
    }
    public function testDeadlockCompletionRetriesOnlyTheWrite(): void { $this->completionScenario(1,1213,false); }
    public function testLockTimeoutCompletionRetriesOnlyTheWrite(): void { $this->completionScenario(1,1205,false); }
    public function testExhaustedCompletionRetriesPreserveTheLease(): void { $this->completionScenario(5,1213,true); }
    public function testPermanentDatabaseErrorsDoNotRetrySuccessfulHandlers(): void { $this->completionScenario(1,1064,true); }
    public function testAutocommitRetryCannotRepeatAnAbortedTransaction(): void {
        try {
            $this->db->transaction(fn()=> $this->db->retryAutocommit(fn()=> $this->db->query('SELECT 1')));
            self::fail('Transactional retries must be rejected.');
        } catch (LogicException $e) { self::assertStringContainsString('within a transaction',$e->getMessage()); }
        self::assertSame(1,(int)$this->db->select('SELECT 1 AS result',[])[0]['result']);
    }
    public function testFailedPolicyReadCannotMasqueradeAsAnEmptyResult(): void {
        global $wpdb;
        $suppressed=$wpdb->suppress_errors(true);
        try {
            try { $this->db->select('SELECT missing_privacy_column FROM '.$this->db->table('edges').' WHERE id=%d',[1]); self::fail('Failed reads must stop serialization.'); }
            catch (RuntimeException $e) { self::assertSame('Database read failed.',$e->getMessage()); }
            self::assertSame(1,(int)$this->db->select('SELECT 1 AS result',[])[0]['result']);
        } finally { $wpdb->suppress_errors($suppressed); }
    }
}
