<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeniPool extends Model
{
    protected $fillable = [
        'tournament_id',
        'age_category_id',
        'match_category_id',
        'gender',
        'bracket_type',
        'name',
    ];

    // 🔗 Tournament
    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    // 🔗 Kategori Usia
    public function ageCategory()
    {
        return $this->belongsTo(AgeCategory::class);
    }

    // 🔗 Kategori Pertandingan (Tunggal, Ganda, Regu)
    public function matchCategory()
    {
        return $this->belongsTo(MatchCategory::class);
    }


    // 🔗 Match di dalam Pool ini
    public function matches()
    {
        return $this->hasMany(SeniMatch::class, 'pool_id');
    }

    public function schedule()
    {
        return $this->belongsTo(MatchSchedule::class, 'schedule_id');
    }
}
