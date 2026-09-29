<?php

use App\Support\ValidationAttributes;

// English messages come from the framework; this file only adds field names
// taken from the UI's labels ("Last name", not "lastname"). Laravel merges it
// over the framework's own lang/en/validation.php.
return [

    'attributes' => ValidationAttributes::for('en'),

];
