<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DianInboxSync extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    public function items(): HasMany
    {
        return $this->hasMany(DianInboxSyncItem::class, 'sync_id');
    }
}
