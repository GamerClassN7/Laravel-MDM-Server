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
        if (null !== json_decode($value, true)) {
            $drives = (array) json_decode($value, true);
            foreach ($drives as $key => $drive) {
                if ($drive['TotalSize'] <= 0) {
                    continue;
                }

                $usedSpace = (int) $drive['TotalSize'] - (int) $drive['AvailableFreeSpace'];
                $drives[$key]['PercentUsed'] = round($usedSpace / ((int) $drive['TotalSize'] / 100));
            }
            return $drives;
        }
        return [];
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

    public function getDisplayNameAttribute()
    {
        $name = $this->friendly_name;
        if (empty($name)) {
            $name = $this->name;
        }
        return $name;
    }

    public function getNiceUptimeAttribute()
    {
        if (isset(json_decode($this->data)->machine->uptime)) {
            return CarbonInterval::seconds(json_decode($this->data)->machine->uptime)->cascade()->forHumans();
        }
        return false;
    }

    public function getOfflineAttribute()
    {
        if (isset(json_decode($this->data)->settings->timeout)) {
            if (json_decode($this->data)->settings->timeout >= $this->updated_at->diffInSeconds()) {
                return false;
            }
        }

        return true;
    }

    public function getRestartPendingAttribute()
    {
        if (null !== json_decode($this->data)->machine->restart_pending) {
            if (filter_var(json_decode($this->data)->machine->restart_pending, FILTER_VALIDATE_BOOLEAN) === true) {
                return true;
            }
        }

        return false;
    }

    public function getLastLogonUserAttribute()
    {
        if (isset(json_decode($this->data)->machine->last_logon_user)) {
            return json_decode($this->data)->machine->last_logon_user;
        }
        return false;
    }

    public function getUpdatesAttribute()
    {
        if (isset(json_decode($this->data)->machine->updates)) {
            return (array) json_decode($this->data)->machine->updates;
        }
        return [];
    }

    public function getNetworksAttribute()
    {
        if (isset(json_decode($this->data)->machine->addresses)) {
            return (array) json_decode($this->data)->machine->addresses;
        }
        return [];
    }
}
