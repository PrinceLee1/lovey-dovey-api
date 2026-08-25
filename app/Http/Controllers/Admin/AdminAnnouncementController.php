<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendFeatureAnnouncement;
use App\Models\FeatureAnnouncement;
use App\Models\User;
use Illuminate\Http\Request;

class AdminAnnouncementController extends Controller
{
    private function eligibleCount(): int
    {
        return User::active()->where('email_news', true)->count();
    }

    public function index(Request $r)
    {
        $announcements = FeatureAnnouncement::with('sender:id,name')
            ->latest()
            ->paginate($r->per_page ?? 20);

        return response()->json([
            'data' => $announcements->items(),
            'meta' => [
                'current_page' => $announcements->currentPage(),
                'last_page' => $announcements->lastPage(),
                'total' => $announcements->total(),
            ],
            'eligible_recipients' => $this->eligibleCount(),
        ]);
    }

    public function store(Request $r)
    {
        $v = $r->validate([
            'subject' => 'required|string|max:150',
            'body' => 'required|string|max:5000',
        ]);

        $announcement = FeatureAnnouncement::create([
            'sent_by' => $r->user()->id,
            'subject' => $v['subject'],
            'body' => $v['body'],
            'status' => 'pending',
            'recipients_count' => $this->eligibleCount(),
        ]);

        // On this app's sync queue connection this runs inline and the
        // announcement already reflects final send counts by the time this
        // request returns; on a real queue worker it'll finish shortly after.
        SendFeatureAnnouncement::dispatch($announcement->id);

        return response()->json($announcement->fresh('sender'), 201);
    }
}
