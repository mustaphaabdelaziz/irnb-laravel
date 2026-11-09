<?php

namespace Tests\Unit;

use App\Support\Import\ImportRowError;
use Illuminate\Database\QueryException;
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
    #[Test]
    public function the_apps_own_refusals_keep_their_translated_sentence(): void
    {
        app()->setLocale('ar');

        $this->assertSame('تاريخ غير صالح', ImportRowError::message(new RuntimeException('تاريخ غير صالح')));
        $this->assertSame('كمية غير صالحة', ImportRowError::message(new InvalidArgumentException('كمية غير صالحة')));

        $validation = new ValidationException(Validator::make([], ['name' => 'required']));
        $this->assertSame($validation->getMessage(), ImportRowError::message($validation));
    }

    #[Test]
    public function a_database_or_unexpected_error_becomes_one_translated_sentence(): void
    {
        app()->setLocale('ar');

        $query = new QueryException('sqlite', 'insert into players', [], new PDOException('SQLSTATE[23000]: Integrity constraint violation'));

        $this->assertSame('تعذّر حفظ هذا السطر.', ImportRowError::message($query));
        $this->assertSame('تعذّر حفظ هذا السطر.', ImportRowError::message(new TypeError('Argument #1 must be of type string')));
    }
}
