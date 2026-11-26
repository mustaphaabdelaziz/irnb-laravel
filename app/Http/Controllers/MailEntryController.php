<?php

namespace App\Http\Controllers;

use App\Http\Requests\Mail\SaveMailEntryRequest;
use App\Models\MailEntry;
use App\Services\Secretariat\MailRegister;
use App\Services\Secretariat\MailSources;
use App\Services\Storage\PrivateFileStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Secretariat → Mail register (سجل البريد): outgoing / incoming lists, form, cancel, scan. */
class MailEntryController extends Controller
{
    /** How many earlier correspondents the form suggests. */
    private const SUGGESTIONS = 300;

    public function __construct(
        private readonly MailRegister $register,
        private readonly MailSources $sources,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'direction' => ['nullable', Rule::in(MailEntry::DIRECTIONS)],
            'year' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $direction = $filters['direction'] ?? MailEntry::OUT;
        $user = $request->user();

        $entries = MailEntry::query()
            ->where('direction', $direction)
            ->when($filters['year'] ?? null, fn ($q, $year) => $q->where('year', $year))
            ->when($filters['q'] ?? null, fn ($q, $text) => $q->where(function ($q) use ($text) {
                $like = '%'.$text.'%';
                $q->where('correspondent', 'like', $like)
                    ->orWhere('subject', 'like', $like)
                    ->orWhere('legacy_reference', 'like', $like)
                    ->orWhere('sender_reference', 'like', $like);
                // "2026/3", "و 2026/03", "IN-2026/03" find entry 3 of 2026; a bare "3" finds number 3 of any year.
                if (preg_match('#(\d{4})\s*/\s*0*(\d+)\s*$#u', $text, $m)) {
                    $q->orWhere(fn ($q) => $q->where('year', (int) $m[1])->where('number', (int) $m[2]));
                } elseif (preg_match('/^\s*0*(\d+)\s*$/', $text, $m)) {
                    $q->orWhere('number', (int) $m[1]);
                }
            }))
            ->orderByDesc('year')->orderByDesc('number')->orderByDesc('legacy_reference')
            ->orderByDesc('entry_date')->orderByDesc('id')
            ->paginate(50)->withQueryString()
            ->through(fn (MailEntry $entry) => [
                'id' => $entry->id,
                'reference' => $entry->reference,
                'entry_date' => $entry->entry_date?->format('Y-m-d'),
                'correspondent' => $entry->correspondent,
                'subject' => $entry->subject,
                'status' => $entry->status,
                'is_legacy' => $entry->is_legacy,
                'has_scan' => $entry->has_scan,
                'source_type' => $entry->source_type,
                // A link from the id alone: no query per row.
                'source_url' => $entry->source_type ? MailSources::url($entry->source_type, (int) $entry->source_id, $user) : null,
            ]);

        return Inertia::render('Mail/Index', [
            'entries' => $entries,
            'filters' => [...$filters, 'direction' => $direction],
            'years' => MailEntry::query()->where('direction', $direction)->whereNotNull('year')
                ->distinct()->orderByDesc('year')->pluck('year'),
        ]);
    }

    public function create(Request $request): Response
    {
        $asked = $request->query('direction');

        return Inertia::render('Mail/Create', [
            'direction' => in_array($asked, MailEntry::DIRECTIONS, true) ? $asked : MailEntry::OUT,
            'correspondents' => $this->correspondents(),
        ]);
    }

    public function store(SaveMailEntryRequest $request): RedirectResponse
    {
        $entry = $this->register->create(Arr::except($request->validated(), ['scan']), $request->user(), $request->file('scan'));

        return redirect()->route('mail.show', $entry)->with('success', 'flash.mail_created');
    }

    public function show(Request $request, MailEntry $mailEntry): Response
    {
        $user = $request->user();

        return Inertia::render('Mail/Show', [
            'entry' => $mailEntry,
            'source' => $this->sources->describe($mailEntry, $user),
            'can' => [
                'edit' => $user->hasPermission('mail', 'edit') && $mailEntry->editableFields() !== [],
                // Imported letters are history: never cancelled.
                'cancel' => $user->hasPermission('mail', 'delete')
                    && $mailEntry->status === MailEntry::ACTIVE && ! $mailEntry->is_legacy,
                'print' => $mailEntry->isPrintable(),
            ],
        ]);
    }

    public function edit(MailEntry $mailEntry): Response|RedirectResponse
    {
        if ($mailEntry->editableFields() === []) {
            return redirect()->route('mail.show', $mailEntry)->with('error', 'flash.mail_locked');
        }

        return Inertia::render('Mail/Edit', [
            'entry' => $mailEntry,
            'editable' => $mailEntry->editableFields(),
            'correspondents' => $this->correspondents(),
        ]);
    }

    public function update(SaveMailEntryRequest $request, MailEntry $mailEntry): RedirectResponse
    {
        $this->register->update($mailEntry, Arr::except($request->validated(), ['scan']), $request->user(), $request->file('scan'));

        return redirect()->route('mail.show', $mailEntry)->with('success', 'flash.mail_updated');
    }

    public function cancel(Request $request, MailEntry $mailEntry): RedirectResponse
    {
        $this->register->cancel($mailEntry, $request->user());

        return back()->with('success', 'flash.mail_cancelled');
    }

    /** The scanned letter — private: this route (auth + mail/view) is the only way to read it. */
    public function scan(MailEntry $mailEntry, PrivateFileStorage $files): StreamedResponse
    {
        $path = $mailEntry->scan_path;
        abort_unless($path && $files->exists($path), 404);

        return $files->serve($path, $mailEntry->scan_name ?: basename($path));
    }

    /**
     * Earlier correspondents for the form's suggestions, per direction: the
     * same direction's names first, then the other's, each name once.
     *
     * @return array{out: list<string>, in: list<string>}
     */
    private function correspondents(): array
    {
        $rows = MailEntry::query()
            ->whereNotNull('correspondent')->where('correspondent', '!=', '')
            ->select(['correspondent', 'direction'])->distinct()
            ->toBase()->get();
        $list = fn (string $direction) => $rows
            ->sortBy([
                fn ($a, $b) => ($a->direction === $direction ? 0 : 1) <=> ($b->direction === $direction ? 0 : 1),
                fn ($a, $b) => strcmp($a->correspondent, $b->correspondent),
            ])
            ->pluck('correspondent')->unique()->take(self::SUGGESTIONS)->values()->all();

        return ['out' => $list(MailEntry::OUT), 'in' => $list(MailEntry::IN)];
    }
}
