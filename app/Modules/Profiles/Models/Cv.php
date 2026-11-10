<?php

namespace App\Modules\Profiles\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $file_name
 * @property string $content_type
 * @property int $size_bytes
 * @property string $storage_key
 * @property Carbon $created_at
 */
class Cv extends Model
{
    use HasUuids, SoftDeletes;

    public const PDF = 'application/pdf';

    public const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    protected $fillable = ['user_id', 'file_name', 'content_type', 'size_bytes', 'storage_key'];
}
