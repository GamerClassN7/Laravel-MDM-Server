<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One item of a source of a device's security inventory, with the hash of its content. */
class SecurityInventoryItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['device_id', 'source', 'item_key', 'item_hash', 'data'];
}
