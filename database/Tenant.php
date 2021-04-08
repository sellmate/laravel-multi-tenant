<?php

namespace App\Models\System;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $connection = 'system';
    protected $guarded = [];
    protected $casts = [
        'configs' => 'json',
        'setup_has_done' => 'boolean'
    ];

    public static function getTableName()
    {
        return with(new static)->getTable();
    }

    /* 
     * 아래 메서드들을 오버라이딩 하여 커넥션 제어
     */
    /* 
    public function getDatabaseName()
    {
        //
    }
    
    public function getDatabaseUsername()
    {
        // 
    }
    
    public function getDatabasePassword()
    {
        // 
    }
    
    public function getDbHostAttribute()
    {
        // 
    }

    public function getDbPortAttribute()
    {
        //
    }
     */
}
