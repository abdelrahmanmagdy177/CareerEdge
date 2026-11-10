<?php

namespace App\Modules\Profiles\Models;

use App\Modules\Profiles\Database\Factories\SpecializationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $slug
 * @property string $name_en
 * @property string $name_ar
 * @property string|null $parent_id
 */
class Specialization extends Model
{
    /** @use HasFactory<SpecializationFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['slug', 'name_en', 'name_ar', 'parent_id'];

    /** The name in the language of the current request (see SetLocaleFromHeader). */
    public function localizedName(): string
    {
        return app()->getLocale() === 'ar' ? $this->name_ar : $this->name_en;
    }

    protected static function newFactory(): SpecializationFactory
    {
        return SpecializationFactory::new();
    }
}
