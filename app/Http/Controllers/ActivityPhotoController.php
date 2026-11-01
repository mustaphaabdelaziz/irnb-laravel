<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivityPhoto;
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
            $large = $storage->storeImage($upload, $activity->photoDirectory(), 1600);
            $thumb = $storage->storeImage($upload, $activity->photoDirectory().'/thumbs', 400);

            $activity->photos()->create([
                'path' => $large['path'],
                'thumb_path' => $thumb['path'],
                'sort_order' => ++$order,
            ]);
        }

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

        return back()->with('success', 'flash.activity_photo_updated');
    }

    public function destroy(Activity $activity, ActivityPhoto $photo, FileStorageService $storage): RedirectResponse
    {
        $storage->delete($photo->path);
        $storage->delete($photo->thumb_path);
        $photo->delete();

        return back()->with('success', 'flash.activity_photo_deleted');
    }
}
