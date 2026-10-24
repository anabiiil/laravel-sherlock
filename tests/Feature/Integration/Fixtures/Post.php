<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Tests\Feature\Integration\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $author_id
 * @property string $title
 * @property-read Author $author
 */
final class Post extends Model
{
    protected $table = 'integration_posts';

    public $timestamps = false;

    protected $guarded = [];

    /** @return BelongsTo<Author, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }
}
