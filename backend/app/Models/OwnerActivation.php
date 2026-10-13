<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OwnerActivation extends Model
{
    use HasFactory;

    protected $fillable = ['account_id', 'email', 'owner_name', 'token_hash', 'expires_at', 'consumed_at', 'revoked_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'consumed_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
