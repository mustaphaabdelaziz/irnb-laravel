<?php

namespace App\Support;

/**
 * Field names for validation messages, taken from the UI's own labels so an
 * error names a field exactly as the form around it does ("حقل اللقب مطلوب",
 * not "The lastname field is required"). Loaded by lang/{ar,fr,en}/validation.php.
 */
final class ValidationAttributes
{
    /** Request field => UI label key (resources/js/i18n/*.json). */
    private const LABELS = [
        'name' => 'name',
        'firstname' => 'firstname',
        'lastname' => 'lastname',
        'nickname' => 'nickname',
        'father' => 'father',
        'grandfather' => 'grandfather',
        'birthdate' => 'date_of_birth',
        'gender' => 'gender',
        'health_blood_group_rhesus' => 'blood_group',
        'health_medical_conditions' => 'medical_notes',
        'phone' => 'phone',
        'phones' => 'phone',
        'phones.*' => 'phone',
        'mobile' => 'mobile',
        'email' => 'email',
        'wilaya_id' => 'state',
        'state' => 'state',
        'city' => 'city',
        'address' => 'address',
        'category' => 'category',
        'category_id' => 'category',
        'category_ids' => 'categories',
        'position_id' => 'main_position',
        'other_position_ids' => 'other_positions',
        'other_position_ids.*' => 'other_positions',
        'branch_id' => 'branch',
        'branch_ids' => 'branches',
        'branch_ids.*' => 'branches',
        'skill_level' => 'skill_level',
        'status' => 'status',
        'status_id' => 'membership_status',
        'join_year' => 'join_year',
        'left_at' => 'left_at',
        'is_student' => 'student',
        'member_job_id' => 'job',
        'picture' => 'picture',
        'photo' => 'photo',
        'logo' => 'logo',
        'emergency_contacts.*.name' => 'emergency_contact',
        'emergency_contacts.*.phones' => 'phone',
        'amount' => 'amount',
        'amount_student' => 'student',
        'amount_worker' => 'worker',
        'description' => 'description',
        'title' => 'title',
        'date' => 'date',
        'transaction_date' => 'date',
        'due_date' => 'due_date',
        'notes' => 'notes',
        'password' => 'password',
        'current_password' => 'current_password',
        'role' => 'role',
        'quantity' => 'quantity',
        'year' => 'year',
        'type' => 'type',
        'label' => 'label',
        'code' => 'code',
        'sort_order' => 'sort_order',
        'finance_account_id' => 'cash_register',
        'from_account_id' => 'from_register',
        'to_account_id' => 'to_register',
        'subscription_id' => 'subscription',
        'player_id' => 'player',
        'player_ids' => 'players',
        'kind' => 'subscription_kind',
        'academic_year' => 'academic_year',
        'certificate' => 'certificate',
        'gpa' => 'gpa',
        'institution' => 'institution',
        'education_level' => 'education_level',
        'field_of_study' => 'field_of_study',
        'reference' => 'reference',
        'payment_method' => 'payment_method',
        'designation' => 'designation',
        'location' => 'location',
        'condition' => 'condition',
        'purchase_date' => 'purchase_date',
        'purchase_price' => 'purchase_price',
        'unit_price' => 'unit_price',
        'abbreviation' => 'abbreviation',
        'priority' => 'priority',
        'season' => 'season',
        'motto' => 'motto',
        'tagline' => 'tagline',
        'founding_date' => 'founding_date',
        'founder' => 'founder',
        'currency' => 'currency',
        'fiscal_year' => 'fiscal_year',
        'opening_balance' => 'opening_balance',
        'quorum_required' => 'quorum',
        'valid_until' => 'doc_valid_until',
        'max_age' => 'doc_max_age',
        'discount_value' => 'discount',
        'return_date' => 'return_date',
        'start_date' => 'att.start_date',
        'body_part' => 'att.injury.col.body_part',
        'returned_on' => 'att.injury.col.returned_on',
        'remark' => 'remark',
    ];

    /** @return array<string, string> */
    public static function for(string $locale): array
    {
        $attributes = [];

        foreach (self::LABELS as $field => $key) {
            $label = UiLang::get($key, '', $locale);

            // A key the catalogs don't have falls back to Laravel's own
            // "field name" wording rather than showing the raw key.
            if ($label !== '' && $label !== $key) {
                $attributes[$field] = $label;
            }
        }

        return $attributes;
    }
}
