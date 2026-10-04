<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/**
 * Sign-in usernames: any letters (Arabic included), digits and . _ - @ +,
 * no spaces. Case-insensitive — normalised to trimmed lowercase before
 * validation and storage, so "Ali" and "ali" are the same account while
 * "mustapha" and "mustapha@club.com" are two different ones.
 */
class Username
{
    public static function normalize(mixed $value): mixed
    {
        return is_string($value) ? mb_strtolower(trim($value)) : $value;
    }

    /** @return list<mixed> */
    public static function rules(?int $ignoreUserId = null): array
    {
        return [
            'required',
            'string',
            'min:3',
            'max:100',
            'regex:/^[\pL\pN._@+-]+$/u',
            Rule::unique('users', 'username')->ignore($ignoreUserId),
        ];
    }
}
