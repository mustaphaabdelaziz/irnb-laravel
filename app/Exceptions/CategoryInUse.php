<?php

namespace App\Exceptions;

use RuntimeException;

/** Thrown inside a delete transaction so everything it removed comes back. */
class CategoryInUse extends RuntimeException
{
}
