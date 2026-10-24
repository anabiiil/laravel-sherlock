<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Tests\Feature\Testing\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $probe_author_id
 * @property string $title
 * @property-read ProbeAuthor $probeAuthor
 */
final class ProbePost extends Model
{
    protected $table = 'probe_posts';

    public $timestamps = false;

    protected $guarded = [];

    /** @return BelongsTo<ProbeAuthor, $this> */
    public function probeAuthor(): BelongsTo
    {
        return $this->belongsTo(ProbeAuthor::class);
    }
}
