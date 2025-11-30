<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Upload extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'original_filename',
        'stored_filename',
        's3_etag',
        'status',
        'error_message',
        'total_pages'
    ];

     public function groups()
    {
        return $this->hasMany(Group::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
