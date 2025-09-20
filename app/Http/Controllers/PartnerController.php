<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Partner;
use App\Models\PartnerInvite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Events\PartnerInviteAccepted;
use App\Events\PartnerInviteRejected;
use App\Events\PartnerStatusUpdated;
class PartnerController extends Controller
{
  // Generate an invite CODE (inviter shares it manually)
  public function createInvite(Request $r) {
    $user = $r->user();

    $has = Partner::where('status','active')
      ->where(fn($q)=>$q->where('user_a_id',$user->id)->orWhere('user_b_id',$user->id))
      ->exists();
    if ($has) return response()->json(['message'=>'You already have a partner'], 422);

    $invite = PartnerInvite::where('inviter_id',$user->id)
      ->where('status','pending')
      ->where(fn($q)=>$q->whereNull('expires_at')->orWhere('expires_at','>',now()))
      ->latest()->first();

    if (!$invite) {
      do { $code = Str::upper(Str::random(8)); } while (PartnerInvite::where('code',$code)->exists());
      $invite = PartnerInvite::create([
        'code' => $code, 'inviter_id' => $user->id, 'expires_at' => now()->addDays(7),
      ]);
    }

    return response()->json(['code'=>$invite->code,'expires_at'=>optional($invite->expires_at)->toIso8601String()]);
  }

  // 🔎 Lookup code (for the input field UX)
  public function lookup(Request $r, string $code) {
    $me = $r->user();

    $invite = PartnerInvite::where('code',$code)->first();
    if (!$invite) return response()->json(['valid'=>false,'reason'=>'not_found'], 404);

    if ($invite->status !== 'pending') return response()->json(['valid'=>false,'reason'=>'resolved'], 422);
    if ($invite->expires_at && $invite->expires_at->isPast()) return response()->json(['valid'=>false,'reason'=>'expired'], 422);
    if ($invite->inviter_id === $me->id) return response()->json(['valid'=>false,'reason'=>'self'], 422);

    // neither inviter nor me can already have partner
    $inviterHas = Partner::where('status','active')->where(fn($q)=>$q->where('user_a_id',$invite->inviter_id)->orWhere('user_b_id',$invite->inviter_id))->exists();
    $meHas      = Partner::where('status','active')->where(fn($q)=>$q->where('user_a_id',$me->id)->orWhere('user_b_id',$me->id))->exists();
    if ($inviterHas || $meHas) return response()->json(['valid'=>false,'reason'=>'already_paired'], 422);

    $inviter = User::select('id','name')->find($invite->inviter_id);
    return response()->json(['valid'=>true,'inviter'=>$inviter, 'expires_at'=>optional($invite->expires_at)->toIso8601String()]);
  }

  // ✅ Accept by entering the code
public function accept(Request $r, string $code) {
    $me = $r->user();

    return \DB::transaction(function () use ($code, $me) {
        $invite = PartnerInvite::lockForUpdate()
            ->where('code', $code)->firstOrFail();

        if ($invite->status !== 'pending') {
            return response()->json(['message' => 'Invite already resolved'], 422);
        }
        if ($invite->expires_at && $invite->expires_at->isPast()) {
            return response()->json(['message' => 'Invite expired'], 422);
        }
        if ((int)$invite->inviter_id === (int)$me->id) {
            return response()->json(['message' => "You can't accept your own invite"], 422);
        }

        // Neither can already be paired
        $inviterHas = Partner::where('status', 'active')
            ->where(fn($q) => $q->where('user_a_id', $invite->inviter_id)
                               ->orWhere('user_b_id', $invite->inviter_id))
            ->exists();
        $meHas = Partner::where('status', 'active')
            ->where(fn($q) => $q->where('user_a_id', $me->id)
                               ->orWhere('user_b_id', $me->id))
            ->exists();
        if ($inviterHas || $meHas) {
            return response()->json(['message' => 'One of you already has a partner'], 422);
        }

        // Normalize order for unique key
        $a = min($me->id, $invite->inviter_id);
        $b = max($me->id, $invite->inviter_id);

        // 🔧 Upsert behavior: revive existing row if present
        $pair = Partner::lockForUpdate()
            ->where('user_a_id', $a)->where('user_b_id', $b)->first();

        if ($pair) {
            if ($pair->status === 'active') {
                return response()->json(['message' => 'You are already partners'], 422);
            }
            $pair->update([
                'status' => 'active',
                'unpair_requested_by' => null,
                'started_at' => now(),
                'ended_at' => null,
            ]);
        } else {
            $pair = Partner::create([
                'user_a_id' => $a,
                'user_b_id' => $b,
                'status'    => 'active',
                'started_at'=> now(),
            ]);
        }

        $invite->update([
            'invitee_id' => $me->id,
            'status'     => 'accepted',
        ]);

        // Notify inviter (optional but recommended)
        $meUser = User::select('id','name')->find($me->id);
        broadcast(new PartnerInviteAccepted($invite->inviter_id, [
            'id' => $meUser->id, 'name' => $meUser->name,
        ]));

        return response()->json(['partner' => $pair]);
    });
}


