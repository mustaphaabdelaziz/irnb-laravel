<?php

namespace Tests\Unit;

use App\Services\Player\Duplicates\DuplicateScorer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DuplicateScorerTest extends TestCase
{
    private function p(array $row): array
    {
        static $id = 0;

        return DuplicateScorer::profile(array_merge([
            'id' => ++$id, 'firstname' => null, 'lastname' => null, 'father' => null,
            'birthdate' => null, 'gender' => 'Male', 'phones' => [],
        ], $row));
    }

    #[Test]
    public function arabic_spelling_variants_are_the_same_name(): void
    {
        $result = DuplicateScorer::score(
            $this->p(['firstname' => 'فاطمة', 'lastname' => 'بن علي', 'gender' => 'Female']),
            $this->p(['firstname' => 'فاطمه', 'lastname' => 'بن على', 'gender' => 'Female']),
        );

        $this->assertSame(['same_name'], $result['reasons']);
        $this->assertSame(80, $result['score']);
    }

    #[Test]
    public function swapped_or_one_letter_off_names_are_similar(): void
    {
        $swapped = DuplicateScorer::score(
            $this->p(['firstname' => 'Said', 'lastname' => 'Badlis']),
            $this->p(['firstname' => 'Badlis', 'lastname' => 'Said']),
        );
        $typo = DuplicateScorer::score(
            $this->p(['firstname' => 'Mohamed', 'lastname' => 'Benali']),
            $this->p(['firstname' => 'Mohammed', 'lastname' => 'Benali']),
        );

        $this->assertSame(['similar_name'], $swapped['reasons']);
        $this->assertSame(['similar_name'], $typo['reasons']);
        $this->assertSame(50, $typo['score']);
    }

    #[Test]
    public function phone_formats_are_folded_before_comparing(): void
    {
        $this->assertSame('0542039525', DuplicateScorer::phone('+213 542 03 95 25'));
        $this->assertSame('0542039525', DuplicateScorer::phone('00213542039525'));
        $this->assertSame('', DuplicateScorer::phone('123'));

        $result = DuplicateScorer::score(
            $this->p(['phones' => ['+213 542 03 95 25'], 'birthdate' => '2012-03-04']),
            $this->p(['phones' => '["0542039525"]', 'birthdate' => '2012-03-04 00:00:00']),
        );

        $this->assertSame(['same_birthdate', 'same_phone'], $result['reasons']);
        $this->assertSame(120, $result['score']);
    }

    #[Test]
    public function siblings_of_different_gender_fall_below_the_floor(): void
    {
        $result = DuplicateScorer::score(
            $this->p(['firstname' => 'Ahmed', 'lastname' => 'Saidi', 'father' => 'Ali', 'gender' => 'Male', 'phones' => ['0550000001']]),
            $this->p(['firstname' => 'Sara', 'lastname' => 'Saidi', 'father' => 'Ali', 'gender' => 'Female', 'phones' => ['0550000001']]),
        );

        $this->assertSame(['same_phone', 'same_father', 'different_gender'], $result['reasons']);
        $this->assertSame(30, $result['score']);
        $this->assertLessThan(DuplicateScorer::MIN_SCORE, $result['score']);
    }

    #[Test]
    public function criteria_must_all_be_met(): void
    {
        $reasons = ['similar_name', 'same_birthdate'];

        $this->assertTrue(DuplicateScorer::meets($reasons, []));
        $this->assertTrue(DuplicateScorer::meets($reasons, ['name']));
        $this->assertTrue(DuplicateScorer::meets($reasons, ['name', 'birthdate']));
        $this->assertFalse(DuplicateScorer::meets($reasons, ['name', 'phone']));
    }
}
