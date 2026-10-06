<?php
use BuddyPressIntelligence\Ranking;
use PHPUnit\Framework\TestCase;

final class RankingTest extends TestCase {
    public function testNormalizationAndDeterminism(): void {
        $signals = ['a'=>10, 'b'=>-5];
        self::assertSame(0.5, Ranking::score($signals, ['a'=>1, 'b'=>1]));
        self::assertSame(Ranking::score($signals, ['a'=>1]), Ranking::score($signals, ['a'=>1]));
        self::assertSame(0.0, Ranking::score(['a'=>1], ['a'=>1], 2));
        self::assertSame(0.0, Ranking::score([], []));
    }
    public function testDecayAndBoundedStrength(): void {
        self::assertEqualsWithDelta(0.5, Ranking::recency(0, 72 * 3600), 0.000001);
        self::assertSame(1.0, Ranking::recency(200, 100));
        self::assertSame(1.0, Ranking::strength(true, true, true, 10000, 1));
        self::assertSame(0.0, Ranking::strength(true, true, true, 10000, 0));
        self::assertGreaterThan(Ranking::trend(20, 10, 72), Ranking::trend(20, 10, 1));
    }
    public function testStableSortAndAuthorDiversity(): void {
        $items = [];
        for ($id=1; $id<=15; ++$id) $items[] = ['id'=>$id,'type'=>'activity','author'=>$id<=9 ? 1 : $id,'group_id'=>0,'topic_id'=>0,'score'=>1.0];
        $sorted = Ranking::sort($items);
        self::assertSame(15, $sorted[0]['id']);
        $result = Ranking::diversify($sorted, 15);
        $authors = array_column($result, 'author');
        self::assertLessThanOrEqual(6, count(array_filter($authors, static fn($a)=>$a===1)));
        for ($i=2; $i<count($authors); ++$i) self::assertFalse($authors[$i]===1 && $authors[$i-1]===1 && $authors[$i-2]===1);
    }
}
