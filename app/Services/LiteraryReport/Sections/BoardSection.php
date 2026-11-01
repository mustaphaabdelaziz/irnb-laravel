<?php

namespace App\Services\LiteraryReport\Sections;

use App\Models\BoardCommittee;
use App\Models\BoardMeeting;
use App\Models\BoardMember;
use App\Models\BoardRole;
use App\Models\BoardTerm;
use Carbon\CarbonImmutable;

/** The current board, its committees, and the meetings held in the period with their decisions. */
final class BoardSection implements Section
{
    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $term = BoardTerm::query()->where('is_current', true)->first() ?? BoardTerm::query()->latest('id')->first();
        $roles = BoardRole::query()->get(['name', 'label', 'level', 'sort_order'])->keyBy('name');
        $levels = array_flip(BoardRole::LEVELS);

        $members = $term === null ? collect() : $term->members()->where('status', 'active')->withPlayer()->get()
            ->sortBy(fn (BoardMember $m) => [
                $levels[$roles->get($m->role)?->level] ?? count($levels),
                $roles->get($m->role)?->sort_order ?? PHP_INT_MAX,
                $m->sort_order,
            ])->values();

        $committees = BoardCommittee::query()
            ->with(['members' => fn ($q) => $q->where('status', 'active')->withPlayer()])
            ->orderBy('sort_order')->orderBy('name')
            ->get()
            ->map(fn (BoardCommittee $c) => [
                'name' => $c->name,
                'head' => $c->members->firstWhere('pivot.position', 'head')?->name,
                'deputies' => $c->members->where('pivot.position', 'deputy')->map(fn (BoardMember $m) => $m->name)->values()->all(),
                'members_count' => $c->members->count(),
            ])->all();

        $meetings = BoardMeeting::query()
            ->where('status', '!=', 'cancelled')
            ->whereBetween('meeting_date', [$from->startOfDay(), $to->endOfDay()])
            ->orderBy('meeting_date')
            ->get(['id', 'title', 'type', 'meeting_date', 'decisions'])
            ->map(fn (BoardMeeting $m) => [
                'date' => $m->meeting_date->toDateString(),
                'title' => $m->title,
                'type' => $m->type,
                'decisions' => array_values(array_filter((array) $m->decisions, fn ($d) => trim((string) $d) !== '')),
            ])->all();

        return [
            'term' => $term?->name,
            'members' => $members->map(fn (BoardMember $m) => [
                'name' => $m->name,
                'role' => $roles->get($m->role)?->label ?: $m->role,
            ])->all(),
            'committees' => $committees,
            'meetings' => $meetings,
        ];
    }
}
