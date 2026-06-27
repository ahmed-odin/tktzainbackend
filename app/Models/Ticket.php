<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    use HasFactory;

    protected static function booted()
    {
        static::creating(function (Ticket $ticket) {
            if (! $ticket->ticket_id) {
                $ticket->ticket_id = (int) Ticket::max('ticket_id') + 1;
            }
        });
    }

    protected $fillable = [
        'ticket_id',
        'missdn',
        'governorate',
        'comments',
        'alwaseet_company',
        'status',
        'created_by',
        'completed_by',
        'completed_at',
    ];

    protected $with = ['creator', 'completer'];

    protected $appends = ['completed_by_name'];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    public function getCompletedByNameAttribute()
    {
        return $this->completer?->name;
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completer()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'Pending');
    }

    public function scopeComplete($query)
    {
        return $query->where('status', 'Complete');
    }

    public function scopeSearchMissdn($query, $missdn)
    {
        return $query->where('missdn', 'like', '%' . $missdn . '%');
    }

    public function scopeFilterGovernorate($query, $governorate)
    {
        return $query->where('governorate', $governorate);
    }
}
