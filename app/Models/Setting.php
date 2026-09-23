<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'value'])]
#[WithoutIncrementing]
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
