<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relation\HasMany;

#[Fillable(['name', 'description'])]
class Category extends Model
{
    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
