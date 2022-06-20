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
    
    public function getOfflineAttribute(){
        if (isset(json_decode($this->data)->settings->timeout)) {
            if (json_decode($this->data)->settings->timeout >= $this->updated_at->diffInSeconds()){
                return false;
            }
        }
        
        return true;
    }
    
    public function getLastLogonUserAttribute(){
        if (isset(json_decode($this->data)->machine->last_logon_user)) {
            return json_decode($this->data)->machine->last_logon_user;
        }
        return false;
    }

    public function getUpdatesAttribute(){
        if (isset(json_decode($this->data)->machine->updates)) {
            return (array) json_decode($this->data)->machine->updates;
        }
        return [];
    }

    public function getNetworksAttribute(){
        if (isset(json_decode($this->data)->machine->addresses)) {
            return (array) json_decode($this->data)->machine->addresses;
        }
        return [];
    }
}
