<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A screenshot of a result screen, kept so a disputed score has evidence behind it.
 */
class TournamentProof extends Model
{
    use HasFactory;

    protected $fillable = [
        'tournament_match_id',
        'path',
        'original_name',
        'uploaded_by',
    ];

    public function match(): BelongsTo
    {
        return $this->belongsTo(TournamentMatch::class, 'tournament_match_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->path);
    }
}
