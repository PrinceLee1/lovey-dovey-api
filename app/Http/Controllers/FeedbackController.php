<?php

namespace App\Http\Controllers;

use App\Models\Feedback;
use Illuminate\Http\Request;

class FeedbackController extends Controller
{
    public function store(Request $r)
    {
        $v = $r->validate([
            'category' => 'nullable|in:bug,idea,praise,other',
            'message'  => 'required|string|max:2000',
        ]);

        $feedback = Feedback::create([
            'user_id'  => $r->user()->id,
            'category' => $v['category'] ?? 'other',
            'message'  => $v['message'],
            'status'   => 'new',
        ]);

        return response()->json($feedback, 201);
    }

    // ── Admin ────────────────────────────────────────────────────────────────
    public function index(Request $r)
    {
        $q = Feedback::with('user:id,name,email')->latest();

        if ($r->status) {
            $q->where('status', $r->status);
        }

        return $q->paginate($r->per_page ?? 20);
    }

    public function markReviewed(Request $r, int $id)
    {
        $feedback = Feedback::findOrFail($id);
        $feedback->update(['status' => $r->boolean('reviewed', true) ? 'reviewed' : 'new']);

        return response()->json($feedback);
    }
}