  public function reject(Request $r, string $code) {
    $me = $r->user();
    $invite = PartnerInvite::where('code',$code)->firstOrFail();
    if ($invite->status !== 'pending') return response()->json(['message'=>'Invite already resolved'], 422);
    if ($invite->inviter_id === $me->id) return response()->json(['message'=>"You can't reject your own invite"], 422);

    $invite->update(['invitee_id'=>$me->id, 'status'=>'rejected']);

    // 🔔 notify inviter
    broadcast(new PartnerInviteRejected($invite->inviter_id));

    return response()->json(['ok'=>true]);
  }

  // Only show my sent invites (no global incoming)
  public function invites(Request $r) {
    $user = $r->user();
    $sent = PartnerInvite::select('id','code','status','expires_at')
      ->where('inviter_id',$user->id)->latest()->limit(20)->get();
    return response()->json(['sent'=>$sent]);
  }

  // Unpair flow with notifications
  public function unpairRequest(Request $r) {
    $me = $r->user();
    $link = Partner::where('status','active')->where(fn($q)=>$q->where('user_a_id',$me->id)->orWhere('user_b_id',$me->id))->firstOrFail();

    if ($link->unpair_requested_by) return response()->json(['message'=>'Unpair already requested'], 422);

    $link->update(['status'=>'pending_unpair','unpair_requested_by'=>$me->id]);

    $otherId = $link->user_a_id === $me->id ? $link->user_b_id : $link->user_a_id;
    // 🔔 notify the other user to approve
    broadcast(new PartnerStatusUpdated($otherId, [
      'status' => 'pending_unpair',
      'by' => ['id'=>$me->id, 'name'=>$me->name],
    ]));

    return response()->json(['ok'=>true]);
  }

  public function unpairConfirm(Request $r) {
    $me = $r->user();
    $link = Partner::where('status','pending_unpair')->where(fn($q)=>$q->where('user_a_id',$me->id)->orWhere('user_b_id',$me->id))->firstOrFail();

    if ($link->unpair_requested_by === $me->id) {
      return response()->json(['message'=>'Partner must confirm'], 422);
    }

    $link->update(['status'=>'ended','ended_at'=>now()]);

    $otherId = $link->user_a_id === $me->id ? $link->user_b_id : $link->user_a_id;
    // 🔔 notify the requester that it’s done
    broadcast(new PartnerStatusUpdated($otherId, ['status'=>'ended']));

    return response()->json(['ok'=>true]);
  }
  public function unpairCancel(Request $r) {
    $me = $r->user();
    $link = Partner::where('status','pending_unpair')
        ->where(fn($q)=>$q->where('user_a_id',$me->id)->orWhere('user_b_id',$me->id))
        ->firstOrFail();

    if ((int)$link->unpair_requested_by !== (int)$me->id) {
        return response()->json(['message' => 'Only the requester can cancel'], 403);
    }

    $link->update(['status' => 'active', 'unpair_requested_by' => null]);

    $otherId = $link->user_a_id === $me->id ? $link->user_b_id : $link->user_a_id;
    broadcast(new PartnerStatusUpdated($otherId, ['status' => 'active']));

    return response()->json(['ok' => true]);
}
public function status(Request $r) {
    $me = $r->user();

    // ⬅️ was only 'active' before; include pending_unpair so UI can show the pending banner/buttons
    $link = Partner::whereIn('status', ['active','pending_unpair'])
        ->where(fn($q) => $q->where('user_a_id', $me->id)->orWhere('user_b_id', $me->id))
        ->first();

    if (!$link) {
        return response()->json([
            'partner' => null,
            'link'    => null,
            'shared'  => ['games' => [], 'counts' => ['total' => 0]],
        ]);
    }

    $otherId = $link->user_a_id === $me->id ? $link->user_b_id : $link->user_a_id;
    $other   = User::select('id','name')->find($otherId);

    $games = DB::table('game_histories')
        ->whereIn('user_id', [$me->id, $otherId])
        ->whereIn('partner_user_id', [$me->id, $otherId])
        ->orderByDesc('created_at')->limit(20)->get();

    return response()->json([
        'partner' => ['id'=>$other->id,'name'=>$other->name],
        'link'    => [
            'status' => $link->status,
            'unpair_requested_by' => $link->unpair_requested_by,
            'started_at' => optional($link->started_at)->toIso8601String(),
            'ended_at'   => optional($link->ended_at)->toIso8601String(),
        ],
        'shared'  => ['games'=>$games, 'counts'=>['total'=>$games->count()]],
    ]);
}
}
