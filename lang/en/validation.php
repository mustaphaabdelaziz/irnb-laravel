<?php

use App\Support\ValidationAttributes;

// English messages come from the framework; this file only adds field names
// taken from the UI's labels ("Last name", not "lastname"). Laravel merges it
// over the framework's own lang/en/validation.php.
return [

    'attributes' => ValidationAttributes::for('en'),

    'branch_code_locked' => "This code can't change: membership IDs already use it.",
    'branch_code_needs_latin' => 'Type the branch code in Latin letters (e.g. HB): the name has none.',

    'join_date_before_birth' => 'The join date is before the birth date.',
    'position_other_branch' => 'This position belongs to another branch.',
    'category_other_branch' => 'This category belongs to another branch.',
    'category_not_in_branch' => 'The category ":name" does not exist in this branch.',

    'join_date_needed' => 'A join date or year is required.',
    'branch_required' => 'Please choose at least one branch.',

];
