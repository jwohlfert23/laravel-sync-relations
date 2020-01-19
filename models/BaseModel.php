<?php namespace Models;

use Illuminate\Database\Eloquent\Model;
use Jwohlfert23\LaravelSyncRelations\SyncableTrait;

class BaseModel extends Model
{
    use SyncableTrait;
}
