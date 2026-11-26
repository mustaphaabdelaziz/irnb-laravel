<?php

namespace App\Http\Controllers;

use App\Models\MailEntry;
use App\Services\Secretariat\MailRegister;
use App\Services\Secretariat\MailSources;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The print dialog's two calls (absence letter, convocation, purchase order):
 * show looks the document's active entry up; store registers it (or returns
 * the existing one — a reprint keeps its reference). The PDF routes never
 * write; only store does. Both need mail → add (config/permissions.php) and
 * the right to print the document (MailSources::resolve()).
 */
class MailSourceController extends Controller
{
    public function __construct(
        private readonly MailRegister $register,
        private readonly MailSources $sources,
    ) {}

    public function show(Request $request, string $type, int $id): JsonResponse
    {
        $source = $this->sources->resolve($request, $type, $id);
        $entry = $this->register->find($source['type'], $source['id'], $source['key']);

        return response()->json(['entry' => $entry ? self::summary($entry) : null]);
    }

    public function store(Request $request, string $type, int $id): JsonResponse
    {
        $source = $this->sources->resolve($request, $type, $id);
        $entry = $this->register->register($source['type'], $source['id'], $source['key'], $source['defaults'], $request->user());

        return response()->json(['entry' => self::summary($entry), 'created' => $entry->wasRecentlyCreated]);
    }

    /** @return array{id: int, reference: string} */
    private static function summary(MailEntry $entry): array
    {
        return ['id' => $entry->id, 'reference' => $entry->reference];
    }
}
