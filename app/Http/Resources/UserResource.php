<?php
// app/Http/Resources/UserResource.php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource {
    public function toArray($request) {
        return [
            'id'        => $this->id,
            'name'      => $this->name,
            'email'     => $this->email,
            'xp'        => (int)($this->xp ?? 0),
            'status'    => $this->status,
            'is_admin'  => (bool)$this->is_admin,
            'created_at'=> $this->created_at?->toIso8601String(),
            'last_active_at' => $this->last_active_at?->toIso8601String(), // if you track it
        ];
    }
}
