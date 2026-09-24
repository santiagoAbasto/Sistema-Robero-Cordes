<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialAlias extends Model
{
    protected $table = 'material_alias';

    protected $guarded = [];

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
