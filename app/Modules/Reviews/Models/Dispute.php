<?php

namespace App\Modules\Reviews\Models;

use App\Support\Http\ApiDate;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A candidate's complaint about a completed session. While it is open the recruiter is not
 * paid. An admin closes it one of two ways: refund the candidate, or release the money.
 *
 * @property string $id
 * @property string $session_id
 * @property string $opened_by
 * @property string $reason
 * @property string $status
 * @property string|null $resolution_note
 * @property Carbon|null $resolved_at
 * @property Carbon $created_at
 */
class Dispute extends Model
{
    use HasUuids;

    public const OPEN = 'open';

    public const RESOLVED_REFUND = 'resolved_refund';

    public const RESOLVED_RELEASE = 'resolved_release';

    /** A dispute can be opened for this many hours after the session ended. */
    public const WINDOW_HOURS = 24;

    protected $fillable = ['session_id', 'opened_by', 'reason', 'status'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    /**
     * The `Dispute` schema of docs/openapi.yaml.
     *
     * @return array<string, mixed>
     */
    public function toContract(): array
    {
        return [
            'id' => $this->id,
            'sessionId' => $this->session_id,
            'reason' => $this->reason,
            'status' => $this->status,
            'resolutionNote' => $this->resolution_note,
            'createdAt' => ApiDate::format($this->created_at),
            'resolvedAt' => $this->resolved_at ? ApiDate::format($this->resolved_at) : null,
        ];
    }
}
