<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeniMatch extends Model
{
    // app/Models/SeniMatch.php

    protected $fillable = [
        'pool_id',
        'match_order',
        'gender',
        'match_category_id',
        'match_type',
        'match_date',
        'match_time',
        'arena_name',
        'contingent_id',
        'team_member_1',
        'team_member_2',
        'team_member_3',
        'final_score',

        // Battle mode fields
        'mode',
        'battle_group',
        'round',
        'round_label',        // ⬅️ tambahkan ini
        'corner',
        'winner_corner',
        'parent_match_red_id',
        'parent_match_blue_id',
        'status',
    ];

    protected $casts = [
        'winner_corner' => 'string',
    ];



    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function matchCategory()
    {
        return $this->belongsTo(MatchCategory::class);
    }

    public function contingent()
    {
        return $this->belongsTo(Contingent::class);
    }

    public function teamMember1()
    {
        return $this->belongsTo(TeamMember::class, 'team_member_1');
    }

    public function teamMember2()
    {
        return $this->belongsTo(TeamMember::class, 'team_member_2');
    }

    public function teamMember3()
    {
        return $this->belongsTo(TeamMember::class, 'team_member_3');
    }

    public function pool()
    {
        return $this->belongsTo(SeniPool::class);
    }

    public function getAllMembersAttribute()
    {
        return collect([$this->teamMember1, $this->teamMember2, $this->teamMember3])->filter();
    }

     public function scheduleDetail()
    {
        return $this->hasOne(MatchScheduleDetail::class, 'seni_match_id');
    }

   
}
