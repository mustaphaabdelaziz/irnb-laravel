<?php

namespace App\Services\Secretariat;

use App\Exceptions\MailEntryException;
use App\Models\MailEntry;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use App\Services\Documents\YearlySequence;
use App\Services\Storage\PrivateFileStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Every write to the mail register, one DB transaction each, each guarded by
 * an atomic conditional claim: create (numbered per direction and year of the
 * entry date), update (only the fields the entry may still change, and its
 * one scan), cancel (keeps the number, frees the printed document) and
 * register() for the print tick box. Entries are never deleted.
 */
final class MailRegister
{
    /** Folder of the scans on the private disk. */
    private const SCANS = 'mail';

    public function __construct(private readonly PrivateFileStorage $files) {}

    /** @param array<string, mixed> $data validated form fields, with `direction` */
    public function create(array $data, User $by, ?UploadedFile $scan = null): MailEntry
    {
        $direction = (string) $data['direction'];
        $fields = Arr::only($data, MailEntry::fieldsFor($direction));
        $stored = $scan ? $this->files->store($scan, self::SCANS) : null;

        try {
            return YearlySequence::retrying(fn () => DB::transaction(function () use ($direction, $fields, $stored, $by) {
                $year = Carbon::parse($fields['entry_date'])->year;
                $entry = MailEntry::create([
                    ...$fields,
                    'direction' => $direction,
                    'year' => $year,
                    'number' => YearlySequence::next('mail_entries', $year, ['direction' => $direction]),
                    'status' => MailEntry::ACTIVE,
                    'scan_path' => $stored['path'] ?? null,
                    'scan_name' => $stored['original_name'] ?? null,
                    'created_by' => $by->id,
                ]);
                ActivityRecorder::record($by, ActivityAction::MAIL_ENTRY_CREATED, $entry, self::properties($entry));

                return $entry->fresh();
            }));
        } catch (Throwable $e) {
            // Refused: do not leave the upload orphaned.
            $this->files->delete($stored['path'] ?? null);

            throw $e;
        }
    }

    /**
     * Only the fields editableFields() allows; the rest of $data is ignored.
     * A new scan replaces the old one, which is deleted once the save holds.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(MailEntry $entry, array $data, User $by, ?UploadedFile $scan = null): MailEntry
    {
        $editable = $entry->editableFields();
        if ($editable === []) {
            throw new MailEntryException('flash.mail_locked');
        }
        $changes = Arr::only($data, $editable);
        // The number belongs to the year it was given in.
        if (isset($changes['entry_date']) && Carbon::parse($changes['entry_date'])->year !== $entry->year) {
            throw new MailEntryException('flash.mail_year_locked');
        }
        $previous = $entry->scan_path;
        $stored = $scan ? $this->files->store($scan, self::SCANS) : null;

        try {
            DB::transaction(function () use ($entry, $changes, $stored, $by) {
                $claimed = MailEntry::whereKey($entry->id)->where('status', MailEntry::ACTIVE)
                    ->update([
                        ...$changes,
                        ...($stored !== null ? ['scan_path' => $stored['path'], 'scan_name' => $stored['original_name']] : []),
                        'lock_version' => DB::raw('lock_version + 1'),
                        'updated_at' => now(),
                    ]);
                if ($claimed === 0) {
                    throw new MailEntryException('flash.mail_locked');
                }
                ActivityRecorder::record($by, ActivityAction::MAIL_ENTRY_UPDATED, $entry, [
                    ...self::properties($entry),
                    // The fact only: file names never reach the log.
                    'scan' => $stored !== null ? true : null,
                ]);
            });
        } catch (Throwable $e) {
            $this->files->delete($stored['path'] ?? null);

            throw $e;
        }

        if ($stored !== null && $previous) {
            $this->files->delete($previous);
        }

        return $entry->fresh();
    }

    /** Active, not imported; keeps its number and frees its printed document for a new entry. */
    public function cancel(MailEntry $entry, User $by): MailEntry
    {
        return DB::transaction(function () use ($entry, $by) {
            $claimed = MailEntry::whereKey($entry->id)
                ->where('status', MailEntry::ACTIVE)->where('is_legacy', false)
                ->update([
                    'status' => MailEntry::CANCELLED,
                    'cancelled_by' => $by->id,
                    'cancelled_at' => now(),
                    'source_lock' => null,
                    'updated_at' => now(),
                ]);
            if ($claimed === 0) {
                throw new MailEntryException('flash.mail_cannot_cancel');
            }
            ActivityRecorder::record($by, ActivityAction::MAIL_ENTRY_CANCELLED, $entry, self::properties($entry));

            return $entry->fresh();
        });
    }

    /**
     * The print tick box: the document's active entry, or a new outgoing one
     * (today, next number) with the document's correspondent, address and
     * subject. A cancelled entry does not count — the document gets a new
     * number. Two clicks at the same instant: the second insert fails on the
     * unique source_lock and the retry returns the first one's entry.
     *
     * @param  array{correspondent?: ?string, address?: ?string, subject?: ?string}  $defaults
     */
    public function register(string $type, int $id, string $key, array $defaults, User $by): MailEntry
    {
        $lock = MailEntry::lockFor($type, $id, $key);

        return YearlySequence::retrying(fn () => DB::transaction(function () use ($type, $id, $key, $lock, $defaults, $by) {
            $existing = MailEntry::where('source_lock', $lock)->first();
            if ($existing !== null) {
                return $existing;
            }

            $today = Carbon::now();
            $entry = MailEntry::create([
                'direction' => MailEntry::OUT,
                'year' => $today->year,
                'number' => YearlySequence::next('mail_entries', $today->year, ['direction' => MailEntry::OUT]),
                'status' => MailEntry::ACTIVE,
                'entry_date' => $today->toDateString(),
                'correspondent' => self::text($defaults['correspondent'] ?? null, 200) ?? '—',
                'address' => self::text($defaults['address'] ?? null, 255),
                'subject' => self::text($defaults['subject'] ?? null, 255) ?? '—',
                'source_type' => $type,
                'source_id' => $id,
                'source_key' => $key,
                'source_lock' => $lock,
                'created_by' => $by->id,
            ]);
            ActivityRecorder::record($by, ActivityAction::MAIL_ENTRY_REGISTERED, $entry, [
                ...self::properties($entry),
                'source_type' => $type,
                'source_id' => $id,
            ]);

            return $entry;
        }));
    }

    /** The document's active entry, if any. Never writes: the PDF routes call only this. */
    public function find(string $type, int $id, string $key): ?MailEntry
    {
        return MailEntry::where('source_lock', MailEntry::lockFor($type, $id, $key))->first();
    }

    /** @return array{reference: string, direction: string} references only, never names or free text */
    private static function properties(MailEntry $entry): array
    {
        return ['reference' => $entry->reference, 'direction' => $entry->direction];
    }

    private static function text(mixed $value, int $limit): ?string
    {
        $value = trim(is_scalar($value) ? (string) $value : '');

        return $value === '' ? null : Str::limit($value, $limit, '');
    }
}
