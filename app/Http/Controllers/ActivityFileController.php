<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivityFile;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use App\Services\Storage\PrivateFileStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Attached documents (minutes, agreements, certificates): private disk, archive only. */
class ActivityFileController extends Controller
{
    public function store(Request $request, Activity $activity, PrivateFileStorage $storage): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png,webp', 'max:20480'],
        ]);

        $activity->files()->create($storage->store($request->file('file'), $activity->fileDirectory()));

        ActivityRecorder::record($request->user(), ActivityAction::ACTIVITY_UPDATED, $activity);

        return back()->with('success', 'flash.activity_file_uploaded');
    }

    public function show(Activity $activity, ActivityFile $file, PrivateFileStorage $storage): StreamedResponse
    {
        return $storage->serve($file->path, $file->original_name);
    }

    public function destroy(Request $request, Activity $activity, ActivityFile $file, PrivateFileStorage $storage): RedirectResponse
    {
        $storage->delete($file->path);
        $file->delete();

        ActivityRecorder::record($request->user(), ActivityAction::ACTIVITY_UPDATED, $activity);

        return back()->with('success', 'flash.activity_file_deleted');
    }
}
