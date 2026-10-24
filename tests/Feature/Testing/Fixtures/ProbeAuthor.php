<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Tests\Feature\Testing\Fixtures;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property-read Collection<int, ProbePost> $probePosts
 */
final class ProbeAuthor extends Model
{
    protected $table = 'probe_authors';

    public $timestamps = false;

    protected $guarded = [];

    /** @return HasMany<ProbePost, $this> */
    public function probePosts(): HasMany
    {
        return $this->hasMany(ProbePost::class);
    }
}
