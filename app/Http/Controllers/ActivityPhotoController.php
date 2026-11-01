<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivityPhoto;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use App\Services\Storage\FileStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ActivityPhotoController extends Controller
{
    public function store(Request $request, Activity $activity, FileStorageService $storage): RedirectResponse
    {
        $request->validate([
            'photos' => ['required', 'array', 'min:1', 'max:20'],
            'photos.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        $order = (int) $activity->photos()->max('sort_order');
        foreach ($request->file('photos') as $upload) {
            $sizes = $storage->storeImageVariants($upload, [
                1600 => $activity->photoDirectory(),
                400 => $activity->photoDirectory().'/thumbs',
            ]);

            $activity->photos()->create([
                'path' => $sizes[1600]['path'],
                'thumb_path' => $sizes[400]['path'],
                'sort_order' => ++$order,
            ]);
        }

        ActivityRecorder::record($request->user(), ActivityAction::ACTIVITY_UPDATED, $activity);

        return back()->with('success', 'flash.activity_photos_added');
    }

    public function update(Request $request, Activity $activity, ActivityPhoto $photo): RedirectResponse
    {
        $data = $request->validate([
            'caption' => ['nullable', 'string', 'max:255'],
            'in_report' => ['sometimes', 'boolean'],
        ]);

        $inReport = $request->has('in_report') ? $request->boolean('in_report') : $photo->in_report;
        if ($inReport && ! $photo->in_report
            && $activity->photos()->where('in_report', true)->count() >= ActivityPhoto::REPORT_LIMIT) {
            return back()->with('error', 'flash.activity_report_photos_limit');
        }

        $photo->update([
            'caption' => array_key_exists('caption', $data) ? $data['caption'] : $photo->caption,
            'in_report' => $inReport,
        ]);

        ActivityRecorder::record($request->user(), ActivityAction::ACTIVITY_UPDATED, $activity);

        return back()->with('success', 'flash.activity_photo_updated');
    }

    public function destroy(Request $request, Activity $activity, ActivityPhoto $photo, FileStorageService $storage): RedirectResponse
    {
        $storage->delete($photo->path);
        $storage->delete($photo->thumb_path);
        $photo->delete();

        ActivityRecorder::record($request->user(), ActivityAction::ACTIVITY_UPDATED, $activity);

        return back()->with('success', 'flash.activity_photo_deleted');
    }
}
