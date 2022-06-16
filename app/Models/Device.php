<?php

namespace App\Models;

use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    use HasFactory;
    public function getDrivesAttribute($value)
    {
        return json_decode($value, true);
    }

    public function setDrivesAttribute($value)
    {
        $this->attributes['drives'] = json_encode($value);
    }

    public function getCommandsAttribute($value)
    {
        return json_decode($value);
    }

    public function setCommandsAttribute($value)
    {
        $this->attributes['commands'] = json_encode((array) $value);
    }


    public function getNiceUptimeAttribute(){
        if (isset(json_decode($this->data)->machine->uptime)){
            return CarbonInterval::seconds(json_decode($this->data)->machine->uptime)->cascade()->forHumans();
        }
        return false;
    }
}
