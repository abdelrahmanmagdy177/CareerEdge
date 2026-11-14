<?php

namespace App\Modules\Messaging\Models;

use App\Support\Http\ApiDate;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $session_id
 * @property string $sender_id
 * @property string $client_message_id
 * @property string $body
 * @property Carbon $created_at
 */
class Message extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const MAX_LENGTH = 4000;

    protected $fillable = ['session_id', 'sender_id', 'client_message_id', 'body'];

    /**
     * The `Message` schema of docs/openapi.yaml. The REST answers and the live event use
     * this same method, so the two can never drift apart.
     *
     * @return array<string, string>
     */
    public function toContract(): array
    {
        return [
            'id' => $this->id,
            'sessionId' => $this->session_id,
            'senderId' => $this->sender_id,
            'clientMessageId' => $this->client_message_id,
            'body' => $this->body,
            'sentAt' => ApiDate::format($this->created_at),
        ];
    }
}
