<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReviewTablesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function review_tables_have_expected_columns_statuses_and_indexes(): void
    {
        $this->assertTrue(Schema::hasTable('review_statuses'));
        $this->assertTrue(Schema::hasColumns('review_statuses', [
            'id',
            'name',
            'created_at',
            'updated_at',
        ]));

        $this->assertTrue(Schema::hasTable('reviews'));
        $this->assertTrue(Schema::hasColumns('reviews', [
            'id',
            'reviewer_profile_id',
            'reviewed_profile_id',
            'rating',
            'comment',
            'status_id',
            'created_at',
            'updated_at',
        ]));

        $this->assertSame(
            ['Flagged', 'Hidden', 'Visible'],
            DB::table('review_statuses')
                ->orderBy('name')
                ->pluck('name')
                ->all()
        );

        $indexes = collect(Schema::getIndexes('reviews'))->keyBy('name');

        $uniquePairIndex = $indexes->get(
            'reviews_reviewer_profile_id_reviewed_profile_id_unique'
        );

        $this->assertNotNull($uniquePairIndex);
        $this->assertTrue($uniquePairIndex['unique']);
        $this->assertSame(
            ['reviewer_profile_id', 'reviewed_profile_id'],
            $uniquePairIndex['columns']
        );

        $reviewedStatusIndex = $indexes->get(
            'reviews_reviewed_profile_id_status_id_index'
        );

        $this->assertNotNull($reviewedStatusIndex);
        $this->assertFalse($reviewedStatusIndex['unique']);
        $this->assertSame(
            ['reviewed_profile_id', 'status_id'],
            $reviewedStatusIndex['columns']
        );
    }
}
