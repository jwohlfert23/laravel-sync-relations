<?php namespace Models;

class Row extends BaseModel
{
    protected $syncable = ['cells'];

    public function cells()
    {
        return $this->hasMany(Cell::class);
    }
}
