<?php

namespace Tests\Unit;

use App\Exceptions\ClosedFiscalYearException;
use App\Exceptions\Equipment\StockException;
use App\Exceptions\Import\ImportRowException;
use App\Models\Player;
use App\Support\Import\ImportRowError;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;
use TypeError;

class ImportRowErrorTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_apps_own_refusals_keep_their_translated_sentence(): void
    {
        app()->setLocale('ar');

        $this->assertSame('تاريخ غير صالح', ImportRowError::message(new ImportRowException('تاريخ غير صالح')));

        $validation = new ValidationException(Validator::make([], ['name' => 'required']));
        $this->assertSame($validation->getMessage(), ImportRowError::message($validation));

        $closed = new ClosedFiscalYearException(2027);
        $this->assertSame($closed->getMessage(), ImportRowError::message($closed));
        $this->assertStringContainsString('مغلق', ImportRowError::message($closed));
    }

    #[Test]
    public function a_stock_refusal_is_shown_in_the_readers_language(): void
    {
        app()->setLocale('ar');

        $this->assertSame(
            'لا يمكن تسليم 5 وحدة: المتاح 2 فقط.',
            ImportRowError::message(StockException::notEnoughAvailable(5, 2)),
        );
    }

    #[Test]
    public function a_database_or_unexpected_error_becomes_one_translated_sentence(): void
    {
        app()->setLocale('ar');

        $query = new QueryException('sqlite', 'insert into players', [], new PDOException('SQLSTATE[23000]: Integrity constraint violation'));

        $this->assertSame('تعذّر حفظ هذا السطر.', ImportRowError::message($query));
        $this->assertSame('تعذّر حفظ هذا السطر.', ImportRowError::message(new TypeError('Argument #1 must be of type string')));
    }

    #[Test]
    public function a_framework_exception_never_shows_its_english_text(): void
    {
        app()->setLocale('ar');

        foreach ([
            (new ModelNotFoundException)->setModel(Player::class, [7]),
            new MassAssignmentException('Add [x] to fillable property to allow mass assignment on [App\Models\Player].'),
            new InvalidFormatException('Could not parse \'abc\': Failed to parse time string'),
            new RuntimeException('The "left" player status is missing.'),
            new InvalidArgumentException('A status change needs the branch it applies to.'),
        ] as $e) {
            $this->assertSame('تعذّر حفظ هذا السطر.', ImportRowError::message($e), $e::class);
        }
    }
}
