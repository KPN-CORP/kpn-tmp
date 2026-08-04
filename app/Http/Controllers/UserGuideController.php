<?php

namespace App\Http\Controllers;

use App\Models\UserGuide;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserGuideController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('UserGuide/Index', [
            'guides' => UserGuide::with('uploader:id,name')
                ->latest()
                ->get(['id', 'title', 'description', 'file_name', 'file_size', 'target_role', 'uploaded_by', 'created_at']),
            'canManage' => $request->user()->can('manage_user_guide'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'target_role' => ['required', 'string', 'in:all,manager,admin'],
            'file' => ['required', 'file', 'mimes:pdf,doc,docx,ppt,pptx', 'max:20480'],
        ]);

        $file = $request->file('file');
        $path = $file->store('user-guides', 'local');

        UserGuide::create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'target_role' => $validated['target_role'],
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $this->humanSize($file->getSize()),
            'uploaded_by' => $request->user()->id,
        ]);

        return back()->with('success', 'User guide uploaded.');
    }

    public function download(UserGuide $userGuide): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($userGuide->file_path), 404);

        return Storage::disk('local')->download($userGuide->file_path, $userGuide->file_name);
    }

    public function destroy(UserGuide $userGuide): RedirectResponse
    {
        if (Storage::disk('local')->exists($userGuide->file_path)) {
            Storage::disk('local')->delete($userGuide->file_path);
        }

        $userGuide->delete();

        return back()->with('success', 'User guide deleted.');
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;

        return round($bytes / (1024 ** $i), 1).' '.$units[$i];
    }
}
