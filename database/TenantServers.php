<?php

namespace App\Models\System;

use Illuminate\Database\Eloquent\Model;

class TenantServers extends Model
{
    protected $connection = 'system';
    protected $guarded = [];    

    public static function getTableName()
    {
        return with(new static)->getTable();
    }
}
