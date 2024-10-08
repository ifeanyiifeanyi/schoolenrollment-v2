<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScholarQuestion extends Model
{
    use HasFactory;
    protected $guarded = [];

    protected $casts = [
        'options' => 'array',
    ];

    public function scholarship()
    {
        return $this->belongsTo(Scholarship::class);
    }

    public function answers()
    {
        return $this->hasMany(ScholarAnswer::class);
    }
}
