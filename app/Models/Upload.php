<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Upload extends Model
{
    use HasFactory;

    protected $fillable = [
        'original_filename','stored_filename','tus_path','total_pages','status','error_message','user_id'
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
