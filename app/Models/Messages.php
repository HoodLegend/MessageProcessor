<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Messages extends Model
{

    use HasFactory;

    protected $fillable = [
    'file_path',
    'content',
    'processed_at',
    'status',
    'error_message'
    ];

    protected $casts = [
        'processed_at' => 'datetime',
        'content' => 'array'
    ];
}
