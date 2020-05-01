<?php namespace Models;

class Table extends BaseModel
{
    protected $syncable = ['rows.cells'];

    public function rows()
    {
        return $this->hasMany(Row::class);
    }
}
