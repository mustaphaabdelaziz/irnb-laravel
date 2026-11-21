<?php

use App\Support\ValidationAttributes;

// English messages come from the framework; this file only adds field names
// taken from the UI's labels ("Last name", not "lastname"). Laravel merges it
// over the framework's own lang/en/validation.php.
return [

    'attributes' => ValidationAttributes::for('en'),

    'branch_code_locked' => "This code can't change: membership IDs already use it.",
    'equipment_expense_needs_price' => 'Enter the unit price to record the expense.',
    'equipment_source_required' => 'Choose where the stock came from.',
    'branch_code_needs_latin' => 'Type the branch code in Latin letters (e.g. HB): the name has none.',
    'category_code_needs_latin' => 'Type the category code in Latin letters (e.g. BALL): the name has none.',

    'join_date_before_birth' => 'The join date is before the birth date.',
    'status_change_before_birth' => 'The date of change is before the birth date.',
    'status_change_before_join' => 'The date of change is before the join date.',
    'position_other_branch' => 'This position belongs to another branch.',
    'category_other_branch' => 'This category belongs to another branch.',
    'category_not_in_branch' => 'The category ":name" does not exist in this branch.',
    'group_other_category' => 'This group belongs to another branch or category.',

    'join_date_needed' => 'A join date or year is required.',
    'branch_required' => 'Please choose at least one branch.',

];
